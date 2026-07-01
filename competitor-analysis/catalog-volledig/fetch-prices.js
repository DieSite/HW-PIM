/**
 * fetch-prices.js – haalt de ontbrekende prijzen op voor geïndexeerde producten.
 *
 * Aanroepen:
 *   node catalog-volledig/fetch-prices.js [--shop karpettenkelder.nl] [--concurrency 6]
 *
 * Werking:
 *   1. Laad catalogus + competitor_index uit DB
 *   2. Bepaal per (shop, sku) welke prijs nog ontbreekt
 *   3. Zoek bijbehorende product-URL op in de index (via brand+model match)
 *   4. Pas shop-specifieke getPrijs-functie toe op HTML van de URL
 *   5. Sla op in prices-tabel (sticky)
 *
 * Shopify + WooCommerce: prijzen zijn al opgeslagen tijdens indexering.
 * Custom shops: hier worden de prijzen gefetcht.
 */

const path = require('path');
const { openDb, getIndexForShop, recordPrice, unpricedSkus, findInIndex } = require('./storage');
const { loadCatalog }  = require('./catalog');
const { normBrand, normModel, isRealPrice, fmtEuro } = require('./normalize');
const { getText, createQueue, sleep } = require('./http');
const { extractJsonLdPrice, parsePriceStr } = require('./indexers/sitemap');
const { CUSTOM_SHOPS } = require('./shops');

const CSV_PATH   = process.env.CATALOG_CSV || path.join(__dirname, '..', '..', 'HW-PIM', 'Result_6.csv');
const CONCURRENCY = Number(process.env.CONCURRENCY || process.argv.find((_, i) => process.argv[i - 1] === '--concurrency') || 6);

// ── Hulpfuncties ─────────────────────────────────────────────────────────────

/** Geef het beste index-record voor (shop, entry) terug, of null. */
function findUrl(db, shop, entry) {
  // Exacte match op normBrand + normModel
  const rows = findInIndex(db, shop, entry.normBrand, entry.normModel);
  if (rows.length) return rows[0].url;

  // Fuzzy: zoek records met hetzelfde brand, check of entry.normModel start met
  // de eerste twee tokens van het competitor model (of vice versa)
  const brandRows = db.prepare(
    `SELECT * FROM competitor_index WHERE shop = ? AND norm_brand = ?`
  ).all(shop, entry.normBrand);

  const entryTokens = entry.normModel.split(' ').filter(Boolean);
  for (const row of brandRows) {
    const rowTokens = row.norm_model.split(' ').filter(Boolean);
    const hits = entryTokens.filter(t => rowTokens.includes(t)).length;
    if (hits >= Math.min(2, entryTokens.length) && hits / entryTokens.length >= 0.7) {
      return row.url;
    }
  }
  return null;
}

/** Fetch prijs voor één (entry, shopCfg, url). */
async function fetchOne(db, entry, shopCfg, url) {
  let html;
  try { html = await getText(url); } catch (e) {
    recordPrice(db, entry.sku, shopCfg.key, 'n.v.t.', url);
    return;
  }

  let priceStr = null;

  if (shopCfg.fromUrl) {
    // Maat zit in URL; prijs ophalen via JSON-LD
    const p = extractJsonLdPrice(html);
    priceStr = p ? fmtEuro(p) : null;
    if (shopCfg.vanaf) priceStr = priceStr ? `Vanaf ${priceStr}` : null;
  } else if (shopCfg.getPrijs) {
    priceStr = shopCfg.getPrijs(html, entry.widthCm, entry.heightCm);
    if (priceStr && shopCfg.vanaf) priceStr = `Vanaf ${priceStr}`;
  }

  recordPrice(db, entry.sku, shopCfg.key, priceStr ?? 'n.v.t.', url);
}

// ── Hoofd-orchestrator ────────────────────────────────────────────────────────

async function main() {
  const args    = process.argv.slice(2);
  const shopArg = args.filter((_, i) => args[i - 1] === '--shop');

  const db      = openDb();
  const catalog = loadCatalog(CSV_PATH);
  const enqueue = createQueue(CONCURRENCY);

  // Alleen custom shops (Shopify/WooCommerce zijn al geprijsd tijdens indexering)
  const shops = shopArg.length
    ? CUSTOM_SHOPS.filter(s => shopArg.includes(s.key))
    : CUSTOM_SHOPS.filter(s => !s.browser);

  for (const shopCfg of shops) {
    console.log(`\n▶ ${shopCfg.key}`);
    const allSkus = catalog.fixedEntries.map(e => e.sku);
    const todo    = unpricedSkus(db, shopCfg.key, allSkus);
    console.log(`  ${todo.length} SKU's zonder prijs (van ${allSkus.length})`);
    if (!todo.length) { console.log('  Alles al geprijsd, skip.'); continue; }

    const tasks = [];
    for (const sku of todo) {
      const entry = catalog.bySku.get(sku);
      if (!entry) continue;

      // Zoek URL op in index
      const url = findUrl(db, shopCfg.key, entry);
      if (!url) {
        // Niet in index = shop verkoopt dit model waarschijnlijk niet
        recordPrice(db, sku, shopCfg.key, 'n.v.t.', null);
        continue;
      }

      // Voor fromUrl shops: controleer of de maat in de URL zit
      if (shopCfg.fromUrl && shopCfg.sizeFromUrl) {
        const urlSize = shopCfg.sizeFromUrl(url);
        if (!urlSize || urlSize.widthCm !== entry.widthCm || urlSize.heightCm !== entry.heightCm) {
          // Andere maat in URL; we kunnen de prijs hier niet zeker bepalen
          recordPrice(db, sku, shopCfg.key, 'n.v.t.', null);
          continue;
        }
      }

      tasks.push(enqueue(() => fetchOne(db, entry, shopCfg, url)));
    }

    let done = 0;
    const total = tasks.length;
    await Promise.all(tasks.map(t => t.then(() => {
      done++;
      if (done % 100 === 0) process.stdout.write(`\r  ${done}/${total}`);
    })));
    console.log(`\r  ✅ ${done} prijzen gefetcht`);
    await sleep(500);
  }

  console.log('\n✅ Prijzen ophalen klaar. Draai nu: node catalog-volledig/excel.js');
}

main().catch(e => { console.error(e); process.exit(1); });
