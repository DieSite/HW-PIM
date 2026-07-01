/**
 * plisse-reus.nl – verkoopt plissé GORDIJNEN (raamdecoratie / honingraat), GEEN
 * plissé hordeuren. Valt buiten deze hordeur-vergelijking. Eerlijk label.
 */
const { test } = require('@playwright/test');
const { registerLabel } = require('./_vanaf');
registerLabel(test, { comp: 'plisse-reus.nl', label: 'Geen hordeur' });
