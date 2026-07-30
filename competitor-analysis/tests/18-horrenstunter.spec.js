/**
 * horrenstunter.nl – Originele plissé hordeur op maat  ✅ per-maat, enkel én dubbel
 *
 * WooCommerce + Gravity Forms maatwerk-formulier (form 134, identiek op beide
 * pagina's). De maat-velden zijn conditioneel en deels readonly, dus we zetten
 * de waarden via JS en triggeren de GF-berekening. De prijs = productbasis
 * (€216) + de maat-meerprijs (.ginput_total). Banded per maat.
 *
 * Deurkeuze staat in radio `input_93` ("Enkele deur" / "Dubbele deur", géén
 * montagekeuze) en bepaalt WELK breedteveld telt:
 *   - enkel : `input_97`  (300 – 1900 mm)
 *   - dubbel: `input_109` (300 – 3800 mm)
 * Hoogte is voor beide `input_70` (1791 – 2750 mm).
 *
 * GECORRIGEERD 2026-07-30: tot deze datum ging de spec ervan uit dat de
 * dubbele variant "geen invulbare maatvelden" toont en paste hij het
 * dekkingsprincipe toe — de prijs van één ENKELE deur voor dubbele openingen
 * t/m 1900 mm, daarboven n.v.t. Dat klopte niet (of niet meer): `input_109` is
 * het echte dubbel-breedteveld, dus dubbele deuren worden nu gewoon geprijsd,
 * tot 3800 mm.
 *
 * Buiten het min/max-bereik van een veld = niet leverbaar -> n.v.t.; de
 * JS-injectie zou anders een niet-bestaande bandprijs berekenen.
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
const DEURKEUZE = { enkel: 'Enkele deur', dubbel: 'Dubbele deur' };
const BREEDTEVELD = { enkel: 'input_97', dubbel: 'input_109' };

async function haalPrijs(page, breedte, hoogte, type) {
  const ok = await page.evaluate(({ b, h, keuze, breedteVeld }) => {
    const fire = el => ['input', 'change', 'keyup', 'blur'].forEach(ev => el.dispatchEvent(new Event(ev, { bubbles: true })));
    const radio = document.querySelector(`input[name="input_93"][value="${keuze}"]`);
    if (radio) { radio.checked = true; fire(radio); }
    let set = 0;
    for (const [naam, val] of [[breedteVeld, b], ['input_70', h]]) {
      const el = document.querySelector(`input[name="${naam}"]`);
      if (!el || !el.min || !el.max) continue;
      if (val < +el.min || val > +el.max) return -1;
      el.value = String(val); fire(el); set++;
    }
    if (set < 2) return -1; // een ontbrekend maatveld nooit stilzwijgend negeren
    const form = document.querySelector('form[id^="gform_"]');
    const fid = form ? +(form.id.match(/\d+/) || [0])[0] : 0;
    try { if (window.gformCalculateTotalPrice && fid) window.gformCalculateTotalPrice(fid); } catch (e) {}
    return set;
  }, { b: breedte, h: hoogte, keuze: DEURKEUZE[type], breedteVeld: BREEDTEVELD[type] });
  if (ok === -1) return null;

  // GF toont het echte totaal in .formattedTotalPrice (= basis + maat-meerprijs).
  // Poll i.p.v. vaste sleep: de GF-herberekening is onder load trager dan 2,5s.
  const raw = await page.waitForFunction(() => {
    const t = document.querySelector('.formattedTotalPrice')?.textContent || '';
    return /[\d.]+,\d{2}/.test(t) ? t : null;
  }, { timeout: 12000 }).then(h => h.jsonValue()).catch(() => null);
  const m = (raw || '').match(/[\d.]+,\d{2}/);
  return m ? `€ ${m[0]}` : null;
}

for (const [naam, { breedte, hoogte, type, gaas }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ page }) => {
    // "Het geplisseerde gaas is alleen leverbaar in zwart" -> grijs n.v.t.
    if (gaas === 'grijs') {
      recordPrice(COMP, naam, 'n.v.t.');
      return;
    }
    let prijs = null;
    try {
      await page.goto(URLS[type], { waitUntil: 'domcontentloaded', timeout: 30000 });
      await acceptCookies(page);
      await page.waitForTimeout(1200);
      prijs = await haalPrijs(page, breedte, hoogte, type);
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
