/**
 * kleed.nl – Lightspeed eCom (géén WooCommerce). Het varianten-JSON in de
 * pagina bevat per maat "price_incl" (= getoonde prijs incl. btw):
 *   "price_incl":2169,...},..."title":"Formaat: 200 x 300cm"
 * -> regexPrijs. Juni 2026: price_old=0 overal — de eerder geziene ±17%
 * korting is momenteel NIET actief; dit zijn gewoon de lijstprijzen.
 */
const { test, expect } = require('@playwright/test');
const { registerKarpetten } = require('./_helpers');

// let op: De Munk-varianten heten "200 x 300cm", MV-varianten "200 x 290 cm"
// (spatie voor cm) -> \\s* ervoor
const PAT = m => `"price_incl":([\\d.]+),"price_excl":[\\d.]+,"price_old":[\\d.]+[^}]*}[^{}]*"title":"Formaat: ${m}\\s*cm"`;
const k = (model, slug) => ({ model, regexUrl: `https://www.kleed.nl/${slug}.html`, pattern: PAT('200 x 300') });

registerKarpetten(test, expect, {
  shop: 'kleed.nl',
  items: [
    // alle 8 De Munk-modellen (sitemap-geverifieerd juni 2026; Grande staat
    // NIET op hun merkpagina, alleen in de sitemap, en sommige Firenze-kleuren
    // hebben een verschreven "firzenze"-slug)
    k('Grande',   'de-munk-carpets-grande-01'),
    k('Martello', 'de-munk-carpets-martello-1'),
    k('Firenze',  'de-munk-carpets-firenze-24-141125269'),
    k('Genova',   'de-munk-carpets-genova-3-141125384'),
    k('Vogue',    'de-munk-carpets-vogue-33-141125415'),
    k('Venezia',  'de-munk-carpets-venezia-7-141125314'),
    k('Lecce',    'de-munk-carpets-lecce-1'),
    k('Toscane',  'de-munk-carpets-toscane-2-141125374'),
    // Mart Visser-lijn is er ook (Cendre/Vernon/Prosper); product-URL via de
    // collectiepagina ontdekken, dan zelfde varianten-JSON met maat 200x290.
    // LET OP: de Cendre-productslugs zijn verschreven als "cender" -> match
    // op de eerste 4 letters van de modelnaam.
    ...['Cendre', 'Vernon', 'Prosper'].map(m => ({
      model: `MV ${m}`,
      vindOp: `https://www.kleed.nl/collectie/mart-visser/${m.toLowerCase()}/`,
      linkRe: new RegExp(`mart-visser-${m.toLowerCase().slice(0, 4)}[^"/]*\\.html`, 'i'),
      pattern: PAT('200 x 290'),
    })),
  ],
});
