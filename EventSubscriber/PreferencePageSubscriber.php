<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPreferencesCenterBundle\EventSubscriber;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The default preference page opened at its own address (e.g. /preferences,
 * a link on a website) has no personal link, so Mautic does not know whose
 * preferences to show. Such visits go to the link request instead.
 * Personal links (/email/unsubscribe/…) and the builder preview are not
 * affected. Off with preferences_center_page_redirect: false.
 */
final class PreferencePageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CoreParametersHelper $parameters,
        private readonly Connection $connection,
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
        if (!$event->isMainRequest() || 'mautic_page_public' !== $request->attributes->get('_route') || !$request->isMethod('GET')) {
            return;
        }
        $pageId = (int) $this->parameters->get('email_default_preference_center_id');
        if ($pageId <= 0 || !$this->parameters->get('preferences_center_page_redirect', true)) {
            return;
        }
        $alias = $this->connection->fetchOne(
            'SELECT alias FROM '.(defined('MAUTIC_TABLE_PREFIX') ? (string) MAUTIC_TABLE_PREFIX : '').'pages WHERE id = ? AND is_preference_center = 1',
            [$pageId]
        );
        $slug = trim((string) $request->attributes->get('slug'), '/');
        // The slug may carry a language prefix (de/preferences).
        if (is_string($alias) && '' !== $alias && ($slug === $alias || str_ends_with($slug, '/'.$alias))) {
            $event->setResponse(new RedirectResponse($this->router->generate('preferences_center_link_request'), 302));
        }
    }
}
