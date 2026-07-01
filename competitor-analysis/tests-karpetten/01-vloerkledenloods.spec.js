/**
 * vloerkledenloods.nl – Shopify. Onze #1: breedste Karpi-range + 7/8 De Munk.
 * Producten gevonden via de Shopify suggest-API, exacte maatvariant-prijs uit
 * /products/<handle>.js (Karpi 200x290, De Munk 200x300).
 */
const { test, expect } = require('@playwright/test');
const { registerKarpetten } = require('./_helpers');

const base = 'https://vloerkledenloods.nl';
const karpi  = m => ({ model: m, zoek: `Karpi ${m}`, titelRe: new RegExp(m, 'i'), maat: '200x290', base });
const demunk = m => ({ model: m, zoek: `De Munk ${m}`, titelRe: new RegExp(m, 'i'), maat: '200x300', base });

registerKarpetten(test, expect, {
  shop: 'vloerkledenloods.nl',
  base,
  items: [
    ...['Cisco', 'Galaxy', 'Lago', 'Marich', 'Olimpos', 'Bilal'].map(karpi),
    // alle 4 MV-modellen (sitemap-geverifieerd juni 2026)
    ...['Cavaro', 'Cendre', 'Vernon', 'Prosper'].map(m => (
      { model: `MV ${m}`, zoek: `Mart Visser ${m}`, titelRe: new RegExp(m, 'i'), maat: '200x290', base })),
    // alle 8 De Munk-modellen, incl. Toscane (sitemap-geverifieerd)
    ...['Grande', 'Martello', 'Firenze', 'Genova', 'Vogue', 'Venezia', 'Lecce', 'Toscane'].map(demunk),
  ],
});
