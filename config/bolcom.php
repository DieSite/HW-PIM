<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bol.com API Configuration
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for the Bol.com API.
    |
    */

    'client_id'     => env('BOLCOM_CLIENT_ID'),
    'client_secret' => env('BOLCOM_CLIENT_SECRET'),
    'api_url'       => env('BOLCOM_API_URL', 'https://api.bol.com'),

    'email_recipients' => env('BOLCOM_EMAIL_RECIPIENTS', [])
];
