/**
 * creon-kozijnen.nl – Plissé hordeur op maat  ✅ per-maat
 *
 * Lightspeed-productpagina met optie-velden die de prijs via AJAX
 * (/product/price) herberekenen. BELANGRIJK: de herberekening triggert op
 * KEYUP, dus breedte/hoogte met echte toetsaanslagen invoeren (pressSequentially);
 * een .fill() laat de prijs op de basisprijs €259,99 staan. De selects zijn
 * custom-gestyled (native <select> verborgen) → via JS zetten + 'change' firen.
 *
 * Velden: select.plisse_verdeling (Enkel/Dubbel), input.plisse_20_width/height
 *         (mm), select.plisse_20_color (RAL 9010), select.plisse_20_color_net (Zwart)
 * Prijs : span#price
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');
const { acceptCookies, normalizePrice } = require('./helpers');

const COMP = 'creon-kozijnen.nl';
const URL = 'https://www.creon-kozijnen.nl/horren/plisse-hordeur';

async function haalPrijs(page, breedte, hoogte, dubbel, state) {
  await page.evaluate((dubbel) => {
    const setSel = (sel, reSrc) => {
      const s = document.querySelector(sel); if (!s) return;
      const re = new RegExp(reSrc, 'i');
      for (const o of s.options) if (re.test(o.text)) { s.value = o.value; s.dispatchEvent(new Event('change', { bubbles: true })); break; }
    };
    setSel('select.plisse_verdeling', dubbel ? 'dubbel' : 'enkel');
    setSel('select.plisse_20_color', '9010');
    setSel('select.plisse_20_color_net', 'zwart');
  }, dubbel);
  await page.waitForTimeout(500);

  const w = page.locator('input.plisse_20_width').first();
  const h = page.locator('input.plisse_20_height').first();
  await w.click(); await w.fill(''); await w.pressSequentially(String(breedte), { delay: 45 });
  await h.click(); await h.fill(''); await h.pressSequentially(String(hoogte), { delay: 45 });
  await h.press('Tab');
  // poll tot de prijs-AJAX geantwoord heeft (max 12s) i.p.v. vaste sleep
  for (let i = 0; i < 24 && state.gross == null; i++) await page.waitForTimeout(500);
  await page.waitForTimeout(800); // korte nazak: laat een evt. tweede respons landen
  if (state.gross != null) return `€ ${state.gross.toFixed(2).replace('.', ',')}`;
  return normalizePrice(await page.locator('#price').first().textContent().catch(() => null));
}

for (const [naam, { breedte, hoogte, type }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ page }) => {
    let prijs = null;
    // vang de laatste /product/price respons (priceGross = consumentenprijs)
    const state = { gross: null };
    page.on('response', async (resp) => {
      if (!/\/product\/price/.test(resp.url())) return;
      try { const j = await resp.json(); if (j && typeof j.priceGross === 'number') state.gross = j.priceGross; } catch {}
    });
    try {
      await page.goto(URL, { waitUntil: 'domcontentloaded', timeout: 30000 });
      await acceptCookies(page);
      await page.waitForTimeout(500);
      prijs = await haalPrijs(page, breedte, hoogte, type === 'dubbel', state);
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
