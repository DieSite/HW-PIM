<?php

return [

    /*
    |--------------------------------------------------------------------------
    | De Munk dealer portal
    |--------------------------------------------------------------------------
    |
    | The De Munk Carpets dealer portal (portal.demunkcarpets.nl) has no public
    | API. Stock is read by replaying the Carpetconfigurator wizard against its
    | internal ASP.NET JSON endpoints (/Api/ConfiguratorService.aspx/*). See
    | App\Clients\DeMunkPortalClient for the request sequence.
    |
    */

    'base_url' => env('DEMUNK_BASE_URL', 'https://portal.demunkcarpets.nl'),

    'username' => env('DEMUNK_USERNAME'),

    'password' => env('DEMUNK_PASSWORD'),

    // The portal's TLS chain is missing an intermediate; the container's CA
    // bundle rejects it. Disable verification for this host only.
    'verify_ssl' => env('DEMUNK_VERIFY_SSL', false),

    'timeout' => env('DEMUNK_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Matching
    |--------------------------------------------------------------------------
    |
    | De Munk articles carry no EAN, so stock is linked to PIM products by name.
    | Our De Munk products (SKU prefix DMC) store `collectie` (e.g. "Modern"),
    | `productnaam` (e.g. "Diamante 08") and per-variant `maat`. These map onto
    | the De Munk collection code (MODERN), quality (DIAMANTE) and CircaMaat.
    |
    */

    'brand_sku_prefix' => 'DMC',

    // Minimum fuzzy score (0-100) for an auto-suggested link to be recorded.
    'match_suggestion_threshold' => env('DEMUNK_MATCH_THRESHOLD', 55),

    // PIM attribute (values.common.*) the imported stock is written to.
    'stock_attribute' => 'voorraad_5_korting_handmatig',

    /*
    |--------------------------------------------------------------------------
    | External sync (WooCommerce + Bol.com)
    |--------------------------------------------------------------------------
    |
    | When true, changed stock is pushed to the live webshop and Bol.com. It
    | defaults to on in production only, so running the import locally (where
    | the DB is usually a production copy pointing at the live shop) updates
    | PIM stock without touching the live shop. Override with DEMUNK_SYNC_EXTERNAL.
    |
    */

    'sync_external' => env('DEMUNK_SYNC_EXTERNAL', env('APP_ENV') === 'production'),
];
