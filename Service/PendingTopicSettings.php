<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPreferencesCenterBundle\Service;

use Mautic\CategoryBundle\Entity\Category;

/**
 * Topic settings submitted with the category form, held until Mautic has
 * saved the category (a new category gets its ID only then).
 */
class PendingTopicSettings
{
    /** @var array<int, array{visible: bool, position: int|null, text: string|null}> by spl_object_id of the category */
    private array $pending = [];

    /** @param array{visible: bool, position: int|null, text: string|null} $settings */
    public function remember(Category $category, array $settings): void
    {
        $this->pending[spl_object_id($category)] = $settings;
    }

    /** @return array{visible: bool, position: int|null, text: string|null}|null */
    public function take(Category $category): ?array
    {
        $settings = $this->pending[spl_object_id($category)] ?? null;
        unset($this->pending[spl_object_id($category)]);

        return $settings;
    }
}
