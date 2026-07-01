/**
 * karpettenshop.nl – Magento 1 (Product.Options). De maattabel koppelt
 * <label>200 x 300 cm</label> aan een <span class="price">€ 2.169,00</span>
 * (€ gevolgd door NBSP; Vogue-label zonder " cm") -> regexPrijs.
 * De eerdere JSON-LD "€299,17" was de kleinste maat EXCL. btw — nooit
 * gebruiken; deze tabelprijzen zijn incl. btw.
 */
const { test, expect } = require('@playwright/test');
const { registerKarpetten } = require('./_helpers');

const PAT = '>200 x 300(?: cm)?</label>[\\s\\S]{0,400}?<span class="price">€\\s?([\\d.,]+)</span>';
const ks = (model, slug) => ({
  model,
  regexUrl: `https://www.karpettenshop.nl/merken/de-munk-carpets/${slug}.html`,
  pattern: PAT,
});

registerKarpetten(test, expect, {
  shop: 'karpettenshop.nl',
  items: [
    ks('Firenze', 'de-munk-carpets-firenze-fi-24'),
    ks('Genova',  'de-munk-carpets-genova-ge-01'),
    ks('Vogue',   'de-munk-carpets-vogue-33-2660'),
    ks('Venezia', 'de-munk-carpets-venezia-ve-10'),
    ks('Toscane', 'de-munk-carpets-toscane-to-01'),
  ],
});
