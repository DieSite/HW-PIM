/**
 * storage.test.js – bewaakt dat het opslaglaagje een Node-proces schoon laat
 * eindigen.
 *
 * Productie draait Node 24. better-sqlite3 11.x is daar niet tegen bestand: de
 * Statement-destructors lopen bij het afbreken van het proces ná de Environment
 * en het proces sterft met SIGABRT ("Assertion failed: (env) != nullptr" in
 * node::RemoveEnvironmentCleanupHook). Het werk in de database is dan al
 * gecommit, maar `run.js` draait de stappen met execSync — een exitcode 134 op
 * index-shops.js breekt dus de hele pijplijn af (geen prijzen, geen Excel).
 *
 * De crash treedt pas op bij voldoende Statement-objecten (~20k) en is daarom
 * onzichtbaar in kleine scripts. Vandaar een kindproces met realistisch volume.
 */

const test = require('node:test');
const assert = require('node:assert');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

test('better-sqlite3 ondersteunt de Node-versie waarop we draaien', () => {
  const { version } = require('better-sqlite3/package.json');
  const major = Number(version.split('.')[0]);

  assert.ok(
    major >= 12,
    `better-sqlite3 ${version} is geïnstalleerd; 11.x crasht bij het afsluiten op Node >= 24`
  );
});

test('een schrijfzware run eindigt met exitcode 0', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'catalog-storage-'));
  const script = `
    const { openDb, recordPrice } = require(${JSON.stringify(path.join(__dirname, 'storage.js'))});
    const db = openDb();
    for (let i = 0; i < 25000; i++) {
      recordPrice(db, 'SKU' + i, 'voorbeeld.nl', '€ ' + i + ',00', 'https://voorbeeld.nl/' + i);
    }
  `;

  try {
    execFileSync(process.execPath, ['-e', script], {
      env: { ...process.env, CATALOG_DB: path.join(dir, 'test.db') },
      stdio: 'pipe',
    });
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

test('prijzen van SKU\'s buiten de catalogus worden opgeruimd', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'catalog-prune-'));
  process.env.CATALOG_DB = path.join(dir, 'prune.db');

  try {
    delete require.cache[require.resolve('./storage.js')];
    const { openDb, recordPrice, pruneUnknownSkus } = require('./storage.js');
    const db = openDb();

    const known = new Set();
    for (let i = 0; i < 1000; i++) {
      const sku = 'SKU' + i;
      known.add(sku);
      recordPrice(db, sku, 'voorbeeld.nl', '€ 100,00', 'https://voorbeeld.nl/' + i);
    }

    // Een met-onderkleed-variant staat niet in de catalogus: die is er bewust
    // uit gehaald en komt dus nooit meer langs de scraper.
    recordPrice(db, 'SKU1.O', 'voorbeeld.nl', '€ 130,00', 'https://voorbeeld.nl/1');
    recordPrice(db, 'OPGEHEVEN', 'voorbeeld.nl', '€ 200,00', null);

    const { pruned, skipped } = pruneUnknownSkus(db, known);

    assert.strictEqual(skipped, false);
    assert.strictEqual(pruned, 2);
    assert.strictEqual(db.prepare('SELECT COUNT(*) c FROM prices').get().c, 1000);
    assert.strictEqual(db.prepare("SELECT COUNT(*) c FROM prices WHERE sku = 'SKU1.O'").get().c, 0);

    // De rem: een halve of mislukte catalogus-export mag de voorraad niet wissen.
    const brake = pruneUnknownSkus(db, new Set(['SKU1']));
    assert.strictEqual(brake.skipped, true);
    assert.strictEqual(brake.pruned, 0);
    assert.strictEqual(db.prepare('SELECT COUNT(*) c FROM prices').get().c, 1000);

    db.close();
  } finally {
    delete process.env.CATALOG_DB;
    delete require.cache[require.resolve('./storage.js')];
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

test('standaard wordt elke prijs elke run opnieuw opgehaald', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'catalog-refresh-'));
  process.env.CATALOG_DB = path.join(dir, 'refresh.db');

  try {
    delete require.cache[require.resolve('./storage.js')];
    const { openDb, recordPrice, unpricedSkus } = require('./storage.js');
    const db = openDb();

    recordPrice(db, 'VERS', 'voorbeeld.nl', '\u20ac 100,00', 'https://voorbeeld.nl/1');
    const alle = ['VERS', 'ONBEKEND'];

    // Standaard (0): een prijs van vannacht is morgen gewoon weer aan de beurt.
    assert.deepStrictEqual(unpricedSkus(db, 'voorbeeld.nl', alle, 0), alle);

    // Met een cyclus blijft een verse prijs staan; dat scheelt fetches maar
    // laat de prijs navenant achterlopen.
    assert.deepStrictEqual(unpricedSkus(db, 'voorbeeld.nl', alle, 7), ['ONBEKEND']);

    db.close();
  } finally {
    delete process.env.CATALOG_DB;
    delete require.cache[require.resolve('./storage.js')];
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
