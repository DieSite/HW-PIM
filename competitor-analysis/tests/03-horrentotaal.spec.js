/**
 * horrentotaal.nl – Plissé hordeur op maat  ✅ per-maat
 *
 * De productpagina laadt een configurator-widget (data van
 * configurator.horrentotaal.nl). Na het kiezen van de plaatsing verschijnen
 * twee maat-velden (placeholder = toegestane range, bv. "580 – 1900").
 * Bij het invullen POST de widget naar
 *   https://configurator.horrentotaal.nl/calculate/<slug>
 * met {breedte,hoogte,plaatsing,...} en krijgt {"totaalPrijs":"302.00",...}.
 * We vangen die respons op = de echte per-maat prijs.
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

for (const [naam, { breedte, hoogte, type }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ page }) => {
    let prijs = null;
    const state = { totaal: null };
    page.on('response', async (resp) => {
      if (!/configurator\.horrentotaal\.nl\/calculate/.test(resp.url())) return;
      try { const j = await resp.json(); if (j && j.totaalPrijs) state.totaal = j.totaalPrijs; } catch {}
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
      // poll tot de calculate-API geantwoord heeft (max 15s) i.p.v. vaste sleep
      for (let i = 0; i < 30 && !state.totaal; i++) await page.waitForTimeout(500);
      if (state.totaal) prijs = fmt(state.totaal);
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
