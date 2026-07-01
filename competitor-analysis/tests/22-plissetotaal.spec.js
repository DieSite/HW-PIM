/**
 * plissetotaal.nl – GEPARKEERD DOMEIN (juni 2026): het TLS-certificaat dekt de
 * domeinnaam niet meer en de pagina toont "Domein gereserveerd" (registrar-
 * parkeerpagina). De webshop bestaat niet meer -> geen prijs extraheerbaar.
 * Eerlijk label.
 */
const { test } = require('@playwright/test');
const { registerLabel } = require('./_vanaf');
registerLabel(test, { comp: 'plissetotaal.nl', label: 'Site offline' });
