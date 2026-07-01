/**
 * CONFIGURATOR: plissehordeurenwebshop.nl  (EIGEN WINKEL)
 *
 * Technologie : WooCommerce + custom JS-configurator ("js-config-button")
 * URL enkel   : /hordeuren/maatwerk-enkele-plissehordeur/
 * URL dubbel  : /hordeuren/maatwerk-dubbele-plissehordeur/
 *
 * Echte flow (gereverse-engineerd):
 *  1. "Accepteer alles" cookie-banner
 *  2. <a.js-config-button> "Start met samenstellen"  -> toont stap-form
 *  3. Situatie     : label[for=op_de_dag-1]  ("In de dag")
 *  4. Maat         : #breedte / #hoogte (number, mm)
 *  5. Drempel      : label[for=schuin-1]      ("Rechtaflopend")
 *  6. Kleurkeuze   : label[for=standaard_of_eigen_kleur-standaard] + label[for=framekleur-ral-9010]
 *  7. Gaas         : label[for=gaaskleur-zwart]
 *  8. Handgreep    : label[for=handgreep-1]   ("Nee, zonder handgreep")
 *  9. Powertape    : label[for=powertape-1]   ("Nee, ik ga schroeven")
 *
 * Prijs: tabel .configurator__totals, rij "Hordeur" -> huidige (niet-doorgestreepte)
 * prijs = de KALE PRODUCTPRIJS voor de geconfigureerde deur. We nemen bewust NIET
 * het order-totaal, want dat bevat afhaalkorting + tijdelijke promo (niet
 * vergelijkbaar met concurrenten).
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');
const { acceptCookies, clickLabelById, normalizePrice } = require('./helpers');

const COMP = 'plissehordeurenwebshop.nl';
const URLS = {
  enkel:  'https://www.plissehordeurenwebshop.nl/hordeuren/maatwerk-enkele-plissehordeur/',
  dubbel: 'https://www.plissehordeurenwebshop.nl/hordeuren/maatwerk-dubbele-plissehordeur/',
};

async function configure(page, breedte, hoogte) {
  await page.locator('a.js-config-button').first().click({ timeout: 8000 });
  await page.waitForTimeout(900);

  await clickLabelById(page, 'op_de_dag-1');              // In de dag
  await page.waitForTimeout(600);

  await page.locator('#breedte').waitFor({ state: 'visible', timeout: 8000 });
  await page.locator('#breedte').fill(String(breedte));
  await page.locator('#hoogte').fill(String(hoogte));
  await page.locator('#hoogte').press('Tab');
  await page.waitForTimeout(600);

  await clickLabelById(page, 'schuin-1');                 // Rechtaflopend
  await clickLabelById(page, 'standaard_of_eigen_kleur-standaard');
  await page.waitForTimeout(300);
  await clickLabelById(page, 'framekleur-ral-9010');      // RAL 9010 wit
  await clickLabelById(page, 'gaaskleur-zwart');          // Zwart gaas
  await clickLabelById(page, 'handgreep-1');              // Geen handgreep
  await clickLabelById(page, 'powertape-1');              // Geen tape (schroeven)

  // Poll tot de "Hordeur"-rij een productprijs toont (i.p.v. vaste sleep —
  // onder parallelle load is de herberekening soms trager dan 2s).
  const prijs = await page.waitForFunction(() => {
    const rows = [...document.querySelectorAll('.configurator__totals tr')];
    const row = rows.find(tr => /Hordeur/i.test(tr.cells?.[0]?.textContent || ''));
    if (!row) return null;
    const cell = row.cells[row.cells.length - 1].cloneNode(true);
    const struck = cell.querySelector('s'); if (struck) struck.remove();   // verwijder van-prijs
    const m = (cell.textContent || '').match(/€\s*[\d.]+,\d{2}/);
    return m ? m[0] : null;
  }, { timeout: 15000 }).then(h => h.jsonValue()).catch(() => null);
  return normalizePrice(prijs);
}

for (const [naam, { breedte, hoogte, type }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ page }) => {
    let prijs = null;
    try {
      await page.goto(URLS[type], { waitUntil: 'domcontentloaded', timeout: 30000 });
      await acceptCookies(page);
      await page.waitForTimeout(400);
      prijs = await configure(page, breedte, hoogte);
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs, `geen prijs gevonden voor ${naam}`).toBeTruthy();
  });
}
