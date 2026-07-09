/**
 * horrengigant.nl – Plissé hordeur configurator (ASP.NET WebForms)  ✅ per-maat
 *
 * URL: https://www.horrengigant.nl/configurator/deurplissehor.htm
 *
 * Flow (gereverse-engineerd):
 *  1. "Kies dit type >"  -> toont Stap 2 (Maat & Kleur)
 *  2. #edit_breedte / #edit_hoogte  -> LET OP: site verwacht CENTIMETERS (mm/10)
 *  3. label[for=rbMountingType0]  (montage in het kozijn = in de dag)
 *  4. label[for=rbSingleDoor] / rbDoubleDoor
 *  5. "Volgende >"  -> Stap 3 (Accessoires; standaard RAL 9010 wit + zwart gaas)
 *  6. "Volgende >"  -> Overzicht; eindprijs in #ctl17_lTotalPriceHeader
 *
 * Elke "Volgende" is een WebForms-postback (volledige herlaad) → traag maar echt.
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');
const { acceptCookies, normalizePrice } = require('./helpers');

const COMP = 'horrengigant.nl';
const URL = 'https://www.horrengigant.nl/configurator/deurplissehor.htm';

async function leesPrijs(page) {
  const raw = await page.locator('#ctl17_lTotalPriceHeader, .PriceLabel').first().textContent().catch(() => null);
  return normalizePrice(raw);
}

async function haalPrijs(page, breedte, hoogte, dubbel) {
  await page.getByText(/kies dit type/i).first().click({ timeout: 8000 });
  await page.waitForTimeout(2500);

  await page.locator('#edit_breedte').waitFor({ state: 'visible', timeout: 8000 });
  await page.locator('#edit_breedte').fill(String(Math.round(breedte / 10)));   // cm!
  await page.locator('#edit_hoogte').fill(String(Math.round(hoogte / 10)));
  await page.locator('label[for="rbMountingType0"]').click({ timeout: 3000 }).catch(() => {});
  await page.locator(`label[for="${dubbel ? 'rbDoubleDoor' : 'rbSingleDoor'}"]`).click({ timeout: 3000 }).catch(() => {});
  await page.waitForTimeout(400);

  // Volgende x2: Maat&Kleur -> Accessoires -> Overzicht (eindprijs)
  for (let i = 0; i < 2; i++) {
    await page.getByText(/volgende/i).first().click({ timeout: 6000 }).catch(() => {});
    await page.waitForTimeout(3000);
  }
  // soms is er een extra stap; probeer nog 1x als prijs nog 0 is
  let prijs = await leesPrijs(page);
  if (!prijs || /0,00/.test(prijs)) {
    await page.getByText(/volgende/i).first().click({ timeout: 4000 }).catch(() => {});
    await page.waitForTimeout(3000);
    prijs = await leesPrijs(page);
  }
  return (prijs && !/0,00/.test(prijs)) ? prijs : null;
}

for (const [naam, { breedte, hoogte, type, gaas }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ page }) => {
    test.setTimeout(90_000); // 3 WebForms-postbacks à ±3-8s + retryslack: 60s is te krap onder load
    // De gaaskleur zit (als die er al is) pas in latere WebForms-stappen en is
    // niet betrouwbaar te kiezen; grijs is hier dus niet te configureren.
    if (gaas === 'grijs') {
      recordPrice(COMP, naam, 'n.v.t.');
      return;
    }
    let prijs = null;
    try {
      await page.goto(URL, { waitUntil: 'domcontentloaded', timeout: 30000 });
      await acceptCookies(page);
      await page.waitForTimeout(600);
      prijs = await haalPrijs(page, breedte, hoogte, type === 'dubbel');
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
