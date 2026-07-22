/**
 * browser-shops.spec.js – prijzen voor de Cloudflare-beschermde shops van de
 * catalog-volledig suite (bommelwonen.nl, lowikmeubelen.nl, …).
 *
 * Deze shops tonen een Cloudflare-challenge voor automatische bezoekers. We
 * omzeilen die NIET geautomatiseerd. In plaats daarvan een MENS-IN-DE-LUS
 * aanpak: een echte persoon lost de check zelf op in een zichtbaar venster.
 *
 *   HEADED=1 npm run volledig:browser
 *
 * In headed-modus opent een ZICHTBAAR browservenster. Blijft een pagina op de
 * Cloudflare-check ("Even geduld…" / Turnstile) staan, dan los JIJ die check
 * zelf op in dat venster. De challenge is juist bedoeld om een mens te
 * verifiëren; dat is precies wat hier gebeurt — er wordt niets nagebootst of
 * heimelijk gepasseerd. Daarna gaat de automatisering verder in dezelfde sessie.
 *
 * We bewaren de sessie (cf_clearance-cookie e.d.) per shop in
 * data/<shop>.state.json, zodat je de check niet voor elke pagina opnieuw hoeft
 * te doen en een her-run de sessie hergebruikt zolang die geldig is.
 *
 * WAT WE BEWUST NIET DOEN: de bescherming heimelijk omzeilen — geen
 * anti-detectie/stealth-fingerprints, geen automatische Turnstile/CAPTCHA-
 * solvers, geen proxy-rotatie. Een mens passeert de check, niet een truc.
 * Zonder HEADED (headless/CI) wachten we alleen kort of de check vanzelf
 * wegvalt; zo niet, dan wordt de cel eerlijk n.v.t.
 *
 * Verder identiek aan de rest van de suite: deelt de SQLite-DB, sticky prijzen
 * (echte € wordt nooit door n.v.t. overschreven), één test per shop, workers:1.
 */

const { test, expect } = require('@playwright/test');
const fs   = require('fs');
const path = require('path');

const { openDb, getIndexForShop, recordPrice, unpricedSkus } = require('../storage');
const { loadCatalog } = require('../catalog');
const { normBrand, detectShape, pageMatchesEntry } = require('../normalize');
const { fetchSitemapUrls } = require('../indexers/sitemap');
const { indexUrls } = require('../discover');
const { CUSTOM_SHOPS } = require('../shops');

const BROWSER_SHOPS = CUSTOM_SHOPS.filter(s => s.browser);

const CSV_PATH  = process.env.CATALOG_CSV || path.join(__dirname, '..', '..', '..', 'HW-PIM', 'Result_6.csv');
const MAX_PAGES = Number(process.env.MAX_PAGES || 600);   // veiligheidsplafond per shop
const DATA_DIR  = path.join(__dirname, '..', 'data');

// Mens-in-de-lus alleen als er een zichtbaar venster is (HEADED=1). Dan mag de
// geautoriseerde gebruiker de Cloudflare-check zelf oplossen; we wachten lang.
const INTERACTIVE       = process.env.HEADED === '1';
const QUICK_WAIT        = 8_000;     // gewone bezoeker is hier al voorbij
const INTERACTIVE_WAIT  = 180_000;   // ruimte voor de mens om de check op te lossen

/** True zodra de challenge-title weg is, binnen `timeout` ms. */
const challengeGone = (page, timeout) => page.waitForFunction(
  () => !/even geduld|just a moment|checking your browser/i.test(document.title),
  { timeout }
).then(() => true, () => false);

let _promptedThisRun = false;

/**
 * Wacht tot de Cloudflare-check weg is. Eerst een korte check (vaak valt hij
 * vanzelf weg). Staat hij dan nog en draaien we headed, dan vragen we de mens
 * om hem in het venster op te lossen en wachten we lang. Headless: kort, geen
 * truc. Retourneert true als de pagina vrij is.
 */
async function passChallenge(page) {
  if (await challengeGone(page, QUICK_WAIT)) return true;
  if (!INTERACTIVE) return false;
  if (!_promptedThisRun) {
    console.log('\n  👤 Cloudflare-check actief. Los hem op in het ZICHTBARE browservenster '
      + '(klik de Turnstile/"Ik ben geen robot" aan). Daarna gaat het vanzelf verder; '
      + 'de sessie wordt onthouden.\n');
    _promptedThisRun = true;
  }
  return challengeGone(page, INTERACTIVE_WAIT);
}

