/**
 * catalog.js – laad de CSV-export van het PIM-systeem en geef een opzoekbare
 * catalogus terug.
 *
 * CSV-formaat (geen header, kommagescheiden):
 *   SKU, Merk, Model, Maat ("200 cm x 290 cm" of "Maatwerk"), Prijs
 *
 * Geeft terug:
 *   entries  – array met alle regels
 *   bySku    – Map<sku, entry>
 *   models   – Map<"merk|model", [entries]>   (alleen vaste maten, geen Maatwerk)
 *   fixedEntries – alleen regels met vaste maat (width/height ingevuld)
 */

const fs   = require('fs');
const path = require('path');
const { normBrand, normModel, parseSize } = require('./normalize');

function loadCatalog(csvPath) {
  const text = fs.readFileSync(csvPath, 'utf8');
  const entries = [];
  const bySku   = new Map();
  const models  = new Map(); // "normBrand|normModel" -> [entry, ...]

  for (const raw of text.split(/\r?\n/)) {
    const line = raw.trim();
    if (!line) continue;
    const parts = line.split(',');
    if (parts.length < 5) continue;

    const sku      = parts[0].trim();
    const brand    = parts[1].trim();
    const model    = parts[2].trim();
    const sizeStr  = parts[3].trim();
    const price    = parseFloat(parts[4]) || 0;

    const size = parseSize(sizeStr);
    const isMaatwerk = !size;
    const isOval     = /ovaal|oval|rond|round/i.test(sizeStr);

    const entry = {
      sku,
      brand,
      model,
      sizeLabel: sizeStr,
      widthCm:   size?.widthCm ?? null,
      heightCm:  size?.heightCm ?? null,
      price,
      isMaatwerk,
      isOval,
      normBrand: normBrand(brand),
      normModel: normModel(model),
    };

    entries.push(entry);
    bySku.set(sku, entry);

    if (!isMaatwerk && !isOval) {
      const key = `${entry.normBrand}|${entry.normModel}`;
      if (!models.has(key)) models.set(key, []);
      models.get(key).push(entry);
    }
  }

  const fixedEntries = entries.filter(e => !e.isMaatwerk && !e.isOval);

  return { entries, bySku, models, fixedEntries };
}

module.exports = { loadCatalog };
