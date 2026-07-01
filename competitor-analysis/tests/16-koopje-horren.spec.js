/**
 * koopje-horren.com – Bruynzeel plissé hordeur s900 "op maat"
 *
 * "Op maat" = op bestelling gemaakt, maar de prijs is VAST per deurtype (er is
 * geen breedte/hoogte-veld; alleen een montagekeuze In/Op de dag). Dus de
 * getoonde prijs IS de echte prijs: enkel €179, dubbel €349.
 *
 * URL enkel  : /bruynzeel-plisse-hordeur-s900-op-maat
 * URL dubbel : /bruynzeel-dubbele-plisse-hordeur-s900-op-maat
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');
const { acceptCookies, normalizePrice } = require('./helpers');

const COMP = 'koopje-horren.com';
const URLS = {
  enkel:  'https://www.koopje-horren.com/bruynzeel-plisse-hordeur-s900-op-maat',
  dubbel: 'https://www.koopje-horren.com/bruynzeel-dubbele-plisse-hordeur-s900-op-maat',
};

const cache = {};
async function prijs(page, type) {
  if (cache[type] !== undefined) return cache[type];
  await page.goto(URLS[type], { waitUntil: 'domcontentloaded', timeout: 30000 });
  await acceptCookies(page);
  await page.waitForTimeout(700);
  const raw = await page.locator('.price-box .price, .price-final_price .price, [class*="price-final"] .price').first().textContent().catch(() => null);
  cache[type] = normalizePrice(raw);
  return cache[type];
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
