/**
 * run.js – alles-in-één runner voor de catalog-volledig suite.
 *
 * Aanroepen:
 *   node catalog-volledig/run.js
 *   node catalog-volledig/run.js --shop vloerkledenloods.nl
 *   node catalog-volledig/run.js --only-excel
 *   node catalog-volledig/run.js --reset
 *
 * Stappen:
 *   1. index-shops.js  – crawl alle competitors → competitor_index + Shopify/WC prijzen
 *   2. fetch-prices.js – haal prijzen op voor custom shops
 *   3. excel.js        – bouw concurrenten-volledig.xlsx
 *
 * Omgevingsvariabelen:
 *   CATALOG_CSV   – pad naar de PIM-export CSV (default: ../../HW-PIM/Result_6.csv)
 *   CONCURRENCY   – parallelle HTTP-verzoeken (default: 6)
 */

const { execSync } = require('child_process');
const path = require('path');
const fs   = require('fs');

const DIR = __dirname;

function run(script, extraArgs = '') {
  const cmd = `node ${path.join(DIR, script)} ${extraArgs}`.trim();
  console.log(`\n${'═'.repeat(60)}`);
  console.log(`▶  ${cmd}`);
  console.log('═'.repeat(60));
  execSync(cmd, { stdio: 'inherit', env: { ...process.env } });
}

const args       = process.argv.slice(2).join(' ');
const onlyExcel  = args.includes('--only-excel');
const shopFilter = (() => {
  const idx = process.argv.indexOf('--shop');
  return idx !== -1 ? `--shop ${process.argv[idx + 1]}` : '';
})();
const reset = args.includes('--reset') ? '--reset' : '';

if (!onlyExcel) {
  run('index-shops.js',   [shopFilter, reset].filter(Boolean).join(' '));
  run('fetch-prices.js',  shopFilter);
}
run('excel.js');
