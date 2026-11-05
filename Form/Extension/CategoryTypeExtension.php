<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPreferencesCenterBundle\Form\Extension;

use Mautic\CategoryBundle\Entity\Category;
use Mautic\CategoryBundle\Form\Type\CategoryType;
use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use MauticPlugin\KopexaPreferencesCenterBundle\Service\PendingTopicSettings;
use MauticPlugin\KopexaPreferencesCenterBundle\Service\TopicStore;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Range;

/**
 * "Preference center" in the category form (Components → Categories, and
 * the categories API as field "preferencesCenter"): show the category as a
 * topic, its position, the text under its name. Stored by
 * CategorySubscriber once Mautic has saved the category; only when the
 * field was submitted, so API calls without it change nothing.
 *
 * @extends AbstractTypeExtension<Category>
 */
final class CategoryTypeExtension extends AbstractTypeExtension
{
    public const FIELD = 'preferencesCenter';

    public function __construct(
        private readonly TopicStore $topics,
        private readonly PendingTopicSettings $pending,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [CategoryType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $section = $builder->create(self::FIELD, FormType::class, [
            'mapped'   => false,
            'label'    => 'kopexa.preferences_center.category.section',
            'required' => false,
            'attr'     => ['class' => 'preferences-center-settings'],
        ]);
        $section->add('visible', YesNoButtonGroupType::class, [
            'label' => 'kopexa.preferences_center.category.visible',
            'attr'  => ['tooltip' => 'kopexa.preferences_center.category.visible.help'],
        ]);
        $section->add('position', IntegerType::class, [
            'label'       => 'kopexa.preferences_center.category.position',
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => ['class' => 'form-control', 'tooltip' => 'kopexa.preferences_center.category.position.help'],
            'required'    => false,
            'constraints' => [new Range(min: -100000, max: 100000)],
        ]);
        $section->add('text', TextareaType::class, [
            'label'       => 'kopexa.preferences_center.category.text',
            'label_attr'  => ['class' => 'control-label'],
            'attr'        => ['class' => 'form-control', 'rows' => 2, 'tooltip' => 'kopexa.preferences_center.category.text.help'],
            'required'    => false,
            'constraints' => [new Length(max: 1000)],
        ]);
        $builder->add($section);

        // Keep Mautic's save/cancel buttons last.
        if ($builder->has('buttons')) {
            $buttons = $builder->get('buttons');
            $builder->remove('buttons');
            $builder->add($buttons);
        }

        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event): void {
            $category = $event->getData();
            $settings = $category instanceof Category && $category->getId() ? $this->topics->get((int) $category->getId()) : TopicStore::defaults();
            $event->getForm()->get(self::FIELD)->setData($settings);
        });

        $submitted = false;
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use (&$submitted): void {
            $data      = $event->getData();
            $submitted = is_array($data) && array_key_exists(self::FIELD, $data);
            // JSON booleans from the API: Symfony takes false for "not submitted".
            if ($submitted && is_array($data[self::FIELD]) && is_bool($data[self::FIELD]['visible'] ?? null)) {
                $data[self::FIELD]['visible'] = $data[self::FIELD]['visible'] ? '1' : '0';
                $event->setData($data);
            }
        });
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use (&$submitted): void {
            $category = $event->getData();
            if (!$submitted || !$category instanceof Category || !$event->getForm()->isValid()) {
                return;
            }
            $data = (array) $event->getForm()->get(self::FIELD)->getData();
            $this->pending->remember($category, [
                'visible'  => (bool) ($data['visible'] ?? true),
                'position' => isset($data['position']) && '' !== $data['position'] ? (int) $data['position'] : null,
                'text'     => isset($data['text']) ? (string) $data['text'] : null,
            ]);
        });
    }
}
