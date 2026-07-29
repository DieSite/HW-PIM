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
 *  7. Gaas         : label[for=gaaskleur-zwart] / label[for=gaaskleur-grijs]
 *  8. Handgreep    : label[for=handgreep-1]   ("Nee, zonder handgreep")
 *  9. Powertape    : label[for=powertape-1]   ("Nee, ik ga schroeven")
 *
 * Prijs: tabel .configurator__totals, rij "Hordeur" (huidige, niet-doorgestreepte
 * prijs) MIN de lopende promokorting-regel. Zie tests/_eigenwinkel.js voor het
 * waarom; kort: de promo geldt voor iedere klant en hoort er dus in, de
 * afhaalkorting in het Totaal geldt alleen bij ophalen en hoort er dus uit.
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');
const { acceptCookies, clickLabelById } = require('./helpers');
const { eigenWinkelPrijs, euro } = require('./_eigenwinkel');

const COMP = 'plissehordeurenwebshop.nl';
const URLS = {
  enkel:  'https://www.plissehordeurenwebshop.nl/hordeuren/maatwerk-enkele-plissehordeur/',
  dubbel: 'https://www.plissehordeurenwebshop.nl/hordeuren/maatwerk-dubbele-plissehordeur/',
};

async function configure(page, breedte, hoogte, gaas) {
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
  const gaasOk = await clickLabelById(page, `gaaskleur-${gaas}`);
  if (!gaasOk && gaas !== 'zwart') return null;           // gaaskleur niet kiesbaar -> n.v.t.
  await clickLabelById(page, 'handgreep-1');              // Geen handgreep
  await clickLabelById(page, 'powertape-1');              // Geen tape (schroeven)

  // Poll tot de "Hordeur"-rij een productprijs toont (i.p.v. vaste sleep —
  // onder parallelle load is de herberekening soms trager dan 2s). We geven de
  // hele tabel terug; welke bedragen meetellen beslist _eigenwinkel.js, zodat
  // die regel te testen is zonder de live configurator.
  const rows = await page.waitForFunction(() => {
    const trs = [...document.querySelectorAll('.configurator__totals tr')];
    const hordeur = trs.find(tr => /^\s*Hordeur\s*$/i.test(tr.cells?.[0]?.textContent || ''));
    if (!hordeur) return null;

    const cells = tr => [...tr.cells].map((cell, i) => {
      // De van-prijs staat doorgestreept vóór de huidige prijs in dezelfde cel.
      const clone = cell.cloneNode(true);
      clone.querySelectorAll('s, del').forEach(el => el.remove());
      return clone.textContent.replace(/\s+/g, ' ').trim();
    });

    const rows = trs.map(cells);
    // Pas doorgeven als de Hordeur-regel echt een bedrag toont.
    return /€/.test(rows.find(r => /^Hordeur$/i.test(r[0]))?.at(-1) || '') ? rows : null;
  }, { timeout: 15000 }).then(h => h.jsonValue()).catch(() => null);

  return euro(eigenWinkelPrijs(rows));
}

for (const [naam, { breedte, hoogte, type, gaas }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ page }) => {
    let prijs = null;
    try {
      await page.goto(URLS[type], { waitUntil: 'domcontentloaded', timeout: 30000 });
      await acceptCookies(page);
      await page.waitForTimeout(400);
      prijs = await configure(page, breedte, hoogte, gaas);
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs, `geen prijs gevonden voor ${naam}`).toBeTruthy();
  });
}
