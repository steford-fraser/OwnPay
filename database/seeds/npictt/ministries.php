<?php
declare(strict_types=1);

/**
 * NPICTT ministry brands seeded by `php bin/npictt-seed`.
 *
 * Each entry becomes (or updates) one OwnPay brand, matched by `slug`.
 * Emails must be unique across all brands. Contact details below are
 * placeholders until NPICTT confirms each ministry's onboarding details.
 *
 * Optional keys per ministry (defaults in brackets):
 *   phone [null], description [null], primary_color ['#0003B1'], accent_color ['#E4002B'],
 *   logo ['/assets/themes/npictt/logo.png'], favicon ['/assets/themes/npictt/favicon-32.png'],
 *   footer_text ['{name} - payments facilitated by NPICTT'], status ['active']
 */
return [
    'ministries' => [
        [
            'code'        => 'MOWI',
            'slug'        => 'mowi',
            'name'        => 'Ministry of Works and Infrastructure',
            'email'       => 'payments@mowi.gov.tt',
            'description' => 'Ministry of Works and Infrastructure, Trinidad and Tobago',
            // Brand assets from https://mowt.gov.tt (official MoWI wordmark).
            'primary_color' => '#272262',
            'accent_color'  => '#1F3F99',
            'logo'          => '/assets/themes/npictt/ministries/mowi/logo.png',
            'favicon'       => '/assets/themes/npictt/ministries/mowi/favicon-32.png',
            'footer_text'   => 'Ministry of Works and Infrastructure · Payments facilitated by NPICTT',
        ],
        [
            'code'        => 'MOTCA',
            'slug'        => 'motca',
            'name'        => 'Ministry of Transport and Civil Aviation',
            'email'       => 'payments@motca.gov.tt',
            'description' => 'Ministry of Transport and Civil Aviation, Trinidad and Tobago',
            // Brand assets from https://mtca.gov.tt (coat of arms + ministry name).
            'primary_color' => '#CE1126',
            'accent_color'  => '#1F1F1F',
            'logo'          => '/assets/themes/npictt/ministries/motca/logo.png',
            'favicon'       => '/assets/themes/npictt/ministries/motca/favicon-32.png',
            'footer_text'   => 'Ministry of Transport and Civil Aviation · Payments facilitated by NPICTT',
        ],
        [
            'code'        => 'MOE',
            'slug'        => 'moe',
            'name'        => 'Ministry of Education',
            'email'       => 'payments@moe.gov.tt',
            'description' => 'Ministry of Education, Trinidad and Tobago',
            // Brand assets from https://www.moe.gov.tt (coat-of-arms strip, site blues).
            'primary_color' => '#1E73BE',
            'accent_color'  => '#49ADD7',
            'logo'          => '/assets/themes/npictt/ministries/moe/logo.png',
            'favicon'       => '/assets/themes/npictt/ministries/moe/favicon-32.png',
            'footer_text'   => 'Ministry of Education · Payments facilitated by NPICTT',
        ],
    ],
];
