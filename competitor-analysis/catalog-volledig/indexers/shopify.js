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
const { normBrand, normModel, parseSize, fmtEuro, extractModel, detectShape, productShape, modelIdentityMatches, sizeMatches, identityOptionsFor } = require('../normalize');
const { upsertIndex, recordPrice } = require('../storage');

/**
 * @param {Database} db
 * @param {object} opts
 * @param {string}   opts.shop      - domeinsleutel ("vloerkledenloods.nl")
 * @param {string}   opts.base      - basis-URL ("https://vloerkledenloods.nl")
 * @param {string[]} opts.brands    - kanonische merknamen om te filteren
 * @param {Map}      opts.catalogModels  - catalog.models map
 * @param {Map}      opts.bySku          - catalog.bySku map
 * @param {string}   [opts.fallbackBrand] - merk voor producten zonder merk in
 *                   vendor/titel (winkel zet zijn eigen naam als vendor)
 * @param {string[]} [opts.productTags]   - alleen producten met één van deze tags
 * @param {Object<string, string[]>} [opts.vendorAliases] - merken waarvan de
 *                   modellen óók onder dit merk verkocht worden (distributeur
 *                   als vendor), bv. { Eurogros: ['Louis De Poortere'] }
 * @param {string}   [opts.collection]  - alleen deze collectie doorbladeren
 *                   (/collections/<handle>/products.json) i.p.v. de hele winkel
 * @param {number}   [opts.pageDelayMs] - pauze tussen pagina's
 * @param {number[]} [opts.retryDelaysMs] - wachttijden bij een mislukte pagina
 */
async function indexShopify(db, { shop, base, brands, catalogModels, bySku, requireDiscriminator, sizeAliases = [], fallbackBrand, productTags, vendorAliases = {}, collection, pageDelayMs = 300, retryDelaysMs = [30_000, 60_000, 120_000] }) {
  const identityOpts = { requireDiscriminator };
  const normBrands = brands.map(b => normBrand(b));
  const aliasesByBrand = new Map(Object.entries(vendorAliases)
    .map(([brand, extra]) => [normBrand(brand), extra.map(normBrand)]));
  let page = 1, indexed = 0, priced = 0;

  const listUrl = collection ? `${base}/collections/${collection}/products.json` : `${base}/products.json`;

  while (true) {
    const products = await fetchPage(`${listUrl}?limit=250&page=${page}`, shop, page, retryDelaysMs);
    if (!products.length) break;

    for (const p of products) {
      if (productTags && !(p.tags ?? []).some(t => productTags.includes(String(t).toLowerCase()))) continue;

      // Detecteer merk uit vendor + tags + title
      const vendor       = normBrand(p.vendor ?? '');
      const titleNorm    = normModel(p.title ?? '');
      const matchedBrand = normBrands.find(nb => {
        // Directe vendor-match (bijv. vendor="De Munk Carpets" -> normBrand="de munk")
        if (vendor === nb || vendor.startsWith(nb) || nb.startsWith(vendor.split(' ')[0])) return true;
        // Fallback: alle merktokens in de producttitel
        return nb.split(' ').every(t => t.length < 3 || titleNorm.includes(t));
      }) ?? (fallbackBrand && normBrand(fallbackBrand));
      if (!matchedBrand) continue;

      // Modelnaam = titel min merknaam; vorm apart uit titel/handle
      const rawBrand     = brands[normBrands.indexOf(matchedBrand)] ?? fallbackBrand;
      const model        = normModel(extractModel(p.title ?? '', rawBrand));
      const url          = `${base}/products/${p.handle}`;
      const shapeOfProduct = productShape(p.title, p.handle);

      upsertIndex(db, { shop, normBrand: matchedBrand, normModel: model, title: p.title, url, platform: 'shopify', shape: shapeOfProduct });
      indexed++;

      // dfmwonen.nl zet het dessinnummer alleen in de handle ("Vloerkleed Acampo
      // Grijs Multicolor" → vloerkleed-acampo-2626). Zonder de handle haalde
      // "acampo 2626" de token-drempel hieronder nooit: 1.085 gemiste maten.
      // Als hele tokens, zodat "12" niet in "120x170" gevonden wordt.
      const handleTokens = new Set(normModel(p.handle).split(' '));

      // Onder welke merken van ons catalogus dit product kan vallen
      const catalogBrands = [matchedBrand, ...(aliasesByBrand.get(matchedBrand) ?? [])];

      // Welke maten dit product zélf voert: bepaalt of een maat-alias mag
      // inspringen (zie sizeMatches).
      const beschikbaar = new Set(
        (p.variants ?? [])
          .map(v => parseSize(variantLabel(p, v)))
          .filter(Boolean)
          .map(s => `${s.widthCm}x${s.heightCm}`)
      );

      // Koppel variantprijzen aan matching catalogusentries
      for (const v of p.variants ?? []) {
        const label = variantLabel(p, v);
        const size = parseSize(label);
        if (!size) continue;
        const variantShape = detectShape(label) ?? shapeOfProduct;
        if (!variantShape) continue;
        const priceStr = fmtEuro(parseFloat(v.price));
        if (!priceStr) continue;

        // Zoek catalogusentries die overeenkomen met dit merk+model+maat
        for (const [key, entries] of catalogModels) {
          if (!catalogBrands.some(nb => key.startsWith(nb + '|'))) continue;
          // Modelnaam: check of alle tokens in de key overeenkomen met de competitor model
          const catModel  = key.split('|')[1];
          const catTokens = catModel.split(' ').filter(Boolean);
          const modTokens = model.split(' ').filter(Boolean);
          const fwdHits   = catTokens.filter(t => model.includes(t) || handleTokens.has(t)).length;
          const revHits   = modTokens.filter(t => catModel.includes(t)).length;
          if (fwdHits < Math.min(2, catTokens.length) && revHits < Math.min(2, modTokens.length)) continue;
          // Modelnaam moet echt voorkomen en kleurnummers mogen niet botsen
          // ("Prosper 69" ≠ "Cendre vintage oker 69", "Brush 13" ≠ "Brush … 69")
          const idText = model + ' ' + url.toLowerCase();

          for (const entry of entries) {
            if (sizeMatches(entry, size, beschikbaar, sizeAliases) && entry.shape === variantShape
                && modelIdentityMatches(catModel, idText, entry.mustHave, { ...identityOpts, ...identityOptionsFor(entry), competitorModel: model })) {
              recordPrice(db, entry.sku, shop, priceStr, url);
              priced++;
            }
          }
        }
      }
    }

    if (products.length < 250) break;
    page++;
    await sleep(pageDelayMs);
  }

  return { indexed, priced };
}

