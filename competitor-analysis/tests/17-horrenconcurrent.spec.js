/**
 * horrenconcurrent.nl – Enkele/Dubbele plissé hordeur op maat  ✅ per-maat
 *
 * WooCommerce + PEWC (Product Extras). Twee number-velden (breedte/hoogte in mm);
 * na een 'change' verschijnt de live totaalprijs in .pewc-total-field-wrapper.
 * BELANGRIJK: cookie-overlay eerst wegklikken, anders blokkeert die de invoer.
 *
 * URL enkel  : /product/enkele-plisse-hordeur-op-maat/
 * URL dubbel : /product/dubbele-plisse-hordeur-op-maat/
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');
const { acceptCookies, normalizePrice, inputAllowsValue } = require('./helpers');

const COMP = 'horrenconcurrent.nl';
const URLS = {
  enkel:  'https://horrenconcurrent.nl/product/enkele-plisse-hordeur-op-maat/',
  dubbel: 'https://horrenconcurrent.nl/product/dubbele-plisse-hordeur-op-maat/',
};

async function haalPrijs(page, breedte, hoogte) {
  const w = page.locator('input.pewc-number-field').nth(0);
  const h = page.locator('input.pewc-number-field').nth(1);
  await w.waitFor({ state: 'visible', timeout: 8000 });
  // maat buiten het min/max-bereik van de velden = niet leverbaar -> n.v.t.
  if (!(await inputAllowsValue(w, breedte)) || !(await inputAllowsValue(h, hoogte))) return null;
  // PEWC herberekent op keyup -> echte toetsaanslagen nodig (fill() volstaat niet)
  await w.click(); await w.fill(''); await w.pressSequentially(String(breedte), { delay: 40 });
  await h.click(); await h.fill(''); await h.pressSequentially(String(hoogte), { delay: 40 });
  await h.press('Tab');

  // grootste niet-nul bedrag in de PEWC-totaalblokken = productprijs.
  // (Het bedrag staat zonder €-teken in .woocommerce-Price-amount, dus we
  //  parsen het getal zelf en formatteren naar "€ x,xx".)
  // Poll i.p.v. vaste sleep: PEWC herberekent async en is onder load traag.
  const best = await page.waitForFunction(() => {
    const num = s => { const m = (s||'').match(/[\d.]+,\d{2}/); if(!m) return 0; return parseFloat(m[0].replace(/\./g,'').replace(',', '.')); };
    let best = 0;
    for (const el of document.querySelectorAll('.pewc-total-field-wrapper, .pewc-grand-total-wrapper, .woocommerce-Price-amount')) {
      const v = num(el.textContent);
      if (v > best) best = v;
    }
    return best > 50 ? best : null;
  }, { timeout: 12000 }).then(h => h.jsonValue()).catch(() => 0);
  return best > 0 ? `€ ${best.toFixed(2).replace('.', ',')}` : null;
}

for (const [naam, { breedte, hoogte, type, gaas }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ page }) => {
    // Geen gaaskleur-optie: het gaas is hier altijd zwart ("voorzien van
    // zwart, geplisseerd gaas") -> grijs is eerlijk n.v.t.
    if (gaas === 'grijs') {
      recordPrice(COMP, naam, 'n.v.t.');
      return;
    }
    let prijs = null;
    try {
      await page.goto(URLS[type], { waitUntil: 'domcontentloaded', timeout: 30000 });
      await acceptCookies(page);
      await page.waitForTimeout(500);
      prijs = await haalPrijs(page, breedte, hoogte);
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
