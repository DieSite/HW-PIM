/**
 * detafelaar.nl – Magento 2, maar GEEN per-maat webshopprijzen: de
 * productpagina's hebben geen maat-opties (ook niet na JS-render); de
 * getoonde prijs is letterlijk "Vanaf" (kleinste maat). We registreren die
 * dus eerlijk als "Vanaf €" — nooit als 200x290-prijs laten kleuren.
 */
const { test, expect } = require('@playwright/test');
const { registerKarpetten } = require('./_helpers');

// let op: tussen € en het bedrag staat een echte NBSP ( ), geen entity
const PAT = 'class="price">€\\s*([0-9][0-9.,]*)';
const t = (model, slug) => ({
  model,
  regexUrl: `https://www.detafelaar.nl/${slug}`,
  pattern: PAT,
  vanaf: true,
});

registerKarpetten(test, expect, {
  shop: 'detafelaar.nl',
  items: [
    t('Aspen',       'aspen-vloerkleed-7270-aspen-vloerkleed-7270-1019486'),
    t('Anaheim',     'anaheim-vloerkleed-3434-anaheim-vloerkleed-3434-1019481'),
    t('Spectrum',    'spectrum-vloerkleed-3333-spectrum-vloerkleed-3333-1019097'),
    t('Twilight',    'twilight-vloerkleed-2211-twilight-vloerkleed-2211-1019187'),
    t('Richmond',    'richmond-vloerkleed-101-richmond-vloerkleed-101-1019213'),
    t('Love Shaggy', 'love-shaggy-vloerkleed-antraciet-love-shaggy-vloerkleed-antraciet-1019145'),
    t('Arizona',     'arizona-vloerkleed-9290-arizona-vloerkleed-9290-1019495'),
  ],
});
