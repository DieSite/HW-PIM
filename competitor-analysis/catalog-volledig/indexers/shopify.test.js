/**
 * shopify.test.js – de Shopify-indexer tegen een nagebootste /products.json.
 *
 * Draaien: npm run volledig:test
 *
 * De fixture volgt youlikeitwonen.nl (23-09-2026): vendor is overal de eigen
 * winkelnaam, het merk staat nergens, en één product draagt meerdere vormen
 * ("Spectrum 3333 Rechthoekig & Rond").
 */

const { test, after } = require('node:test');
const assert = require('node:assert');
const fs     = require('node:fs');
const os     = require('node:os');
const path   = require('node:path');
const http   = require('node:http');

const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'shopify-indexer-'));
process.env.CATALOG_DB = path.join(dir, 'test.db');

const { indexShopify } = require('./shopify');
const { openDb } = require('../storage');
const { loadCatalog } = require('../catalog');

const variant = (title, price) => ({ title, public_title: title, price: String(price) });

const PRODUCTS = [
  {
    title: 'Vloerkleed Spectrum 3333 Rechthoekig & Rond',
    handle: 'vloerkleed-spectrum-3333-rechthoekig',
    vendor: 'You Like It Wonen',
    tags: ['vloerkleed'],
    variants: [variant('200x290 cm', 519), variant('Rond 200 cm', 370)],
  },
  {
    title: 'Vloerkleed Anaheim 3434 Rond Of Ovaal',
    handle: 'vloerkleed-rond-of-ovaal-anaheim-3434',
    vendor: 'You Like It Wonen',
    tags: ['vloerkleed'],
    // De kale maat is hier dubbelzinnig (rond of ovaal?) en mag nergens op
    // koppelen — ook niet op de rechthoek van 200 x 290.
    variants: [variant('Ovaal 160x230 cm', 305), variant('Rond  Ø 200 cm', 319), variant('200x290 cm', 999)],
  },
  {
    title: 'Vloerkleed Rond Of Ovaal Amado 6474',
    handle: 'vloerkleed-rond-of-ovaal-amado-6474',
    vendor: 'You Like It Wonen',
    tags: ['vloerkleed'],
    variants: [variant('Rond 2 meter doorsnede', 319), variant('Rond 240 cm doorsnede', 459)],
  },
  {
    title: 'Vloerkleed Rechthoekig Mad Men 8618',
    handle: 'vloerkleed-rechthoekig-mad-men-8618',
    vendor: 'You Like It Wonen',
    tags: ['vloerkleed'],
    variants: [variant('200x280 cm', 799)],
  },
  {
    // Geen vloerkleed-tag: valt buiten het filter, ook al heet hij zoals een
    // van onze modellen.
    title: 'Spectrum 3333 Verf',
    handle: 'spectrum-3333-verf',
    vendor: 'You Like It Wonen',
    tags: ['verf'],
    variants: [variant('200x290 cm', 1)],
  },
];

const CSV = [
  'SKU,Merk,Model,Maat,Prijs,Kleuren',
  'ERG0340.6,Eurogros,Spectrum 3333,200 cm x 290 cm,499,Bruin',
  'ERG0340.9.R,Eurogros,Spectrum 3333 Rond,Rond 200 cm,349,Bruin',
  'ERG0047.3,Eurogros,Anaheim 3434,200 cm x 290 cm,439,Multi',
  'ERG0047.5.R,Eurogros,Anaheim 3434 Rond,Rond 200 cm,319,Multi',
  'ERG0047.7.V,Eurogros,Anaheim 3434 Ovaal,160 cm x 230 cm,299,Multi',
  'ERG0044.5.R,Eurogros,Amado 6474 Rond,Rond 200 cm,319,Multi',
  'ERG0044.6.R,Eurogros,Amado 6474 Rond,Rond 240 cm,449,Multi',
].join('\n');

/** Paden die de server zag, en hoeveel verzoeken hij nog met 429 weigert. */
const requested = [];
let refuseNext = 0;

const server = http.createServer((req, res) => {
  requested.push(new URL(req.url, 'http://x').pathname);
  if (refuseNext > 0) {
    refuseNext--;
    res.statusCode = 429;
    return res.end('Verifying your connection...');
  }
  const page = Number(new URL(req.url, 'http://x').searchParams.get('page'));
  res.setHeader('content-type', 'application/json');
  res.end(JSON.stringify({ products: page === 1 ? PRODUCTS : [] }));
});

after(() => {
  server.close();
  fs.rmSync(dir, { recursive: true, force: true });
});

let base;
const listening = new Promise(resolve => server.listen(0, '127.0.0.1', () => {
  base = `http://127.0.0.1:${server.address().port}`;
  resolve();
}));

