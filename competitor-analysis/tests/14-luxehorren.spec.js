/**
 * luxehorren.nl – Plissé hordeur op maat (standaardmodel)  ✅ per-maat
 *
 * WooCommerce + TM Extra Product Options (tmcp). De maatvelden zitten in een
 * EPO-formulier dat pas "opent" via de Samenstellen-knop (een Betheme-div die
 * niet echt klikbaar is). Oplossing: zet de waarden via JS en vuur de events,
 * dan herberekent TM EPO en verschijnt "Totaal prijs € …".
 *
 * Velden: tmcp_textfield_0 = Totale breedte (mm), tmcp_textfield_1 = Totale
 *         hoogte (mm), tmcp_radio_2 = framekleur (RAL 9010).
 * URL enkel  : /horren-bestellen/standaard-plisse-hordeur/
 * URL dubbel : /horren-bestellen/dubbele-plisse-hordeur/
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');
const { acceptCookies } = require('./helpers');

const COMP = 'luxehorren.nl';
const URLS = {
  enkel:  'https://www.luxehorren.nl/horren-bestellen/standaard-plisse-hordeur/',
  dubbel: 'https://www.luxehorren.nl/horren-bestellen/dubbele-plisse-hordeur/',
};

async function haalPrijs(page, breedte, hoogte, gaas) {
  const gaasOk = await page.evaluate(({ b, h, gaas }) => {
    const fire = el => ['input', 'change', 'keyup', 'blur'].forEach(ev => el.dispatchEvent(new Event(ev, { bubbles: true })));
    const bf = document.querySelector('input[name=tmcp_textfield_0]');
    const hf = document.querySelector('input[name=tmcp_textfield_1]');
    if (bf) { bf.value = String(b); fire(bf); }
    if (hf) { hf.value = String(h); fire(hf); }
    const ral = [...document.querySelectorAll('input[name=tmcp_radio_2]')].find(x => /9010/.test(x.closest('li,div')?.textContent || ''));
    if (ral) { ral.checked = true; fire(ral); }
    // Soort gaas = tmcp_radio_4: "Standaard (zwart)" / "Grijs" (grijs tot max 260 cm hoog)
    const wil = gaas === 'grijs' ? /grijs/i : /zwart/i;
    const gr = [...document.querySelectorAll('input[name=tmcp_radio_4]')].find(x => wil.test(x.closest('li,div')?.textContent || ''));
    if (gr) { gr.checked = true; fire(gr); }
    try { if (window.jQuery) { jQuery('body').trigger('tm-epo-update'); jQuery(document).trigger('tc_update_totals'); } } catch (e) {}
    return !!gr;
  }, { b: breedte, h: hoogte, gaas });
  if (!gaasOk && gaas !== 'zwart') return null;          // gaaskleur niet kiesbaar -> n.v.t.

  // Poll tot TM EPO een totaal toont (max 12s) i.p.v. vaste sleep.
  await page.waitForFunction(() => {
    const els = document.querySelectorAll('[class*=tm-final-totals], [class*=tm-totals], [class*=tc-totals]');
    for (const el of els) if (/€\s*[\d.]+/.test((el.textContent || ''))) return true;
    return /Totaal\s*prijs\s*€\s*[\d.,]+/i.test(document.body.innerText);
  }, { timeout: 12000 }).catch(() => {});

  // "Totaal prijs € …" staat in een tm-final-totals element
  const raw = await page.evaluate(() => {
    const norm = s => (s || '').replace(/\s+/g, ' ').trim();
    // zoek element dat "Totaal" + € bevat
    let best = null;
    for (const el of document.querySelectorAll('[class*=tm-final-totals], [class*=tm-totals], [class*=tc-totals]')) {
      const t = norm(el.textContent);
      if (/€\s*[\d.]+/.test(t)) best = t;
    }
    if (!best) {
      const m = document.body.innerText.match(/Totaal\s*prijs\s*€\s*[\d.,]+/i);
      best = m ? m[0] : null;
    }
    return best;
  });
  const m = (raw || '').match(/([\d.]+)[.,](\d{2})/);
  if (!m) return null;
  const val = parseFloat(m[1].replace(/\./g, '') + '.' + m[2]);
  return val > 0 ? `€ ${val.toFixed(2).replace('.', ',')}` : null;
}

for (const [naam, { breedte, hoogte, type, gaas }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ page }) => {
    let prijs = null;
    try {
      await page.goto(URLS[type], { waitUntil: 'domcontentloaded', timeout: 30000 });
      await acceptCookies(page);
      await page.waitForTimeout(1500);
      prijs = await haalPrijs(page, breedte, hoogte, gaas);
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
