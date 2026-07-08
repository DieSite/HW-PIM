# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A price competitive-analysis tool for **plissé hordeuren** (pleated screen doors). Playwright drives the public online configurators of 10 competitor webshops, enters 6 standard door sizes at each, scrapes the resulting price, and compiles everything into a styled Excel comparison sheet (`prijsvergelijking-plisse-hordeuren.xlsx`). All domain text is in Dutch.

## Commands

```bash
npm install
npm run install-browsers      # one-time: playwright install chromium

npm test                      # run all specs headless, then generate the Excel
HEADED=1 npm test             # met zichtbare browservensters (debuggen)
npm run test:eigen            # single competitor (see package.json for the full set:
                              #   eigen, unilux, horrentotaal, horrengigant, horren-com,
                              #   praxis, luxaflex, creon, gamma, bruynzeel)
npx playwright test 01-plissehordeurenwebshop   # one spec
```

Tests run **headless by default**; set `HEADED=1` for visible browsers (`playwright.config.js` reads the env var). `retries: 1`, 60s timeout per test (horrengigant: 90s).

The full suite takes ~5–10 min: de meeste specs draaien nu een echte live pagina of API. Resultaten accumuleren **sticky** over runs heen (zie `priceRecorder.js`): een echte €-prijs wordt nooit overschreven door een mislukte poging, dus her-runnen vult gaten. `RESET_RESULTS=1` voor een schone start.

## Architecture / data flow

Data flows through per-test JSON parts that are merged at the end:

1. `globalSetup.js` — clears stale `results.json` and the `results-parts/` dir.
2. Each `tests/NN-*.spec.js` — one file per competitor, generating 6 tests (one per size from `tests/sizes.js`). It calls `recordPrice(competitor, sizeName, price)` from `tests/priceRecorder.js`, which writes **one file per (competitor, size)** into `results-parts/`.
3. `globalTeardown.js` — calls `collectResults()` to merge all parts into `results.json`, then writes the formatted Excel (`prijsvergelijking-plisse-hordeuren.xlsx`).

**Why per-test files instead of one shared `results.json`:** Playwright runs spec files in parallel worker processes. The original code did read-modify-write on a single `results.json`, which raced and silently dropped cells. Each test now owns its own file — no contention. If you reintroduce shared mutable state, you'll get missing cells again.

`results.json`, `results-parts/`, and the `.xlsx` are build artifacts. **The competitor list, labels, size labels, and Excel styling live in `globalTeardown.js`, redundantly with the specs** — keep them in sync when adding/removing a competitor.

### Conventions shared across all specs

