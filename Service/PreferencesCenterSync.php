<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPreferencesCenterBundle\Service;

use Mautic\CategoryBundle\Entity\Category;
use Mautic\CategoryBundle\Model\CategoryModel;
use Mautic\CoreBundle\Configurator\Configurator;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\LanguageHelper;
use Mautic\CoreBundle\Helper\ThemeHelperInterface;
use Mautic\PageBundle\Entity\Page;
use Mautic\PageBundle\Model\PageModel;

/**
 * Brings the preference center to a declared state (preferences-center:sync),
 * e.g. from deployment configuration:
 *
 * - topics: global categories matched by name; missing ones are created;
 *   shown, in the declared order, with the declared text. Other categories
 *   are never changed, unless hideOtherTopics hides them on the page;
 * - page (optional): the preference page, matched by alias; created once
 *   from a theme that contains the preference center tokens, marked as
 *   preference center and published. Its content is kept afterwards (it may
 *   be edited in the builder) unless page.updateContent is set;
 * - Mautic's settings: the unsubscribe link shows the preferences with the
 *   topics, not segments; frequency and pause on request; the page as
 *   default preference center; optionally the theme of the system pages.
 *   Written to config/local.php only when they differ.
 */
class PreferencesCenterSync
{
    private const KEYS       = ['topics', 'hideOtherTopics', 'page', 'frequency', 'pauseDates', 'systemTheme'];
    private const TOPIC_KEYS = ['name', 'text', 'visible'];
    private const PAGE_KEYS  = ['title', 'alias', 'theme', 'language', 'updateContent'];
    private const TOKENS     = ['{categorylist}', '{saveprefsbutton}'];

    public function __construct(
        private readonly CategoryModel $categories,
        private readonly PageModel $pages,
        private readonly ThemeHelperInterface $themes,
        private readonly Configurator $configurator,
        private readonly CoreParametersHelper $parameters,
        private readonly LanguageHelper $languages,
        private readonly TopicStore $topics,
    ) {
    }

