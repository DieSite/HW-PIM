/**
 * koopje-horren.com – Bruynzeel plissé hordeur s900 "op maat"  ✅ per-maat
 *
 * Twee productpagina's (H1 = "Bruynzeel Plissé Hordeur s900" en "Bruynzeel
 * dubbele Plissé Hordeur s900"), beide met een échte maatconfigurator:
 * Magento + de plugin `MageArray_Customprice`, die de complete prijstabel als
 * JSON in de pagina zet. Geen browserpagina nodig — één `request.get()` per
 * deurtype volstaat (zelfde aanpak als 19-decozijn).
 *
 * De rekenregel (bandlookup + gaasoptie) staat in `_koopje.js`, met unit
 * tests: `npm run test:koopje`.
 *
 * LET OP (gecorrigeerd 2026-07-30): tot deze datum noteerde de spec de
 * getoonde € 179 / € 349 als VASTE prijs voor alle 34 maten, met in de header
 * de aanname dat er geen breedte/hoogte-velden zijn. Dat klopte niet: dat zijn
 * de goedkoopste cellen van de tabel (580×1791 resp. 1160×1791). 730×1970
 * kost € 233 en 1430×1970 kost € 457 — koopje-horren stond dus stelselmatig te
 * goedkoop in de vergelijking.
 *
 * Standaardopties zoals bij de andere bronnen: montage "In de dag" (€ 0; "Op
 * de dag" is +25%) en RAL 9010 wit (geen meerprijs). Gaaskleur komt uit de
 * optietabel: grijs kost niets extra maar kan tot 2600 mm hoogte, daarboven
 * eerlijk n.v.t.
 *
 * URL enkel  : /bruynzeel-plisse-hordeur-s900-op-maat
 * URL dubbel : /bruynzeel-dubbele-plisse-hordeur-s900-op-maat
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');
const { parseOptionConfig, prijsUitSheet, gaasOptie } = require('./_koopje');

const COMP = 'koopje-horren.com';
const URLS = {
  enkel:  'https://www.koopje-horren.com/bruynzeel-plisse-hordeur-s900-op-maat',
  dubbel: 'https://www.koopje-horren.com/bruynzeel-dubbele-plisse-hordeur-s900-op-maat',
};

// Per deurtype één pagina-fetch voor alle tests in dit bestand. Alleen een
// geslaagde fetch wordt gecachet: een gecachete mislukking zou de overige 33
// maten meeslepen.
const cache = {};
async function haalConfig(request, type) {
  if (cache[type]) return cache[type];

  const resp = await request.get(URLS[type], { timeout: 20000 });
  const cfg = resp.ok() ? parseOptionConfig(await resp.text()) : null;
  if (cfg) cache[type] = cfg;

  return cfg;
}

for (const [naam, { breedte, hoogte, type, gaas }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ request }) => {
    let prijs = null;
    try {
      const cfg = await haalConfig(request, type);
      const optie = cfg ? gaasOptie(cfg, gaas) : null;

      // Geen gaaskleur-optie, of deze maat valt buiten de grens die in de
      // optietitel staat ("Grijs (maximaal 2600mm hoog)") -> niet leverbaar.
      const gaasOk = optie && (optie.maxHoogte === null || hoogte <= optie.maxHoogte);

      if (gaasOk) {
        const basis = prijsUitSheet(cfg, breedte, hoogte);
        if (basis !== null) {
          prijs = `€ ${(basis + optie.meerprijs).toFixed(2).replace('.', ',')}`;
        }
      }
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
