<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPreferencesCenterBundle\Form\Extension;

use Mautic\CategoryBundle\Model\CategoryModel;
use Mautic\LeadBundle\Form\Type\ContactFrequencyType;
use Mautic\LeadBundle\Form\Type\LeadCategoryType;
use MauticPlugin\KopexaPreferencesCenterBundle\Service\TopicStore;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * The topics (global_categories) of the public preference form:
 *
 * - only categories marked as shown, in their order, each with its text;
 * - hidden categories keep the contact's state when the form is saved.
 *   Mautic treats every category missing from the submission as switched
 *   off, so without this saving the page would unsubscribe contacts from
 *   every topic they never saw.
 *
 * The contact form in Mautic's admin (not public) is left as it is.
 *
 * @extends AbstractTypeExtension<mixed>
 */
final class ContactFrequencyTypeExtension extends AbstractTypeExtension
{
    public function __construct(
        private readonly CategoryModel $categories,
        private readonly TopicStore $topics,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [ContactFrequencyType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (empty($options['public_view']) || !$builder->has('global_categories')) {
            return;
        }

        $titles = [];
        foreach ($this->categories->getLookupResults('email', '', 0) as $category) {
            $titles[(int) $category['id']] = (string) $category['title'];
        }
        $shown  = $this->topics->order($titles);
        $hidden = array_values(array_diff(array_keys($titles), $shown));

        // Mautic's template (@MauticCore/Slots/categorylist.html.twig) prints the
        // choices last to first, so they are handed over in reverse.
        $choices = [];
        foreach (array_reverse($shown) as $id) {
            $choices[$titles[$id]] = $id;
        }
        $field = $builder->get('global_categories');
        $builder->add('global_categories', LeadCategoryType::class, array_replace($field->getOptions(), [
            'choices'      => $choices,
            'label_html'   => true,
            'choice_label' => fn (int $id): string => $this->label($titles[$id], $this->topics->get($id)['text']),
            'choice_attr'  => fn (int $id): array => ['data-topic-id' => $id],
        ]));

        $initial = [];
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use (&$initial): void {
            $data    = $event->getData();
            $initial = array_map(intval(...), (array) ($data['global_categories'] ?? []));
        });
        // SUBMIT: the submitted topics plus the hidden ones the contact had.
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) use (&$initial, $hidden): void {
            $data = $event->getData();
            if (!is_array($data)) {
                return;
            }
            $kept = array_values(array_intersect($hidden, $initial));
            if ([] !== $kept) {
                $data['global_categories'] = array_values(array_unique(array_merge(array_map(intval(...), (array) ($data['global_categories'] ?? [])), $kept)));
                $event->setData($data);
            }
        });
    }

    private function label(string $title, ?string $text): string
    {
        $html = '<span class="pref-topic-name">'.htmlspecialchars($title, ENT_QUOTES).'</span>';
        if (null !== $text) {
            $html .= ' <span class="pref-topic-text">'.nl2br(htmlspecialchars($text, ENT_QUOTES)).'</span>';
        }

        return $html;
    }
}
