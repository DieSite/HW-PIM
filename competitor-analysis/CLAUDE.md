# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A price competitive-analysis tool for **plissé hordeuren** (pleated screen doors). Playwright drives the public online configurators of the competitor webshops, enters 34 door configurations at each (6 generic sizes + the own assortment 96E–190N as single and double door, in black and grey mesh — see `tests/sizes.js`), scrapes the resulting price, and compiles everything into a styled Excel comparison sheet (`prijsvergelijking-plisse-hordeuren.xlsx`). All domain text is in Dutch.

## Commands

```bash
npm install
npm run install-browsers      # one-time: playwright install chromium

npm test                      # run all specs headless, then generate the Excel
HEADED=1 npm test             # met zichtbare browservensters (debuggen)
npm run test:eigen            # single competitor (see package.json for the full set:
                              #   eigen, horrentotaal, horrengigant, horren-com, praxis, creon)
npx playwright test 01-plissehordeurenwebshop   # one spec
```

Tests run **headless by default**; set `HEADED=1` for visible browsers (`playwright.config.js` reads the env var). `retries: 1`, 60s timeout per test (horrengigant: 90s).

The full suite takes ~5–10 min: de meeste specs draaien nu een echte live pagina of API. Resultaten accumuleren **sticky** over runs heen (zie `priceRecorder.js`): een echte €-prijs wordt nooit overschreven door een mislukte poging, dus her-runnen vult gaten. `RESET_RESULTS=1` voor een schone start.

## Architecture / data flow

Data flows through per-test JSON parts that are merged at the end:

1. `globalSetup.js` — clears stale `results.json` and the `results-parts/` dir.
2. Each `tests/NN-*.spec.js` — one file per competitor, generating one test per configuration in `tests/sizes.js` (34 currently). It calls `recordPrice(competitor, sizeName, price)` from `tests/priceRecorder.js`, which writes **one file per (competitor, size)** into `results-parts/`.
3. `globalTeardown.js` — calls `collectResults()` to merge all parts into `results.json`, then writes the formatted Excel (`prijsvergelijking-plisse-hordeuren.xlsx`).

**Why per-test files instead of one shared `results.json`:** Playwright runs spec files in parallel worker processes. The original code did read-modify-write on a single `results.json`, which raced and silently dropped cells. Each test now owns its own file — no contention. If you reintroduce shared mutable state, you'll get missing cells again.

`results.json`, `results-parts/`, and the `.xlsx` are build artifacts. **The competitor list, labels, size labels, and Excel styling live in `globalTeardown.js`, redundantly with the specs** — keep them in sync when adding/removing a competitor.

### Conventions shared across all specs

