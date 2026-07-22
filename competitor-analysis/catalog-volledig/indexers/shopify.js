/**
 * shopify.js – generieke Shopify-catalogusindexer.
 *
 * Pagineert door /products.json (max 250/page) en indexeert elk product dat
 * op een van onze merken matcht. Schrijft resultaten naar competitor_index;
 * legt METEEN ook de variantprijzen vast in prices als de maat in ons
 * catalogus zit.
 *
 * Retourneert { indexed: number, priced: number }.
 */

const { getText, getJson, sleep } = require('../http');
const { normBrand, normModel, parseSize, fmtEuro, extractModel, detectShape, numbersCompatible, hasModelNameToken, containsAllTokens } = require('../normalize');
const { upsertIndex, recordPrice } = require('../storage');

/**
 * @param {Database} db
 * @param {object} opts
 * @param {string}   opts.shop      - domeinsleutel ("vloerkledenloods.nl")
 * @param {string}   opts.base      - basis-URL ("https://vloerkledenloods.nl")
 * @param {string[]} opts.brands    - kanonische merknamen om te filteren
 * @param {Map}      opts.catalogModels  - catalog.models map
 * @param {Map}      opts.bySku          - catalog.bySku map
 */
async function indexShopify(db, { shop, base, brands, catalogModels, bySku }) {
  const normBrands = brands.map(b => normBrand(b));
  let page = 1, indexed = 0, priced = 0;

  while (true) {
    let products;
    try {
      const json = await getJson(`${base}/products.json?limit=250&page=${page}`);
      products = json?.products ?? [];
    } catch (e) {
      console.warn(`  [shopify] ${shop} p${page}: ${e.message}`);
      break;
    }
    if (!products.length) break;

    for (const p of products) {
      // Detecteer merk uit vendor + tags + title
      const vendor       = normBrand(p.vendor ?? '');
      const titleNorm    = normModel(p.title ?? '');
      const matchedBrand = normBrands.find(nb => {
        // Directe vendor-match (bijv. vendor="De Munk Carpets" -> normBrand="de munk")
        if (vendor === nb || vendor.startsWith(nb) || nb.startsWith(vendor.split(' ')[0])) return true;
        // Fallback: alle merktokens in de producttitel
        return nb.split(' ').every(t => t.length < 3 || titleNorm.includes(t));
      });
      if (!matchedBrand) continue;

      // Modelnaam = titel min merknaam; vorm apart uit titel/handle
      const rawBrand     = brands[normBrands.indexOf(matchedBrand)];
      const model        = normModel(extractModel(p.title ?? '', rawBrand));
      const url          = `${base}/products/${p.handle}`;
      const productShape = detectShape(p.title, p.handle) ?? 'rechthoek';

      upsertIndex(db, { shop, normBrand: matchedBrand, normModel: model, title: p.title, url, platform: 'shopify', shape: productShape });
      indexed++;

      // Koppel variantprijzen aan matching catalogusentries
      for (const v of p.variants ?? []) {
        const size = parseSize(v.title ?? v.public_title ?? '');
        if (!size) continue;
        const variantShape = detectShape(v.title, v.public_title) ?? productShape;
        const priceStr = fmtEuro(parseFloat(v.price));
        if (!priceStr) continue;

        // Zoek catalogusentries die overeenkomen met dit merk+model+maat
        for (const [key, entries] of catalogModels) {
          if (!key.startsWith(matchedBrand + '|')) continue;
          // Modelnaam: check of alle tokens in de key overeenkomen met de competitor model
          const catModel  = key.split('|')[1];
          const catTokens = catModel.split(' ').filter(Boolean);
          const modTokens = model.split(' ').filter(Boolean);
          const fwdHits   = catTokens.filter(t => model.includes(t)).length;
          const revHits   = modTokens.filter(t => catModel.includes(t)).length;
          if (fwdHits < Math.min(2, catTokens.length) && revHits < Math.min(2, modTokens.length)) continue;
          // Modelnaam moet echt voorkomen en kleurnummers mogen niet botsen
          // ("Prosper 69" ≠ "Cendre vintage oker 69", "Brush 13" ≠ "Brush … 69")
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

    if (products.length < 250) break;
    page++;
    await sleep(300);
  }

  return { indexed, priced };
}

module.exports = { indexShopify };
