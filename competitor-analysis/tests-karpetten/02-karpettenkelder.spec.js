/**
 * karpettenkelder.nl – custom ASP.NET-shop; Eurogros (8) + De Munk (8).
 * Maatprijs staat per maat-radio in de pagina:
 *   <li class="maat-item-to-filter" data-title="200 x 290 rechthoek">
 *     ... data-prijs="525" (incl. btw; data-afhaalkorting negeren)
 * -> regexPrijs met de maat als anker. De Munk = "200 x 300 rechthoek".
 */
const { test, expect } = require('@playwright/test');
const { registerKarpetten } = require('./_helpers');

const pat = maat => `data-title="${maat} rechthoek"[\\s\\S]{0,500}?data-prijs="([\\d.]+)"`;
const kk = (model, pad, maat) => ({
  model,
  regexUrl: `https://www.karpettenkelder.nl/vloerkleed/${pad}`,
  pattern: pat(maat),
});

registerKarpetten(test, expect, {
  shop: 'karpettenkelder.nl',
  items: [
    kk('Aspen',       '5687/eurogros/aspen-7270-grijs-groen-multicolor-terra', '200 x 290'),
    kk('Anaheim',     '6289/eurogros/anaheim-3243-antraciet-grijs-zand', '200 x 290'),
    kk('Allison',     '6909/eurogros/allison-3293-antraciet-camel-grijs', '200 x 290'),
    kk('Spectrum',    '5351/eurogros/spectrum-3333-antraciet-bruin-grijs', '200 x 290'),
    kk('Twilight',    '6595/eurogros/twilight-4422-groen-grijs', '200 x 290'),
    kk('Richmond',    '4913/eurogros/richmond-101-creme-ivory', '200 x 290'),
    kk('Love Shaggy', '5098/eurogros/love-shaggy-beige-creme-zand', '200 x 290'),
    kk('Arizona',     '5734/eurogros/arizona-9290-blauw-grijs-groen-multicolor', '200 x 290'),
    kk('Grande',      '6675/de-munk-carpets/grande-gr-01-creme-multicolor', '200 x 300'),
    kk('Martello',    '6569/de-munk-carpets/munk-martello-ma-01-grijs-ivory', '200 x 300'),
    kk('Firenze',     '3344/de-munk-carpets/firenze-01-camel-cognac-creme-ivory', '200 x 300'),
    kk('Genova',      '5171/de-munk-carpets/genova-ge-01-antraciet-grijs-taupe', '200 x 300'),
    kk('Vogue',       '5890/de-munk-carpets/vogue-33-ivory-taupe-zand', '200 x 300'),
    kk('Venezia',     '3734/de-munk-carpets/venezia-ve-01-beige-bruin-creme-grijs', '200 x 300'),
    kk('Lecce',       '6553/de-munk-carpets/lecce-le-01-bruin-ivory', '200 x 300'),
    kk('Toscane',     '4478/de-munk-carpets/toscane-01-creme-grijs-ivory', '200 x 300'),
    // Karpi-modellen onder het white-label "Core by Dersimo" (zelfde
    // kleurnummers als Karpi — vrijwel zeker hetzelfde kleed)
    kk('Cisco',       '6748/core-by-dersimo/cisco-24-donker-grijs', '200 x 290'),
    kk('Marich',      '6650/core-by-dersimo/marich-22-antraciet-grijs', '200 x 290'),
    kk('Bilal',       '6831/core-by-dersimo/bilal-11-grijs-ivory', '200 x 290'),
  ],
});
