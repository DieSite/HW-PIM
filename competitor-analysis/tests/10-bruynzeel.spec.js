/**
 * bruynzeelhomeproducts.nl – GEEN online prijsconfigurator.
 *
 * Bruynzeel verkoopt plissé horren via het dealernetwerk "op aanvraag"; er is
 * geen online afrekenbare maatwerkprijs. We registreren een eerlijk label
 * i.p.v. een verzonnen prijs. (Zie ook de Info-tab in de Excel.)
 */

const { test } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');

const COMP = 'bruynzeelhomeproducts.nl';
const LABEL = 'Op aanvraag';

for (const [naam] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (geen online prijs)`, async () => {
    recordPrice(COMP, naam, LABEL);
  });
}
