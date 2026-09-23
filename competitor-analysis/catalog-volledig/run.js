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
 *   CATALOG_CSV        – pad naar de PIM-export CSV (default: ../../HW-PIM/Result_6.csv)
 *   CONCURRENCY        – parallelle HTTP-verzoeken (default: 6)
 *   SCRAPER_BUDGET_MIN – totale looptijd (default 50). Moet onder de harde
 *                        limiet in PHP blijven (competitor_pricing.scraper_timeout,
 *                        60 min): raakt PHP die, dan sterft dit proces en wordt
 *                        er die nacht niets geïmporteerd. Binnen het budget
 *                        stopt een uitlopende winkel en gaat de rest door
 *                        (zie shop-runner.js), dus deze run eindigt altijd op tijd.
 */

const { execSync } = require('child_process');
const path = require('path');
const fs   = require('fs');

const DIR = __dirname;

const STARTED = Date.now();
const BUDGET_MS = Number(process.env.SCRAPER_BUDGET_MIN || 50) * 60_000;

/**
 * @param {number} [stageShare] deel van het totaalbudget waarop deze stap klaar
 *                              moet zijn (0–1); winkels die dan nog lopen
 *                              worden gestopt
 */
function run(script, extraArgs = '', stageShare = null) {
  const cmd = `node ${path.join(DIR, script)} ${extraArgs}`.trim();
  const env = { ...process.env };
  if (stageShare) env.STAGE_DEADLINE = String(STARTED + BUDGET_MS * stageShare);
  console.log(`\n${'═'.repeat(60)}`);
  console.log(`▶  ${cmd}   (${((Date.now() - STARTED) / 60_000).toFixed(1)} min verstreken)`);
  console.log('═'.repeat(60));
  execSync(cmd, { stdio: 'inherit', env });
}

const args       = process.argv.slice(2).join(' ');
const onlyExcel  = args.includes('--only-excel');
const shopFilter = (() => {
  const idx = process.argv.indexOf('--shop');
  return idx !== -1 ? `--shop ${process.argv[idx + 1]}` : '';
})();
const reset = args.includes('--reset') ? '--reset' : '';

if (!onlyExcel) {
  run('index-shops.js',   [shopFilter, reset].filter(Boolean).join(' '), 0.6);
  run('fetch-prices.js',  shopFilter, 0.9);
  // Hertoets de hele voorraad aan de huidige matchguards. Standaard alleen
  // rapporteren; met --audit-fix worden afgekeurde koppelingen verwijderd
  // (audit-prices.js weigert dat bij een onvolledige catalogus-CSV).
  run('audit-prices.js',  [shopFilter, args.includes('--audit-fix') ? '--fix' : ''].filter(Boolean).join(' '));
}
run('excel.js');
console.log(`\nKlaar na ${((Date.now() - STARTED) / 60_000).toFixed(1)} min (budget ${BUDGET_MS / 60_000} min).`);
