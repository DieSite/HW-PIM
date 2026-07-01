/**
 * vloerkledenspecialist.nl (Brokking) – WooCommerce met thema-custom
 * maat-select: <select name="size"> met opties "2.00 x 3.00|1439" (meters +
 * prijs in de option-value) -> sizeSelectPrijs, exact 200x300.
 */
const { test, expect } = require('@playwright/test');
const { registerKarpetten } = require('./_helpers');

const p = (model, cat, slug) => ({
  model,
  sizeSelect: `https://vloerkledenspecialist.nl/vloerkleden/${cat}/${slug}/`,
  meters: '2.00 x 3.00',
});

registerKarpetten(test, expect, {
  shop: 'vloerkledenspecialist.nl',
  items: [
    p('Grande',   'handgeweven-tapijt', 'de-munk-carpets-grande-01-vloerkleed'),
    p('Martello', 'handgeweven-tapijt', 'de-munk-carpets-martello-01-vloerkleed'),
    p('Firenze',  'handgeweven-tapijt', 'de-munk-carpets-firenze-26-vloerkleed'),
    p('Genova',   'modern-vloerkleed',  'de-munk-carpets-genova-01-vloerkleed'),
    p('Toscane',  'hoogpolig-vloerkleed', 'de-munk-carpets-toscane-01-vloerkleed'),
    p('Venezia',  'handgeweven-tapijt', 'de-munk-carpets-venezia-01-vloerkleed'),
    p('Lecce',    'handgeweven-tapijt', 'de-munk-carpets-lecce-01-vloerkleed'),
    // Vogue wordt hier aantoonbaar NIET verkocht (volledige product-sitemap, juni 2026)
  ],
});
