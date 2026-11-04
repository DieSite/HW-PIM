/**
 * catalog.js – laad de CSV-export van het PIM-systeem en geef een opzoekbare
 * catalogus terug.
 *
 * CSV-formaat (geen header, kommagescheiden):
 *   SKU, Merk, Model, Maat ("200 cm x 290 cm" of "Maatwerk"), Prijs[, Kleuren]
 *
 * Geeft terug:
 *   entries  – array met alle regels
 *   bySku    – Map<sku, entry>
 *   models   – Map<"merk|model", [entries]>   (alleen vaste maten, geen Maatwerk)
 *   fixedEntries – alleen regels met vaste maat (width/height ingevuld)
 */

const fs   = require('fs');
const path = require('path');
const { normBrand, normModel, parseSize, detectShape, designNumbers, GENERIC_MODEL_WORDS } = require('./normalize');

/**
 * Merken die kleurnummers over collecties heen hergebruiken, zodat het nummer
 * alleen niet zegt welk kleed het is (zie de naamgenoten in loadCatalog).
 */
const TWIN_BRANDS = new Set(['brinker']);

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
    // 6e kolom (kleuren) is optioneel: oudere CSV's hebben hem niet.
    const colour   = (parts[5] ?? '').trim();

    const size = parseSize(sizeStr);
    const isMaatwerk = !size;
    // Vorm zit in de modelnaam ("Diamante 01 Oval") en/of de maat ("Rond 200 cm")
    const shape = detectShape(model, sizeStr) ?? 'rechthoek';

    const entry = {
      sku,
      brand,
      model,
      sizeLabel: sizeStr,
      widthCm:   size?.widthCm ?? null,
      heightCm:  size?.heightCm ?? null,
      price,
      colour,
      isMaatwerk,
      shape,
      normBrand: normBrand(brand),
      normModel: normModel(model),
    };

    entries.push(entry);
    bySku.set(sku, entry);

    if (!isMaatwerk) {
      const key = `${entry.normBrand}|${entry.normModel}`;
      if (!models.has(key)) models.set(key, []);
      models.get(key).push(entry);
    }
  }

  const fixedEntries = entries.filter(e => !e.isMaatwerk);

  // Structuurvarianten: bestaat naast "gentle 13" ook "gentle 13 organic",
  // dan zijn de extra (alfabetische) tokens VERPLICHT in de competitor-tekst.
  // Anders pakt de duurdere variant de prijs van het basismodel.
  for (const [key, keyEntries] of models) {
    const [brand, model] = key.split('|');
    const tokens = model.split(' ').filter(Boolean);
    const extra = new Set();
    for (const otherKey of models.keys()) {
      if (otherKey === key || !otherKey.startsWith(brand + '|')) continue;
      const otherTokens = otherKey.split('|')[1].split(' ').filter(Boolean);
      if (!otherTokens.length || otherTokens.length >= tokens.length) continue;
      if (!otherTokens.every(t => tokens.includes(t))) continue;
      for (const t of tokens) {
        if (!otherTokens.includes(t) && !/^\d+$/.test(t)) extra.add(t);
      }
    }
    const mustHave = [...extra];
    for (const entry of keyEntries) entry.mustHave = mustHave;
  }

  // En andersom: "kapiti 172" mag niet koppelen op een tekst die de extra
  // tokens van "kapiti black 172" draagt, want dat is de andere lijn.
  for (const [key, keyEntries] of models) {
    const [brand, model] = key.split('|');
    const tokens = model.split(' ').filter(Boolean);
    const forbidden = new Set();
    for (const otherKey of models.keys()) {
      if (otherKey === key || !otherKey.startsWith(brand + '|')) continue;
      const otherTokens = otherKey.split('|')[1].split(' ').filter(Boolean);
      if (otherTokens.length <= tokens.length) continue;
      if (!tokens.every(t => otherTokens.includes(t))) continue;
      for (const t of otherTokens) {
        if (!tokens.includes(t) && !/^\d+$/.test(t)) forbidden.add(t);
      }
    }
    for (const entry of keyEntries) entry.mustNotHave = [...forbidden];
  }

  // Zonder dessinnummer in de concurrenttekst vergelijken we namen zónder
  // nummers: "prosper copper" is een deel van "prosper vintage copper", dus
  // mag Prosper 65 – Copper geen tekst met "vintage" pakken als er geen
  // nummer bij staat. Zie wordsCarryIdentity in normalize.js.
  //
  // Daarnaast: welke woorden onderscheiden dit model van zijn naamgenoten
  // (zelfde merk, zelfde eerste woord)? "white" komt bij geen andere Prosper
  // voor en is dus genoeg bewijs; "grey" delen er vier en is het niet.
  // Voor het verbod tellen ook generieke woorden mee ("vintage" in Vintage
  // Copper); als bewijs van identiteit niet.
  const words = model => model.split(' ').filter(t => t && !/^\d+$/.test(t));
  const telling = ws => ws.filter(t => !GENERIC_MODEL_WORDS.has(t));
  for (const [key, keyEntries] of models) {
    const [brand, model] = key.split('|');
    const own = words(model);
    const forbidden = new Set();
    const shared = new Set([own[0]]);
    for (const otherKey of models.keys()) {
      if (otherKey === key || !otherKey.startsWith(brand + '|')) continue;
      const other = words(otherKey.split('|')[1]);
      if (other[0] !== own[0]) continue;
      for (const t of other) shared.add(t);
      if (other.length <= own.length || !own.every(t => other.includes(t))) continue;
      for (const t of other) if (!own.includes(t)) forbidden.add(t);
    }
    for (const entry of keyEntries) {
      entry.mustNotHaveWithoutNumber = [...forbidden];
      // Brinker: zonder nummer moeten ALLE woorden er staan, ook "new". De
      // lijnen delen hun collectienaam ("New Berbero" / "Berbero Lungo",
      // "Arezzo ST" / "Arezzo"), dus "brinker-berbero-gold" (zonder nummer)
      // prijsde anders onze New Berbero 611 op 1,9× de adviesprijs. Pallio-
      // pagina's voor alle kleuren ("Herme Black Warp") noemen wél elk woord.
      if (TWIN_BRANDS.has(brand)) {
        entry.nameWords = own;
        entry.distinctWords = [];
      } else {
        entry.nameWords = telling(own);
        entry.distinctWords = telling(own).filter(t => !shared.has(t));
      }
    }
  }

  // Naamgenoten met hetzelfde dessinnummer. Brinker hergebruikt kleurnummers
  // over collecties: "Berbero Lungo 101" naast "New Berbero 101", "Arezzo ST
  // 803" naast "Arezzo 803". Delen twee modellen van één merk het nummer én een
  // woord, dan onderscheidt alleen het andere woord ze. Over ALLE regels, ook
  // Maatwerk: "Arezzo 803" bestaat bij ons alleen als maatwerk, maar zijn
  // pagina (de CS-lijn) mag de vaste maten van Arezzo ST 803 niet prijzen.
  // Zie twinsDistinguished in normalize.js.
  //
  // Alleen voor merken in TWIN_BRANDS. Bij De Munk en Mart Visser keurde de
  // regel op de productievoorraad (04-11-2026) vooral goede koppelingen af:
  // ons PIM spelt "Tafraout HOL1" naast "Safi HOL 1", en een vormversie heet
  // genormaliseerd kaal "vernon 68" naast "vernon 68 copper glow gold". Daar
  // doen mustHave/mustNotHave het werk al.
  const byNumber = new Map();
  for (const entry of entries) {
    if (!TWIN_BRANDS.has(entry.normBrand)) continue;
    const nr = designNumbers(entry.normModel)[0];
    if (nr === undefined) continue;
    const key = `${entry.normBrand}|${nr}`;
    if (!byNumber.has(key)) byNumber.set(key, new Set());
    byNumber.get(key).add(entry.normModel);
  }
  const twinRulesFor = new Map();
  for (const names of byNumber.values()) {
    if (names.size < 2) continue;
    for (const own of names) {
      const ownWords = words(own);
      const rules = [];
      for (const other of names) {
        if (other === own) continue;
        const otherWords = words(other);
        if (!ownWords.some(t => otherWords.includes(t))) continue;
        const need = ownWords.filter(t => !otherWords.includes(t));
        const forbid = otherWords.filter(t => !ownWords.includes(t));
        if (need.length || forbid.length) rules.push({ need, forbid });
      }
      twinRulesFor.set(own, rules);
    }
  }
  for (const entry of entries) entry.twinRules = twinRulesFor.get(entry.normModel) ?? [];

  return { entries, bySku, models, fixedEntries };
}

module.exports = { loadCatalog };