- Sizes come from `tests/sizes.js` (`SIZES` map): 3 single + 3 double door sizes, each `{ breedte, hoogte, type: 'enkel' | 'dubbel' }`, dimensions in **mm, measured inside the frame** ("in de dag").
- `tests/helpers.js` holds the shared robustness utilities: `acceptCookies`, `clickLabelById` (for custom-styled radios via their `<label for>`), `selectOptionByText` (regex option match — Playwright's `selectOption` does **not** accept a RegExp), and `normalizePrice`.
- `tests/_vanaf.js` holds two spec factories that keep the many thin competitor specs uniform: `registerVanaf(test, expect, {comp, url, selectors, min})` loads a product page once and records the lowest plausible `€` price (≥ `min`, guards against €0/shipping noise) as `Vanaf € x`; `registerLabel(test, {comp, label})` records a fixed honest label with no browser. Specs 12–23 are mostly one call to one of these.
- Standard options are intentionally uniform across competitors so prices are comparable: **RAL 9010 white frame, black mesh, no handle, no powertape**. Preserve that when editing.
- **Every spec must finish fast and record something** — a real price or an honest label — never hang or throw on a missing selector. Use short timeouts and swallow failures; assert on "a value was recorded", not on a specific price (live third-party DOM is not a reliable CI gate).

### Techniques that unlocked the live configurators

Several competitor configurators only compute price on **keyup** or via a hidden API — a Playwright `.fill()` silently leaves the base price. Patterns that worked, reused across specs:
- **Type the digits** (`pressSequentially`), don't `.fill()` — required for creon, horrenconcurrent, horrentotaal.
- **Capture the price API response** instead of scraping the DOM: creon (`/product/price` → `priceGross`), horrentotaal (`configurator.horrentotaal.nl/calculate/<slug>` → `totaalPrijs`). Attach a `page.on('response')` listener before configuring. Direct (browser-less) calls to these APIs get 504 — they need the real page session, so drive the UI and read the response.
- **JS-inject + trigger the framework's calc** when fields are conditionally hidden/readonly: horrenstunter (Gravity Forms — set values by their min/max range, dispatch input/change/keyup, then read `.formattedTotalPrice`).
- **Robust cookie dismissal** (`acceptCookies` covers plugin selectors + role/text) — a consent overlay was silently blocking interaction on horrenconcurrent.

### Per-site scraping status (this is the real state, not aspirational)

| Spec | Site | Status |
|---|---|---|
| 01 | plissehordeurenwebshop.nl (**own shop**) | ✅ real per-size prices. Progressive JS configurator: `a.js-config-button` → `label[for=op_de_dag-1]` → `#breedte`/`#hoogte` → `schuin-1`/framekleur/gaas/handgreep/powertape labels. Price = "Hordeur" row current (non-struck) price in `.configurator__totals` (product price, **excludes** order-level pickup discount + promo). |
| 03 | horrentotaal.nl | live **"vanaf"** price (`.bndlr-old-price`); per-size is behind a multi-step Shopify "bundler" — not yet automated. |
| 08 | creon-kozijnen.nl | live **"vanaf"** price (`#price`); page does not compute a per-size price online (verified flat). |
| 02/07/10 | unilux / luxaflex / bruynzeel | **no online price** — record honest label (`Catalogus` / `Op aanvraag`). Documented in the Excel Info sheet. |
| 04 | horrengigant.nl | ✅ **real per-size** — ASP.NET WebForms configurator driven to completion. Two gotchas baked into the spec: width/height are entered in **cm (mm/10)**, and the price only appears in `#ctl17_lTotalPriceHeader` after clicking "Volgende >" through to the final overview step (~25s/size); per-test timeout is verruimd naar 90s. |
| 05 | horren.com | ✅ **real per-size, no browser page** — Laravel/Vue, maar de prijs komt uit `POST https://horren.com/product/validate-state/SE-100|SE-200` (Playwright `request`-fixture). Bootstrap: GET productpagina in dezelfde request-context → CSRF-token uit `<meta name="csrf-token">` + sessiecookies; headers `X-CSRF-TOKEN` + `X-Requested-With: XMLHttpRequest`. Maten in **cm**. Lees `price.price`, eis `complete===true` + lege `messages`. Geen `www.` (301), nooit `/product/add-to-cart` aanroepen. |
| 06 | praxis.nl | ✅ **live standaardmaat-prijs** — geen maatwerk; assortiment komt uit de publieke Algolia-API van de site zelf (app `DGVF2HE476`, index `prd_praxis_products_nl_nl`, filter `deepest_category:wd0044`, prijs in `facets.price_praxis`). Mapping: goedkoopste **witte plissé-DEURhor** (categorie bevat ook raam/rolhor/telescopisch — wegfilteren!) die de doelmaat dekt (inkortbaar). Dubbel middel/groot: aanbod stopt bij 150 cm → eerlijk `n.v.t.`. |
| 09 | gamma.nl | ✅ **live standaardmaat-prijs** — pagina's zijn server-side gerenderd (de oude "SPA + bot-bescherming"-aanname klopt niet meer); categoriepagina `type-plisse-hordeur` via `request`-fixture met browser-UA/Accept/Accept-Language. Maat zit in de URL-slug (`...-100x209-cm`), prijs in `itemprop="price"` per tegel. **Productpagina's niet opvragen — die geven 403.** Zelfde dekkings-mapping als praxis; dubbel middel/groot → `n.v.t.`. |

**Specs 11–23 are an additional batch of competitors** (added later). Same three-way pattern:
- **Real prices** (each cracked by reverse-engineering its configurator):
  - `11-qniq` — full configurator (`#breedte`/`#hoogte` + selects → live `#prijstxt`). Prices by door *type*, not exact mm.
  - `17-horrenconcurrent` — WooCommerce + **PEWC**. Recalcs on **keyup** (`pressSequentially`, not `.fill()`); amount in `.woocommerce-Price-amount` has **no € sign** (parse the number yourself). Size-bands.
  - `19-plissehordeur-discount` — fixed price per type (`.Price`, take the lowest; `.TextFromPrice` is the was-price). MeasureWidth/Height are size *advice*, don't change price.
  - `14-luxehorren` — WooCommerce + **TM Extra Product Options** behind a non-functional "Samenstellen" Betheme div. Set `tmcp_textfield_0`/`_1` (breedte/hoogte mm) + `tmcp_radio_2` (RAL) via JS + dispatch events; read "Totaal prijs €…".
  - `15-horrenbouw` — fixed **width-band** products ("tot 96/110/130/160/190 cm breed"). Scrape the bands live, map each size's width (mm/10 = cm) to the smallest covering band.
  - `13-horrenmax` ✅ per-maat — Shopify + **DPO (Itoris)**. De pagina-bundel van node1.itoris.com zit achter bot-bescherming (de oude DOM-aanpak gaf daardoor meestal `n.v.t.`), maar de widget-endpoints zelf zijn aanroepbaar: `POST node1.itoris.com/...controller=ValidateForm` met `options[1002]`/`options[1003]` (breedte/hoogte in **cm**, max 210×300) → DPO maakt de verborgen Shopify-variant aan en geeft `variant_id`; `POST /cart/add.js` met die variant → `items[0].price` (anonieme sessie-cart, geen bestelling). Twee routes in de spec: eerst de `request`-fixture (snel, met browser-headers), en als de WAF Node's TLS-fingerprint challenget (HTML i.p.v. JSON — gebeurt zodra het IP als druk is aangemerkt) dezelfde drie calls via `page.evaluate`+`fetch` vanaf de echte productpagina — exact wat de widget zelf doet. Gevalideerd tegen de prijsformule uit GetOptionConfig (total = 239 + tiers[hoogteband][breedteband], prijs = ×0,8; Shopify-basisprijs €191,20 = 239×0,8). Rustig aan: ±15 req/10s triggert de challenge; de spec pauzeert 4s per test en herkanst met backoff.
- **Real fixed price** (no per-mm online): `16-koopje-horren` (Bruynzeel s900 op maat, flat per type — €179 enkel / €349 dubbel).
- More cracked configurators (added 2026-06):
  - `12-handigehorren` ✅ per-maat — Shopify + **Easify Product Options** (niet "TPO"): de bandformule zit volledig in de pagina-HTML; na invullen van `input[name="properties[Dimension-Breedte in mm]"]`/`...Hoogte...` toont `.tpo_additional-price.active` de toeslag als `(49,50)` (komma, geen €). Prijs = toeslag + basisprijs uit `/products/<handle>.js`. De eerdere "add-to-cart blocked"-conclusie was fout: dat was Easify-validatie op niet-aangevinkte verplichte radio's, geen botmuur. Al onze 6 maten hebben toeslag > 0, dus de spec EIST het toeslag-element (anders n.v.t. — nooit stilletjes de basisprijs noteren).
  - `20-plissexxl` ✅ per-maat — WooCommerce + **PEWC** (zelfde plugin als 17); prijsformule staat client-side in de pagina: enkel basis €45 + `ceil(b×h/22000)`, dubbel basis €90 + `ceil(b×h/17000)`. Echte clicks worden door een overlay geblokkeerd → waarden via jQuery `.val().trigger('keyup')` zetten (luxehorren-patroon). Totaal in **`#pewc-grand-total`** lezen — de "grootste €-bedrag"-heuristiek pakte hier een statisch element (foute €180).
- **Honest label** (`registerLabel`): `21-plisse-reus` (sells plissé *blinds*, not door screens → "Geen hordeur"), `22-plissetotaal` (**geparkeerd domein**, "Domein gereserveerd" + kapot TLS-cert → "Site offline"), `23-hamstrahorren` (merksite zonder webshop/prijzen, verkoop via fysieke verkooppunten → "Via verkooppunten"; de Cloudflare-challenge is overigens wél passeerbaar met headless Chromium door na domcontentloaded de titel "Even geduld..." max ~20s uit te pollen).

**Recurring gotchas across configurators** (check these first when a price won't move): (1) dimensions wanted in **cm not mm** (horrengigant, horrenmax, horren.com); (2) recalc fires on **keyup**, so `.fill()` does nothing — use `pressSequentially` or JS-set + dispatch input/change/keyup (creon, horrenconcurrent, luxehorren, plissexxl); (3) the price is in a **hidden API response** — capture it with `page.on('response')` (creon `/product/price`, horrentotaal `configurator.horrentotaal.nl/calculate`) of roep de API direct aan via de `request`-fixture als dat zonder paginasessie werkt (horren.com `validate-state`); (4) a **cookie overlay** silently blocks interaction until dismissed — let op: de knoptekst "Accepteren" zit pas sinds 2026-06 in `acceptCookies`, en `playwright.config.js` heeft nu `actionTimeout: 10_000` zodat een afgedekt element een test nooit meer tot de test-timeout laat hangen.

**Wachten op een herberekende prijs: NOOIT een vaste `waitForTimeout`-sleep.** Dat was de oorzaak van de incomplete Excel van juni 2026: onder parallelle worker-load landde de recalc ná de sleep en noteerden specs `n.v.t.`. Alle per-maat specs pollen nu met `page.waitForFunction` (12–15s budget) op het concrete prijselement, of met een 500ms-lus op de afgevangen API-state (creon, horrentotaal). Bij een nieuw spec: zelfde patroon gebruiken.

Do **not** fabricate prices for the label/n.v.t. sites — the file is a real business comparison. The Excel Info sheet spells out the method per source. When adding a competitor, also add its `{key,label}` to `COMPETITORS` in `globalTeardown.js` (the key must match the `recordPrice` competitor string).

### Reverse-engineering a new site: `explore.js`

`node explore.js <url> [outName]` (headless; `HEADED=1` voor zichtbaar) opens a URL, accepts cookies, and dumps every input/select/button plus any `€`-bearing leaf node to `<outName>.json` + a screenshot. This is how the working selectors above were found — use it before touching a spec, since the live DOM rarely matches a guess.

## Karpetten-suite (tweede, losstaande vergelijking)

Naast de hordeuren is er een concurrentieanalyse voor **karpetten/vloerkleden**
(merken Eurogros, De Munk Carpets, Karpi/Mart Visser — zie "Goedlopende
karpetten.xlsx"). Eigen config en mappen, zelfde architectuur als de hordeuren:

```bash
npm run test:karpetten        # scrape live + herbouw concurrenten-karpetten.xlsx
node concurrenten-karpetten.js  # alleen Excel herbouwen (geen scrape)
```

- `playwright.karpetten.config.js` → `tests-karpetten/` → `results-parts-karpetten/`
  (sticky, zelfde regels als hordeuren; `RESET_RESULTS=1` voor schone start).
- `tests-karpetten/_helpers.js`: `registerKarpetten`-factory met drie adapters,
  alles via de `request`-fixture (geen browserpagina): **shopify** (suggest-API →
  `/products/<handle>.js` → exacte maatvariant-prijs; vloerkledenloods,
  hetdesignhuys), **pagina** (JSON-LD/meta-prijs; `maatExact: true` alleen als
  de maat in de URL zit, anders "Vanaf €"), **discovery** (`vindOp` merkpagina +
  `linkRe` → productpagina; karpettenshop, kleed.nl, karpettenkelder).
- De Excel heeft **hetzelfde formaat als de hordeuren-sheet**: tab
  "Prijsvergelijking" met producten (26 modellen) als rijen en shops als
  kolommen, gekleurd t.o.v. onze adviesprijs (rood = concurrent goedkoper,
  groen = duurder, geel = gelijk; "Vanaf"-prijzen cursief en ongekleurd want
  niet maat-vergelijkbaar), tab "Bronnen" (gescrapete pagina per cel, als
  link, automatisch vastgelegd door de recorder), "Top 10" en "Info".
- `karpetten-data.js` bevat MODELLEN (incl. eigen adviesprijs uit "Goedlopende
  karpetten.xlsx"), SHOPS (kolomvolgorde), de Top 10 + de per-merk
  onderzoeksmatrices (handmatig, juni 2026). `karpettenExcel.js` legt
  gescrapete prijzen als **overlay** over de onderzoeks-✓'s: alleen echte
  €-waarden vervangen een cel. Shop-keys = domeinnaam, identiek aan de `shop`
  in de specs — anders mist de overlay.
- Maten: Eurogros/Karpi 200x290, De Munk 200x300 (De Munk kent geen 200x290).
- Status (juni 2026): **149 exacte prijzen + 7 eerlijke "Vanaf"** (detafelaar
  heeft geen maatprijzen online); de enige ✓-zonder-prijs is bol.com (3 cellen;
  IP-blokkade op datacenter-ranges, geverifieerd — geen omzeiling).
  **Aanwezigheid per shop×model is volledig geverifieerd** via complete
  sitemaps/merkpagina's: een lege cel betekent aantoonbaar "verkoopt dit model
  niet in onze maat" (zie de noot per merk in `karpetten-data.js` voor de
  vervallen shops en naamgenoot-valkuilen zoals INHOUSE "Cavaro" en Richmond
  Interiors). Adapters
  per platform in `_helpers.js`: Shopify variant-API, WooCommerce
  (inline variations / Store API / wc-ajax), Lightspeed & Magento via
  shop-specifieke `regexUrl`-patronen, `browserUrl` voor Cloudflare-shops
  (lowik, bommelwonen), `sizeSelect` (vloerkledenspecialist), `vanaf: true`
  voor shops zonder maatprijs. Gotchas: maat-slugs variëren ("200-x-290-cm",
  "200x290ovaal" — adapter pakt de rechthoek), Magento-prijzen met NBSP na €,
  Lightspeed JSON-LD kan EXCL. btw zijn (karpettenshop) — gebruik de
  maattabel/variant-JSON. Dode shops (purewood→whoon, wonenop10, valeurhome,
  plissé... rispenswonen 200x290) zijn uit `karpetten-data.js` verwijderd;
  debommelmeubelen heet nu bommelwonen.nl.

## Catalog-volledig-suite (volledige PIM-catalogus, ~20k maatregels)

Naast de twee handmatig samengestelde vergelijkingen (26 hordeuren-cellen, 26
karpetten-modellen) is er een **schaalbare** pipeline die de **hele**
PIM-catalogus tegen de concurrenten prijst: `catalog-volledig/`. Input is de
CSV-export uit het PIM (`SKU,Merk,Model,Maat,Prijs`; ~26k regels, ~20k met
vaste maat, ~2.2k unieke modellen over De Munk/Eurogros/Karpi/Mart Visser/
Louis De Poortere/Desso). Pad via `CATALOG_CSV` (default `../HW-PIM/Result_6.csv`).

**Vereist Node 18+** (native `fetch` niet nodig, maar `better-sqlite3` is
gebouwd tegen 18; zie `.nvmrc`). `nvm use` vóór het draaien.

```bash
npm run volledig             # index → prijzen → excel (headless node-pipeline)
npm run volledig:index       # alleen competitor_index opbouwen (--shop x --reset)
npm run volledig:prijzen     # alleen custom-shop prijzen ophalen
npm run volledig:excel       # alleen concurrenten-volledig.xlsx herbouwen
npm run volledig:browser     # Cloudflare-shops via echte Chromium (Playwright)
```

Architectuur — **index-first**, niet per-product zoeken:
1. `index-shops.js` crawlt elke concurrent één keer en vult de SQLite-tabel
   `competitor_index` (`shop,norm_brand,norm_model,url`). Shopify
   (`/products.json`) en WooCommerce (Store API + inline `data-product_variations`)
   leggen meteen de variantprijzen vast; custom shops worden via sitemap-crawl
   (`indexers/sitemap.js`) + slug→model-matching (`discover.js`) alleen
   geïndexeerd.
2. `fetch-prices.js` haalt voor de custom shops de ontbrekende prijzen op
   (shop-specifieke `getPrijs(html,w,h)` in `shops.js`), parallel via
   `http.createQueue`. Maat-in-URL-shops (`fromUrl`) lezen JSON-LD.
3. `excel.js` bouwt `concurrenten-volledig.xlsx`: één tab per merk, rijen =
   (model, maat), kolommen = shops, gekleurd t.o.v. de eigen PIM-prijs (rood =
   concurrent goedkoper, groen = duurder, geel = gelijk; "Vanaf" cursief,
   ongekleurd). Plus een Bronnen-tab per merk.

Opslag: **SQLite** (`catalog-volledig/data/`, `better-sqlite3`, WAL +
`busy_timeout`) i.p.v. de per-test JSON-parts van de andere suites — 20k
prijspunten zijn te veel losse bestanden. `prices` is **sticky** op
(sku, shop): een echte €-prijs wordt nooit door `n.v.t.` overschreven, dus
her-runnen vult alleen gaten. `--reset` wist de index per shop.

**Matching is fuzzy, geen exacte SKU-koppeling.** We matchen op
`norm_brand` + token-overlap van de modelnaam (`normalize.js`), met
white-label-aliassen (Antoin Carpets→Eurogros, Core by Dersimo→Karpi,
Headlam→Karpi, "De Munk Carpets"→De Munk). Dekking is **patchy**: een lege cel
betekent dat de shop dat model (in die maat) niet in de index had.

**Per-shop config staat in `catalog-volledig/shops.js`** (platform, merkfilter,
`getPrijs`, `detectBrand`, `sizeFromUrl`). Een nieuwe concurrent = één entry
toevoegen. De recipes zijn dezelfde als in de karpetten-suite, hergebruikt en
gegeneraliseerd over alle modellen i.p.v. 8 hardcoded.

**Cloudflare-shops** (`browser: true` → bommelwonen.nl, lowikmeubelen.nl) worden
in de node-pipeline overgeslagen en via een **Playwright-spec**
(`catalog-volledig/specs/browser-shops.spec.js`, config
`playwright.volledig.config.js`, `workers:1`, deelt dezelfde SQLite-DB,
teardown herbouwt de Excel) gedraaid. De spec doet één nette paginalaad en
geeft de Cloudflare-challenge een paar seconden om vanzelf weg te vallen.
**We omzeilen de bot-bescherming bewust NIET** (geen anti-detectie-flags, geen
reload-hammering, geen IP-trucs). **Stand juni 2026: de challenge valt headless
niet weg (sitemap/productpagina's blijven op "Even geduld…")** — de spec legt
dan eerlijk `n.v.t.` vast (geen testfout, geen stille gaten). Deze shops zijn
dus simpelweg niet geautomatiseerd te scrapen; behandel ze als label-only,
zoals lowik/bommel ook in de karpetten-suite grotendeels waren.

`catalog-volledig/data/` en `concurrenten-volledig.xlsx` zijn build-artefacten.

## `create_excel.py` is separate

A standalone `openpyxl` script that builds a similarly-styled workbook from **hardcoded** data — independent of the Playwright pipeline and `results.json`. It does not read scraped results; treat it as a manual/template generator, not part of `npm test`.