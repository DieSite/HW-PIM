/**
 * qniq.nl – Plissé hordeur op maat  (volledige per-maat configurator)
 *
 * URL enkel  : https://qniq.nl/plisse-hordeur/
 * URL dubbel : https://qniq.nl/dubbele-plisse-hordeur/
 *
 * Velden: #breedte / #hoogte (mm), select #montage ("Tussen het kozijn"),
 *         #profielkleur ("Zuiver wit (RAL 9010)"), #gaas ("Zwart plissé").
 * Live prijs: span#prijstxt (huidige prijs; #prijstxt2 = doorgestreepte van-prijs).
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');
const { acceptCookies, selectOptionByText, normalizePrice } = require('./helpers');

const COMP = 'qniq.nl';
const URLS = {
  enkel:  'https://qniq.nl/plisse-hordeur/',
  dubbel: 'https://qniq.nl/dubbele-plisse-hordeur/',
};

async function haalPrijs(page, breedte, hoogte) {
  await page.locator('#breedte').waitFor({ state: 'visible', timeout: 8000 });
  await selectOptionByText(page.locator('#montage'), /tussen het kozijn/i);
  await page.locator('#breedte').fill(String(breedte));
  await page.locator('#hoogte').fill(String(hoogte));
  await selectOptionByText(page.locator('#profielkleur'), /9010/);
  await selectOptionByText(page.locator('#gaas'), /zwart/i);
  await page.locator('#hoogte').press('Tab');
  // poll tot #prijstxt een prijs bevat (max 12s) i.p.v. vaste sleep
  const raw = await page.waitForFunction(() => {
    const t = document.querySelector('#prijstxt')?.textContent || '';
    return /\d+[.,]\d{2}|€\s*\d/.test(t) ? t : null;
  }, { timeout: 12000 }).then(h => h.jsonValue()).catch(() => null);
  return normalizePrice(raw);
}

for (const [naam, { breedte, hoogte, type }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ page }) => {
    let prijs = null;
    try {
      await page.goto(URLS[type], { waitUntil: 'domcontentloaded', timeout: 30000 });
      await acceptCookies(page);
      await page.waitForTimeout(400);
      prijs = await haalPrijs(page, breedte, hoogte);
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
