<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPreferencesCenterBundle\EventSubscriber;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Helper\MailHashHelper;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use MauticPlugin\KopexaPreferencesCenterBundle\Service\LinkRequests;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Unsubscribe links of emails sent without statistics (Mautic's "Send
 * example", test emails) are signed with the address instead of a sent
 * email (/email/unsubscribe/<x>/<address>/<HMAC>). For those Mautic knows
 * no email and shows its plain built-in form instead of the preference
 * page. Such a link, when the signature is valid and a contact has the
 * address, is turned into a regular personal link: a statistics row of
 * the link email for that contact, then a redirect to it. The page looks
 * the same as from any real email.
 *
 * Only GET; one-click unsubscribe (POST) and invalid signatures go to
 * Mautic unchanged.
 */
final class SignedLinkSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CoreParametersHelper $parameters,
        private readonly EmailModel $emails,
        private readonly MailHashHelper $hashes,
        private readonly LeadRepository $contacts,
        private readonly LinkRequests $links,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the router (priority 32) has matched the route.
        return [KernelEvents::REQUEST => ['onRequest', 16]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || 'mautic_email_unsubscribe' !== $request->attributes->get('_route') || !$request->isMethod('GET')) {
            return;
        }
        $address = (string) $request->attributes->get('urlEmail', '');
        $secret  = (string) $request->attributes->get('secretHash', '');
        if ('' === $address || '' === $secret || (int) $this->parameters->get('email_default_preference_center_id') <= 0
            || !hash_equals($this->hashes->getEmailHash($address), $secret)
            || null !== $this->emails->getEmailStatus((string) $request->attributes->get('idHash'))) {
            return;
        }
        $contact = null;
        foreach ($this->contacts->getContactsByEmail($address) as $candidate) {
            if ($candidate instanceof Lead && 0 === strcasecmp((string) $candidate->getEmail(), $address)) {
                $contact = $candidate;
                break;
            }
        }
        if (null === $contact) {
            return;
        }

        $stat = new Stat();
        $stat->setEmail($this->links->linkEmail());
        $stat->setLead($contact);
        $stat->setEmailAddress((string) $contact->getEmail());
        $stat->setTrackingHash(str_replace('.', '', uniqid('', true)));
        $stat->setDateSent(new \DateTime());
        $stat->setSource('preferences_center');
        $this->emails->getStatRepository()->saveEntity($stat);

        $event->setResponse(new RedirectResponse(
            $this->router->generate('mautic_email_unsubscribe', ['idHash' => $stat->getTrackingHash()]),
            302
        ));
    }
}
