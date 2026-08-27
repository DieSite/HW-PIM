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
    'enabled' => true,

    'db_path' => env(
        'COMPETITOR_PRICING_DB_PATH',
        base_path('competitor-analysis/catalog-volledig/data/catalog-volledig.db')
    ),

    /*
    |--------------------------------------------------------------------------
    | Scraper (Node) pipeline
    |--------------------------------------------------------------------------
    |
    | Directory of the copied-in Node/Playwright scraper.
    | `pricing:run-competitor-analysis` runs `node catalog-volledig/run.js` from
    | this directory. Its input catalog (SKU,Merk,Model,Maat,Prijs) is exported
    | from the product database at runtime by App\Services\CompetitorCatalogExporter
    | into a temporary CSV pointed at via CATALOG_CSV — no manual export needed.
    |
    | node_bin pins the toolchain the scraper runs on. The nightly run comes in
    | from cron, whose PATH is not the shell's and may lead with an ancient Node
    | that neither better-sqlite3 (a native addon) nor modern npm survives, so
    | `node` and `npm` are always invoked from here rather than resolved.
    |
    */
    'scraper_dir'     => base_path('competitor-analysis'),
    'node_bin'        => env('COMPETITOR_PRICING_NODE_BIN', '/usr/local/node-24/bin'),
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
        'node_bin'      => env('HORDEUREN_NODE_BIN', '/usr/local/node-24/bin'),
        'install_deps'  => (bool) env('HORDEUREN_INSTALL_DEPS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily report mail
    |--------------------------------------------------------------------------
    |
    | After every run of `pricing:run-competitor-analysis` a report is mailed
    | summarising which prices changed and why, plus the outliers that deserve
    | a human look. The thresholds below decide what counts as an outlier:
    |
    | - drop_pct / rise_pct: a single-run price move of at least this many
    |   percent. A normal competitor drift is a few percent; a double-digit
    |   jump is either a real competitor move or a wrong coupling.
    | - competitor_ratio: a cheapest competitor below this percentage of our
    |   adviesverkoopprijs. The audit found healthy couplings sit at 75–110%,
    |   so anything far below is more likely the wrong product than a bargain.
    | - stale_days: a competitor price this old is still driving our price
    |   even though the scraper has not confirmed it since.
    | - max_rows: how many rows of each outlier group the mail body lists;
    |   the attached CSV always holds every change.
    |
    */
    'report' => [
        'recipients' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'COMPETITOR_PRICING_REPORT_RECIPIENTS',
                'luuk@diesite.nl,hans@huis-en-wonen.nl'
            ))
        ))),

        'outliers' => [
            'drop_pct'         => (float) env('COMPETITOR_PRICING_REPORT_DROP_PCT', 15),
            'rise_pct'         => (float) env('COMPETITOR_PRICING_REPORT_RISE_PCT', 15),
            'competitor_ratio' => (float) env('COMPETITOR_PRICING_REPORT_COMPETITOR_RATIO', 60),
            'stale_days'       => (int) env('COMPETITOR_PRICING_REPORT_STALE_DAYS', 14),
            'max_rows'         => (int) env('COMPETITOR_PRICING_REPORT_MAX_ROWS', 25),
        ],

        /*
        | The report also runs two sets of checks. The first asks whether the
        | RUN itself was healthy; the second looks for indirect tells that a
        | PRICE is wrong even when the run finished cleanly. None of the price
        | tells is proof — they are the patterns that accompany a bad match:
        |
        | - dissent_pct: the cheapest competitor being this much cheaper than
        |   the second cheapest. When several shops agree and one disagrees,
        |   the odd one out is usually a different rug — and it is exactly the
        |   one that sets our price.
        | - psqm_deviation_pct: a variant whose price per m² deviates this much
        |   from the median of its own model family. Within one model every
        |   size costs roughly the same per m², so this catches a wrong price
        |   that no competitor says anything about.
        | - shop_ratio_low/high: the band a shop's MEDIAN price ratio versus
        |   our adviesprijs must stay in. One row outside is a bargain; a whole
        |   shop outside is a systematic coupling error.
        | - min_refresh_pct / shop_partial_pct: how much of the stored data the
        |   scrape must have confirmed, overall and per shop. Unrefreshed
        |   prices keep driving our price, so a half-finished scrape is worse
        |   than an empty one.
        | - flapping_days: a price changing on this many of the last 7 days is
        |   following an unstable match, not a market.
        | - mass_change_pct: a share of the catalog changing in one night that
        |   is too large to be competitor drift.
        */
        'checks' => [
            'min_refresh_pct'    => (float) env('COMPETITOR_PRICING_REPORT_MIN_REFRESH_PCT', 80),
            'shop_partial_pct'   => (float) env('COMPETITOR_PRICING_REPORT_SHOP_PARTIAL_PCT', 50),
            'shop_ratio_low'     => (float) env('COMPETITOR_PRICING_REPORT_SHOP_RATIO_LOW', 70),
            'shop_ratio_high'    => (float) env('COMPETITOR_PRICING_REPORT_SHOP_RATIO_HIGH', 120),
            'dissent_pct'        => (float) env('COMPETITOR_PRICING_REPORT_DISSENT_PCT', 25),
            'psqm_deviation_pct' => (float) env('COMPETITOR_PRICING_REPORT_PSQM_DEVIATION_PCT', 40),
            'flapping_days'      => (int) env('COMPETITOR_PRICING_REPORT_FLAPPING_DAYS', 5),
            'mass_change_pct'    => (float) env('COMPETITOR_PRICING_REPORT_MASS_CHANGE_PCT', 25),
            'max_items'          => (int) env('COMPETITOR_PRICING_REPORT_MAX_ITEMS', 15),
        ],
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

    /*
    |--------------------------------------------------------------------------
    | Confirmation threshold for the handmatige extra korting
    |--------------------------------------------------------------------------
    |
    | The `extra_korting` attribute discounts a single rug on top of the price
    | the competitor logic computed, deliberately breaking through the normal
    | max-discount floor. That is the point of the field, so this is NOT a cap:
    | a percentage above it is applied in full.
    |
    | It is the point where the admin editor stops accepting the number
    | silently and makes you confirm the resulting price, and the point above
    | which CompetitorPricingService logs a warning — so an unusual discount is
    | always a deliberate act with a trail, never a quiet typo.
    |
    | The nightly run cannot ask anyone, so by design it applies whatever is
    | stored: every route into the field (the voorraad editor, the bulk edit
    | tool) puts a human confirmation in front of it.
    |
    */
    'manual_discount_confirm_pct' => (int) env('COMPETITOR_PRICING_MANUAL_DISCOUNT_CONFIRM_PCT', 50),
];
