/**
 * karpetwereld.nl – WooCommerce met open Store API.
 * Toscane: inline data-product_variations (200x300). MV Prosper: Store API
 * (product 5088 -> variant 200x290).
 */
const { test, expect } = require('@playwright/test');
const { registerKarpetten } = require('./_helpers');

registerKarpetten(test, expect, {
  shop: 'karpetwereld.nl',
  base: 'https://karpetwereld.nl',
  items: [
    { model: 'Toscane', wooVariaties: 'https://karpetwereld.nl/product/vloerkleed-toscane-01/', maat: '200x300' },
    // "FI-01"-codes + wol/geweven = De Munk Firenze (merknaam niet op de pagina)
    { model: 'Firenze', wooVariaties: 'https://karpetwereld.nl/product/vloerkleed-firenze-01/', maat: '200x300' },
    { model: 'MV Prosper', wooStore: 5088, maat: '200x290',
      url: 'https://karpetwereld.nl/product/vloerkleed-prosper-vintage-copper-mart-visser/' },
    { model: 'MV Cendre', wooVariaties: 'https://karpetwereld.nl/product/vloerkleed-cendre-soft-grey-mart-visser/', maat: '200x290' },
  ],
});
