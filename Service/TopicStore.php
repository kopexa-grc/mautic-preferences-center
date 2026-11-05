<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPreferencesCenterBundle\Service;

use Doctrine\DBAL\Connection;

/**
 * How each category appears as a topic in the preference center: shown or
 * hidden, its position, and a text for contacts under its name. Categories
 * without settings are shown (Mautic's own behaviour), after the ones with a
 * position, by name.
 *
 * Stored in its own table, created on first use, so the plugin needs no
 * install step and Mautic's categories table is left alone. Rows go away
 * with their category (foreign key, ON DELETE CASCADE).
 */
class TopicStore
{
    private bool $schemaChecked = false;

    /** @var array<int, array{visible: bool, position: int|null, text: string|null}>|null */
    private ?array $cache = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function table(): string
    {
        return $this->prefix().'preferences_center_topics';
    }

    /** @return array{visible: bool, position: int|null, text: string|null} */
    public function get(int $categoryId): array
    {
        return $this->all()[$categoryId] ?? self::defaults();
    }

    /** @return array<int, array{visible: bool, position: int|null, text: string|null}> settings by category ID */
    public function all(): array
    {
        if (null !== $this->cache) {
            return $this->cache;
        }
        $this->ensureSchema();
        $this->cache = [];
        foreach ($this->connection->fetchAllAssociative('SELECT category_id, visible, position, text FROM '.$this->table()) as $row) {
            $this->cache[(int) $row['category_id']] = [
                'visible'  => (bool) $row['visible'],
                'position' => null === $row['position'] ? null : (int) $row['position'],
                'text'     => null === $row['text'] || '' === trim((string) $row['text']) ? null : (string) $row['text'],
            ];
        }

        return $this->cache;
    }

    /** @param array{visible?: bool, position?: int|null, text?: string|null} $settings merged into the current ones */
    public function save(int $categoryId, array $settings): void
    {
        $merged = array_replace($this->get($categoryId), $settings);
        $text   = null === $merged['text'] ? null : trim($merged['text']);
        $values = [
            'visible'  => $merged['visible'] ? 1 : 0,
            'position' => $merged['position'],
            'text'     => '' === $text ? null : $text,
        ];
        $this->ensureSchema();
        $this->connection->executeStatement(
            'INSERT INTO '.$this->table().' (category_id, visible, position, text) VALUES (:id, :visible, :position, :text)
             ON DUPLICATE KEY UPDATE visible = VALUES(visible), position = VALUES(position), text = VALUES(text)',
            ['id' => $categoryId] + $values
        );
        $this->cache = null;
    }

    /**
     * Orders categories for the preference center: positioned ones first (by
     * position), then the others by name. Hidden ones are left out unless
     * $withHidden.
     *
     * @param array<int, string> $titles category titles by ID
     *
     * @return list<int> category IDs
     */
    public function order(array $titles, bool $withHidden = false): array
    {
        $ids = [];
        foreach ($titles as $id => $title) {
            if ($withHidden || $this->get((int) $id)['visible']) {
                $ids[] = (int) $id;
            }
        }
        usort($ids, function (int $a, int $b) use ($titles): int {
            $pa = $this->get($a)['position'];
            $pb = $this->get($b)['position'];
            if ($pa !== $pb) {
                return null === $pa ? 1 : (null === $pb ? -1 : $pa <=> $pb);
            }

            return strnatcasecmp($titles[$a], $titles[$b]) ?: $a <=> $b;
        });

        return $ids;
    }

    /** @return array{visible: bool, position: int|null, text: string|null} */
    public static function defaults(): array
    {
        return ['visible' => true, 'position' => null, 'text' => null];
    }

    private function prefix(): string
    {
        return defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }
        $this->connection->executeStatement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                category_id INT UNSIGNED NOT NULL PRIMARY KEY,
                visible TINYINT(1) NOT NULL DEFAULT 1,
                position INT NULL,
                text TEXT NULL,
                CONSTRAINT %s FOREIGN KEY (category_id) REFERENCES %scategories (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $this->table(),
            $this->prefix().'fk_preferences_center_topics_category',
            $this->prefix()
        ));
        $this->schemaChecked = true;
    }
}
