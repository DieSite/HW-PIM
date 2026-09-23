/**
 * woocommerce.test.js – het doorbladeren van de WooCommerce-catalogus.
 *
 * Draaien: npm run volledig:test
 *
 * Aanleiding: op 23-09-2026 las wooPage één mislukte API-pagina bij
 * grootinvloeren.nl als "einde van de catalogus". De index stopte na 233 van
 * de ~1.280 prijzen zonder één regel in de log.
 */

const { test } = require('node:test');
const assert = require('node:assert');
const fs     = require('node:fs');
const os     = require('node:os');
const path   = require('node:path');

process.env.CATALOG_DB = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'woo-indexer-')), 'test.db');

const { wooPage } = require('./woocommerce');

const NO_WAIT = { retryDelaysMs: [0, 0] };

test('een tijdelijke fout wordt herhaald in plaats van als einde gelezen', async () => {
  let calls = 0;
  const fetchJson = async () => {
    calls++;
    if (calls <= 2) throw new Error('Timeout: https://shop.test/wp-json/wc/store/v1/products');
    return [{ id: 1, name: 'Vloerkleed Derbe 7252' }];
  };

  const products = await wooPage('https://shop.test', 4, 'shop.test', { ...NO_WAIT, fetchJson });

  assert.strictEqual(products.length, 1);
});

test('een pagina die blijft falen gooit, zodat de halve index in de log staat', async () => {
  const fetchJson = async () => { throw new Error('HTTP 503 for https://shop.test/…'); };

  await assert.rejects(
    wooPage('https://shop.test', 4, 'shop.test', { ...NO_WAIT, fetchJson }),
    /shop\.test catalogus pagina 4 blijft falen.*ONVOLLEDIG/,
  );
});

test('HTTP 400 is voorbij de laatste pagina: een lege lijst, geen fout', async () => {
  let calls = 0;
  const fetchJson = async () => {
    calls++;
    throw new Error('HTTP 400 for https://shop.test/wp-json/wc/store/v1/products?page=20');
  };

  const products = await wooPage('https://shop.test', 20, 'shop.test', { ...NO_WAIT, fetchJson });

  assert.deepStrictEqual(products, []);
  assert.strictEqual(calls, 1);
});

test('valt terug op v2 als v1 geen productlijst geeft', async () => {
  const fetchJson = async url => (url.includes('/v1/') ? { code: 'rest_no_route' } : [{ id: 2 }]);

  const products = await wooPage('https://shop.test', 1, 'shop.test', { ...NO_WAIT, fetchJson });

  assert.deepStrictEqual(products, [{ id: 2 }]);
});
