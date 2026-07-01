/**
 * priceRecorder.js
 * Writes collected prices so globalTeardown.js can build the Excel.
 *
 * Playwright draait spec-bestanden PARALLEL in losse worker-processen. Een
 * gedeelde results.json met read-modify-write geeft dan race-condities
 * (gelijktijdige writes overschrijven elkaar -> ontbrekende cellen). Daarom
 * schrijft elke prijs naar een EIGEN bestand in results-parts/ (atomair, geen
 * race). globalTeardown voegt ze samen tot results.json.
 */

const fs   = require('fs');
const path = require('path');

const PARTS_DIR = path.join(__dirname, '..', 'results-parts');

function safeName(s) {
  return String(s).replace(/[^a-z0-9]+/gi, '_').toLowerCase();
}

const isRealPrice = v => /€\s*\d/.test(String(v || ''));

/**
 * STICKY accumulatie: live concurrent-sites zijn flaky. Een eerder vastgelegde
 * ECHTE prijs (€ …) mag NIET overschreven worden door een mislukte run
 * (n.v.t.). Zo bouwen meerdere runs samen een complete dataset op. Een echte
 * prijs wordt wel door een nieuwe echte prijs vervangen (verse data).
 * Verwijder results-parts/ handmatig voor een schone start.
 */
function recordPrice(competitor, sizeName, price) {
  fs.mkdirSync(PARTS_DIR, { recursive: true });
  const file = path.join(PARTS_DIR, `${safeName(competitor)}__${safeName(sizeName)}.json`);
  const next = (price ?? 'n.v.t.').toString().trim();
  if (!isRealPrice(next) && fs.existsSync(file)) {
    try {
      const prev = JSON.parse(fs.readFileSync(file, 'utf8'));
      if (isRealPrice(prev.price)) return; // bewaar de bestaande echte prijs
    } catch (_) { /* corrupt -> overschrijf */ }
  }
  fs.writeFileSync(file, JSON.stringify({ competitor, sizeName, price: next }));
}

/** Merge all part-files into a single {competitor: {sizeName: price}} object. */
function collectResults() {
  const out = {};
  if (!fs.existsSync(PARTS_DIR)) return out;
  for (const f of fs.readdirSync(PARTS_DIR)) {
    if (!f.endsWith('.json')) continue;
    try {
      const { competitor, sizeName, price } = JSON.parse(fs.readFileSync(path.join(PARTS_DIR, f), 'utf8'));
      if (!out[competitor]) out[competitor] = {};
      out[competitor][sizeName] = price;
    } catch (_) { /* skip corrupt part */ }
  }
  return out;
}

function clearParts() {
  if (fs.existsSync(PARTS_DIR)) fs.rmSync(PARTS_DIR, { recursive: true, force: true });
}

module.exports = { recordPrice, collectResults, clearParts, PARTS_DIR };
