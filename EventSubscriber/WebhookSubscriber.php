<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPreferencesCenterBundle\EventSubscriber;

use Mautic\LeadBundle\Event\CategoryChangeEvent;
use Mautic\LeadBundle\Event\ChannelSubscriptionChange;
use Mautic\LeadBundle\LeadEvents;
use Mautic\WebhookBundle\Event\WebhookBuilderEvent;
use Mautic\WebhookBundle\Model\WebhookModel;
use Mautic\WebhookBundle\WebhookEvents;
use MauticPlugin\KopexaPreferencesCenterBundle\Service\PreferenceChanges;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Webhook event "Contact email preferences changed" for Mautic's webhooks
 * (Settings → Webhooks: URL, secret, events). One message per contact and
 * request with what changed (topics, all emails) and the current state,
 * sent after the response so the preference page stays fast.
 */
final class WebhookSubscriber implements EventSubscriberInterface
{
    public const EVENT = 'kopexa.preferences_center.preferences_changed';

    public function __construct(
        private readonly PreferenceChanges $changes,
        private readonly WebhookModel $webhooks,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WebhookEvents::WEBHOOK_ON_BUILD          => 'onWebhookBuild',
            // Before Mautic writes the category change: the state before.
            LeadEvents::LEAD_CATEGORY_CHANGE         => ['onCategoryChange', 100],
            LeadEvents::CHANNEL_SUBSCRIPTION_CHANGED => 'onChannelSubscriptionChange',
            KernelEvents::TERMINATE                  => 'send',
            ConsoleEvents::TERMINATE                 => 'send',
        ];
    }

    public function onWebhookBuild(WebhookBuilderEvent $event): void
    {
        $event->addEvent(self::EVENT, [
            'label'       => 'kopexa.preferences_center.webhook.preferences_changed',
            'description' => 'kopexa.preferences_center.webhook.preferences_changed.desc',
        ]);
    }

    public function onCategoryChange(CategoryChangeEvent $event): void
    {
        foreach (array_filter([$event->getLead(), ...($event->getLeads() ?? [])]) as $lead) {
            $this->changes->topicTouched($lead);
        }
    }

    public function onChannelSubscriptionChange(ChannelSubscriptionChange $event): void
    {
        if ('email' === $event->getChannel()) {
            $this->changes->emailChanged($event->getLead(), $event->getOldStatus(), $event->getNewStatus(), $event->getNewStatusVerb());
        }
    }

    public function send(): void
    {
        if ($this->changes->isEmpty()) {
            return;
        }
        try {
            foreach ($this->changes->flush() as $payload) {
                $this->webhooks->queueWebhooksByType(self::EVENT, $payload);
            }
        } catch (\Throwable $e) {
            // The change itself is saved; a failing webhook must not break the page.
            $this->logger->error('preferences center: webhook not queued: '.$e->getMessage(), ['exception' => $e]);
        }
    }
}
