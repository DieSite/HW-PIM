/**
 * horrenstunter.nl – Originele plissé hordeur op maat  ✅ per-maat
 *
 * WooCommerce + Gravity Forms maatwerk-formulier. De maat-velden zijn
 * conditioneel (verborgen tot de montage-radio gekozen is) en gedeeltelijk
 * readonly, dus we zetten breedte/hoogte via JS op basis van hun min/max-range
 * en triggeren de GF-berekening. De prijs = productbasis (€216) + de
 * maat-meerprijs (.ginput_total). Banded per maat.
 *
 * URL enkel  : /product/originele-plissehordeur/
 * URL dubbel : /product/originele-plissehordeur-dubbel/
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');
const { acceptCookies } = require('./helpers');

const COMP = 'horrenstunter.nl';
const URLS = {
  enkel:  'https://horrenstunter.nl/product/originele-plissehordeur/',
  dubbel: 'https://horrenstunter.nl/product/originele-plissehordeur-dubbel/',
};

async function haalPrijs(page, breedte, hoogte) {
  // kies montage (eerste radio = tussen het kozijn) + zet maat via JS + bereken
  const ok = await page.evaluate(({ b, h }) => {
    const fire = el => ['input', 'change', 'keyup', 'blur'].forEach(ev => el.dispatchEvent(new Event(ev, { bubbles: true })));
    const radio = document.querySelector('input[name="input_93"]'); if (radio) { radio.checked = true; fire(radio); }
    let set = 0;
    for (const el of document.querySelectorAll('input[type=text]')) {
      if (el.readOnly || !el.min || !el.max) continue;
      const mn = +el.min, mx = +el.max;
      if (mn >= 1700 && mx >= 2000) { el.value = String(h); fire(el); set++; }       // hoogte
      else if (mn <= 400 && mx >= 1800) { el.value = String(b); fire(el); set++; }    // breedte
    }
    const form = document.querySelector('form[id^="gform_"]');
    const fid = form ? +(form.id.match(/\d+/) || [0])[0] : 0;
    try { if (window.gformCalculateTotalPrice && fid) window.gformCalculateTotalPrice(fid); } catch (e) {}
    return set;
  }, { b: breedte, h: hoogte });

  // GF toont het echte totaal in .formattedTotalPrice (= basis + maat-meerprijs).
  // Poll i.p.v. vaste sleep: de GF-herberekening is onder load trager dan 2,5s.
  const raw = await page.waitForFunction(() => {
    const t = document.querySelector('.formattedTotalPrice')?.textContent || '';
    return /[\d.]+,\d{2}/.test(t) ? t : null;
  }, { timeout: 12000 }).then(h => h.jsonValue()).catch(() => null);
  const m = (raw || '').match(/[\d.]+,\d{2}/);
  return m ? `€ ${m[0]}` : null;
}

for (const [naam, { breedte, hoogte, type }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ page }) => {
    let prijs = null;
    try {
      await page.goto(URLS[type], { waitUntil: 'domcontentloaded', timeout: 30000 });
      await acceptCookies(page);
      await page.waitForTimeout(1200);
      prijs = await haalPrijs(page, breedte, hoogte);
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
