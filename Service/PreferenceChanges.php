<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPreferencesCenterBundle\Service;

use Doctrine\DBAL\Connection;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\Lead;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Collects what changes in a contact's email preferences during one request
 * (or console command) and describes it once at the end:
 *
 * - topics: the contact's state per category before the first change is
 *   compared with the state at the end, so only real changes are reported
 *   (Mautic re-saves unchanged categories and reports some unsubscribes as
 *   additions);
 * - emails: Do Not Contact on the email channel, from Mautic's own
 *   subscription change events (first old status, last new status).
 */
class PreferenceChanges
{
    /** @var array<int, array{lead: Lead, source: string, topicsBefore: array<int, bool>|null, emailBefore: int|null, emailAfter: int|null, emailReason: string|null}> */
    private array $contacts = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requests,
    ) {
    }

    /** Before Mautic writes a category change for the contact. */
    public function topicTouched(Lead $lead): void
    {
        $entry = &$this->entry($lead);
        $entry['topicsBefore'] ??= $this->topicStates((int) $lead->getId());
    }

    public function emailChanged(Lead $lead, int $oldStatus, int $newStatus, string $reason): void
    {
        $entry = &$this->entry($lead);
        $entry['emailBefore'] ??= $oldStatus;
        $entry['emailAfter']  = $newStatus;
        $entry['emailReason'] = $reason;
    }

    public function isEmpty(): bool
    {
        return [] === $this->contacts;
    }

    /**
     * The collected changes as webhook payloads (one per contact with a real
     * change) and forgets them.
     *
     * @return list<array<string, mixed>>
     */
    public function flush(): array
    {
        $contacts       = $this->contacts;
        $this->contacts = [];
        if ([] === $contacts) {
            return [];
        }
        $titles  = $this->topicTitles();
        $payload = [];
        foreach ($contacts as $id => $entry) {
            $after   = $this->topicStates($id);
            $changes = [];
            if (null !== $entry['topicsBefore']) {
                foreach ($titles as $categoryId => $title) {
                    $was = $entry['topicsBefore'][$categoryId] ?? true;
                    $is  = $after[$categoryId] ?? true;
                    if ($was !== $is) {
                        $changes[] = ['id' => $categoryId, 'name' => $title, 'subscribed' => $is];
                    }
                }
            }
            $email = null;
            if (null !== $entry['emailBefore'] && (DoNotContact::IS_CONTACTABLE === $entry['emailBefore']) !== (DoNotContact::IS_CONTACTABLE === $entry['emailAfter'])) {
                $email = ['subscribed' => DoNotContact::IS_CONTACTABLE === $entry['emailAfter'], 'reason' => $entry['emailReason']];
            }
            if ([] === $changes && null === $email) {
                continue;
            }

            $lead      = $entry['lead'];
            $emailNow  = null !== $entry['emailAfter'] ? DoNotContact::IS_CONTACTABLE === $entry['emailAfter'] : $this->emailContactable($id);
            $subscribed = $unsubscribed = [];
            foreach ($titles as $categoryId => $title) {
                if ($after[$categoryId] ?? true) {
                    $subscribed[] = ['id' => $categoryId, 'name' => $title];
                } else {
                    $unsubscribed[] = ['id' => $categoryId, 'name' => $title];
                }
            }
            $payload[] = [
                'contact' => ['id' => $id, 'email' => $lead->getEmail()],
                'source'  => $entry['source'],
                'changes' => array_filter(['topics' => $changes, 'emails' => $email], fn ($v): bool => null !== $v && [] !== $v),
                'current' => [
                    'emails'              => $emailNow,
                    'topics_subscribed'   => $subscribed,
                    'topics_unsubscribed' => $unsubscribed,
                ],
            ];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function &entry(Lead $lead): array
    {
        $id = (int) $lead->getId();
        // The source now: the request is gone when the changes are sent.
        $this->contacts[$id] ??= ['lead' => $lead, 'source' => $this->source(), 'topicsBefore' => null, 'emailBefore' => null, 'emailAfter' => null, 'emailReason' => null];

        return $this->contacts[$id];
    }

    /** @return array<int, bool> subscribed per category the contact has a row for (no row: subscribed) */
    private function topicStates(int $leadId): array
    {
        $states = [];
        foreach ($this->connection->fetchAllAssociative('SELECT category_id, manually_removed FROM '.$this->prefix().'lead_categories WHERE lead_id = ?', [$leadId]) as $row) {
            $states[(int) $row['category_id']] = !(bool) $row['manually_removed'];
        }

        return $states;
    }

    /** @return array<int, string> published topic categories (bundles global and email) by ID */
    private function topicTitles(): array
    {
        $titles = [];
        $rows   = $this->connection->fetchAllAssociative(
            'SELECT id, title FROM '.$this->prefix()."categories WHERE is_published = 1 AND bundle IN ('global', 'email') ORDER BY id"
        );
        foreach ($rows as $row) {
            $titles[(int) $row['id']] = (string) $row['title'];
        }

        return $titles;
    }

    private function emailContactable(int $leadId): bool
    {
        return false === $this->connection->fetchOne(
            'SELECT 1 FROM '.$this->prefix()."lead_donotcontact WHERE lead_id = ? AND channel = 'email' LIMIT 1",
            [$leadId]
        );
    }

    /** Where the change came from, by the route that handled the request. */
    private function source(): string
    {
        $route = (string) $this->requests->getMainRequest()?->attributes->get('_route', '');

        return match (true) {
            'mautic_email_unsubscribe' === $route    => 'preference_center',
            'mautic_email_unsubscribe_all' === $route => 'one_click_unsubscribe',
            'mautic_email_resubscribe' === $route    => 'resubscribe',
            'mautic_page_public' === $route         => 'preference_center',
            str_starts_with($route, 'mautic_api_')  => 'api',
            '' === $route                            => 'system',
            default                                  => 'admin',
        };
    }

    private function prefix(): string
    {
        return defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '';
    }
}
