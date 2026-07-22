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
 * SKU's die voor een bepaalde shop geen VERSE echte prijs hebben. Een echte
 * prijs ouder dan REFRESH_DAYS (default 7) telt als verlopen en wordt opnieuw
 * opgehaald — anders blijven custom-shopprijzen voor eeuwig op hun eerste
 * scrape staan terwijl de concurrent zijn prijzen allang verhoogd heeft.
 * (Sticky blijft gelden: een mislukte her-scrape overschrijft de oude prijs
 * niet met n.v.t.)
 */
function unpricedSkus(db, shop, allSkus, maxAgeDays = Number(process.env.REFRESH_DAYS || 7)) {
  const priced = new Set(
    db.prepare(
      `SELECT sku FROM prices WHERE shop = ? AND price_str LIKE '€%' AND scraped_at >= datetime('now', ?)`
    ).all(shop, `-${maxAgeDays} days`).map(r => r.sku)
  );
  return allSkus.filter(s => !priced.has(s));
}

/** Reset prijzen voor één shop (voor herstart). */
function clearPrices(db, shop) {
  db.prepare(`DELETE FROM prices WHERE shop = ?`).run(shop);
}

module.exports = {
  openDb, upsertIndex, clearIndex, getIndexForShop, findInIndex,
  recordPrice, collectPrices, unpricedSkus, clearPrices,
};
