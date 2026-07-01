/**
 * homecompanyshop.nl – Lightspeed eCom. Variantprijs staat in het inline
 * varianten-JSON: "price":{"price":659,...},..."title":"Maat: Medium 200 x 290"
 * -> regexPrijs (prijs staat ~200 tekens vóór de titel). Olimpos: anker exact
 * "200 x 290 cm" — er bestaat ook een 240x290-variant!
 */
const { test, expect } = require('@playwright/test');
const { registerKarpetten } = require('./_helpers');

const MV_PAT = '"price":\\{"price":([\\d.]+)[^{]{0,250}?"title":"Maat: Medium 200 x 290';

registerKarpetten(test, expect, {
  shop: 'homecompanyshop.nl',
  items: [
    { model: 'MV Vernon', regexUrl: 'https://www.homecompanyshop.nl/mart-visser-vloerkleed-vernon.html', pattern: MV_PAT },
    { model: 'MV Cendre', regexUrl: 'https://www.homecompanyshop.nl/mart-visser-vloerkleed-cendre.html', pattern: MV_PAT },
    { model: 'MV Prosper', regexUrl: 'https://www.homecompanyshop.nl/mart-visser-tapijt-prosper.html', pattern: MV_PAT },
    { model: 'Olimpos', regexUrl: 'https://www.homecompanyshop.nl/karpet-olimpos-24.html',
      pattern: '"price":\\{"price":([\\d.]+)[^{]{0,250}?"title":"[^"]*\\b200 x 290 cm' },
    // gelabeld "Headlam" (Karpi's moederbedrijf) — zelfde Galaxy-kleed
    { model: 'Galaxy', regexUrl: 'https://www.homecompanyshop.nl/headlam-karpet-galaxy.html',
      pattern: '"price":\\{"price":([\\d.]+)[^{]{0,250}?"title":"[^"]*\\b200 x 290' },
  ],
});
