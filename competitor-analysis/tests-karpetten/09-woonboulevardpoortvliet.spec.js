/**
 * woonboulevardpoortvliet.nl – custom Nuxt-storefront; maat zit in de
 * URL-slug -> exact, prijs via JSON-LD (paginaPrijs).
 * LET OP (geverifieerd juni 2026): hun "Casual Grande" is 250x300 en hun
 * "Carpet Venezia" (€324) heeft geen maat en is vrijwel zeker een ander
 * product dan De Munk Venezia (RRP €2169) — beide bewust NIET opgenomen.
 * Karpi Bilal staat niet (meer) in de sitemap; de enige Prosper is Ø200 rond.
 */
const { test, expect } = require('@playwright/test');
const { registerKarpetten } = require('./_helpers');

registerKarpetten(test, expect, {
  shop: 'woonboulevardpoortvliet.nl',
  items: [
    { model: 'Love Shaggy', url: 'https://www.woonboulevardpoortvliet.nl/vloerkleed-love-shaggy-taupe-200x290', maatExact: true },
    { model: 'Twilight', url: 'https://www.woonboulevardpoortvliet.nl/vloerkleed-twilight-zilvergrijs-200x290-300012915.html', maatExact: true },
    // "FI-04" + prijsladder = De Munk Firenze (merknaam staat niet op de pagina)
    { model: 'Firenze', url: 'https://www.woonboulevardpoortvliet.nl/vloerkleed-firenze-grijs-fi-04-200x300-49314.html', maatExact: true },
    // zelfde product-id als Bouman&Potter (gedeeld platform); IS de 200x290
    { model: 'MV Vernon', url: 'https://www.woonboulevardpoortvliet.nl/mart-visser-vloerkleed-vernon-800100099.html', maatExact: true },
  ],
});