/**
 * Eén pagina van products.json, met herhaalpogingen.
 *
 * Vroeger stopte het bladeren stil bij de eerste fout. mooierthuis.nl geeft na
 * ~6 snelle pagina's een 429 ("Verifying your connection") en de winkel leek
 * dan 1.500 producten te hebben, zonder één Eurogros-kleed, terwijl er 451
 * waren. Blijft een pagina falen, dan gooien we: een halve index moet als fout
 * in de log staan, niet als een winkel die toevallig weinig verkoopt.
 */
async function fetchPage(url, shop, page, retryDelaysMs) {
  for (let attempt = 0; ; attempt++) {
    try {
      const json = await getJson(url);
      return json?.products ?? [];
    } catch (e) {
      if (attempt >= retryDelaysMs.length) {
        throw new Error(`${shop} pagina ${page} blijft falen (${e.message}); index is ONVOLLEDIG`);
      }
      console.warn(`  [shopify] ${shop} p${page}: ${e.message}, opnieuw over ${retryDelaysMs[attempt] / 1000}s`);
      await sleep(retryDelaysMs[attempt]);
    }
  }
}

/**
 * Het maatlabel van een variant. Een product zonder opties heeft één variant
 * "Default Title"; dan staat de maat in de producttitel, bij dfmwonen.nl als
 * laatste segment: "Vloerkleed Kapiti Black - Kleur 175 - 170 x 230 cm". Alleen
 * dat segment, want parseSize leest op de hele titel "175 - 170" als maat.
 */
function variantLabel(p, v) {
  const title = v.title ?? v.public_title ?? '';
  if (title !== 'Default Title') return title;
  return String(p.title ?? '').split(' - ').pop();
}

module.exports = { indexShopify };
