<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'ml_service' => [
        'url' => env('URL_MACHINE_LEARNING'),
    ],

    'antares' => [
        'url' => env('ANTARES_DEVICE'),
        'access_key' => env('ANTARES_ACCESS_KEY'),
    ],

    'device' => [
        'air_nyala' => env('RELAY_AIR_NYALA'),
        'air_mati' => env('RELAY_AIR_MATI'),
        'pupuk_nyala' => env('RELAY_PUPUK_NYALA'),
        'pupuk_mati' => env('RELAY_PUPUK_MATI'),
    ]
];
