<?php

/*
|--------------------------------------------------------------------------
| Cushions (kussens)
|--------------------------------------------------------------------------
|
| Cushions are configurable products in their own attribute family, with
| variants per kussenmaat × kleur × vulling. A "Met vulling" variant has no
| price of its own: it is the "Zonder vulling" variant of the same size and
| colour plus the filling surcharge for that size (App\Services\CushionFilling).
|
*/

return [
    'family_code' => 'kussens',

    /*
     * The WooCommerce variation attributes of a cushion, in the order the
     * shop shows them. Each needs a WooCommerce attribute mapping (add it to
     * the credential's custom fields and run the attribute export once).
     */
    'variation_attributes' => ['kussenmaat', 'kleur', 'vulling'],

    /*
     * Surcharge in euros for the filling, per kussenmaat option. A size that
     * is missing here gets no derived price: the "Met vulling" variant keeps
     * what it has and a warning is logged.
     */
    'filling_surcharge' => [
        // '45 x 45 cm' => 10,
    ],
];
