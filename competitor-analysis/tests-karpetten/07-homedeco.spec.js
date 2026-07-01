/**
 * homedeco.nl – De Munk bij een algemene woonwebshop. De ZOEKfunctie is
 * client-side, maar de merk- en productpagina's zijn gewoon server-side
 * gerenderd: één URL per (dessin, maat), prijs in product:price:amount-meta.
 * Maat zit in de URL (200-x-300-cm) -> exact. Toscane wordt NIET verkocht
 * (geverifieerd op de volledige merkpagina, juni 2026).
 */
const { test, expect } = require('@playwright/test');
const { registerKarpetten } = require('./_helpers');

const p = (model, slug) => ({
  model,
  url: `https://homedeco.nl/p/${slug}/`,
  maatExact: true,
});

registerKarpetten(test, expect, {
  shop: 'homedeco.nl',
  items: [
    p('Grande',   'wollen-vloerkleed-grande-01-de-munk-carpets-200-x-300-cm-l'),
    p('Martello', 'wollen-vloerkleed-martello-02-de-munk-carpets-200-x-300-cm-l'),
    p('Firenze',  'wollen-vloerkleed-firenze-22-de-munk-carpets-taupe-200-x-300-cm-l-2'),
    p('Lecce',    'wollen-vloerkleed-lecce-02-de-munk-carpets-200-x-300-cm-l'),
  ],
});
