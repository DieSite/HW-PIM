/**
 * horrenmax.nl – Plissé (schuif) hordeur op maat  ✅ per-maat (intermitterend)
 *
 * Shopify + DPO (Itoris Dynamic Product Options), geladen vanaf node1.itoris.com.
 * LET OP: maatvelden in CENTIMETERS (mm/10; max 210 breed / 300 hoog). Bij een
 * geldige cm-maat herberekent DPO en update .s1pr.f8pr-price .regular (huidige
 * prijs; .compare = van-prijs).
 *
 * KANTTEKENING: horrenmax heeft bot-bescherming; de externe DPO-bundel laadt
 * niet altijd in een headless run. We herladen daarom de pagina tot de
 * DPO-velden verschijnen (meerdere pogingen). Lukt het niet, dan n.v.t.
 *
 * Eén product (schuifhordeur) dat op breedte/hoogte geprijsd wordt.
 * Velden: options[1001]=montage, options[1002]=breedte cm, options[1003]=hoogte cm.
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');
const { acceptCookies, normalizePrice } = require('./helpers');

const COMP = 'horrenmax.nl';
const URL = 'https://horrenmax.nl/products/plisse-schuif-hordeur-op-maat-z35';

async function leesPrijs(page, wcm, hcm) {
  await page.evaluate(({ w, h }) => {
    const fire = el => ['input', 'change', 'keyup', 'blur'].forEach(ev => el.dispatchEvent(new Event(ev, { bubbles: true })));
    const r = document.querySelector('input[name="options[1001]"][value="10002"]'); if (r) { r.checked = true; fire(r); }
    const wi = document.querySelector('input[name="options[1002]"]'), hi = document.querySelector('input[name="options[1003]"]');
    if (wi) { wi.value = ''; fire(wi); wi.value = String(w); fire(wi); }
    if (hi) { hi.value = ''; fire(hi); hi.value = String(h); fire(hi); }
  }, { w: wcm, h: hcm });
  await page.waitForTimeout(2800);
  const raw = await page.locator('.s1pr.f8pr-price .regular').first().textContent().catch(() => null);
  const m = (raw || '').match(/€\s*[\d.]+,\d{2}/);
  return m ? normalizePrice(m[0]) : null;
}

for (const [naam, { breedte, hoogte }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ page }) => {
    const wcm = Math.round(breedte / 10), hcm = Math.round(hoogte / 10);
    let prijs = null;
    // max 2 snelle pogingen zodat een geblokkeerde DPO-load de 60s test-timeout
    // niet overschrijdt (anders n.v.t.). Bij geladen DPO wel de echte prijs.
    for (let attempt = 0; attempt < 2 && !prijs; attempt++) {
      try {
        if (attempt > 0) await page.waitForTimeout(2000);
        await page.goto(URL, { waitUntil: 'domcontentloaded', timeout: 20000 });
        await acceptCookies(page);
        // wacht tot de externe (bot-beschermde) DPO-velden geladen zijn
        await page.waitForSelector('input[name="options[1002]"]', { timeout: 8000 });
        await page.waitForTimeout(800);
        prijs = await leesPrijs(page, wcm, hcm);
        if (!prijs) prijs = await leesPrijs(page, wcm, hcm); // 2e poging zelfde load
      } catch (e) {
        console.log(`${COMP} ${naam} poging ${attempt}: ${e.message.split('\n')[0]}`);
      }
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
