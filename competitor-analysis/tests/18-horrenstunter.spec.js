/**
 * horrenstunter.nl – Originele plissé hordeur op maat  ✅ per-maat
 *
 * WooCommerce + Gravity Forms maatwerk-formulier (form 134, identiek op beide
 * pagina's). De maat-velden zijn conditioneel en deels readonly, dus we zetten
 * de waarden via JS en triggeren de GF-berekening. De prijs = productbasis
 * (€216) + de maat-meerprijs (.ginput_total). Banded per maat.
 *
 * BELANGRIJK (uitgezocht 2026-07-09): radio input_93 is de keuze
 * "Enkele deur / Dubbele deur" (géén montagekeuze), en de maat-meerprijs
 * hangt aan het enkel-breedteveld input_97 (max 1900). Na de keuze
 * "Dubbele deur" toont het formulier GEEN invulbare maatvelden meer (alleen
 * readonly totalen) — de dubbele variant is dus niet te automatiseren.
 * Daarom passen we voor dubbele deuren het dekkingsprincipe toe (zelfde idee
 * als praxis/gamma/horrenbouw): een opening tot 1900 mm breed wordt gedekt
 * door één enkele deur van die breedte -> die echte enkel-prijs noteren we;
 * bredere dubbele openingen -> eerlijk n.v.t.
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
  // "Enkele deur" kiezen (dekkingsprincipe, zie header) + maat via JS in het
  // enkel-breedteveld input_97 (300–1900) en hoogteveld input_70 (1791–2750).
  // Buiten het min/max-bereik van een veld = niet leverbaar -> -1 (n.v.t.);
  // de JS-injectie zou anders een niet-bestaande bandprijs berekenen.
  const ok = await page.evaluate(({ b, h }) => {
    const fire = el => ['input', 'change', 'keyup', 'blur'].forEach(ev => el.dispatchEvent(new Event(ev, { bubbles: true })));
    const radio = document.querySelector('input[name="input_93"][value="Enkele deur"], input[name="input_93"]');
    if (radio) { radio.checked = true; fire(radio); }
    let set = 0;
    for (const [naam, val] of [['input_97', b], ['input_70', h]]) {
      const el = document.querySelector(`input[name="${naam}"]`);
      if (!el || !el.min || !el.max) continue;
      if (val < +el.min || val > +el.max) return -1;
      el.value = String(val); fire(el); set++;
    }
    const form = document.querySelector('form[id^="gform_"]');
    const fid = form ? +(form.id.match(/\d+/) || [0])[0] : 0;
    try { if (window.gformCalculateTotalPrice && fid) window.gformCalculateTotalPrice(fid); } catch (e) {}
    return set;
  }, { b: breedte, h: hoogte });
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
    // Dubbele openingen breder dan de grootste enkele deur (1900 mm) zijn
    // niet te prijzen: het dubbel-formulier toont geen maatvelden (zie header).
    if (gaas === 'grijs' || (type === 'dubbel' && breedte > 1900)) {
      recordPrice(COMP, naam, 'n.v.t.');
      return;
    }
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
