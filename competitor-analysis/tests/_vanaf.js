/**
 * _vanaf.js / _label.js helpers – keep the many competitor specs tiny and uniform.
 *
 * registerVanaf : load a product page ONCE, read a live "vanaf"-prijs via the
 *                 first matching selector, record "Vanaf € x" for all sizes
 *                 (or n.v.t. if nothing reliable is found). Pass-safe.
 * registerLabel : record a fixed honest label for all sizes (no browser).
 */

const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');
const { acceptCookies, normalizePrice } = require('./helpers');

/** Euro string -> number. Handles NL "1.234,50" and dot-decimal "5.00"/"255". */
function euroToNumber(s) {
  if (!s) return null;
  const m = String(s).match(/\d[\d.,]*/);
  if (!m) return null;
  let t = m[0];
  if (t.includes(',')) {
    t = t.replace(/\./g, '').replace(',', '.');        // comma = decimal, dot = thousands
  } else if (/\.\d{2}$/.test(t) && t.split('.').length === 2) {
    /* dot is the decimal separator, e.g. "5.00" -> keep */
  } else {
    t = t.replace(/\./g, '');                          // dot(s) = thousands separator
  }
  const n = parseFloat(t);
  return isNaN(n) ? null : n;
}

function registerVanaf(test, expect, { comp, url, selectors, min = 50 }) {
  let cached = null;
  async function read(page) {
    if (cached) return cached;
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await acceptCookies(page);
    await page.waitForTimeout(900);
    let price = null;
    for (const sel of selectors) {
      // pak de eerste paar matches; kies de laagste plausibele prijs (>= min)
      const raws = await page.locator(sel).allTextContents().catch(() => []);
      const cands = raws.map(normalizePrice).filter(Boolean)
        .map(p => ({ p, n: euroToNumber(p) }))
        .filter(x => x.n != null && x.n >= min)
        .sort((a, b) => a.n - b.n);
      if (cands.length) { price = cands[0].p; break; }
    }
    cached = price ? `Vanaf ${price}` : 'n.v.t.';
    return cached;
  }
  for (const [naam] of Object.entries(SIZES)) {
    test(`${comp} – ${naam} (vanaf-prijs)`, async ({ page }) => {
      let label = 'n.v.t.';
      try { label = await read(page); }
      catch (e) { console.log(`${comp} ${naam}: ${e.message.split('\n')[0]}`); }
      recordPrice(comp, naam, label);
      expect(label.length).toBeGreaterThan(0);
    });
  }
}

function registerLabel(test, { comp, label }) {
  for (const [naam] of Object.entries(SIZES)) {
    test(`${comp} – ${naam} (${label})`, async () => {
      recordPrice(comp, naam, label);
    });
  }
}

module.exports = { registerVanaf, registerLabel };
