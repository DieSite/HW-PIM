<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Stripe, Mailgun, SparkPost and others. This file provides a sane
    | default location for this type of information, allowing packages
    | to have a conventional place to find your various credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
    ],

    'ses' => [
        'key'    => env('SES_KEY'),
        'secret' => env('SES_SECRET'),
        'region' => env('SES_REGION', 'us-east-1'),
    ],

    'sparkpost' => [
        'secret' => env('SPARKPOST_SECRET'),
    ],

    /*
     * Dropbox app the MCP server fetches product photos with. Needs the scopes
     * account_info.read, files.metadata.read, files.content.read and
     * sharing.read. The account is connected under Tools → Dropbox; its tokens
     * live in Redis (App\Services\DropboxTokenStore), not here.
     */
    'dropbox' => [
        'app_key'          => env('DROPBOX_APP_KEY'),
        'app_secret'       => env('DROPBOX_APP_SECRET'),
        'redis_connection' => env('DROPBOX_REDIS_CONNECTION', 'default'),
        'redis_key_prefix' => 'dropbox:',
    ],
];
