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
