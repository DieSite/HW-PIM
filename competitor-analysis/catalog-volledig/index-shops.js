/**
 * index-shops.js – bouwt de competitor_index tabel door alle shops te crawlen.
 *
 * Aanroepen:
 *   node catalog-volledig/index-shops.js [--shop vloerkledenloods.nl] [--reset]
 *
 * --shop  : indexeer alleen deze shop (herhaald gebruik voor meerdere shops)
 * --reset : wis de index voor de geselecteerde shop(s) vóór het indexeren
 *
 * Strategie per platform:
 *   shopify      -> /products.json API (inclusief variantprijzen)
 *   woocommerce  -> WC Store API + per-pagina variaties-JSON
 *   custom       -> sitemap-crawl → URL-filtering → upsert in index
 *                   (prijzen worden apart gefetcht door fetch-prices.js)
 */

const path = require('path');
const { openDb, clearIndex, upsertIndex } = require('./storage');
const { loadCatalog }  = require('./catalog');
const { normBrand, normModel, slugMatchScore } = require('./normalize');
const { indexShopify } = require('./indexers/shopify');
const { indexWooCommerce } = require('./indexers/woocommerce');
const { fetchSitemapUrls } = require('./indexers/sitemap');
const { indexUrls }   = require('./discover');
const { SHOPIFY_SHOPS, WOOCOMMERCE_SHOPS, CUSTOM_SHOPS } = require('./shops');
const { sleep } = require('./http');

const CSV_PATH = process.env.CATALOG_CSV || path.join(__dirname, '..', '..', 'HW-PIM', 'Result_6.csv');

// ── Custom-shop sitemap indexer ───────────────────────────────────────────────

async function indexCustomShop(db, shopCfg, catalog) {
  const { key, base, brands, sitemapUrl, brandKeys } = shopCfg;
  console.log(`  sitemap crawl: ${sitemapUrl}`);

  let allUrls;
  try {
    allUrls = await fetchSitemapUrls(sitemapUrl, 100_000);
  } catch (e) {
    console.warn(`  ⚠ sitemap fout voor ${key}: ${e.message}`);
    return { indexed: 0 };
  }
  console.log(`  ${allUrls.length} URL's in sitemap`);

  const { indexed } = indexUrls(db, shopCfg, catalog, allUrls);
  console.log(`  ${indexed} URL's gematcht op catalogusmodellen`);
  return { indexed };
}

// ── Hoofd-orchestrator ────────────────────────────────────────────────────────

async function main() {
  const args    = process.argv.slice(2);
  const shopArg = args.filter((_, i) => args[i - 1] === '--shop');
  const reset   = args.includes('--reset');

  const db      = openDb();
  const catalog = loadCatalog(CSV_PATH);
  console.log(`Catalogus: ${catalog.entries.length} regels, ${catalog.fixedEntries.length} vaste maten, ${catalog.models.size} unieke modellen`);

  // Bepaal welke shops we draaien
  const runShops = shopArg.length
    ? [...SHOPIFY_SHOPS, ...WOOCOMMERCE_SHOPS, ...CUSTOM_SHOPS].filter(s => shopArg.includes(s.key))
    : [...SHOPIFY_SHOPS, ...WOOCOMMERCE_SHOPS, ...CUSTOM_SHOPS];

  if (!runShops.length) {
    console.error('Geen shops gevonden (controleer --shop argument)');
    process.exit(1);
  }

  for (const shopCfg of runShops) {
    console.log(`\n▶ ${shopCfg.key}`);
    if (reset) { clearIndex(db, shopCfg.key); console.log('  Index gewist'); }

    let result;
    try {
      if (SHOPIFY_SHOPS.includes(shopCfg)) {
        result = await indexShopify(db, {
          shop:          shopCfg.key,
          base:          shopCfg.base,
          brands:        shopCfg.brands,
          catalogModels: catalog.models,
          bySku:         catalog.bySku,
        });
        console.log(`  ✅ ${result.indexed} producten geïndexeerd, ${result.priced} prijzen opgeslagen`);

      } else if (WOOCOMMERCE_SHOPS.includes(shopCfg)) {
        // Sla browser-shops over (die staan in CUSTOM_SHOPS)
        result = await indexWooCommerce(db, {
          shop:          shopCfg.key,
          base:          shopCfg.base,
          brands:        shopCfg.brands,
          catalogModels: catalog.models,
          bySku:         catalog.bySku,
        });
        console.log(`  ✅ ${result.indexed} producten geïndexeerd, ${result.priced} prijzen opgeslagen`);

      } else {
        // Custom shop
        if (shopCfg.browser) {
          console.log(`  ⏭ browser-shop (${shopCfg.key}) – overgeslagen (zie fetch-prices.js --browser)`);
          continue;
        }
        result = await indexCustomShop(db, shopCfg, catalog);
        console.log(`  ✅ ${result.indexed} URL's geïndexeerd (prijzen via fetch-prices.js)`);
      }
    } catch (e) {
      console.error(`  ❌ fout bij ${shopCfg.key}: ${e.message}`);
    }

    await sleep(1000); // respecteer de shop
  }

  console.log('\n✅ Indexering klaar. Draai nu: node catalog-volledig/fetch-prices.js');
}

main().catch(e => { console.error(e); process.exit(1); });
