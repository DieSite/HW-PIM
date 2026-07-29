/**
 * audit-prices.js – hertoetst ELKE al opgeslagen prijs aan de huidige
 * matchguards.
 *
 * Waarom dit nodig is: `prices` is sticky (een echte prijs wordt nooit door
 * n.v.t. overschreven) en de Shopify/WooCommerce-indexers slaan een afgekeurde
 * koppeling gewoon over. Een prijs die ooit door een zwakkere guard is
 * binnengekomen blijft daardoor voor altijd staan, ook nadat de guard is
 * aangescherpt. Deze audit sluit dat gat: hij past de guards van vandaag toe op
 * de voorraad van gisteren.
 *
 * Aanroepen:
 *   node catalog-volledig/audit-prices.js            # alleen rapporteren
 *   node catalog-volledig/audit-prices.js --fix      # foute rijen verwijderen
 *   node catalog-volledig/audit-prices.js --shop kleed.nl
 *
 * Omgevingsvariabelen: CATALOG_CSV, CATALOG_DB (zie run.js / storage.js).
 */

const path = require('path');
const { openDb, deletePrice } = require('./storage');
const { loadCatalog } = require('./catalog');
const { CUSTOM_SHOPS } = require('./shops');
const { isRealPrice, detectShape, modelIdentityMatches, normModel } = require('./normalize');

const CSV_PATH = process.env.CATALOG_CSV || path.join(__dirname, '..', '..', 'HW-PIM', 'Result_6.csv');

/**
 * Beoordeel één opgeslagen prijsrij. Geeft null als de rij in orde is, anders
 * de reden van afkeuring.
 *
 * De vormcheck geldt alléén voor custom shops: bij Shopify/WooCommerce zit de
 * vorm in de VARIANT-titel (die hier niet bewaard is), niet in de paginatitel,
 * dus daar zou een vormcheck op de paginatitel juist gekoppelde ovale kleden
 * ten onrechte afkeuren.
 */
