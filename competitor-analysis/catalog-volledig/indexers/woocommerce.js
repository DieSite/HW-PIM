/**
 * woocommerce.js – generieke WooCommerce-indexer.
 *
 * Zoekt producten via de WooCommerce Store API (publiek, geen auth nodig):
 *   GET /wp-json/wc/store/v1/products?search=<merk>&per_page=100&page=N
 *
 * Voor elk gevonden product:
 *   1. Haal de productpagina op
 *   2. Lees data-product_variations (inline JSON) voor maat+prijs per variant
 *   3. Als er geen inline JSON is (>30 variaties): sla de URL op in de index
 *      voor een latere "precisie" fetch via wc-ajax (zie fetch-prices.js)
 *
 * TERUGVAL OP DE HELE CATALOGUS. Zoeken op merknaam werkt alleen bij winkels
 * die het merk in de producttitel zetten ("Karpi Karpet Bermuda" bij
 * meubelcity). grootinvloeren.nl noemt exact hetzelfde kleed "Vloerkleed Upton
 * 9191" en karpetwereld.nl "Napoli 12" — daar levert `?search=Eurogros`
 * respectievelijk `?search=De Munk` nul producten op, en de merkcheck op de
 * titel gooide wat er tóch doorkwam alsnog weg. Beide winkels stonden dus
 * volledig ingericht in shops.js en leverden nul prijzen, zonder dat iets
 * daarover klaagde. Levert het zoeken op merk niets op, dan bladeren we de
 * catalogus door en matchen we de titel op onze eigen modelnamen.
 *
 * Retourneert { indexed, priced }.
 */

const { getText, getJson, sleep } = require('../http');
const { normBrand, normModel, parseSize, fmtEuro, extractModel, matchScore, detectShape, modelIdentityMatches } = require('../normalize');
const { upsertIndex, recordPrice } = require('../storage');

/** Bovengrens op het doorbladeren, zodat een winkel met een enorme catalogus de run niet opeet. */
const MAX_CATALOG_PAGES = 60;

/**
 * Haal variatie-JSON op uit HTML (data-product_variations attribuut).
 * Geeft array terug, of null als niet aanwezig.
 */