test('youlikeitwonen: merk uit de winkelconfig, vorm per variant', async () => {
  await listening;

  const csvPath = path.join(dir, 'catalog.csv');
  fs.writeFileSync(csvPath, CSV);
  const catalog = loadCatalog(csvPath);
  const db = openDb();

  await indexShopify(db, {
    shop: 'youlikeitwonen.nl',
    base,
    brands: ['Eurogros'],
    catalogModels: catalog.models,
    bySku: catalog.bySku,
    fallbackBrand: 'Eurogros',
    productTags: ['vloerkleed'],
  });

  const prices = Object.fromEntries(
    db.prepare(`SELECT sku, price_str FROM prices WHERE shop = 'youlikeitwonen.nl'`).all()
      .map(r => [r.sku, r.price_str]),
  );

  assert.deepStrictEqual(prices, {
    'ERG0340.6':   '€ 519,00',
    'ERG0340.9.R': '€ 370,00',
    'ERG0047.5.R': '€ 319,00',
    'ERG0047.7.V': '€ 305,00',
    'ERG0044.5.R': '€ 319,00',
    'ERG0044.6.R': '€ 459,00',
  });
});

test('dfmwonen: distributeur als vendor en maat in de titel bij "Default Title"', async () => {
  await listening;

  const csvPath = path.join(dir, 'catalog-dfm.csv');
  fs.writeFileSync(csvPath, [
    'SKU,Merk,Model,Maat,Prijs,Kleuren',
    'ERG0154.3,Louis De Poortere,Fading World Medallion 8261,200 cm x 280 cm,899,Rood',
    'ERG0418.2,Eurogros,Kapiti 175,170 cm x 230 cm,879,Zwart',
    'ERG0430.2,Eurogros,Kapiti Black 175,170 cm x 230 cm,979,Zwart',
    'ERG1040.3,Eurogros,Acampo 2626,200 cm x 290 cm,499,Grijs',
    'ERG1041.3,Eurogros,Acampo 7270,200 cm x 290 cm,499,Terra',
  ].join('\n'));
  const catalog = loadCatalog(csvPath);

  PRODUCTS.splice(0, PRODUCTS.length,
    {
      title: 'Vloerkleed Fading World - Kleur 8261',
      handle: 'vloerkleed-fading-world-8261',
      vendor: 'Eurogros',
      tags: [],
      variants: [variant('Rechthoek / 200 x 280 cm', 889)],
    },
    {
      // Eén variant zonder opties: de maat staat alleen in de titel, en
      // "175 - 170" mag niet als maat gelezen worden.
      title: 'Vloerkleed Kapiti Black - Kleur 175 - 170 x 230 cm',
      handle: 'kapiti-black-175-170x230',
      vendor: 'Eurogros',
      tags: [],
      variants: [variant('Default Title', 989)],
    },
    {
      // Het dessinnummer staat alleen in de handle
      title: 'Vloerkleed Acampo Grijs Multicolor',
      handle: 'vloerkleed-acampo-2626',
      vendor: 'Eurogros',
      tags: [],
      variants: [variant('Rechthoek / 200 x 290 cm', 525)],
    },
  );

  const db = openDb();
  await indexShopify(db, {
    shop: 'dfmwonen.nl',
    base,
    brands: ['Eurogros'],
    catalogModels: catalog.models,
    bySku: catalog.bySku,
    vendorAliases: { Eurogros: ['Louis De Poortere'] },
  });

  const prices = Object.fromEntries(
    db.prepare(`SELECT sku, price_str FROM prices WHERE shop = 'dfmwonen.nl'`).all()
      .map(r => [r.sku, r.price_str]),
  );

  assert.deepStrictEqual(prices, {
    'ERG0154.3': '€ 889,00',
    'ERG0430.2': '€ 989,00',
    'ERG1040.3': '€ 525,00',
  });
});

test('mooierthuis: alleen de collectie, en een 429 is een pauze, geen einde', async () => {
  await listening;
  const csvPath = path.join(dir, 'catalog-mt.csv');
  fs.writeFileSync(csvPath, 'ERG1040.3,Eurogros,Acampo 2626,200 cm x 290 cm,499,Grijs');
  const catalog = loadCatalog(csvPath);
  const opts = {
    shop: 'mooierthuis.nl',
    base,
    brands: ['Eurogros'],
    catalogModels: catalog.models,
    bySku: catalog.bySku,
    collection: 'vloerkleden',
    pageDelayMs: 0,
    retryDelaysMs: [0, 0],
  };

  requested.length = 0;
  refuseNext = 2;
  const result = await indexShopify(openDb(), opts);
  assert.strictEqual(result.priced, 1);
  assert.ok(requested.every(p => p === '/collections/vloerkleden/products.json'), requested.join(', '));

  // Blijft de winkel weigeren, dan is de index onvolledig: dat moet een fout zijn.
  refuseNext = 3;
  await assert.rejects(indexShopify(openDb(), opts), /ONVOLLEDIG/);
  refuseNext = 0;
});
