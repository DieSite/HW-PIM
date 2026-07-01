/**
 * Watchlist + kleinere shops, één spec-bestand (per shop 1-3 modellen).
 * Recipes geverifieerd juni 2026:
 *  - floorpassion.nl: Lightspeed; absolute maatprijs in de Afmeting-dropdown
 *    (<option ... data-price="659" ...>Afmeting: 200x290 cm).
 *  - gigameubel.nl: maat zit in de URL-slug; JSON-LD prijs (paginaPrijs).
 *  - boumanenpotter.nl: JSON-LD; het Vernon-product IS de 200x290.
 *    (MV Prosper bestaat daar alleen als Ø200 rond — NIET vergelijkbaar.)
 *  - vivaldixl.nl / meubelcity.nl: WooCommerce inline variations-JSON.
 *  - purewood.nl is DOOD (301 naar whoon.com, assortiment niet meegegaan) en
 *    laminaatxxl.nl heeft de Karpi-producten "niet beschikbaar" zonder prijs
 *    -> beide verwijderd.
 */
const { test, expect } = require('@playwright/test');
const { registerKarpetten } = require('./_helpers');

const FP_PAT = m => `<option value="\\d+"[^>]*data-price="([\\d.]+)"[^>]*>Afmeting: ${m} cm`;

const SHOPS = [
  { shop: 'floorpassion.nl', items: [
    { model: 'MV Cavaro',  regexUrl: 'https://www.floorpassion.nl/cavaro-62.html', pattern: FP_PAT('200x290') },
    { model: 'MV Prosper', regexUrl: 'https://www.floorpassion.nl/prosper-23-wolf-grey.html', pattern: FP_PAT('200x290') },
    { model: 'MV Cendre',  regexUrl: 'https://www.floorpassion.nl/cendre-21-soft-grey.html', pattern: FP_PAT('200x290') }] },
  { shop: 'gigameubel.nl', items: [
    { model: 'MV Prosper', url: 'https://www.gigameubel.nl/mart-visser-vloerkleed-prosper-200x290cm-groen', maatExact: true },
    { model: 'MV Vernon',  url: 'https://www.gigameubel.nl/mart-visser-vloerkleed-vernon-200x290cm-zand', maatExact: true },
    { model: 'MV Cendre',  url: 'https://www.gigameubel.nl/mart-visser-vloerkleed-cendre-200x290cm-grijs', maatExact: true },
    { model: 'MV Cavaro',  url: 'https://www.gigameubel.nl/mart-visser-vloerkleed-cavaro-200x290cm-goud', maatExact: true }] },
  { shop: 'boumanenpotter.nl', items: [
    { model: 'MV Vernon', url: 'https://www.boumanenpotter.nl/mart-visser-vloerkleed-vernon-800100099.html', maatExact: true }] },
  { shop: 'vivaldixl.nl', items: [
    { model: 'Marich',    wooVariaties: 'https://www.vivaldixl.nl/winkel/meubelen/vloerkleden/karpi-marich-vloerkleed/', maat: '200x290' },
    { model: 'Bilal',     wooVariaties: 'https://www.vivaldixl.nl/winkel/meubelen/vloerkleden/karpi-bilal-vloerkleed/', maat: '200x290' },
    { model: 'Lago',      wooVariaties: 'https://www.vivaldixl.nl/winkel/meubelen/vloerkleden/karpi-lago-vloerkleed/', maat: '200x290' },
    { model: 'MV Cavaro', wooVariaties: 'https://www.vivaldixl.nl/winkel/meubelen/vloerkleden/mart-visser-cavaro-vloerkleed/', maat: '200x290' },
    // per-maat product (maat in slug) -> JSON-LD
    { model: 'MV Vernon', url: 'https://www.vivaldixl.nl/winkel/meubelen/vloerkleden/mart-visser-vernon-vloerkleed-fall-grey-200-x-290-cm/', maatExact: true }] },
  { shop: 'meubelcity.nl', items: [
    { model: 'Cisco',  wooVariaties: 'https://www.meubelcity.nl/karpet-cisco/', maat: '200x290' },
    { model: 'Lago',   wooVariaties: 'https://www.meubelcity.nl/karpet-lago/', maat: '200x290' },
    { model: 'Galaxy', wooVariaties: 'https://www.meubelcity.nl/karpet-galaxy/', maat: '200x290' },
    { model: 'Marich', wooVariaties: 'https://www.meubelcity.nl/karpet-marich/', maat: '200x290' },
    { model: 'Bilal',  wooVariaties: 'https://www.meubelcity.nl/karpet-bilal/', maat: '200x290' }] },
  // Cloudflare-challenge -> echte paginalaad; prijs staat in de <title>
  // ("Aspen 7270 vloerkleed – 200×290 € 525,- …"). Arizona heeft daar geen
  // 200x290 (alleen rond/240x330) -> niet opgenomen.
  // Lowik voert ook Anaheim/Spectrum/Twilight/Richmond/Arizona, maar alleen in
  // afwijkende maten (240x330/240x340/rond) — geen 200x290 aangetroffen, en de
  // zoek-/sitemap-/wp-json-routes zitten achter een interactieve
  // Cloudflare-challenge (alleen productpagina's laden headless).
  { shop: 'lowikmeubelen.nl', items: [
    { model: 'Aspen', browserUrl: 'https://www.lowikmeubelen.nl/product/aspen-7270-vloerkleed-200x290/',
      pattern: '<title>[^<]*€\\s*([0-9][0-9.,]*)' }] },
  // Shopware 6 achter Cloudflare (curl = 403) -> echte paginalaad; maat zit
  // in de URL-slug. debommelmeubelen.nl is omgedoopt naar bommelwonen.nl.
  { shop: 'bommelwonen.nl', items: [
    ...[
      ['Twilight',   '16761/vloerkleed-200x290cm-twilight-8822'],
      ['Aspen',      '23047/vloerkleed-aspen-200x290-cm-9293-zandkleuren'],
      ['Anaheim',    '15103/vloerkleed-200x290cm-anaheim-3243'],
      ['Spectrum',   '22519/vloerkleed-200x290cm-spectrum-3333'],
      ['Arizona',    '15110/vloerkleed-200x290cm-arizona-6282'],
      ['MV Cendre',  '4818/vloerkleed-200x290cm-cendre-soft-grey'],
      ['MV Vernon',  '4867/vloerkleed-200x290cm-vernon-fall-grey'],
      ['MV Prosper', '4841/vloerkleed-200x290cm-prosper-black-grey'],
    ].map(([model, pad]) => ({
      model, browserUrl: `https://www.bommelwonen.nl/${pad}/`,
      pattern: 'property="product:price:amount" content="([\\d.]+)"',
    }))] },
  // WooCommerce; volledige variatie-JSON in de pagina. LET OP: er bestaat ook
  // een ovaal-variant met dezelfde maat -> adapter pakt de rechthoek.
  { shop: 'grootinvloeren.nl', items: [
    ...[
      ['Richmond',    'richmond-102'], ['Twilight', 'twilight-2244'],
      ['Aspen',       'aspen-7270'],   ['Anaheim',  'anaheim-3243'],
      ['Allison',     'allison-3293'], ['Spectrum', 'spectrum-3333'],
      ['Love Shaggy', 'loveshaggy-beige'], ['Arizona', 'arizona-9290'],
    ].map(([model, slug]) => ({
      model, wooVariaties: `https://www.grootinvloeren.nl/${slug}/`, maat: '200x290',
    }))] },
];

for (const cfg of SHOPS) registerKarpetten(test, expect, cfg);
