/**
 * storage.js – SQLite-opslag voor de catalog-volledig suite via better-sqlite3.
 *
 * Tabellen:
 *   competitor_index  – ontdekte product-URL's per shop (geregenereerd)
 *   prices            – gescrapete prijzen per (sku, shop), sticky
 */

const Database = require('better-sqlite3');
const path     = require('path');
const fs       = require('fs');

// CATALOG_DB maakt een alternatieve database mogelijk (tests/verificatieruns
// zonder de productiedata te raken)
const DB_PATH = process.env.CATALOG_DB || path.join(__dirname, 'data', 'catalog-volledig.db');

let _db = null;

function openDb() {
  if (_db) return _db;
  fs.mkdirSync(path.dirname(DB_PATH), { recursive: true });
  _db = new Database(DB_PATH);
  _db.pragma('journal_mode = WAL');
  _db.pragma('synchronous = NORMAL');
  // De Playwright-suite draait in een apart proces dat tegelijk met andere
  // workers naar dezelfde DB kan schrijven; WAL + busy_timeout serialiseert dat.
  _db.pragma('busy_timeout = 10000');
  _db.exec(`
    CREATE TABLE IF NOT EXISTS competitor_index (
      id          INTEGER PRIMARY KEY AUTOINCREMENT,
      shop        TEXT NOT NULL,
      norm_brand  TEXT NOT NULL,
      norm_model  TEXT NOT NULL,
      title       TEXT NOT NULL,
      url         TEXT NOT NULL,
      platform    TEXT,
      shape       TEXT,
      indexed_at  TEXT NOT NULL DEFAULT (datetime('now')),
      UNIQUE(shop, url)
    );
    CREATE INDEX IF NOT EXISTS idx_ci_brand_model ON competitor_index(shop, norm_brand, norm_model);

    CREATE TABLE IF NOT EXISTS prices (
      sku        TEXT NOT NULL,
      shop       TEXT NOT NULL,
      price_str  TEXT,
      url        TEXT,
      scraped_at TEXT NOT NULL DEFAULT (datetime('now')),
      PRIMARY KEY (sku, shop)
    );
  `);
  // Migratie voor bestaande databases van vóór de vorm-kolom
  const hasShape = _db.prepare(`PRAGMA table_info(competitor_index)`).all().some(c => c.name === 'shape');
  if (!hasShape) {
    _db.exec(`ALTER TABLE competitor_index ADD COLUMN shape TEXT`);
  }
  return _db;
}

/* ── competitor_index ───────────────────────────────────────────────────── */

function upsertIndex(db, { shop, normBrand, normModel, title, url, platform, shape }) {
  db.prepare(`
    INSERT INTO competitor_index (shop, norm_brand, norm_model, title, url, platform, shape)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON CONFLICT(shop, url) DO UPDATE SET
      norm_brand = excluded.norm_brand,
      norm_model = excluded.norm_model,
      title      = excluded.title,
      platform   = excluded.platform,
      shape      = excluded.shape,
      indexed_at = datetime('now')
  `).run(shop, normBrand, normModel, title, url, platform ?? null, shape ?? null);
}

function clearIndex(db, shop) {
  db.prepare(`DELETE FROM competitor_index WHERE shop = ?`).run(shop);
}

function getIndexForShop(db, shop) {
  return db.prepare(`SELECT * FROM competitor_index WHERE shop = ?`).all(shop);
}

function findInIndex(db, shop, normBrand, normModel) {
  return db.prepare(`
    SELECT * FROM competitor_index
    WHERE shop = ? AND norm_brand = ? AND norm_model = ?
  `).all(shop, normBrand, normModel);
}

/* ── prices (sticky) ────────────────────────────────────────────────────── */

function isRealPrice(str) { return /€\s*\d/.test(String(str ?? '')); }

/**
 * Sla een prijs op voor (sku, shop). Sticky: een bestaande echte prijs
 * wordt NIET overschreven door n.v.t. of null.
 */
function recordPrice(db, sku, shop, priceStr, url = null) {
  const existing = db.prepare(`SELECT price_str FROM prices WHERE sku = ? AND shop = ?`).get(sku, shop);
  if (existing && isRealPrice(existing.price_str) && !isRealPrice(priceStr)) return;
  db.prepare(`
    INSERT INTO prices (sku, shop, price_str, url)
    VALUES (?, ?, ?, ?)
    ON CONFLICT(sku, shop) DO UPDATE SET
      price_str  = excluded.price_str,
      url        = excluded.url,
      scraped_at = datetime('now')
  `).run(sku, shop, priceStr ?? 'n.v.t.', url ?? null);
}

