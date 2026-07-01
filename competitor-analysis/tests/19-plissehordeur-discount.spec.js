/**
 * plissehordeur-discount.nl – Plissé hordeur  (vaste productprijs)
 *
 * De productpagina toont een vaste prijs (.Price, bv. "267,-", van .TextFromPrice
 * "356,-"). De MeasureWidth/MeasureHeight-velden zijn enkel een maatadvies-tool
 * en wijzigen de prijs NIET. Het is dus een vaste prijs per deurtype, geen
 * per-mm berekening. We lezen de echte .Price live in.
 *
 * URL enkel  : /product/plisse-hordeur/
 * URL dubbel : /product/dubbele-plisse-hordeur/
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');
const { acceptCookies, normalizePrice } = require('./helpers');

const COMP = 'plissehordeur-discount.nl';
const URLS = {
  enkel:  'https://www.plissehordeur-discount.nl/product/plisse-hordeur/',
  dubbel: 'https://www.plissehordeur-discount.nl/product/dubbele-plisse-hordeur/',
};

const cache = {};
async function prijs(page, type) {
  if (cache[type] !== undefined) return cache[type];
  await page.goto(URLS[type], { waitUntil: 'domcontentloaded', timeout: 30000 });
  await acceptCookies(page);
  await page.waitForTimeout(700);
  // verkoopprijs = laagste .Price (de .TextFromPrice van-prijs is hoger)
  const best = await page.evaluate(() => {
    const num = s => { const m = (s||'').match(/(\d[\d.]*)\s*,(-|\d{2})/); if(!m) return 0; return parseFloat(m[1].replace(/\./g,'') + '.' + (m[2]==='-'?'00':m[2])); };
    let lo = Infinity;
    for (const el of document.querySelectorAll('.Price')) { const v = num(el.textContent); if (v >= 50 && v < lo) lo = v; }
    return lo === Infinity ? 0 : lo;
  });
  const p = best > 0 ? `€ ${best.toFixed(2).replace('.', ',')}` : null;
  cache[type] = p;
  return p;
}

for (const [naam, { type }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (vaste prijs ${type})`, async ({ page }) => {
    let p = null;
    try { p = await prijs(page, type); }
    catch (e) { console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`); }
    recordPrice(COMP, naam, p ?? 'n.v.t.');
    expect(p ?? 'n.v.t.').toBeTruthy();
  });
}
