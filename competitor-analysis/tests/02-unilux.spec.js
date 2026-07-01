/**
 * unilux.nl – GEEN online prijsconfigurator.
 *
 * Unilux publiceert geen online afrekenbare maatwerkprijs voor de plissé
 * hordeur; prijzen lopen via een bruto catalogus / offerte. Er valt dus niets
 * betrouwbaars te scrapen. We registreren daarom een EERLIJK label i.p.v. een
 * verzonnen prijs. (Zie ook de Info-tab in de gegenereerde Excel.)
 */

const { test } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');

const COMP = 'unilux.nl';
const LABEL = 'Catalogus';   // bruto catalogusprijs, geen online configurator

for (const [naam] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (geen online prijs)`, async () => {
    recordPrice(COMP, naam, LABEL);
  });
}
