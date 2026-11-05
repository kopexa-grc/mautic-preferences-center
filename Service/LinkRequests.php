<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPreferencesCenterBundle\Service;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Send me a link to my preferences": someone enters an email address; if a
 * contact has it, a personal link to the preference page goes to that
 * address. Only who reads the mailbox gets in.
 *
 * - The answer is the same whether the address is known or not, so nobody
 *   can find out who is on the list.
 * - At most preferences_center_rate_email requests per address and
 *   preferences_center_rate_ip per IP address within an hour, counted
 *   whether the address is known or not (stored as hashes).
 * - The link goes out as a Mautic email (see linkEmail()), so it is
 *   {unsubscribe_url}: a personal link to the preference page, tracked like
 *   every email.
 * - Sent like a requested service email: also to contacts who unsubscribed
 *   from all emails (they may want to come back).
 */
class LinkRequests
{
    public const SENT         = 'sent';
    public const RATE_LIMITED = 'rate_limited';
    public const INVALID      = 'invalid';

    public const DEFAULT_EMAIL_NAME = 'Preferences center: link';

    private bool $schemaChecked = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly CoreParametersHelper $parameters,
        private readonly LeadRepository $contacts,
        private readonly EmailModel $emails,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return self::SENT|self::RATE_LIMITED|self::INVALID SENT also when no contact has the address */
    public function request(string $address, string $ip): string
    {
        $address = mb_strtolower(trim($address));
        if (false === filter_var($address, FILTER_VALIDATE_EMAIL) || mb_strlen($address) > 191) {
            return self::INVALID;
        }
        if (!$this->allow('email', $address, (int) $this->parameters->get('preferences_center_rate_email', 3))
            || !$this->allow('ip', $ip, (int) $this->parameters->get('preferences_center_rate_ip', 10))) {
            return self::RATE_LIMITED;
        }

        $contact = $this->contact($address);
        if (null === $contact) {
            return self::SENT;
        }
        try {
            $this->send($this->linkEmail(), $contact);
        } catch (\Throwable $e) {
            // Same answer for the visitor; the reason is in Mautic's log.
            $this->logger->error('preferences center: link email not sent: '.$e->getMessage(), ['exception' => $e]);
        }

        return self::SENT;
    }

    public function locale(): string
    {
        $locale = trim((string) $this->parameters->get('preferences_center_locale', ''));

        return '' !== $locale ? $locale : (string) $this->parameters->get('locale', 'en_US');
    }

    public function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans('kopexa.preferences_center.request.'.$key, $parameters, 'messages', $this->locale());
    }

    /**
     * The email with the link: the one set in preferences_center_link_email,
     * else the plugin's own ("Preferences center: link"), created on first
     * use and then editable in Mautic like any email. Its {unsubscribe_url}
     * opens the preference page for the contact it was sent to.
     */
    public function linkEmail(): Email
    {
        $id = (int) $this->parameters->get('preferences_center_link_email', 0);
        if ($id > 0) {
            $email = $this->emails->getEntity($id);
            if ($email instanceof Email && $email->isPublished()) {
                return $email;
            }
            $this->logger->warning("preferences center: link email $id not found or not published, using the default one");
        }
        $email = $this->emails->getRepository()->findOneBy(['name' => self::DEFAULT_EMAIL_NAME]);
        if ($email instanceof Email) {
            return $email;
        }

        $email = new Email();
        $email->setName(self::DEFAULT_EMAIL_NAME);
        $email->setEmailType('template');
        $email->setSubject($this->trans('email.subject'));
        $email->setLanguage($this->locale());
        $email->setCustomHtml($this->defaultHtml());
        $email->setIsPublished(true);
        $this->emails->saveEntity($email);

        return $email;
    }

    private function contact(string $address): ?Lead
    {
        foreach ($this->contacts->getContactsByEmail($address) as $contact) {
            if ($contact instanceof Lead && 0 === strcasecmp((string) $contact->getEmail(), $address)) {
                return $contact;
            }
        }

        return null;
    }

    private function send(Email $email, Lead $contact): void
    {
        $result = $this->emails->sendEmail($email, $contact->getProfileFields() + ['id' => $contact->getId()], [
            'source'        => ['preferences_center', 0],
            'ignoreDNC'     => true,
            'allowResends'  => true,
            'return_errors' => true,
        ]);
        if (true !== $result && !empty($result)) {
            throw new \RuntimeException('Mautic did not send email '.$email->getId().': '.json_encode($result));
        }
    }

    private function defaultHtml(): string
    {
        $color = (string) $this->parameters->get('preferences_center_button_color', '#1F2937');
        if (1 !== preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = '#1F2937';
        }
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);

        return '<!DOCTYPE html><html lang="'.$e(substr($this->locale(), 0, 2)).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$e($this->trans('email.subject')).'</title></head>'
            .'<body style="margin:0;padding:0;background:#FFFFFF;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FFFFFF;"><tr><td align="center" style="padding:32px 16px;">'
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;font-family:Helvetica,Arial,sans-serif;color:#111827;font-size:16px;line-height:1.6;">'
            .'<tr><td style="font-size:22px;font-weight:bold;padding:0 0 12px;">'.$e($this->trans('email.title')).'</td></tr>'
            .'<tr><td style="padding:0 0 24px;">'.$e($this->trans('email.text')).'</td></tr>'
            .'<tr><td style="padding:0 0 24px;"><a href="{unsubscribe_url}" style="display:inline-block;background:'.$color.';color:#FFFFFF;text-decoration:none;font-weight:bold;padding:13px 22px;border-radius:8px;">'.$e($this->trans('email.button')).'</a></td></tr>'
            .'<tr><td style="padding:0 0 8px;font-size:14px;color:#4B5563;">'.$e($this->trans('email.ignore')).'</td></tr>'
            .'</table></td></tr></table></body></html>';
    }

    /** Counts this request and tells whether it is within the limit. */
    private function allow(string $kind, string $value, int $limit): bool
    {
        if ($limit <= 0) {
            return true;
        }
        $this->ensureSchema();
        $key = hash_hmac('sha256', $kind.':'.$value, (string) $this->parameters->get('secret_key', ''));
        $now = time();
        $this->connection->executeStatement('DELETE FROM '.$this->table().' WHERE requested_at < ?', [$now - 3600]);
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$this->table().' WHERE request_key = ?', [$key]);
        $this->connection->insert($this->table(), ['request_key' => $key, 'requested_at' => $now]);

        return $count < $limit;
    }

    private function table(): string
    {
        return (defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '').'preferences_center_link_requests';
    }

    private function ensureSchema(): void
    {
        if ($this->schemaChecked) {
            return;
        }
        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS '.$this->table().' (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_key CHAR(64) NOT NULL,
            requested_at INT UNSIGNED NOT NULL,
            KEY request_key (request_key),
            KEY requested_at (requested_at)
        ) DEFAULT CHARACTER SET ascii');
        $this->schemaChecked = true;
    }
}
