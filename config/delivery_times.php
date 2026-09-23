<?php

return [
    /*
    |--------------------------------------------------------------------------
    | External sync (WooCommerce)
    |--------------------------------------------------------------------------
    |
    | When true, saving the rules on Tools → Levertijden pushes every changed
    | variant's delivery time to the live webshop. It defaults to on in
    | production only, so saving the rules locally (where the DB is usually a
    | production copy pointing at the live shop) updates the PIM without
    | touching the live shop. Override with DELIVERY_TIMES_SYNC_EXTERNAL.
    |
    */

    'sync_external' => env('DELIVERY_TIMES_SYNC_EXTERNAL', env('APP_ENV') === 'production'),
];
