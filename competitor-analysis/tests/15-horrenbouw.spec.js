/**
 * horrenbouw.nl – Plissé hordeur op maat  ✅ per-maat (breedte-banden)
 *
 * Horrenbouw verkoopt de plissé hordeur in vaste BREEDTE-banden, elk met een
 * eigen prijs:  "Plissé hordeur tot 96 cm breed" €335, "tot 110 cm" €385,
 * "tot 130 cm" €395, "tot 160 cm" €455, "tot 190 cm" €495.
 * We lezen die banden live van de categoriepagina en kiezen per maat de
 * kleinste band die de breedte (mm/10 = cm) dekt. Echte per-maat prijs.
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');
const { acceptCookies } = require('./helpers');

const COMP = 'horrenbouw.nl';
const URL = 'https://www.horrenbouw.nl/webshop/hordeuren/plisse-hordeur/';

let banden = null;  // [{maxCm, price}] gesorteerd
async function getBanden(page) {
  if (banden) return banden;
  await page.goto(URL, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await acceptCookies(page);
  await page.waitForTimeout(1500);
  banden = await page.evaluate(() => {
    const norm = s => (s || '').replace(/\s+/g, ' ').trim();
    const out = [];
    // elk product met "tot N cm breed" + een € prijs in dezelfde card
    for (const el of document.querySelectorAll('a, h2, h3, li, div, article')) {
      const t = norm(el.textContent);
      const mm = t.match(/tot\s+(\d+)\s*cm\s*breed/i);
      if (!mm) continue;
      const card = el.closest('li, .product, [class*=product], article, div') || el;
      const pm = norm(card.textContent).match(/€\s*([\d.]+),(\d{2})/);
      if (pm) out.push({ maxCm: +mm[1], price: parseFloat(pm[1].replace(/\./g, '') + '.' + pm[2]) });
    }
    // dedup op maxCm, hou laagste prijs
    const byCm = {};
    for (const o of out) if (byCm[o.maxCm] == null || o.price < byCm[o.maxCm]) byCm[o.maxCm] = o.price;
    return Object.entries(byCm).map(([maxCm, price]) => ({ maxCm: +maxCm, price })).sort((a, b) => a.maxCm - b.maxCm);
  });
  return banden;
}

for (const [naam, { breedte }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}mm breed)`, async ({ page }) => {
    let prijs = null;
    try {
      const b = await getBanden(page);
      const cm = breedte / 10;
      const band = b.find(x => cm <= x.maxCm) || b[b.length - 1];
      if (band) prijs = `€ ${band.price.toFixed(2).replace('.', ',')}`;
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
