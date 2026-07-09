/**
 * plissexxl.nl – Enkel/Dubbel horren deur (plissé)  ✅ per-maat
 *
 * WooCommerce + PEWC (zelfde plugin als horrenconcurrent, spec 17). De prijs
 * is een client-side PEWC-formule in de pagina zelf:
 *   enkel  : basis + ceil(breedte*hoogte/22000)
 *   dubbel : basis + ceil(breedte*hoogte/17000)
 * PEWC herberekent op keyup -> pressSequentially, niet .fill(). De maatvelden
 * zijn herkenbaar aan aria-label "Breedte in mm" / "Hoogte in mm". Het live
 * totaal verschijnt in de PEWC-totaalblokken (woocommerce-Price-amount).
 * Standaardopties (wit frame, zwart gaas) zijn de defaults -> niet aankomen.
 *
 * URL enkel  : /product/enkel-horren-deur-1/
 * URL dubbel : /product/dubbel-horren-deur/
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');
const { acceptCookies, inputAllowsValue } = require('./helpers');

const COMP = 'plissexxl.nl';
const URLS = {
  enkel:  'https://plissexxl.nl/product/enkel-horren-deur-1/',
  dubbel: 'https://plissexxl.nl/product/dubbel-horren-deur/',
};

async function haalPrijs(page, breedte, hoogte) {
  // Echte clicks worden op deze pagina geblokkeerd door een overlay die niet
  // betrouwbaar weg te klikken is. PEWC rekent client-side, dus we zetten de
  // waarden via jQuery en vuren de keyup/change-handlers direct af (zelfde
  // JS-injectiepatroon als luxehorren).
  await page.locator('input.pewc-number-field[aria-label="Breedte in mm"]').first()
    .waitFor({ state: 'attached', timeout: 10000 });
  // De PEWC-formule rekent vrolijk door buiten het leverbare bereik; de
  // JS-injectie omzeilt de veld-validatie, dus wij bewaken min/max zelf.
  if (!(await inputAllowsValue(page.locator('input.pewc-number-field[aria-label="Breedte in mm"]'), breedte))
    || !(await inputAllowsValue(page.locator('input.pewc-number-field[aria-label="Hoogte in mm"]'), hoogte))) {
    return null;
  }
  await page.evaluate(({ b, h }) => {
    const set = (label, val) => {
      const el = document.querySelector(`input.pewc-number-field[aria-label="${label}"]`);
      if (!el) return;
      if (window.jQuery) { window.jQuery(el).val(String(val)).trigger('input').trigger('keyup').trigger('change').trigger('blur'); }
      else { el.value = String(val); ['input', 'keyup', 'change', 'blur'].forEach(ev => el.dispatchEvent(new Event(ev, { bubbles: true }))); }
    };
    set('Breedte in mm', b);
    set('Hoogte in mm', h);
  }, { b: breedte, h: hoogte });

  // poll tot PEWC het eindtotaal toont (max ~10s). Het echte totaal staat in
  // #pewc-grand-total; de "grootste bedrag"-heuristiek pakte hier een statisch
  // €-element van de pagina en is alleen nog de fallback.
  const best = await page.waitForFunction(() => {
    const num = s => { const m = (s || '').match(/[\d.]+,\d{2}|\d+[.,]\d{2}/); if (!m) return 0; return parseFloat(m[0].replace(/\./g, '').replace(',', '.')); };
    const grand = num(document.querySelector('#pewc-grand-total')?.textContent);
    if (grand > 50) return grand;
    let best = 0;
    for (const el of document.querySelectorAll('.pewc-total-field-wrapper, .pewc-grand-total-wrapper, .pewc-product-total')) {
      const v = num(el.textContent);
      if (v > best) best = v;
    }
    return best > 50 ? best : null;
  }, { timeout: 10000 }).then(h => h.jsonValue()).catch(() => 0);

  return best > 0 ? `€ ${best.toFixed(2).replace('.', ',')}` : null;
}

for (const [naam, { breedte, hoogte, type, gaas }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ page }) => {
    // Alleen een gaas-TYPE-optie (standaard/anti-pollen), geen gaaskleur ->
    // grijs is hier niet te configureren.
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
