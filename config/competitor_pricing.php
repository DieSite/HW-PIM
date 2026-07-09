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
    | Hordeuren (plissé screen doors) on-demand analysis
    |--------------------------------------------------------------------------
    |
    | The Playwright suite in `competitor-analysis/tests/` compares our plissé
    | hordeur prices against the competitors for 34 door configurations (6
    | generic sizes + the own assortment as single/double door in black and
    | grey mesh) and rebuilds the Excel report below. It runs on demand from
    | the admin
    | (Tools → Hordeuren concurrentie-analyse) via
    | App\Jobs\RunHordeurenAnalysisJob, which mails the report when done.
    | Chromium is installed on first run into `browsers_path` (inside the
    | mounted repo, so it survives container rebuilds).
    |
    */
    'hordeuren' => [
        'timeout'       => (int) env('HORDEUREN_ANALYSIS_TIMEOUT', 5400),
        'max_passes'    => (int) env('HORDEUREN_ANALYSIS_MAX_PASSES', 3),
        'output'        => base_path('competitor-analysis/prijsvergelijking-plisse-hordeuren.xlsx'),
        'results'       => base_path('competitor-analysis/results.json'),
        'browsers_path' => env('PLAYWRIGHT_BROWSERS_PATH', base_path('competitor-analysis/.pw-browsers')),
    ],

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
