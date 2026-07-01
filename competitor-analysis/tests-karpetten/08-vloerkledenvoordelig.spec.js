/**
 * vloerkledenvoordelig.nl – custom ASP.NET-shop; per-SKU pagina's met de maat
 * in de URL (… -200x290_<id>.html) -> exacte prijs uit de itemprop/JSON-LD
 * meta (paginaPrijs). Er is GEEN zoekroute (/zoeken/ = 404); URL-discovery
 * loopt via de sitemap-generator (zie agent-notitie in CLAUDE.md) — daarom
 * staan de SKU-URL's hier hardcoded per model (eerste kleur).
 */
const { test, expect } = require('@playwright/test');
const { registerKarpetten } = require('./_helpers');

const v = (model, slug) => ({
  model,
  url: `https://www.vloerkledenvoordelig.nl/vloerkleden/alle-soorten/${slug}.html`,
  maatExact: true,
});

registerKarpetten(test, expect, {
  shop: 'vloerkledenvoordelig.nl',
  items: [
    v('Galaxy',     'karpi-galaxy-10-200x290_724709'),
    v('Cisco',      'karpi-cisco-11-200x290_726486'),
    v('Lago',       'karpi-lago-11-200x290_725097'),
    v('Marich',     'karpi-marich-22-200x290_726699'),
    v('Olimpos',    'karpi-olimpos-13-200x290_728815'),
    v('Bilal',      'karpi-bilal-11-200x290_726456'),
    v('MV Cendre',  'karpi-mart-visser-cendre-soft-grey-200x290_595932'),
    v('MV Vernon',  'karpi-mart-visser-vernon-13-200x290_726925'),
    v('MV Prosper', 'karpi-mart-visser-prosper-21-200x290_726791'),
  ],
});