/** page.content() faalt terwijl de challenge-pagina zichzelf herlaadt — vang dat af. */
async function safeContent(page) {
  for (let i = 0; i < 3; i++) {
    try { return await page.content(); }
    catch { await page.waitForTimeout(1500); }
  }
  return '';
}

/** Haal alle <loc>-URL's uit een sitemap (incl. één niveau index-nesting) via de browser. */
async function sitemapUrlsViaBrowser(page, rootUrl, maxUrls = 100_000) {
  const out = new Set();

  async function load(url) {
    try {
      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 40_000 });
    } catch { return ''; }
    await passChallenge(page);
    return safeContent(page);
  }

  const rootXml = await load(rootUrl);
  const subMaps = [...rootXml.matchAll(/<sitemap>[\s\S]*?<loc>([^<]+)<\/loc>/gi)].map(m => m[1].trim());

  if (subMaps.length) {
    for (const sub of subMaps) {
      if (out.size >= maxUrls) break;
      const xml = await load(sub);
      for (const m of xml.matchAll(/<url>[\s\S]*?<loc>([^<]+)<\/loc>/gi)) {
        if (out.size >= maxUrls) break;
        out.add(m[1].trim());
      }
    }
  } else {
    for (const m of rootXml.matchAll(/<url>[\s\S]*?<loc>([^<]+)<\/loc>/gi)) {
      if (out.size >= maxUrls) break;
      out.add(m[1].trim());
    }
  }
  return [...out];
}

