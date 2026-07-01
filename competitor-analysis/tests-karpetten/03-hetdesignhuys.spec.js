/**
 * hetdesignhuys.nl – Shopify; prijsagressor op Eurogros (6/8) + 2 Karpi MV.
 * Shopify suggest-API + exacte 200x290-variantprijs.
 */
const { test, expect } = require('@playwright/test');
const { registerKarpetten } = require('./_helpers');

const base = 'https://hetdesignhuys.nl';
const eg = m => ({ model: m, zoek: m, titelRe: new RegExp(m.replace(' ', '\\s*'), 'i'), maat: '200x290', base });

registerKarpetten(test, expect, {
  shop: 'hetdesignhuys.nl',
  base,
  items: [
    // alle 8 Eurogros-modellen (sitemap-geverifieerd juni 2026). Allison is
    // een per-maat product -> titel moet de maat bevatten, anders pakt de
    // suggest het 240x330-product.
    ...['Aspen', 'Anaheim', 'Spectrum', 'Twilight', 'Richmond', 'Love Shaggy', 'Arizona'].map(eg),
    { model: 'Allison', zoek: 'Allison 200x290', titelRe: /allison.*200\s*x\s*290/i, maat: '200x290', base },
    // Karpi (Lago/Bilal stonden bij verificatie op €0 — mogelijk op aanvraag;
    // de Shopify-variantprijs beslist: geen prijs -> eerlijk n.v.t.)
    { model: 'Galaxy', zoek: 'Galaxy', titelRe: /galaxy/i, maat: '200x290', base },
    { model: 'Lago', zoek: 'Lago', titelRe: /lago/i, maat: '200x290', base },
    { model: 'Bilal', zoek: 'Bilal', titelRe: /bilal/i, maat: '200x290', base },
    // alle 4 MV-modellen (sitemap-geverifieerd)
    ...['Cendre', 'Vernon', 'Cavaro', 'Prosper'].map(m => (
      { model: `MV ${m}`, zoek: `Mart Visser ${m}`, titelRe: new RegExp(m, 'i'), maat: '200x290', base })),
  ],
});
