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
 * Retourneert { indexed, priced }.
 */

const { getText, getJson, sleep } = require('../http');
const { normBrand, normModel, parseSize, fmtEuro, extractModel, matchScore, detectShape, numbersCompatible, hasModelNameToken, containsAllTokens } = require('../normalize');
const { upsertIndex, recordPrice } = require('../storage');

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

async function indexWooCommerce(db, { shop, base, brands, catalogModels, bySku }) {
  const normBrands  = brands.map(b => normBrand(b));
  let indexed = 0, priced = 0;

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
                if (!hasModelNameToken(model, catModel) || !numbersCompatible(catModel, model)) continue;
                for (const entry of entries) {
                  if (entry.widthCm === size.widthCm && entry.heightCm === size.heightCm && entry.shape === variantShape
                      && containsAllTokens(model, entry.mustHave)) {
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

      if (products.length < 100) break;
      page++;
      await sleep(400);
    }
  }

  return { indexed, priced };
}

module.exports = { indexWooCommerce, extractVariations };