    /**
     * @return array{topics: list<array{name: string, text: string|null, visible: bool}>, hideOtherTopics: bool, page: array{title: string, alias: string, theme: string, language: string, updateContent: bool}|null, frequency: bool, pauseDates: bool, systemTheme: string}
     *
     * @throws \InvalidArgumentException listing every invalid value
     */
    public function parse(string $json): array
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('not valid JSON: '.$e->getMessage());
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException('the declaration must be a JSON object');
        }

        $errors = [];
        foreach (array_diff(array_keys($data), self::KEYS) as $key) {
            $errors[] = "unknown key \"$key\" (allowed: ".implode(', ', self::KEYS).')';
        }

        $topics = [];
        $names  = [];
        foreach ((array) ($data['topics'] ?? []) as $i => $topic) {
            $name = is_array($topic) && is_string($topic['name'] ?? null) ? trim($topic['name']) : '';
            if ('' === $name || mb_strlen($name) > 191) {
                $errors[] = sprintf('topic %d: "name" is required (at most 191 characters)', (int) $i + 1);
                continue;
            }
            if (isset($names[mb_strtolower($name)])) {
                $errors[] = "topic \"$name\": declared twice";
                continue;
            }
            $names[mb_strtolower($name)] = true;
            foreach (array_diff(array_keys($topic), self::TOPIC_KEYS) as $key) {
                $errors[] = "topic \"$name\": unknown key \"$key\" (allowed: ".implode(', ', self::TOPIC_KEYS).')';
            }
            $text = trim((string) ($topic['text'] ?? ''));
            if (mb_strlen($text) > 1000) {
                $errors[] = "topic \"$name\": \"text\" has more than 1000 characters";
            }
            $topics[] = ['name' => $name, 'text' => '' === $text ? null : $text, 'visible' => false !== ($topic['visible'] ?? true)];
        }

        $page = null;
        if (isset($data['page'])) {
            $declared = is_array($data['page']) ? $data['page'] : [];
            foreach (array_diff(array_keys($declared), self::PAGE_KEYS) as $key) {
                $errors[] = "page: unknown key \"$key\" (allowed: ".implode(', ', self::PAGE_KEYS).')';
            }
            $page = [
                'title'         => trim((string) ($declared['title'] ?? 'Email preferences')),
                'alias'         => trim((string) ($declared['alias'] ?? 'preferences')),
                'theme'         => trim((string) ($declared['theme'] ?? '')),
                'language'      => trim((string) ($declared['language'] ?? '')),
                'updateContent' => true === ($declared['updateContent'] ?? false),
            ];
            if (1 !== preg_match('/^[a-z0-9][a-z0-9-]*$/', $page['alias'])) {
                $errors[] = 'page.alias: lowercase letters, digits and hyphens only';
            }
            if ('' === $page['theme']) {
                $errors[] = 'page.theme is required: a theme whose page template contains '.implode(' and ', self::TOKENS);
            } elseif (!$this->themes->exists($page['theme'])) {
                $errors[] = "page.theme: theme \"{$page['theme']}\" is not installed";
            } elseif (null !== $missing = $this->missingTokens($page['theme'])) {
                $errors[] = "page.theme: the page template of \"{$page['theme']}\" has no $missing (it is not a preference center theme)";
            }
            if ('' !== $page['language'] && !array_key_exists($page['language'], $installed = $this->languages->getSupportedLanguages())) {
                // Saving a page in a language that is not installed makes Mautic download it.
                $errors[] = sprintf('page.language: "%s" is not installed (installed: %s)', $page['language'], implode(', ', array_keys($installed)));
            }
        }

        $systemTheme = trim((string) ($data['systemTheme'] ?? ''));
        if ('' !== $systemTheme && !$this->themes->exists($systemTheme)) {
            $errors[] = "systemTheme: theme \"$systemTheme\" is not installed";
        }
        foreach (['hideOtherTopics', 'frequency', 'pauseDates'] as $key) {
            if (isset($data[$key]) && !is_bool($data[$key])) {
                $errors[] = "$key: true or false";
            }
        }

        if ([] !== $errors) {
            throw new \InvalidArgumentException("invalid preferences center declaration:\n  - ".implode("\n  - ", $errors));
        }

        return [
            'topics'          => $topics,
            'hideOtherTopics' => true === ($data['hideOtherTopics'] ?? false),
            'page'            => $page,
            'frequency'       => true === ($data['frequency'] ?? false),
            'pauseDates'      => true === ($data['pauseDates'] ?? false),
            'systemTheme'     => $systemTheme,
        ];
    }

    /**
     * @param array{topics: list<array{name: string, text: string|null, visible: bool}>, hideOtherTopics: bool, page: array{title: string, alias: string, theme: string, language: string, updateContent: bool}|null, frequency: bool, pauseDates: bool, systemTheme: string} $declared
     *
     * @return list<string> what was done, one line each
     */
    public function apply(array $declared, bool $dryRun = false): array
    {
        $log      = [];
        $declaredIds = [];
        foreach ($declared['topics'] as $position => $topic) {
            [$id, $log[]]  = $this->syncTopic($topic, $position + 1, $dryRun);
            $declaredIds[] = $id;
        }
        if ($declared['hideOtherTopics']) {
            $log = array_merge($log, $this->hideOthers(array_filter($declaredIds), $dryRun));
        }
        $pageId = null;
        if (null !== $declared['page']) {
            [$pageId, $log[]] = $this->syncPage($declared['page'], $dryRun);
        }
        $log[] = $this->syncSettings($declared, $pageId, $dryRun);

        return $log;
    }

    /**
     * @param array{name: string, text: string|null, visible: bool} $topic
     *
     * @return array{0: int|null, 1: string}
     */
    private function syncTopic(array $topic, int $position, bool $dryRun): array
    {
        $wanted   = ['visible' => $topic['visible'], 'position' => $position, 'text' => $topic['text']];
        $category = $this->categories->getRepository()->findOneBy(['title' => $topic['name'], 'bundle' => 'global']);
        if (!$category instanceof Category) {
            if ($dryRun) {
                return [null, sprintf('topic "%s": created', $topic['name'])];
            }
            $category = new Category();
            $category->setTitle($topic['name']);
            $category->setBundle('global');
            $category->setIsPublished(true);
            $this->categories->saveEntity($category);
            $this->topics->save((int) $category->getId(), $wanted);

            return [(int) $category->getId(), sprintf('topic "%s": created (id %d)', $topic['name'], $category->getId())];
        }

        $id      = (int) $category->getId();
        $changed = [];
        if (!$category->getIsPublished()) {
            $changed[] = 'published';
            if (!$dryRun) {
                $category->setIsPublished(true);
                $this->categories->saveEntity($category);
            }
        }
        foreach ($wanted as $key => $value) {
            if ($this->topics->get($id)[$key] !== $value) {
                $changed[] = $key;
            }
        }
        if ([] === $changed) {
            return [$id, sprintf('topic "%s": unchanged (id %d)', $topic['name'], $id)];
        }
        if (!$dryRun) {
            $this->topics->save($id, $wanted);
        }

        return [$id, sprintf('topic "%s": updated – %s (id %d)', $topic['name'], implode(', ', $changed), $id)];
    }

    /**
     * @param list<int> $keep
     *
     * @return list<string>
     */
    private function hideOthers(array $keep, bool $dryRun): array
    {
        $log = [];
        foreach ($this->categories->getLookupResults('email', '', 0) as $category) {
            $id = (int) $category['id'];
            if (in_array($id, $keep, true) || !$this->topics->get($id)['visible']) {
                continue;
            }
            if (!$dryRun) {
                $this->topics->save($id, ['visible' => false]);
            }
            $log[] = sprintf('category "%s": hidden on the page (id %d)', $category['title'], $id);
        }

        return $log;
    }

    /**
     * @param array{title: string, alias: string, theme: string, language: string, updateContent: bool} $declared
     *
     * @return array{0: int|null, 1: string}
     */
    private function syncPage(array $declared, bool $dryRun): array
    {
        $page  = $this->pages->getRepository()->findOneBy(['alias' => $declared['alias']]);
        $isNew = !$page instanceof Page;
        $page ??= new Page();

        $changed = [];
        if ($isNew) {
            $page->setTitle($declared['title']);
            $page->setAlias($declared['alias']);
        }
        if ('' !== $declared['language'] && $page->getLanguage() !== $declared['language']) {
            $page->setLanguage($declared['language']);
            $changed[] = 'language';
        }
        $themeChanged = !$isNew && $page->getTemplate() !== $declared['theme'];
        if ($isNew || $themeChanged) {
            $page->setTemplate($declared['theme']);
            $changed[] = 'theme';
        }
        if ($isNew || $themeChanged || $declared['updateContent']) {
            if (!$dryRun) {
                $page->setCustomHtml($this->render($page, $declared['theme']));
            }
            $changed[] = 'content';
        }
        if (!$page->getIsPreferenceCenter()) {
            $page->setIsPreferenceCenter(true);
            $changed[] = 'preference center';
        }
        if (!$page->isPublished()) {
            $page->setIsPublished(true);
            $changed[] = 'published';
        }

        if ([] === $changed) {
            return [(int) $page->getId(), sprintf('page "%s": unchanged (id %d)', $declared['alias'], $page->getId())];
        }
        if (!$dryRun) {
            $this->pages->saveEntity($page);
        }
        $id = $page->getId() ? (int) $page->getId() : null;

        return [$id, sprintf('page "%s": %s%s', $declared['alias'], $isNew ? 'created' : 'updated – '.implode(', ', $changed), $id ? " (id $id)" : '')];
    }

    /** The theme's page template, rendered the way the builder renders it for a new page. */
    private function render(Page $page, string $theme): string
    {
        $html = $this->themes->renderThemeTemplate(
            $this->themes->checkForTwigTemplate('@themes/'.$theme.'/html/page.html.twig'),
            ['isNew' => true, 'content' => [], 'page' => $page, 'template' => $theme, 'basePath' => '']
        );

        // Editor-only stylesheets (the builder's own CSS) do not belong in the page.
        return (string) preg_replace('#<link\b[^>]*href="[^"]*GrapesJsBuilderBundle[^"]*"[^>]*>\s*#i', '', $html);
    }

    private function missingTokens(string $theme): ?string
    {
        try {
            $html = $this->render(new Page(), $theme);
        } catch (\Throwable $e) {
            return 'page template that renders ('.$e->getMessage().')';
        }
        foreach (self::TOKENS as $token) {
            if (!str_contains($html, $token)) {
                return $token;
            }
        }

        return null;
    }

    /** @param array{frequency: bool, pauseDates: bool, systemTheme: string} $declared */
    private function syncSettings(array $declared, ?int $pageId, bool $dryRun): string
    {
        $wanted = [
            'show_contact_preferences'        => true,
            'show_contact_categories'         => true,
            'show_contact_segments'           => false,
            'show_contact_preferred_channels' => false,
            'show_contact_frequency'          => $declared['frequency'],
            'show_contact_pause_dates'        => $declared['pauseDates'],
        ];
        if (null !== $pageId) {
            $wanted['email_default_preference_center_id'] = $pageId;
        }
        if ('' !== $declared['systemTheme']) {
            $wanted['theme'] = $declared['systemTheme'];
        }

        $changed = [];
        foreach ($wanted as $key => $value) {
            $current = $this->parameters->get($key);
            if ('email_default_preference_center_id' === $key ? (int) $current !== $value : $current !== $value) {
                $changed[$key] = $value;
            }
        }
        if ([] === $changed) {
            return 'settings: unchanged';
        }
        if (!$dryRun) {
            if (!$this->configurator->isFileWritable()) {
                throw new \RuntimeException('config/local.php is not writable');
            }
            $this->configurator->mergeParameters($changed);
            $this->configurator->write();
        }

        return 'settings: '.implode(', ', array_keys($changed));
    }
}
