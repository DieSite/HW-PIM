/**
 * shop-runner.js – draait een script per winkel in een eigen kindproces.
 *
 * Waarom: de nachtelijke run heeft in PHP een harde limiet (scraper_timeout,
 * 1 uur). Wordt die geraakt, dan sterft het hele node-proces en is de nacht
 * verloren: geen import, geen rapport. Met alle winkels na elkaar in één
 * proces kon één trage of blokkerende winkel (maxwonen.nl: een uur aan 429's)
 * dat voor iedereen veroorzaken.
 *
 * Nu krijgt elke winkel een eigen proces en een eigen tijdslimiet, en draaien
 * er een paar tegelijk (elke winkel is een andere server, dus per winkel blijft
 * het even rustig). Loopt een winkel uit, dan wordt alleen dat proces gestopt.
 * Wat hij al had opgeslagen blijft staan — prijzen zijn sticky, dus zijn
 * vorige prijzen blijven gewoon gelden — en de andere winkels lopen door.
 *
 * Omgevingsvariabelen:
 *   SHOP_TIMEOUT_MIN   – maximale duur per winkel (default 12)
 *   SHOP_CONCURRENCY   – winkels tegelijk (default 4)
 *   STAGE_DEADLINE     – epoch-ms waarop de hele stap klaar moet zijn (gezet
 *                        door run.js); winkels die dan nog lopen worden gestopt
 *                        en nog niet gestarte winkels overgeslagen
 */

const { spawn } = require('child_process');

const SHOP_TIMEOUT_MS = Number(process.env.SHOP_TIMEOUT_MIN || 12) * 60_000;
const CONCURRENCY = Number(process.env.SHOP_CONCURRENCY || 4);
/** Onder deze resttijd heeft het geen zin meer een winkel te starten. */
const MIN_START_MS = 60_000;

/**
 * @param {string}   script   absoluut pad naar het script
 * @param {string[]} shopKeys winkels om te draaien
 * @param {string[]} [extraArgs]
 * @param {object}   [opts]
 * @param {number}   [opts.timeoutMs]    tijdslimiet per winkel
 * @param {number}   [opts.concurrency]
 * @param {number}   [opts.deadline]     epoch-ms: eind van de hele stap
 * @returns {Promise<Array<{shop: string, status: 'ok'|'failed'|'timeout'|'skipped', ms: number}>>}
 */
async function runPerShop(script, shopKeys, extraArgs = [], {
  timeoutMs = SHOP_TIMEOUT_MS,
  concurrency = CONCURRENCY,
  deadline = Number(process.env.STAGE_DEADLINE) || Infinity,
} = {}) {
  const queue = [...shopKeys];
  const results = [];

  async function worker() {
    while (queue.length) {
      const shop = queue.shift();
      const left = deadline - Date.now();
      if (left < MIN_START_MS) {
        results.push({ shop, status: 'skipped', ms: 0 });
        console.warn(`  ⏭ ${shop}: overgeslagen, de tijd voor deze stap is op`);
        continue;
      }
      results.push(await runOne(script, shop, extraArgs, Math.min(timeoutMs, left)));
    }
  }

  await Promise.all(Array.from({ length: Math.min(concurrency, queue.length) }, worker));
  printSummary(results);
  return results;
}

function runOne(script, shop, extraArgs, timeoutMs) {
  return new Promise(resolve => {
    const started = Date.now();
    const child = spawn(process.execPath, [script, '--single', '--shop', shop, ...extraArgs], {
      env: process.env,
      stdio: ['ignore', 'pipe', 'pipe'],
    });

    // Regels van parallelle winkels lopen door elkaar; zet de winkel ervoor.
    const prefix = stream => {
      let rest = '';
      stream.on('data', chunk => {
        const lines = (rest + chunk).split('\n');
        rest = lines.pop();
        for (const line of lines) if (line.trim()) console.log(`[${shop}] ${line}`);
      });
      stream.on('end', () => { if (rest.trim()) console.log(`[${shop}] ${rest}`); });
    };
    prefix(child.stdout);
    prefix(child.stderr);

    let timedOut = false;
    const timer = setTimeout(() => {
      timedOut = true;
      console.warn(`  ⏱ ${shop}: tijdslimiet van ${Math.round(timeoutMs / 60_000)} min bereikt, winkel gestopt — wat al opgeslagen is blijft staan`);
      child.kill('SIGTERM');
      setTimeout(() => child.kill('SIGKILL'), 10_000).unref();
    }, timeoutMs);

    child.on('close', code => {
      clearTimeout(timer);
      const status = timedOut ? 'timeout' : code === 0 ? 'ok' : 'failed';
      resolve({ shop, status, ms: Date.now() - started });
    });
  });
}

function printSummary(results) {
  console.log('\n  Duur per winkel:');
  for (const r of [...results].sort((a, b) => b.ms - a.ms)) {
    const mark = { ok: '✅', failed: '❌', timeout: '⏱', skipped: '⏭' }[r.status];
    console.log(`  ${mark} ${r.shop.padEnd(28)} ${(r.ms / 60_000).toFixed(1).padStart(5)} min  ${r.status}`);
  }
}

module.exports = { runPerShop };