/**
 * Verwijder een eerder vastgelegde prijs. Bewust NIET sticky: `recordPrice`
 * beschermt een echte prijs tegen een mislukte fetch, maar een matchguard die
 * de pagina afkeurt is geen mislukking — het is de vaststelling dát deze
 * koppeling fout is. Zonder deze deur blijft een fout gekoppelde prijs eeuwig
 * staan, ook nadat een nieuwe guard hem zou tegenhouden.
 */
function deletePrice(db, sku, shop) {
  return db.prepare(`DELETE FROM prices WHERE sku = ? AND shop = ?`).run(sku, shop).changes;
}

/** Alle gescrapete prijzen terug als { sku: { shop: { priceStr, url } } }. */
function collectPrices(db) {
  const rows = db.prepare(`SELECT sku, shop, price_str, url FROM prices`).all();
  const out = {};
  for (const { sku, shop, price_str, url } of rows) {
    (out[sku] ??= {})[shop] = { priceStr: price_str, url: url ?? null };
  }
  return out;
}

/**
 * SKU's die voor een bepaalde shop opnieuw opgehaald moeten worden.
 *
 * REFRESH_DAYS = 0 (de standaard) betekent: élke run alles opnieuw. Dat is wat
 * je wilt — de indexwinkels leveren hun hele catalogus toch al elke nacht, en
 * een custom-shopprijs die een week oud is bepaalt intussen gewoon onze
 * verkoopprijs terwijl de concurrent allang iets anders vraagt.
 *
 * Een positieve waarde laat een echte prijs die jonger is dan dat aantal dagen
 * staan. Dat scheelt fetches, maar de prijzen lopen er navenant op achter.
 *
 * Sticky blijft in beide gevallen gelden: een mislukte her-scrape overschrijft
 * de oude prijs niet met n.v.t.
 */
function unpricedSkus(db, shop, allSkus, maxAgeDays = Number(process.env.REFRESH_DAYS || 0)) {
  if (!(maxAgeDays > 0)) return [...allSkus];

  const priced = new Set(
    db.prepare(
      `SELECT sku FROM prices WHERE shop = ? AND price_str LIKE '€%' AND scraped_at >= datetime('now', ?)`
    ).all(shop, `-${maxAgeDays} days`).map(r => r.sku)
  );
  return allSkus.filter(s => !priced.has(s));
}

/**
 * Gooi prijzen weg van SKU's die niet meer in de catalogus staan.
 *
 * De prijzen-tabel is sticky: een rij die niet opnieuw gescrapet wordt blijft
 * met haar oude scraped_at eeuwig staan. Zodra een SKU uit de catalogus
 * verdwijnt — opgeheven product, of de met-onderkleed-varianten die er bewust
 * uit gehaald zijn omdat geen concurrent die bundel verkoopt — komt hij nooit
 * meer langs de scraper en veroudert hij dus voor altijd. Dat vervuilt niet
 * alleen de database maar ook de verversingsgraad: die rijen tellen wel mee in
 * de noemer en kunnen per definitie nooit ververst worden.
 *
 * Rem: bij een lege of half ingelezen catalogus-CSV zou dit de hele tabel
 * wissen, dus onder `minCatalogSize` doet deze functie niets.
 */
function pruneUnknownSkus(db, knownSkus, minCatalogSize = 1000) {
  const known = knownSkus instanceof Set ? knownSkus : new Set(knownSkus);

  if (known.size < minCatalogSize) {
    return { pruned: 0, skipped: true };
  }

  const orphans = db.prepare(`SELECT DISTINCT sku FROM prices`).all()
    .map(r => r.sku)
    .filter(sku => !known.has(sku));

  if (!orphans.length) return { pruned: 0, skipped: false };

  const stmt = db.prepare(`DELETE FROM prices WHERE sku = ?`);
  const deleteAll = db.transaction(skus => skus.reduce((n, sku) => n + stmt.run(sku).changes, 0));

  return { pruned: deleteAll(orphans), skipped: false };
}

/** Reset prijzen voor één shop (voor herstart). */
function clearPrices(db, shop) {
  db.prepare(`DELETE FROM prices WHERE shop = ?`).run(shop);
}

module.exports = {
  openDb, upsertIndex, clearIndex, getIndexForShop, findInIndex,
  recordPrice, deletePrice, collectPrices, unpricedSkus, clearPrices,
  pruneUnknownSkus,
};
