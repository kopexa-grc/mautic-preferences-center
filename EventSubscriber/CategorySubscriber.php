<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPreferencesCenterBundle\EventSubscriber;

use Mautic\CategoryBundle\CategoryEvents;
use Mautic\CategoryBundle\Event\CategoryEvent;
use MauticPlugin\KopexaPreferencesCenterBundle\Service\PendingTopicSettings;
use MauticPlugin\KopexaPreferencesCenterBundle\Service\TopicStore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Stores the topic settings of the category form once the category is saved. */
final class CategorySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PendingTopicSettings $pending,
        private readonly TopicStore $topics,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [CategoryEvents::CATEGORY_POST_SAVE => 'onCategoryPostSave'];
    }

    public function onCategoryPostSave(CategoryEvent $event): void
    {
        $category = $event->getCategory();
        $settings = $this->pending->take($category);
        if (null !== $settings && $category->getId()) {
            $this->topics->save((int) $category->getId(), $settings);
        }
    }
}
