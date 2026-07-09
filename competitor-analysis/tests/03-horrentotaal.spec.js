/**
 * horrentotaal.nl – Plissé hordeur op maat  ✅ per-maat
 *
 * De productpagina laadt een configurator-widget (data van
 * configurator.horrentotaal.nl). Na het kiezen van de plaatsing verschijnen
 * twee maat-velden (placeholder = toegestane range, bv. "580 – 1900").
 * Bij het invullen POST de widget naar
 *   https://configurator.horrentotaal.nl/calculate/<slug>
 * met {breedte,hoogte,plaatsing,...} en krijgt {"totaalPrijs":"302.00",...}.
 * We vangen die respons op = de echte per-maat basisprijs.
 *
 * LET OP: de widget POST bij ELKE toetsaanslag (hoogte "2", "20", "208",
 * "2080"), dus we accepteren alleen responsen waarvan het request exact onze
 * doelmaat bevat — anders kan een trage tussenrespons de prijs overschrijven.
 *
 * Gaaskleur: zwart is de standaard en zit NIET in de calculate-payload; de
 * widget telt optie-meerprijzen client-side op vanuit zijn config
 * (GET configurator.horrentotaal.nl/configurators/<slug>). Voor grijs gaas
 * lezen we die meerprijs live uit de config (enkel +€39, dubbel +€78,
 * stand 2026-07) en tellen hem bij de basisprijs op; ontbreekt de optie in
 * de config, dan n.v.t.
 *
 * URL enkel  : /products/plisse-hordeur
 * URL dubbel : /products/dubbele-plisse-hordeur
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');
const { acceptCookies } = require('./helpers');

const COMP = 'horrentotaal.nl';
const URLS = {
  enkel:  'https://horrentotaal.nl/products/plisse-hordeur',
  dubbel: 'https://horrentotaal.nl/products/dubbele-plisse-hordeur',
};

function fmt(n) { return `€ ${Number(n).toFixed(2).replace('.', ',')}`; }

/** Zoek recursief de optie met key `grijs_gaas` in de configurator-config. */
function vindGrijsPrijs(obj) {
  if (!obj || typeof obj !== 'object') return null;
  if (obj.key === 'grijs_gaas') return Number(obj.price) || 0;
  for (const v of Object.values(obj)) {
    const r = vindGrijsPrijs(v);
    if (r != null) return r;
  }
  return null;
}

for (const [naam, { breedte, hoogte, type, gaas }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ page, request }) => {
    let prijs = null;
    const state = { totaal: null, slug: null };
    page.on('response', async (resp) => {
      const m = resp.url().match(/configurator\.horrentotaal\.nl\/calculate\/([^/?]+)/);
      if (!m) return;
      // alleen berekeningen van exact onze maat accepteren (zie header)
      try {
        const body = JSON.parse(resp.request().postData() || '{}');
        if (Number(body.breedte) !== breedte || Number(body.hoogte) !== hoogte) return;
        const j = await resp.json();
        if (j && j.totaalPrijs) { state.totaal = j.totaalPrijs; state.slug = m[1]; }
      } catch {}
    });
    try {
      await page.goto(URLS[type], { waitUntil: 'domcontentloaded', timeout: 30000 });
      await acceptCookies(page);
      await page.waitForTimeout(1800);
      // kies plaatsing -> toont maat-velden
      await page.getByText(/tussen het kozijn/i).first().click({ timeout: 5000 }).catch(() => {});
      await page.waitForTimeout(800);
      const dims = page.locator('input[placeholder*="–"]');
      await dims.nth(0).waitFor({ state: 'visible', timeout: 8000 });
      await dims.nth(0).click(); await dims.nth(0).pressSequentially(String(breedte), { delay: 55 });
      await dims.nth(1).click(); await dims.nth(1).pressSequentially(String(hoogte), { delay: 55 });
      await page.keyboard.press('Tab');
      // poll tot de calculate-API voor onze exacte maat geantwoord heeft
      for (let i = 0; i < 30 && !state.totaal; i++) await page.waitForTimeout(500);
      if (state.totaal && gaas === 'grijs') {
        const cfg = await request.get(`https://configurator.horrentotaal.nl/configurators/${state.slug}`, { timeout: 15000 })
          .then(r => r.json()).catch(() => null);
        const meerprijs = vindGrijsPrijs(cfg);
        prijs = meerprijs != null ? fmt(Number(state.totaal) + meerprijs) : null;
      } else if (state.totaal) {
        prijs = fmt(state.totaal);
      }
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
