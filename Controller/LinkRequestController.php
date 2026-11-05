<?php

declare(strict_types=1);

namespace MauticPlugin\KopexaPreferencesCenterBundle\Controller;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\ThemeHelperInterface;
use MauticPlugin\KopexaPreferencesCenterBundle\Service\LinkRequests;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * GET/POST /email/preferences: "Send me a link to my email preferences".
 * Rendered in the message layout (html/message.html.twig) of Mautic's
 * system theme, like Mautic's own unsubscribe pages.
 */
final class LinkRequestController
{
    private const CSRF_ID = 'preferences_center_link_request';

    public function __construct(
        private readonly LinkRequests $requests,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly ThemeHelperInterface $themes,
        private readonly CoreParametersHelper $parameters,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    public function requestAction(Request $request): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, (string) $request->request->get('_token')))) {
                return $this->page($this->form($this->requests->trans('expired'), (string) $request->request->get('email')), Response::HTTP_BAD_REQUEST);
            }
            // Bots fill every field; people do not see this one.
            if ('' !== trim((string) $request->request->get('website', ''))) {
                return $this->page($this->sent());
            }
            $result = $this->requests->request((string) $request->request->get('email', ''), (string) $request->getClientIp());
            if (LinkRequests::SENT === $result) {
                return $this->page($this->sent());
            }
            $error = $this->requests->trans(LinkRequests::RATE_LIMITED === $result ? 'rate_limited' : 'invalid');

            return $this->page($this->form($error, (string) $request->request->get('email')), LinkRequests::RATE_LIMITED === $result ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->page($this->form(null, ''));
    }

    private function form(?string $error, string $value): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES);

        return '<form class="pc-request" method="post" action="'.$e($this->router->generate('preferences_center_link_request')).'" novalidate>'
            .'<p class="pc-request-intro">'.$e($this->requests->trans('intro')).'</p>'
            .(null !== $error ? '<p class="pc-request-error" role="alert">'.$e($error).'</p>' : '')
            .'<label class="pc-request-label" for="pc-request-email">'.$e($this->requests->trans('label')).'</label>'
            .'<input class="pc-request-input" type="email" id="pc-request-email" name="email" value="'.$e($value).'" required autocomplete="email" inputmode="email" maxlength="191">'
            .'<input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden">'
            .'<input type="hidden" name="_token" value="'.$e($this->csrf->getToken(self::CSRF_ID)->getValue()).'">'
            .'<button class="pc-request-button" type="submit">'.$e($this->requests->trans('button')).'</button>'
            .'</form>';
    }

    private function sent(): string
    {
        return '<div class="pc-request pc-request-sent" role="status"><p><strong>'.htmlspecialchars($this->requests->trans('sent.title'), ENT_QUOTES).'</strong></p>'
            .'<p>'.htmlspecialchars($this->requests->trans('sent.text'), ENT_QUOTES).'</p></div>';
    }

    private function page(string $message, int $status = Response::HTTP_OK): Response
    {
        $theme    = (string) $this->parameters->get('theme', 'blank');
        $template = $this->themes->checkForTwigTemplate('@themes/'.$theme.'/html/message.html.twig');
        $response = new Response($this->themes->renderThemeTemplate($template, ['message' => $message, 'type' => 'notice', 'template' => $theme]), $status);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
