/**
 * recorder.js – per-(shop, model) resultaatbestanden voor de karpetten-suite.
 *
 * Zelfde ontwerp als tests/priceRecorder.js (hordeuren): Playwright draait
 * specs parallel, dus elke prijs krijgt een EIGEN bestand (geen races) in
 * results-parts-karpetten/, en accumulatie is STICKY: een echte €-prijs wordt
 * nooit overschreven door een mislukte run. Modelnamen zijn uniek over de
 * drie merken heen, dus (shop, model) volstaat als sleutel.
 */

const fs   = require('fs');
const path = require('path');

const PARTS_DIR = path.join(__dirname, '..', 'results-parts-karpetten');

const safeName    = s => String(s).replace(/[^a-z0-9]+/gi, '_').toLowerCase();
const isRealPrice = v => /€\s*\d/.test(String(v || ''));

function recordKarpet(shop, model, prijs, url = null) {
  fs.mkdirSync(PARTS_DIR, { recursive: true });
  const file = path.join(PARTS_DIR, `${safeName(shop)}__${safeName(model)}.json`);
  const next = (prijs ?? 'n.v.t.').toString().trim();
  if (!isRealPrice(next) && fs.existsSync(file)) {
    try {
      const prev = JSON.parse(fs.readFileSync(file, 'utf8'));
      if (isRealPrice(prev.prijs)) return; // bewaar bestaande echte prijs
    } catch (_) { /* corrupt -> overschrijf */ }
  }
  fs.writeFileSync(file, JSON.stringify({ shop, model, prijs: next, url }));
}

/** -> { 'vloerkledenloods.nl': { 'Firenze': { prijs: '€ 2.169,00', url }, ... }, ... } */
function collectKarpetten() {
  const out = {};
  if (!fs.existsSync(PARTS_DIR)) return out;
  for (const f of fs.readdirSync(PARTS_DIR)) {
    if (!f.endsWith('.json')) continue;
    try {
      const { shop, model, prijs, url } = JSON.parse(fs.readFileSync(path.join(PARTS_DIR, f), 'utf8'));
      (out[shop] ??= {})[model] = { prijs, url: url || null };
    } catch (_) { /* skip corrupt part */ }
  }
  return out;
}

function clearKarpetParts() {
  if (fs.existsSync(PARTS_DIR)) fs.rmSync(PARTS_DIR, { recursive: true, force: true });
}

module.exports = { recordKarpet, collectKarpetten, clearKarpetParts, PARTS_DIR };