for (const shopCfg of BROWSER_SHOPS) {
  test(`volledig browser – ${shopCfg.key}`, async ({ browser }) => {
    test.setTimeout(0); // shop kan honderden pagina's hebben; config-timeout is per-poging vangnet

    const db      = openDb();
    const catalog = loadCatalog(CSV_PATH);

    // Eigen context per shop met persistente sessie: een eenmaal (door de mens)
    // opgeloste Cloudflare-check blijft zo geldig over pagina's én runs heen.
    fs.mkdirSync(DATA_DIR, { recursive: true });
    const statePath = path.join(DATA_DIR, `${shopCfg.key}.state.json`);
    const context = await browser.newContext(
      fs.existsSync(statePath) ? { storageState: statePath } : {}
    );
    const page = await context.newPage();
    // Sla de sessie op zodra de check vrij is (en aan het eind).
    const saveSession = async () => { try { await context.storageState({ path: statePath }); } catch {} };

    // ── 1. Index opbouwen indien leeg ────────────────────────────────────────
    let indexRows = getIndexForShop(db, shopCfg.key);
    if (!indexRows.length) {
      console.log(`  [${shopCfg.key}] index leeg — sitemap ophalen`);
      let rawUrls = [];
      try {
        rawUrls = await fetchSitemapUrls(shopCfg.sitemapUrl, 100_000);
      } catch { /* val terug op browser */ }
      if (!rawUrls.length) {
        console.log(`  [${shopCfg.key}] sitemap via node faalde — via browser`);
        rawUrls = await sitemapUrlsViaBrowser(page, shopCfg.sitemapUrl);
      }
      const { indexed } = indexUrls(db, shopCfg, catalog, rawUrls);
      console.log(`  [${shopCfg.key}] ${rawUrls.length} URL's → ${indexed} gematcht op catalogusmodellen`);
      indexRows = getIndexForShop(db, shopCfg.key);
    } else {
      console.log(`  [${shopCfg.key}] index al gevuld: ${indexRows.length} rijen`);
    }

    // Groepeer index-URL's per (normBrand|normModel) -> [urls]
    const byModel = new Map();
    for (const r of indexRows) {
      const k = `${r.norm_brand}|${r.norm_model}`;
      if (!byModel.has(k)) byModel.set(k, []);
      byModel.get(k).push(r.url);
    }

    // ── 2. Te doen: catalogus-entries van de shop-merken zonder prijs ─────────
    const shopBrands = new Set(shopCfg.brands.map(b => normBrand(b)));
    const allSkus    = catalog.fixedEntries.filter(e => shopBrands.has(e.normBrand)).map(e => e.sku);
    const todoSkus   = new Set(unpricedSkus(db, shopCfg.key, allSkus));
    const todo       = catalog.fixedEntries.filter(e => todoSkus.has(e.sku));
    console.log(`  [${shopCfg.key}] ${todo.length} SKU's zonder prijs (van ${allSkus.length})`);

    // ── 3. Prijzen ophalen ───────────────────────────────────────────────────
    let fetched = 0, real = 0, blocked = 0, capped = false;
    for (const entry of todo) {
      const urls = byModel.get(`${entry.normBrand}|${entry.normModel}`);
      if (!urls) { recordPrice(db, entry.sku, shopCfg.key, 'n.v.t.', null); continue; }

      // Kies de URL waarvan maat én vorm in de slug overeenkomen met deze entry
      const url = urls.find(u => {
        const sz = shopCfg.sizeFromUrl?.(u);
        return sz && sz.widthCm === entry.widthCm && sz.heightCm === entry.heightCm
          && (detectShape(u) ?? 'rechthoek') === entry.shape;
      });
      if (!url) { recordPrice(db, entry.sku, shopCfg.key, 'n.v.t.', null); continue; }

      if (fetched >= MAX_PAGES) { capped = true; break; }

      let priceStr = null;
      try {
        await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 40_000 });
        const free = await passChallenge(page);
        if (free) { await saveSession(); } else { blocked++; }
        const html = await safeContent(page);
        const pageTitle = html.match(/<title>([^<]*)<\/title>/i)?.[1] ?? '';
        if (pageMatchesEntry(pageTitle, url, entry)) {
          priceStr = shopCfg.getPrijs(html, entry.widthCm, entry.heightCm, entry.shape);
          if (priceStr && shopCfg.vanaf) priceStr = `Vanaf ${priceStr}`;
        }
      } catch (e) {
        console.log(`  [${shopCfg.key}] ${entry.model} ${entry.sizeLabel}: ${e.message.split('\n')[0]}`);
      }

      recordPrice(db, entry.sku, shopCfg.key, priceStr ?? 'n.v.t.', priceStr ? url : null);
      fetched++;
      if (priceStr) real++;
      if (fetched % 25 === 0) console.log(`  [${shopCfg.key}] ${fetched}/${Math.min(todo.length, MAX_PAGES)} (${real} prijzen)`);
    }

    if (capped) {
      console.log(`  ⚠ [${shopCfg.key}] MAX_PAGES (${MAX_PAGES}) bereikt — her-run vult de rest (sticky). Verhoog MAX_PAGES om in één keer af te ronden.`);
    }
    console.log(`  ✅ [${shopCfg.key}] ${fetched} pagina's bezocht, ${real} echte prijzen`);
    if (blocked > 0) {
      const hint = INTERACTIVE
        ? `de check werd niet (op tijd) opgelost in het venster — her-run en los hem aan het begin op.`
        : `headless modus: draai met HEADED=1 en los de check zelf op in het venster (we omzeilen hem niet automatisch).`;
      console.log(`  ⚠ [${shopCfg.key}] ${blocked}× Cloudflare-check bleef staan — die cellen blijven n.v.t. ${hint}`);
    }

    await saveSession();
    await context.close();

    // Best-effort gate (zelfde filosofie als de hordeuren/karpetten-suite): we
    // asserten dat er voor élke verwerkte entry iets is vastgelegd (echte prijs
    // óf eerlijk n.v.t.), NIET dat een derde-partij-DOM een prijs gaf. Een door
    // Cloudflare geblokkeerde run is dus geen testfout — de cellen blijven leeg.
    // Bij een cap blijven SKU's bewust onbehandeld → die tellen we niet mee.
    const recordedCount = db.prepare(
      `SELECT COUNT(*) AS n FROM prices WHERE shop = ?`
    ).get(shopCfg.key).n;
    if (capped) {
      expect(recordedCount).toBeGreaterThan(0);
    } else {
      // Alles wat te doen viel, moet nu een record hebben (geen stille gaten).
      const stillMissing = unpricedSkus(db, shopCfg.key, allSkus)
        .filter(sku => !db.prepare(`SELECT 1 FROM prices WHERE shop = ? AND sku = ?`).get(shopCfg.key, sku));
      expect(stillMissing.length).toBe(0);
    }
  });
}
