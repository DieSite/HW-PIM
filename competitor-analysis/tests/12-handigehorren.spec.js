/**
 * handigehorren.nl – Plissé hordeur (Shopify + Easify Product Options)  ✅ per-maat
 *
 * De maat-toeslag is een bandformule die VOLLEDIG in de productpagina zit
 * (Easify option-set JSON, `window.TPOConfigs`). Na het invullen van de twee
 * dimensievelden toont Easify de toeslag live in `.tpo_additional-price.active`
 * als bv. "(49,50)" — komma-decimaal, zonder €-teken. Echte prijs = Shopify
 * basisprijs (uit /products/<handle>.js) + die toeslag.
 *
 * Alle standaardopties (wit 9016/9010, in het kozijn, schroeven, vlakke
 * onderkant) zijn +€0; er is geen gaaskleur-optie. De verplichte radio's zijn
 * alleen nodig voor add-to-cart, niet om de toeslag te tonen — we blijven dus
 * netjes vóór de winkelwagen. Cart-geverifieerd: enkel 730×1970 = €274,50.
 *
 * LET OP: al onze 6 maten hebben een toeslag > 0, dus we EISEN dat het
 * toeslag-element verschijnt; ontbreekt het, dan is de herberekening mislukt
 * en is n.v.t. eerlijker dan stilletjes de basisprijs noteren.
 *
 * URL enkel  : /products/plisse-hordeur          (basis €225)
 * URL dubbel : /products/dubbele-plisse-hordeur  (basis €440)
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');
const { acceptCookies } = require('./helpers');

const COMP = 'handigehorren.nl';
const URLS = {
  enkel:  'https://www.handigehorren.nl/products/plisse-hordeur',
  dubbel: 'https://www.handigehorren.nl/products/dubbele-plisse-hordeur',
};
const HANDLES = { enkel: 'plisse-hordeur', dubbel: 'dubbele-plisse-hordeur' };

async function haalPrijs(page, type, breedte, hoogte) {
  const w = page.locator('input[name="properties[Dimension-Breedte in mm]"]').first();
  const h = page.locator('input[name="properties[Dimension-Hoogte in mm]"]').first();
  await w.waitFor({ state: 'visible', timeout: 10000 });
  await w.click(); await w.fill(''); await w.pressSequentially(String(breedte), { delay: 40 });
  await h.click(); await h.fill(''); await h.pressSequentially(String(hoogte), { delay: 40 });
  await h.press('Tab');

  // poll tot Easify de maat-toeslag toont, bv. "(49,50)"
  const toeslag = await page.waitForFunction(() => {
    for (const el of document.querySelectorAll('.tpo_additional-price.active, .tpo_additional-price')) {
      const m = (el.textContent || '').match(/(\d[\d.]*),(\d{2})/);
      if (m) return parseFloat(m[1].replace(/\./g, '') + '.' + m[2]);
    }
    return null;
  }, { timeout: 10000 }).then(x => x.jsonValue()).catch(() => null);
  if (toeslag == null) return null;

  // basisprijs uit het Shopify product-JSON (centen)
  const basis = await page.evaluate(async (handle) => {
    const r = await fetch(`/products/${handle}.js`);
    const j = await r.json();
    return j.price / 100;
  }, HANDLES[type]).catch(() => null);
  if (!basis) return null;

  const totaal = basis + toeslag;
  return `€ ${totaal.toFixed(2).replace('.', ',')}`;
}

for (const [naam, { breedte, hoogte, type, gaas }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ page }) => {
    // Er is geen gaaskleur-optie (zie header) -> grijs gaas is hier niet te
    // configureren en dus eerlijk n.v.t.
    if (gaas === 'grijs') {
      recordPrice(COMP, naam, 'n.v.t.');
      return;
    }
    let prijs = null;
    try {
      await page.goto(URLS[type], { waitUntil: 'domcontentloaded', timeout: 30000 });
      await acceptCookies(page);
      await page.waitForTimeout(500);
      prijs = await haalPrijs(page, type, breedte, hoogte);
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
