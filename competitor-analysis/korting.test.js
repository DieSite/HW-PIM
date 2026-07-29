/**
 * Unit tests voor de kortingsregel van horrentotaal (tests/_korting.js).
 * Draaien: `npm run test:korting`.
 */

const test = require('node:test');
const assert = require('node:assert');
const { bestDiscount, priceAfterDiscount } = require('./tests/_korting');

/** De actie zoals hun API hem op 2026-07-29 teruggaf. */
const TIEN_PROCENT = [{ type: 'percentage', value: 0.1, title: '10% Korting', endsAt: null }];

test('trekt een lopend kortingspercentage van de prijs af', () => {
  assert.strictEqual(priceAfterDiscount(302, TIEN_PROCENT), 271.8);
});

test('rekent de korting ook over de meerprijs voor grijs gaas', () => {
  // 302 basis + 39 grijs gaas = 341 bruto, zoals hun widget rekent.
  assert.strictEqual(priceAfterDiscount(302 + 39, TIEN_PROCENT), 306.9);
});

test('laat de prijs ongemoeid als er geen actie loopt', () => {
  assert.strictEqual(priceAfterDiscount(302, []), 302);
  assert.strictEqual(priceAfterDiscount(302, null), 302);
  assert.strictEqual(bestDiscount(302, []), null);
});

test('past een vast bedrag toe en nooit meer dan de prijs zelf', () => {
  assert.strictEqual(priceAfterDiscount(302, [{ type: 'amount', value: 25 }]), 277);
  assert.strictEqual(priceAfterDiscount(20, [{ type: 'amount', value: 25 }]), 0);
});

test('stapelt niet maar kiest de gunstigste korting', () => {
  const kortingen = [
    { type: 'percentage', value: 0.1 },
    { type: 'amount', value: 50 },
  ];

  // 10% van 302 = 30,20 en dus minder dan € 50: het vaste bedrag wint, en
  // ze worden niet bij elkaar opgeteld (302 - 30,20 - 50 zou 221,80 zijn).
  assert.strictEqual(priceAfterDiscount(302, kortingen), 252);
});

test('negeert een volumekorting, want die geldt pas vanaf een aantal', () => {
  const kortingen = [{ type: 'volume', value: 0.15, threshold: 5 }];

  assert.strictEqual(bestDiscount(302, kortingen), null);
  assert.strictEqual(priceAfterDiscount(302, kortingen), 302);
});

test('negeert een onzinnige korting in plaats van een prijs van nul te noteren', () => {
  assert.strictEqual(priceAfterDiscount(302, [{ type: 'percentage', value: 1 }]), 302);
  assert.strictEqual(priceAfterDiscount(302, [{ type: 'percentage', value: -0.5 }]), 302);
  assert.strictEqual(priceAfterDiscount(302, [{ type: 'onbekend', value: 0.5 }]), 302);
});

test('rondt af op centen zoals hun widget', () => {
  assert.strictEqual(priceAfterDiscount(333.33, TIEN_PROCENT), 300);
  assert.strictEqual(priceAfterDiscount(199.99, TIEN_PROCENT), 179.99);
});
