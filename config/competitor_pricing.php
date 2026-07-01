<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Competitor scraper SQLite database
    |--------------------------------------------------------------------------
    |
    | Path to the SQLite database produced by the Node/Playwright scraper that
    | lives in `competitor-analysis/`. Its `prices` table is keyed by our exact
    | variant SKU and holds one scraped price + source URL per competitor shop.
    |
    */
    'db_path' => env(
        'COMPETITOR_PRICING_DB_PATH',
        base_path('competitor-analysis/catalog-volledig/data/catalog-volledig.db')
    ),

    /*
    |--------------------------------------------------------------------------
    | Scraper (Node) pipeline
    |--------------------------------------------------------------------------
    |
    | Directory of the copied-in Node/Playwright scraper and the manually
    | exported PIM catalog CSV (SKU,Merk,Model,Maat,Prijs) it reads as input.
    | `pricing:run-competitor-analysis` runs `node catalog-volledig/run.js`
    | from this directory with CATALOG_CSV pointed at the file below.
    |
    */
    'scraper_dir'     => base_path('competitor-analysis'),
    'catalog_csv'     => env('COMPETITOR_PRICING_CATALOG_CSV', base_path('competitor-analysis/Result_6.csv')),
    'concurrency'     => (int) env('COMPETITOR_PRICING_CONCURRENCY', 6),
    'scraper_timeout' => (int) env('COMPETITOR_PRICING_SCRAPER_TIMEOUT', 1800),

    /*
    |--------------------------------------------------------------------------
    | Default maximum discount percentage
    |--------------------------------------------------------------------------
    |
    | The computed price may never drop more than this percentage below the
    | adviesverkoopprijs (the ceiling). This is only the fallback default; the
    | live value is editable by admins under Configuration → Prijsstelling and
    | read via core()->getConfigData('general.pricing.settings.max_kortingspercentage').
    |
    */
    'default_max_discount_pct' => 25,
];
