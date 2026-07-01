/**
 * hamstrahorren.nl – GEEN webshop: fabrikant-/merksite, verkoop loopt via
 * fysieke verkooppunten ("VERKOOPPUNTEN", dealer-login). De plissé-pagina
 * (/plisse-hor/) bevat geen enkel €-bedrag, geen configurator en geen
 * winkelwagen — er bestaat dus geen online prijs om te scrapen.
 *
 * (De Cloudflare-challenge is overigens WEL passeerbaar met headless
 *  Chromium: na domcontentloaded de titel "Even geduld..." max ~20s
 *  uitpollen. Niet nodig zolang de site geen prijzen publiceert.)
 */
const { test } = require('@playwright/test');
const { registerLabel } = require('./_vanaf');
registerLabel(test, { comp: 'hamstrahorren.nl', label: 'Via verkooppunten' });
