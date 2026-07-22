/**
 * discover.js – koppel een lijst product-URL's aan catalogusmodellen en zet ze
 * in competitor_index. Gedeeld door:
 *   - index-shops.js (URL's uit sitemap via node http)
 *   - de Playwright browser-spec (URL's uit sitemap via een echte browser, om
 *     Cloudflare te passeren)
 *
 * De feitelijke prijsbepaling gebeurt elders (fetch-prices.js of de spec).
 */

const { normBrand, slugMatchScore, detectShape, numbersCompatible, hasModelNameToken, containsAllTokens } = require('./normalize');
const { upsertIndex } = require('./storage');
const { filterByKeywords } = require('./indexers/sitemap');

/**
 * Indexeer een set ruwe URL's voor één shop.
 *
 * @param {Database} db
 * @param {object}   shopCfg  - shop-config uit shops.js (key, brandKeys, detectBrand)
 * @param {object}   catalog  - resultaat van loadCatalog()
 * @param {string[]} rawUrls  - alle gevonden URL's (sitemap of crawl)
 * @returns {{ indexed: number, matched: Map<string,object> }}
 *          matched: norm "brand|model" -> { url, brand } (handig voor de spec)
 */
function indexUrls(db, shopCfg, catalog, rawUrls) {
  const { key, brandKeys } = shopCfg;
  const productUrls = filterByKeywords(rawUrls, brandKeys ?? []);

  let indexed = 0;
  const matched = new Map();

  for (const url of productUrls) {
    const brand = shopCfg.detectBrand?.(url) ?? null;
    if (!brand) continue;
    const nb = normBrand(brand);

    const slug = url.split('/').pop()?.split('?')[0]?.split('.')[0] ?? '';
    const slugNorm = slug.toLowerCase().replace(/[-_]/g, ' ');

    // Zoek best-matchende model in de catalogus. De modelnaam zelf moet in de
    // slug staan en kleurnummers mogen niet botsen — sfeerwoorden als
    // "vintage"/"69" mochten eerder "Prosper 69" aan "Cendre vintage oker 69"
    // koppelen.
    let bestModel = null, bestScore = 0;
    for (const [modelKey, keyEntries] of catalog.models) {
      if (!modelKey.startsWith(nb + '|')) continue;
      const catModel = modelKey.split('|')[1];
      if (!hasModelNameToken(slugNorm, catModel) || !numbersCompatible(catModel, slugNorm)) continue;
      if (!containsAllTokens(slugNorm, keyEntries[0]?.mustHave)) continue;
      const score = slugMatchScore(slugNorm, brand, catModel);
      if (score > bestScore && score >= 50) { bestScore = score; bestModel = catModel; }
    }
    if (!bestModel) continue;

    const shape = detectShape(slugNorm) ?? 'rechthoek';
    upsertIndex(db, { shop: key, normBrand: nb, normModel: bestModel, title: slug, url, platform: 'custom', shape });
    matched.set(`${nb}|${bestModel}`, { url, brand });
    indexed++;
  }

  return { indexed, matched };
}

module.exports = { indexUrls };