- Sizes come from `tests/sizes.js` (`SIZES` map): 6 generic sizes (black mesh) + the own assortment (7 type codes 96E–190N, each as single door and as double door at 2× the width, in black and grey mesh), each `{ breedte, hoogte, type: 'enkel' | 'dubbel', gaas: 'zwart' | 'grijs' }`, dimensions in **mm, measured inside the frame** ("in de dag"). `globalTeardown.js` derives its Excel rows from this map — no longer redundant.
- `tests/helpers.js` holds the shared robustness utilities: `acceptCookies`, `clickLabelById` (for custom-styled radios via their `<label for>`), `selectOptionByText` (regex option match — Playwright's `selectOption` does **not** accept a RegExp), and `normalizePrice`.
- `tests/_vanaf.js` holds two spec factories that keep the many thin competitor specs uniform: `registerVanaf(test, expect, {comp, url, selectors, min})` loads a product page once and records the lowest plausible `€` price (≥ `min`, guards against €0/shipping noise) as `Vanaf € x`; `registerLabel(test, {comp, label})` records a fixed honest label with no browser. Specs 12–23 are mostly one call to one of these.
- Standard options are intentionally uniform across competitors so prices are comparable: **RAL 9010 white frame, no handle, no powertape**; the mesh color comes from the size row's `gaas` field. A spec that cannot select grey mesh at a competitor must record `n.v.t.` for the grey rows (never silently record the black-mesh price). Oversized rows (the double doors go up to 3800 mm) must also end in `n.v.t.` — use `inputAllowsValue` from `tests/helpers.js` before JS-injecting values past a field's min/max validation.
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
| 04 | horrengigant.nl | ✅ **real per-size**, incl. grey mesh — ASP.NET WebForms configurator driven to completion. Gotchas baked into the spec: width/height are entered in **cm (mm/10)**; mesh color (`label[for=screenChoiceZwart/Grijs]`, no surcharge for grey) is selected on the same Maat&Kleur step as the dimensions; the price only appears in `#ctl17_lTotalPriceHeader` after clicking "Volgende >" through to the final overview step (~25s/size); per-test timeout is verruimd naar 90s. |
| 05 | horren.com | ✅ **real per-size, no browser page** — Laravel/Vue, maar de prijs komt uit `POST https://horren.com/product/validate-state/SE-100|SE-200` (Playwright `request`-fixture). Bootstrap: GET productpagina in dezelfde request-context → CSRF-token uit `<meta name="csrf-token">` + sessiecookies; headers `X-CSRF-TOKEN` + `X-Requested-With: XMLHttpRequest`. Maten in **cm**. Lees `price.price`, eis `complete===true` + lege `messages`. Geen `www.` (301), nooit `/product/add-to-cart` aanroepen. |
| 06 | praxis.nl | ✅ **live standaardmaat-prijs** — geen maatwerk; assortiment komt uit de publieke Algolia-API van de site zelf (app `DGVF2HE476`, index `prd_praxis_products_nl_nl`, filter `deepest_category:wd0044`, prijs in `facets.price_praxis`). Mapping: goedkoopste **witte "Plisséhordeur Premium"** (klantverzoek: specifiek deze lijn, niet de goedkopere Comfort/Livn/Dtch-lijnen die ook aan de maat voldoen) die de doelmaat dekt (inkortbaar). Geen passende Premium-maat → eerlijk `n.v.t.`. |

**Specs 11–21 are an additional batch of competitors** (added later). Same
per-competitor scraping approach, each cracked by reverse-engineering its
configurator:
  - `11-qniq` — full configurator (`#breedte`/`#hoogte` + selects → live `#prijstxt`). Prices by door *type*, not exact mm.
  - `19-decozijn` ✅ per-maat, **no browser page** — WooCommerce + Gravity Forms Product Add-Ons, everything server-rendered so a plain `request.get()` of the page HTML is enough. Breedte is a Gravity Forms product-select whose `<option value>` pipe-encodes `"LABEL mm|SURCHARGE"` (already in **mm**, not cm — don't ×10 like horrengigant); pick the cheapest band ≥ target width (max 1900mm, wider → n.v.t.). Hoogte is a free-text field but price-neutral — verified live that 1970/2080/2350mm all give the identical total for a fixed width; only its `min`/`max` attributes (1800–2700mm) gate n.v.t. No double-door variant and no mesh-color option on this page → dubbel/grijs always n.v.t.
  - `20-solano-wonen` ✅ per-maat, **no browser page** — Luxaflex-dealer op een eigen shopplatform (niet Shopify/WooCommerce). Configurator praat met `POST /services/getProductConfiguration` (form-encoded `csrfToken` + `productId=581` + genummerde `options[ID]`), gevonden door de live pagina te draaien met `page.on('response')` terwijl breedte/hoogte werden ingevuld. Breedte/hoogte (**cm**) in `options[2310]`/`options[2313]`; deurtype in `options[2307]` (`21018`=enkel, `21021`=dubbel); gaaskleur in `options[2325]` (`21042`=zwart, `21045`=grijs, geen meerprijs — geverifieerd via de API, niet alleen de DOM). **Val niet terug op eigen min/max-aannames**: de API keurt combinaties af via een niet-triviale breedte×hoogte-matrix per deurtype (190×2350mm wordt bv. afgekeurd voor enkele deur met een "oppervlakte overschreden"-fout, ook al valt de breedte los binnen 60–360cm) — vertrouw op het eigen `errors`-object (alle velden leeg = geldig) plus `priceGroupDetails.inRange`. **Cookie/CSRF-val**: de bootstrap-GET (voor het csrfToken) moet **per test** opnieuw, niet gecached over tests heen — Playwright geeft elke `test()` een eigen lege `request`-context, dus een gecachet token zonder de bijbehorende sessiecookie van dezelfde GET geeft vanaf de tweede test een foutieve/sessieloze POST (in dit geval stilletjes terugvallend op een generieke fallback-prijs, geen crash — zo'n stille bug valt alleen op door de resultaten te controleren, niet aan een groene testrun). `fittingAmount` (optionele opmeetservice aan huis) telt niet mee in de genoteerde prijs.
  - `17-horrenconcurrent` — WooCommerce + **PEWC**. Recalcs on **keyup** (`pressSequentially`, not `.fill()`); amount in `.woocommerce-Price-amount` has **no € sign** (parse the number yourself). Size-bands.
  - `14-luxehorren` — WooCommerce + **TM Extra Product Options** behind a non-functional "Samenstellen" Betheme div. Set `tmcp_textfield_0`/`_1` (breedte/hoogte mm) + `tmcp_radio_2` (RAL) via JS + dispatch events; read "Totaal prijs €…". Product is `/plisse-hordeur/standaard-plisse-hordeur/` a.k.a. "Standaard Plissé hordeur" — geverifieerd (2026-07-22, klantverzoek) dat dit géén "Royal" is; die is een losstaande, duurdere productlijn (`/plisse-hordeur/royal-*`) zonder overlap met dit configuratorformulier.
  - `16-koopje-horren` — **real fixed price** (no per-mm online): Bruynzeel Plissé Hordeur s900 op maat, flat per type — €179 enkel / €349 dubbel.
  - `21-raamdecoratie` ❌ **niet geautomatiseerd te scrapen** — Cloudflare-uitdaging ("Just a moment... / Even geduld...") die met headless Chromium ook na 30s niet zelf wegvalt (curl geeft direct 403). `registerLabel` met `"Geblokkeerd (Cloudflare)"` — bewust geen bot-bescherming omzeild, zelfde afspraak als bommelwonen/lowikmeubelen in de catalog-volledig-suite.
  - `12-handigehorren` ✅ per-maat — Shopify + **Easify Product Options** (niet "TPO"): de bandformule zit volledig in de pagina-HTML; na invullen van `input[name="properties[Dimension-Breedte in mm]"]`/`...Hoogte...` toont `.tpo_additional-price.active` de toeslag als `(49,50)` (komma, geen €). Prijs = toeslag + basisprijs uit `/products/<handle>.js` + vaste €20 voor de optie "Op maat zagen: Ja" (klantverzoek: zonder die optie levert Handige Horren een zelf-in te korten kit, niet vergelijkbaar met de kant-en-klare deuren van de andere bronnen). De eerdere "add-to-cart blocked"-conclusie was fout: dat was Easify-validatie op niet-aangevinkte verplichte radio's, geen botmuur. Al onze 6 maten hebben toeslag > 0, dus de spec EIST het toeslag-element (anders n.v.t. — nooit stilletjes de basisprijs noteren).

**Recurring gotchas across configurators** (check these first when a price won't move): (1) dimensions wanted in **cm not mm** (horrengigant, horren.com); (2) recalc fires on **keyup**, so `.fill()` does nothing — use `pressSequentially` or JS-set + dispatch input/change/keyup (creon, horrenconcurrent, luxehorren); (3) the price is in a **hidden API response** — capture it with `page.on('response')` (creon `/product/price`, horrentotaal `configurator.horrentotaal.nl/calculate`) of roep de API direct aan via de `request`-fixture als dat zonder paginasessie werkt (horren.com `validate-state`); (4) a **cookie overlay** silently blocks interaction until dismissed — let op: de knoptekst "Accepteren" zit pas sinds 2026-06 in `acceptCookies`, en `playwright.config.js` heeft nu `actionTimeout: 10_000` zodat een afgedekt element een test nooit meer tot de test-timeout laat hangen.

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
PIM-catalogus tegen de concurrenten prijst: `catalog-volledig/`. Input is een
catalogus-CSV (`SKU,Merk,Model,Maat,Prijs`; ~26k regels, ~20k met vaste maat,
~2.2k unieke modellen over De Munk/Eurogros/Karpi/Mart Visser/Louis De
Poortere/Desso). Deze wordt bij `pricing:run-competitor-analysis` automatisch
uit de productdatabase geëxporteerd door `App\Services\CompetitorCatalogExporter`
naar een tijdelijk bestand; het pad komt binnen via `CATALOG_CSV` (geen
handmatige export meer nodig).

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

**Vorm (rechthoek/ovaal/rond/loper) is een aparte matchdimensie** (toegevoegd
2026-07-22). `detectShape()` in `normalize.js` leest de vorm uit modelnaam,
maat, competitor-titel/variant of URL-slug; `normModel()` stript vormwoorden
zodat "Diamante 01 Oval" en "Diamante 01" hetzelfde model zijn met een andere
`entry.shape`. Elke prijskoppeling (Shopify/Woo-variant, index-URL,
`sizeFromUrl`, `getPrijs(html,w,h,shape)`) eist vormgelijkheid — vóór deze fix
kregen 614 ovale varianten (`.Oval`-parents met rechthoekige maat) de
rechthoekprijs van vloerkledenloods.nl/hetdesignhuys.nl. Ronde maten ("Rond
200 cm", "Ø 200") parsen nu als w=h met shape `rond` en doen dus mee.
`competitor_index` heeft een `shape`-kolom (auto-migratie in `storage.js`);
oude rijen zonder shape vallen terug op detectie uit model+URL. Unit tests:
`npm run volledig:test`. De PIM-import kent nu `--prune` (verwijdert
`competitor_prices`-rijen die niet meer in de SQLite staan); de nachtelijke
`pricing:run-competitor-analysis` geeft die standaard mee.

**Drie extra matchguards** (2026-07-22, zelfde sessie) — elke koppeling eist nu:
(1) `hasModelNameToken`: de modelnaam zelf ("prosper") moet in de
competitor-titel/slug staan — kleed.nl koppelde "Prosper 69 - Vintage Copper"
aan de Cendre-pagina op de sfeertokens "vintage 69"; (2) `numbersCompatible`:
kleur-/dessinnummers mogen niet botsen ("Brush Ovale 13" kreeg de prijs van de
69-kleurpagina); maatparen en ID's ≥5 cijfers tellen niet mee, "01"≡"1";
(3) `mustHave` (berekend in `catalog.js`): bestaat naast "Gentle 13" ook
"Gentle 13 Organic", dan is "organic" verplicht in de competitor-tekst — de
structuur-/vormvarianten (Organic/Pebble/Plaza/Wing/Eye, €919 i.p.v. €599)
kregen allemaal de basisprijs. "Ellips" is als vormwoord (→ ovaal) toegevoegd.
Opgeruimd: 614 (vorm) + 1.026 (model/kleur) + 3.408 (variant/ellips) foute
prijsrijen uit SQLite én `competitor_prices`. Geen live prijzen geraakt
(price history was leeg).

**Live-verificatie (2026-07-22, 48-rijen steekproef + volero/gigameubel
integraal) leverde nog drie fixes op:** (a) `normModel` translitereert nu
accenten ("Suède"→"suede" — anders verwierp de modelnaam-guard geldige
matches); (b) `pageMatchesEntry` in `normalize.js` als tweede verdedigings-
linie bij custom shops: fetch-prices en de browser-spec checken de
PAGINATITEL (met entity-stripping en vormcheck) vóór het vastleggen — de slug
mist soms het dessinnummer dat de titel wél toont (volero prijsde "Fading
World Babylon 8545" van de "Pink Flash 8261"-pagina; alle 24 rijen verwijderd);
(c) "organisch/organic" is een VORM (De Munk/Karpi organische kleden) —
floorpassion's "… in Organische Vorm"-pagina's (€759) prijsden onze
rechthoekige Vernons (advies €429); 16 rijen verwijderd. Verder verversen
custom-shopprijzen nu wekelijks: `unpricedSkus` beschouwt echte prijzen ouder
dan `REFRESH_DAYS` (default 7) als verlopen — vier karpettenkelder-drifts
(o.a. €5.975→€6.335) bewezen dat prijzen anders eeuwig op de eerste scrape
blijven staan. Eindstand: 9.768 rijen in beide stores, audit schoon;
ratio-verdeling concurrent/advies: 94% in 75–110%, de 9 rijen <60% zijn
live geverifieerd echt (o.a. Vogue 170x240 €344 bij vloerkledenloods — hún
datafout, maar de échte paginaprijs). gigameubel (32 rijen) blijft
onverifieerbaar: slugs/titels dragen geen kleurnummer ("prosper …-wit"),
kleuren kosten daar vermoedelijk hetzelfde.

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