function extractVariations(html) {
  const m = html.match(/data-product_variations="([^"]+)"/);
  if (!m) return null;
  try {
    return JSON.parse(
      m[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&')
          .replace(/&#(\d+);/g, (_, c) => String.fromCharCode(c))
    );
  } catch { return null; }
}

/**
 * Probeer de WooCommerce Store API (v2 en v1). Sommige shops draaien alleen v1.
 */
/** Eén pagina van de complete catalogus (zonder zoekterm). */
async function wooPage(base, page) {
  for (const ver of ['v1', 'v2']) {
    try {
      const url = `${base}/wp-json/wc/store/${ver}/products?per_page=100&page=${page}&status=publish`;
      const json = await getJson(url);
      if (Array.isArray(json)) return json;
    } catch { /* probeer volgende versie */ }
  }
  return [];
}

/**
 * Lijkt deze titel op een model uit onze catalogus van dit merk?
 *
 * Dezelfde token-overlap die verderop de prijs koppelt, hier als voorfilter:
 * een winkel doorbladeren betekent duizenden producten zien, en alleen voor de
 * kandidaten hoeft de productpagina opgehaald te worden. Bij grootinvloeren
 * scheelt dat 1.848 paginaloads. De strenge guards volgen daarna gewoon.
 */
function looksLikeCatalogModel(model, nb, catalogModels) {
  const modTokens = model.split(' ').filter(Boolean);

  for (const key of catalogModels.keys()) {
    if (!key.startsWith(nb + '|')) continue;

    const catModel  = key.split('|')[1];
    const catTokens = catModel.split(' ').filter(Boolean);
    const fwdHits   = catTokens.filter(t => model.includes(t)).length;
    const revHits   = modTokens.filter(t => catModel.includes(t)).length;

    if (fwdHits >= Math.min(2, catTokens.length) || revHits >= Math.min(2, modTokens.length)) return true;
  }

  return false;
}

async function wooSearch(base, search, page) {
  for (const ver of ['v1', 'v2']) {
    try {
      const url = `${base}/wp-json/wc/store/${ver}/products?search=${encodeURIComponent(search)}&per_page=100&page=${page}&status=publish`;
      const json = await getJson(url);
      if (Array.isArray(json)) return json;
    } catch { /* probeer volgende versie */ }
  }
  return [];
}

async function indexWooCommerce(db, { shop, base, brands, catalogModels, bySku, requireDiscriminator }) {
  const identityOpts = { requireDiscriminator };
  const normBrands  = brands.map(b => normBrand(b));
  let indexed = 0, priced = 0, seen = 0;

  for (let bi = 0; bi < brands.length; bi++) {
    const brand     = brands[bi];
    const nb        = normBrands[bi];
    let page = 1;

    while (true) {
      let products;
      try {
        products = await wooSearch(base, brand, page);
      } catch (e) {
        console.warn(`  [woo] ${shop} zoek "${brand}" p${page}: ${e.message}`);
        break;
      }
      if (!products.length) break;

      for (const p of products) {
        const titleNorm = normModel(p.name ?? '');
        // Sla producten over die niet echt bij dit merk horen
        if (!nb.split(' ').every(t => t.length < 3 || titleNorm.includes(t) || p.name?.toLowerCase().includes(t))) continue;

        seen++;
        await indexProduct(p, brand, nb);
      }

      if (products.length < 100) break;
      page++;
      await sleep(400);
    }
  }

  // Geen enkel product via de merknaam gevonden: die winkel zet het merk niet
  // in zijn titels. Blader dan de catalogus door en match op modelnaam.
  if (seen === 0) {
    console.log(`  [woo] ${shop}: merkzoekopdracht leverde niets op — catalogus doorbladeren`);

    for (let page = 1; page <= MAX_CATALOG_PAGES; page++) {
      const products = await wooPage(base, page);
      if (!products.length) break;

      for (const p of products) {
        const name = p.name ?? '';

        for (let bi = 0; bi < brands.length; bi++) {
          const nb = normBrands[bi];
          if (!looksLikeCatalogModel(normModel(extractModel(name, brands[bi])), nb, catalogModels)) continue;

          await indexProduct(p, brands[bi], nb);
          break;
        }
      }

      if (products.length < 100) break;
      await sleep(400);
    }
  }

  return { indexed, priced };

  /** Indexeer één product en leg de variantprijzen vast die bij ons passen. */
  async function indexProduct(p, brand, nb) {
        const model        = normModel(extractModel(p.name ?? '', brand));
        const url          = p.permalink ?? `${base}/?p=${p.id}`;
        const productShape = detectShape(p.name, url) ?? 'rechthoek';

        upsertIndex(db, { shop, normBrand: nb, normModel: model, title: p.name, url, platform: 'woocommerce', shape: productShape });
        indexed++;

        // Haal productpagina op voor inline variatie-JSON
        let html = null;
        try { html = await getText(url); } catch {}

        if (html) {
          const variations = extractVariations(html);
          if (variations) {
            for (const v of variations) {
              const attrs = JSON.stringify(v.attributes ?? {});
              const size  = parseSize(attrs) ?? parseSize(Object.values(v.attributes ?? {}).join(' '));
              if (!size) continue;
              const variantShape = detectShape(attrs) ?? productShape;
              const priceStr = typeof v.display_price === 'number' ? fmtEuro(v.display_price) : null;
              if (!priceStr) continue;

              // Koppel aan catalogus
              for (const [key, entries] of catalogModels) {
                if (!key.startsWith(nb + '|')) continue;
                const catModel  = key.split('|')[1];
                const catTokens = catModel.split(' ').filter(Boolean);
                const modTokens = model.split(' ').filter(Boolean);
                const fwdHits   = catTokens.filter(t => model.includes(t)).length;
                const revHits   = modTokens.filter(t => catModel.includes(t)).length;
                if (fwdHits < Math.min(2, catTokens.length) && revHits < Math.min(2, modTokens.length)) continue;
                const idText = model + ' ' + url.toLowerCase();
                for (const entry of entries) {
                  if (entry.widthCm === size.widthCm && entry.heightCm === size.heightCm && entry.shape === variantShape
                      && modelIdentityMatches(catModel, idText, entry.mustHave, { ...identityOpts, colour: entry.colour })) {
                    recordPrice(db, entry.sku, shop, priceStr, url);
                    priced++;
                  }
                }
              }
            }
          }
        }
        await sleep(150);
  }
}

module.exports = { indexWooCommerce, extractVariations };
