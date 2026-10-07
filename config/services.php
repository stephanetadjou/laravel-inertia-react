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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'meta_whatsapp' => [
        'token' => env('META_WHATSAPP_TOKEN'),
        'phone_number_id' => env('META_PHONE_NUMBER_ID'),
        'api_version' => env('META_WHATSAPP_API_VERSION', 'v21.0'),
        // cloud : vraie API Meta. log : simulation locale, aucun appel externe
        // (ignoré en production), cf. App\Services\WhatsAppClient.
        'driver' => env('WHATSAPP_DRIVER', 'cloud'),
        // Envoi des campagnes : messages par lot (un job par lot) et pause
        // entre deux messages pour rester sous le débit autorisé par Meta.
        'batch_size' => (int) env('WHATSAPP_BATCH_SIZE', 50),
        'send_delay_ms' => (int) env('WHATSAPP_SEND_DELAY_MS', 100),
        // Coût estimé avant envoi, par message et par catégorie de modèle.
        // Valeurs indicatives (grille Meta « Rest of Africa », en USD) à
        // ajuster selon la grille Meta en vigueur et le pays des destinataires.
        'pricing' => [
            'currency' => env('WHATSAPP_PRICE_CURRENCY', 'USD'),
            'marketing' => (float) env('WHATSAPP_PRICE_MARKETING', 0.0225),
            'utility' => (float) env('WHATSAPP_PRICE_UTILITY', 0.004),
            'authentication' => (float) env('WHATSAPP_PRICE_AUTHENTICATION', 0.004),
        ],
    ],

];
