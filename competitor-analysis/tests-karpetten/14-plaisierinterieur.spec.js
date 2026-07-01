/**
 * plaisierinterieur.nl – WooCommerce; Vogue heeft >30 variaties dus geen
 * inline JSON -> wc-ajax get_variation POST (product 29857, 200x300, VO-35).
 */
const { test, expect } = require('@playwright/test');
const { registerKarpetten } = require('./_helpers');

registerKarpetten(test, expect, {
  shop: 'plaisierinterieur.nl',
  base: 'https://www.plaisierinterieur.nl',
  items: [
    { model: 'Vogue', wooAjax: 29857,
      attrs: { attribute_maat: '200 x 300 cm', attribute_kleur: 'VO-35' },
      url: 'https://www.plaisierinterieur.nl/product/de-munk-carpets-vogue-vloerkleed/' },
    // overige De Munk-modellen (sitemap-geverifieerd juni 2026). Producten met
    // >30 variaties (Firenze/Venezia) hebben geen inline JSON -> wc-ajax met
    // product-id + kleurcode; de rest via inline variatie-JSON.
    { model: 'Firenze', wooAjax: 25738,
      attrs: { attribute_maat: '200 x 300 cm', attribute_kleur: 'FI-01' },
      url: 'https://www.plaisierinterieur.nl/product/de-munk-carpets-firenze-vloerkleed/' },
    { model: 'Venezia', wooAjax: 25956,
      attrs: { attribute_maat: '200 x 300 cm', attribute_kleur: 'VE-01' },
      url: 'https://www.plaisierinterieur.nl/product/de-munk-carpets-venezia-vloerkleed/' },
    ...[
      ['Martello', 'de-munk-carpets-casual-martello-vloerkleed'],
      ['Genova',   'de-munk-carpets-genova-vloerkleed'],
      ['Toscane',  'de-munk-carpets-toscane-vloerkleed'],
    ].map(([model, slug]) => ({
      model, wooVariaties: `https://www.plaisierinterieur.nl/product/${slug}/`, maat: '200x300',
    })),
  ],
});
