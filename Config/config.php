<?php

declare(strict_types=1);

use MauticPlugin\KopexaPreferencesCenterBundle\Controller\LinkRequestController;

return [
    'name'        => 'Preferences Center',
    'description' => 'Topics in the preference center: which categories appear, in which order, with which text. People request a link to their preferences by email. Webhook event when contacts change their preferences.',
    'version'     => '0.2.1', // x-release-please-version
    'author'      => 'Kopexa',
    'routes'      => [
        'public' => [
            // Request a personal link to the preferences (no email at hand).
            'preferences_center_link_request' => [
                'path'       => '/email/preferences',
                'controller' => LinkRequestController::class.'::requestAction',
                'method'     => 'GET|POST',
            ],
        ],
    ],
    'parameters' => [
        // Language of the link request page and the built-in link email
        // (en_US, de); empty: Mautic's locale.
        'preferences_center_locale'       => '',
        // A Mautic email sent as the link email instead of the built-in one;
        // it must contain {unsubscribe_url}. 0: built-in email.
        'preferences_center_link_email'   => 0,
        // Button colour of the built-in link email.
        'preferences_center_button_color' => '#1F2937',
        // Requests per email address and per IP address within an hour.
        'preferences_center_rate_email'   => 3,
        'preferences_center_rate_ip'      => 10,
        // Send visitors of the default preference page who come without a
        // personal link to the link request.
        'preferences_center_page_redirect' => true,
    ],
];