function judge(row, entry, indexRow, shopCfg = null) {
  if (!entry) return 'unknown-sku';
  if (!indexRow) return 'no-index-row';

  const clean = String(indexRow.title ?? '').replace(/&#\d+;/g, ' ').replace(/&[a-z]+;/gi, ' ');
  const text = normModel(clean) + ' ' + String(row.url ?? '').toLowerCase();

  if (!modelIdentityMatches(entry.normModel, text, entry.mustHave,
      { requireDiscriminator: shopCfg?.requireDiscriminator, colour: entry.colour })) return 'identity';

  if ((indexRow.platform ?? 'custom') === 'custom') {
    const pageShape = detectShape(clean, row.url) ?? 'rechthoek';
    if (pageShape !== (entry.shape ?? 'rechthoek')) return 'shape';
  }

  // De maat zelf is hier meestal niet te hercontroleren (die staat niet in
  // `prices`), maar bij shops die de maat in de URL zetten wél — en juist daar
  // is één verkeerde URL genoeg om een prijs aan de verkeerde maat te hangen.
  if (shopCfg?.sizeFromUrl && row.url) {
    const urlSize = shopCfg.sizeFromUrl(row.url);
    if (urlSize && (urlSize.widthCm !== entry.widthCm || urlSize.heightCm !== entry.heightCm)) {
      return 'size';
    }
  }
  return null;
}

/**
 * Afkeurredenen die niets over de KOPPELING zeggen, maar over ontbrekende
 * invoer: `unknown-sku` = niet in de catalogus-CSV, `no-index-row` = geen
 * indexrij voor die (shop, url). Allebei schieten omhoog zodra een export of
 * een crawl mislukt — een 429-storm of een `--reset` waarna de sitemap
 * onbereikbaar is, laat de hele index leeg.
 */
const STRUCTURAL_REASONS = new Set(['unknown-sku', 'no-index-row']);

/**
 * Veiligheidsrem op `--fix`. Structurele afkeuringen (ontbrekende catalogus- of
 * indexgegevens) betekenen dat er invoer mist, niet dat er fout gekoppeld is.
 *
 * Een shop die de rem haalt wordt OVERGESLAGEN — niet de hele opruiming.
 * Anders blokkeren twee stukke rijen bij een shop van 12 het opruimen van echte
 * fouten bij de andere zes. Bij zo'n shop blijven ook de identity-/shape-
 * afkeuringen staan: als de index onbetrouwbaar is, zijn die oordelen dat ook.
 * Alleen de GLOBALE rem kan alles tegenhouden, en identity-/shape-/size-
 * afkeuringen tellen daarin niet mee — dat zijn echte vondsten.
 *
 * Een shop wordt overgeslagen als BEIDE verhoudingen boven de limiet liggen:
 *   - structureel t.o.v. alle afkeuringen bij die shop, én
 *   - structureel t.o.v. het aantal gecontroleerde rijen van die shop.
 *
 * Allebei zijn nodig. Alleen de eerste maakt de rem veel te gretig: 5 vervallen
 * producten bij karpettenkelder zijn 100% van drie afkeuringen maar 0,1% van de
 * shop, en dan zou --fix in de normale situatie niets meer opruimen — precies
 * wanneer je hem wél wilt draaien. Alleen de tweede laat kleine shops
 * onbeschermd: 11 ontbrekende indexrijen bij een shop van 12 is 92% van wat we
 * zagen, maar een rij-drempel keek daar niet naar.
 *
 * @returns {{refuse: boolean, pct: number, limit: number, skipShops: Set<string>}}
 */
function structuralBrake(bad, checked, perShopChecked = new Map(),
  limit = Number(process.env.AUDIT_MAX_UNKNOWN_PCT || 10)) {
  const structural = bad.filter(b => STRUCTURAL_REASONS.has(b.reason));
  const pct = checked ? (structural.length / checked) * 100 : 0;

  const skipShops = new Set();
  const shops = new Set([...perShopChecked.keys(), ...bad.map(b => b.shop)]);
  for (const shop of shops) {
    const rejected = bad.filter(b => b.shop === shop).length;
    if (!rejected) continue;
    const hits = structural.filter(b => b.shop === shop).length;
    const rows = perShopChecked.get(shop) ?? rejected;
    if ((hits / rejected) * 100 > limit && (hits / rows) * 100 > limit) skipShops.add(shop);
  }

  return { refuse: pct > limit, pct, limit, skipShops };
}

function main() {
  const args = process.argv.slice(2);
  const fix = args.includes('--fix');
  const shopIdx = args.indexOf('--shop');
  const shopFilter = shopIdx !== -1 ? args[shopIdx + 1] : null;

  const db = openDb();
  const catalog = loadCatalog(CSV_PATH);

  const rows = db.prepare(
    `SELECT sku, shop, price_str, url FROM prices${shopFilter ? ' WHERE shop = ?' : ''}`
  ).all(...(shopFilter ? [shopFilter] : []));

  const index = new Map();
  for (const r of db.prepare(`SELECT shop, url, title, platform FROM competitor_index`).all()) {
    index.set(r.shop + '|' + r.url, r);
  }

  const shopCfgs = new Map(CUSTOM_SHOPS.map(c => [c.key, c]));
  const perShop = new Map();
  const perShopChecked = new Map();
  const bad = [];
  let checked = 0;

  for (const row of rows) {
    if (!isRealPrice(row.price_str)) continue;
    checked++;
    perShopChecked.set(row.shop, (perShopChecked.get(row.shop) ?? 0) + 1);
    const reason = judge(row, catalog.bySku.get(row.sku), index.get(row.shop + '|' + row.url), shopCfgs.get(row.shop));
    if (!reason) continue;
    bad.push({ ...row, reason });
    const s = perShop.get(row.shop) ?? {};
    s[reason] = (s[reason] ?? 0) + 1;
    perShop.set(row.shop, s);
  }

  console.log(`Gecontroleerd: ${checked} echte prijzen`);
  console.log(`Afgekeurd:     ${bad.length}`);
  for (const [shop, counts] of [...perShop].sort()) {
    console.log(`  ${shop.padEnd(30)} ${Object.entries(counts).map(([k, v]) => `${k}=${v}`).join('  ')}`);
  }
  for (const b of bad.slice(0, 25)) {
    console.log(`   [${b.reason}] ${b.sku} @ ${b.shop} ${b.price_str} ${b.url ?? ''}`);
  }
  if (bad.length > 25) console.log(`   … en nog ${bad.length - 25}`);

  const brake = structuralBrake(bad, checked, perShopChecked);

  // Bewust exitcode 0 bij een weigering: een terechte rem mag de nachtelijke
  // pipeline (run.js gebruikt execSync, dat op non-zero afbreekt) niet stilleggen.
  if (fix && brake.refuse) {
    console.error(`\n✋ ${brake.pct.toFixed(1)}% van alle gecontroleerde rijen mist catalogus- of ` +
                  `indexgegevens (limiet ${brake.limit}%) — niets verwijderd.`);
    console.error('   Dat wijst op een onvolledige PIM-export of een mislukte crawl, niet op fout gekoppelde prijzen.');
    console.error(`   Controleer CATALOG_CSV (${CSV_PATH}, ${catalog.entries.length} regels) en de index, of zet AUDIT_MAX_UNKNOWN_PCT hoger.`);
    return;
  }

  if (fix) {
    for (const shop of brake.skipShops) {
      console.error(`   ⏭  ${shop} overgeslagen: te veel ontbrekende catalogus-/indexgegevens ` +
                    `(limiet ${brake.limit}%). De andere shops worden wél opgeruimd.`);
    }
    const doomed = bad.filter(b => !brake.skipShops.has(b.shop));
    const tx = db.transaction(list => { for (const b of list) deletePrice(db, b.sku, b.shop); });
    tx(doomed);
    console.log(`\n${doomed.length} rijen verwijderd. Draai daarna:`);
    console.log('  php artisan pricing:import-competitor-prices --prune');
  } else if (bad.length) {
    console.log('\n(rapportage-modus; gebruik --fix om deze rijen te verwijderen)');
  }

  process.exitCode = 0;
}

if (require.main === module) main();

module.exports = { judge, structuralBrake, STRUCTURAL_REASONS };
