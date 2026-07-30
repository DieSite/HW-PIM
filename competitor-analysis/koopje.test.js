/**
 * Unit tests voor de prijsregel van koopje-horren (tests/_koopje.js).
 * Draaien: `npm run test:koopje`.
 */

const test = require('node:test');
const assert = require('node:assert');
const { parseOptionConfig, prijsUitSheet, gaasOptie } = require('./tests/_koopje');

/** Uitsnede van de echte optionConfig van de enkele deur (30-07-2026). */
const ENKEL = {
  pricesheet: {
    1791: { 580: '179', 960: '233', 1300: '247', 1600: '289', 1700: 'x', 1800: 'x', 1900: 'x' },
    1970: { 580: '233', 960: '233', 1300: '247', 1600: '289', 1700: '329', 1800: '329', 1900: 'x' },
    2390: { 580: '254', 960: '254', 1300: '269', 1600: '304', 1700: '344', 1800: '344', 1900: '344' },
    2960: { 580: '268', 960: '268', 1300: '284', 1600: '319', 1700: '359', 1800: '359', 1900: '359' },
  },
  vals: { col: [580, 960, 1300, 1600, 1700, 1800, 1900], row: [1791, 1970, 2390, 2960] },
  row: { type: 'field', id: '5', min: 1791, max: 2960 },
  col: { type: 'field', id: '4', min: 580, max: 1900 },
  otheroptions: {
    1: {
      1: { title: 'In de dag (tussen het kozijn)', price: '0.000000', price_type: 'fixed' },
      798: { title: 'Op de dag (Op het kozijn)', price: '25.000000', price_type: 'percent' },
    },
    4: { type: 'field', title: 'Breedte (millimeters)', price: '0.000000' },
    13: {
      19: { title: 'Grijs (maximaal 2600mm hoog)', price: '0.000000', price_type: 'fixed' },
      20: { title: 'Zwart', price: '0.000000', price_type: 'fixed' },
    },
    14: {
      21: { title: 'Standaard ondergeleider grijs', price: '0.000000', price_type: 'fixed' },
    },
  },
};

test('kiest de eerste band die de maat dekt, niet de vanaf-prijs', () => {
  // "Enkele klein" = 730×1970 -> rij 1970, kolom 960.
  assert.strictEqual(prijsUitSheet(ENKEL, 730, 1970), 233);
  // Exact op een bandgrens telt die band zelf.
  assert.strictEqual(prijsUitSheet(ENKEL, 960, 1791), 233);
  assert.strictEqual(prijsUitSheet(ENKEL, 1900, 2390), 344);
});

test('geeft null bij een niet-leverbare combinatie ("x")', () => {
  assert.strictEqual(prijsUitSheet(ENKEL, 1900, 1970), null);
  assert.strictEqual(prijsUitSheet(ENKEL, 1700, 1791), null);
});

test('geeft null buiten het bereik van de invoervelden', () => {
  assert.strictEqual(prijsUitSheet(ENKEL, 1950, 1970), null); // breder dan 1900
  assert.strictEqual(prijsUitSheet(ENKEL, 730, 3000), null);  // hoger dan 2960
  assert.strictEqual(prijsUitSheet(ENKEL, 400, 1970), null);  // smaller dan 580
  assert.strictEqual(prijsUitSheet(ENKEL, 730, 1700), null);  // lager dan 1791
});

test('geeft null zonder bruikbare prijstabel', () => {
  assert.strictEqual(prijsUitSheet(null, 730, 1970), null);
  assert.strictEqual(prijsUitSheet({}, 730, 1970), null);
});

test('leest de gaaskleur-optie met zijn hoogtegrens uit de optietabel', () => {
  assert.deepStrictEqual(gaasOptie(ENKEL, 'grijs'), {
    titel: 'Grijs (maximaal 2600mm hoog)',
    meerprijs: 0,
    maxHoogte: 2600,
  });
  assert.deepStrictEqual(gaasOptie(ENKEL, 'zwart'), {
    titel: 'Zwart',
    meerprijs: 0,
    maxHoogte: null,
  });
  assert.strictEqual(gaasOptie(ENKEL, 'rood'), null);
});

test('verwart een ondergeleider-optie niet met de gaaskleur', () => {
  // "Standaard ondergeleider grijs" bevat wel "grijs" maar begint er niet mee.
  assert.strictEqual(gaasOptie(ENKEL, 'grijs').titel, 'Grijs (maximaal 2600mm hoog)');
});

test('haalt de optionConfig uit de pagina-HTML', () => {
  const html = `
    <script type="text/x-magento-init">
    {
      "#product_addtocart_form": {
        "MageArray_Customprice/js/customprice": {
          "optionConfig": {"pricesheet":{"1791":{"580":"179"}},"vals":{"col":[580],"row":[1791]},
            "row":{"min":1791,"max":1791},"col":{"min":580,"max":580},
            "alert":{"notfound":"De gekozen maat is niet beschikbaar {\\"nested\\"}"}}
        }
      }
    }
    </script>`;

  const cfg = parseOptionConfig(html);
  assert.strictEqual(prijsUitSheet(cfg, 580, 1791), 179);
});

test('geeft null als de pagina geen customprice-configurator heeft', () => {
  assert.strictEqual(parseOptionConfig('<html><body>geen configurator</body></html>'), null);
});
