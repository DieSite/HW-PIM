/**
 * volero.nl – Eurogros-breedte (Magento-achtig). De maatprijs staat gewoon in
 * de optietekst van de maat-select: <option ...>200x290cm - €525</option>
 * -> regexPrijs. LET OP: Twilight-URL bevat de sitefout "twiilght".
 */
const { test, expect } = require('@playwright/test');
const { registerKarpetten } = require('./_helpers');

const PAT = '>200x290cm\\s*-\\s*€\\s*([\\d.,]+)<';
const v = (model, slug) => ({ model, regexUrl: `https://www.volero.nl/${slug}.html`, pattern: PAT });

registerKarpetten(test, expect, {
  shop: 'volero.nl',
  items: [
    v('Aspen',       'modern-vloerkleed-aspen-groen-7270'),
    v('Anaheim',     'bloemen-vloerkleed-anaheim-3434'),
    v('Spectrum',    'hoogpolig-vloerkleed-spectrum-6656'),
    v('Twilight',    'hoogpolig-vloerkleed-twiilght-beige-gemeleerd-2211'),
    v('Love Shaggy', 'hoogpolig-vloerkleed-love-shaggy-beige'),
    v('Richmond',    'hoogpolig-vloerkleed-richmond-beige-102'),
    v('Arizona',     'modern-vloerkleed-arizona-9290'),
  ],
});
