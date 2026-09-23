/**
 * shop-runner.test.js – één winkel die uitloopt mag de rest niet ophouden.
 *
 * Draaien: npm run volledig:test
 */

const { test } = require('node:test');
const assert = require('node:assert');
const fs     = require('node:fs');
const os     = require('node:os');
const path   = require('node:path');

const { runPerShop } = require('./shop-runner');

const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'shop-runner-'));

// Nepscript: "traag.nl" blijft hangen, "kapot.nl" faalt, de rest schrijft een
// bestand zodat we zien dat hij echt heeft gedraaid.
const script = path.join(dir, 'fake-shop.js');
fs.writeFileSync(script, `
  const shop = process.argv[process.argv.indexOf('--shop') + 1];
  if (shop === 'traag.nl') setInterval(() => {}, 1000);
  else if (shop === 'kapot.nl') process.exit(1);
  else require('fs').writeFileSync(${JSON.stringify(dir)} + '/' + shop, 'ok');
`);

test('een winkel die uitloopt wordt gestopt, de andere winkels draaien gewoon', async () => {
  const results = await runPerShop(script, ['traag.nl', 'a.nl', 'kapot.nl', 'b.nl'], [], {
    timeoutMs: 1500,
    concurrency: 2,
  });
  const status = Object.fromEntries(results.map(r => [r.shop, r.status]));

  assert.deepStrictEqual(status, { 'traag.nl': 'timeout', 'a.nl': 'ok', 'kapot.nl': 'failed', 'b.nl': 'ok' });
  assert.ok(fs.existsSync(path.join(dir, 'a.nl')));
  assert.ok(fs.existsSync(path.join(dir, 'b.nl')));
});

test('na de eindtijd van de stap worden winkels overgeslagen in plaats van gestart', async () => {
  const results = await runPerShop(script, ['c.nl'], [], { deadline: Date.now() + 1000 });
  assert.strictEqual(results[0].status, 'skipped');
  assert.ok(!fs.existsSync(path.join(dir, 'c.nl')));
});
