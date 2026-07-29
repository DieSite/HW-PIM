/**
 * eigenwinkel.test.js – de prijsregel van de eigen winkel.
 *
 * Draaien: npm run test:eigenwinkel   (node --test, geen extra dependencies)
 *
 * Staat bewust BUITEN tests/: Playwright's testDir is './tests' en zijn default
 * testMatch pakt zowel *.spec.js als *.test.js, dus hierbinnen zou `npm test`
 * dit bestand als browser-spec proberen te draaien.
 */

const { test } = require('node:test');
const assert = require('node:assert');

const { parseEuro, eigenWinkelPrijs, euro } = require('./tests/_eigenwinkel');

/**
 * Letterlijk overgenomen uit de live configurator (730×1970, zwart gaas,
 * RAL 9010, geen handgreep, geen powertape) op 2026-07-29. De van-prijs
 * (€ 465,00) is er al uit, zoals de spec hem ook aanlevert.
 */
const LIVE_730x1970 = [
  ['Situatie', 'In de dag', '-'],
  ['Hordeur', '730mm x 1970mm', '€ 289,00'],
  ['Drempel', 'Rechtaflopend', '-'],
  ['Framekleur', 'Standaardkleur', '-'],
  ['Standaardkleur', 'RAL 9010 (wit)', '-'],
  ['Gaaskleur', 'Zwart gaas', '-'],
  ['Handgreep', 'Nee, zonder handgreep', '-'],
  ['Powertape', 'Nee, ik ga schroeven', '-'],
  ['Levering', 'Afhalen in Gorinchem (extra korting)', '€ -5,20'],
  ['Levertijd', 'Standaardkleuren: 6-8 werkdagen', '-'],
  ['Subtotaal', '', '€ 283,80'],
  ['Korting', 'Zomerkorting 2026', '€ -29,00'],
  ['Totaal', '', '€ 254,80'],
];

test('trekt de promokorting van de kale hordeurprijs af', () => {
  assert.equal(eigenWinkelPrijs(LIVE_730x1970), 260);
  assert.equal(euro(eigenWinkelPrijs(LIVE_730x1970)), '€ 260,00');
});

/**
 * De regel waar dit misging: de Levering-regel heet zelf "Afhalen in Gorinchem
 * (extra korting)". Wie op de tekst "korting" matcht, trekt die €5,20 er ook
 * af en komt op € 254,80 — het Totaal, dat alleen geldt als de klant ophaalt.
 */
test('laat de afhaalkorting staan, ook al heet die zelf "korting"', () => {
  assert.notEqual(eigenWinkelPrijs(LIVE_730x1970), 254.8);
});

test('gebruikt de kale prijs als er geen promo loopt', () => {
  const zonderPromo = LIVE_730x1970.filter(r => r[0] !== 'Korting');

  assert.equal(eigenWinkelPrijs(zonderPromo), 289);
});

test('telt meerdere promoregels bij elkaar op', () => {
  const twee = [
    ['Hordeur', '730mm x 1970mm', '€ 289,00'],
    ['Korting', 'Zomerkorting 2026', '€ -29,00'],
    ['Korting', 'Kortingscode WELKOM', '€ -10,00'],
  ];

  assert.equal(eigenWinkelPrijs(twee), 250);
});

test('geeft null zonder Hordeur-regel, zodat de spec n.v.t. noteert', () => {
  assert.equal(eigenWinkelPrijs([['Situatie', 'In de dag', '-']]), null);
  assert.equal(eigenWinkelPrijs([]), null);
  assert.equal(eigenWinkelPrijs(null), null);
});

test('parseEuro leest negatieve, duizendtal- en lege bedragen', () => {
  assert.equal(parseEuro('€ -29,00'), -29);
  assert.equal(parseEuro('€ 1.234,50'), 1234.5);
  assert.equal(parseEuro('€ 289,00'), 289);
  assert.equal(parseEuro('-'), null);
  assert.equal(parseEuro(''), null);
  assert.equal(parseEuro(undefined), null);
});

test('euro formatteert zoals de andere specs noteren', () => {
  assert.equal(euro(260), '€ 260,00');
  assert.equal(euro(1234.5), '€ 1.234,50');
  assert.equal(euro(null), null);
});
