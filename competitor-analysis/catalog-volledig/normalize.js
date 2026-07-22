/**
 * normalize.js – string normalisatie + maat-parsing voor catalog-volledig.
 *
 * Alle functies zijn puur (geen I/O). Gebruikt door indexers, matcher, prijsfetcher.
 */

const BRAND_ALIASES = {
  'de munk carpets': 'de munk',
  'demunk':          'de munk',
  'munk carpets':    'de munk',
  'mart visser|karpi': 'mart visser',
  'mart visser / karpi': 'mart visser',
  'karpi mart visser': 'mart visser',
  'antoin carpets':  'eurogros',    // volero white-label
  'lano':            'eurogros',    // another label
  'core by dersimo': 'karpi',       // karpettenkelder white-label
  'headlam':         'karpi',       // homecompanyshop label
  'louis de poortere': 'louis de poortere',
  'de poortere':     'louis de poortere',
};

/** Normalize brand name to canonical form. */
function normBrand(raw) {
  if (!raw) return '';
  const s = String(raw).toLowerCase().trim()
    .replace(/\bcarpets\b/g, 'carpets')
    .replace(/[^a-z0-9 |/]/g, ' ')
    .replace(/\s+/g, ' ').trim();
  return BRAND_ALIASES[s] ?? s;
}

const SHAPE_WORDS_RE = /\b(ovaal|ovale|oval|ellipse?|rond|ronde|round|loper|lopers|runner|organic|organische?)\b/g;

/**
 * Detect the rug shape from any text fragments (model name, size label,
 * competitor title, variant title, URL slug). Returns 'ovaal' | 'rond' |
 * 'loper' or null when no shape word is present (= rechthoek by default).
 */
function detectShape(...parts) {
  const s = parts.filter(Boolean).join(' ').toLowerCase();
  if (/\b(ovaal|ovale|oval|ellipse?)\b/.test(s)) return 'ovaal';
  if (/\b(rond|ronde|round)\b|ø|⌀/.test(s)) return 'rond';
  if (/\b(loper|lopers|runner)\b/.test(s)) return 'loper';
  if (/\b(organic|organische?)\b/.test(s)) return 'organisch';
  return null;
}

/**
 * Normalize model name for fuzzy matching. Shape words are stripped: the
 * shape is a separate match-dimension (detectShape), so "Diamante 01 Oval"
 * and "Diamante 01" normalize to the same model.
 */
function normModel(raw) {
  return String(raw ?? '').toLowerCase()
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]/g, ' ')
    .replace(SHAPE_WORDS_RE, ' ')
    .replace(/\s+/g, ' ').trim();
}

/**
 * Parse a size string to { widthCm, heightCm } or null.
 * Handles: "200 cm x 290 cm", "200x290", "200-x-290", "200 x 290 cm", "200x290cm"
 * Round rugs ("Rond 200 cm", "200 cm rond", "Ø 200") parse as width = height;
 * pair them with detectShape() so a round Ø200 never matches a square 200x200.
 */
function parseSize(str) {
  if (!str) return null;
  const s = String(str);
  const m = s.match(/(\d+)\s*(?:cm)?\s*[-x×]\s*(\d+)\s*(?:cm)?/i);
  if (m) {
    const w = Number(m[1]), h = Number(m[2]);
    // Sanity check: plausible rug sizes 50–600 cm
    if (w < 50 || h < 50 || w > 600 || h > 600) return null;
    return { widthCm: w, heightCm: h };
  }
  const r = s.match(/(?:\brond\b|\bronde\b|\bround\b|ø|⌀)[^0-9]{0,10}(\d{2,3})/i)
         || s.match(/(\d{2,3})\s*(?:cm)?\s*(?:\brond\b|\bronde\b|\bround\b)/i);
  if (r) {
    const d = Number(r[1]);
    if (d >= 50 && d <= 600) return { widthCm: d, heightCm: d };
  }
  return null;
}

/** Format cm dimensions as a size key. */
const sizeKey = (w, h) => `${w}x${h}`;

/** Format a price number to Dutch euro string. */
function fmtEuro(amount) {
  const n = Number(amount);
  if (!Number.isFinite(n) || n <= 0) return null;
  return `€ ${n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?=,))/g, '.')}`;
}

/** Parse a euro string back to a number (or null). */
function euroNum(str) {
  const m = String(str ?? '').match(/€\s*([\d.]+)(?:,(\d{2}))?/);
  if (!m) return null;
  return parseFloat(m[1].replace(/\./g, '') + '.' + (m[2] ?? '00'));
}

const isRealPrice = str => /€\s*\d/.test(String(str ?? ''));
const isVanaf     = str => /^vanaf/i.test(String(str ?? '').trim());

/**
 * Kleur-/dessinnummers uit een genormaliseerde modelnaam of slug.
 * Maatparen ("200x290", "160 x 230") en lange ID's (≥5 cijfers) tellen niet mee.
 */
function designNumbers(str) {
  const s = String(str ?? '').toLowerCase()
    .replace(/\d{2,3}\s*-?[x×]-?\s*\d{2,3}/g, ' ')
    .replace(/\d{5,}/g, ' ');
  // Genormaliseerd op waarde: "01" en "1" zijn hetzelfde kleurnummer
  return [...new Set((s.match(/\d+/g) ?? []).map(n => String(Number(n))))];
}

/**
 * True als kleur-/dessinnummers elkaar niet tegenspreken. Zonder nummers aan
 * één van beide kanten is er geen oordeel (true). Voorkomt dat "Brush 13" de
 * prijs van de "…-69"-kleurvariant krijgt.
 */
function numbersCompatible(a, b) {
  const na = designNumbers(a), nb = designNumbers(b);
  return !na.length || !nb.length || na.some(n => nb.includes(n));
}

/**
 * De eigenlijke modelnaam (eerste niet-numerieke token, bv. "prosper" in
 * "prosper 69 vintage copper") moet in de competitor-titel/slug voorkomen.
 * Voorkomt dat losse sfeerwoorden ("vintage", "69") een ander model koppelen.
 */
function hasModelNameToken(text, catModel) {
  const tokens = String(catModel ?? '').split(' ').filter(Boolean);
  const first = tokens.find(t => t.length > 2 && !/^\d+$/.test(t)) ?? tokens[0];
  return !first || String(text ?? '').toLowerCase().includes(first);
}

/** True als alle (verplichte) tokens in de tekst voorkomen. Lege lijst = altijd true. */
function containsAllTokens(text, tokens) {
  const t = String(text ?? '').toLowerCase();
  return (tokens ?? []).every(tok => t.includes(tok));
}

/**
 * Tweede verdedigingslinie bij het prijzen van custom shops: klopt de
 * opgehaalde PAGINA (titel + url) met de catalogusentry? De slug mist soms het
 * dessinnummer dat de titel wél toont — zo kreeg "Fading World Babylon 8545"
 * de prijs van de "Pink Flash 8261"-pagina. HTML-entities worden gestript
 * zodat "&#9193;" geen nep-dessinnummer wordt.
 */
function pageMatchesEntry(title, url, entry) {
  const clean = String(title ?? '').replace(/&#\d+;/g, ' ').replace(/&[a-z]+;/gi, ' ');
  const text = normModel(clean) + ' ' + String(url ?? '').toLowerCase();
  const pageShape = detectShape(clean, url) ?? 'rechthoek';
  return hasModelNameToken(text, entry.normModel)
    && numbersCompatible(entry.normModel, text)
    && containsAllTokens(text, entry.mustHave)
    && pageShape === (entry.shape ?? 'rechthoek');
}

/**
 * Score how well a competitor title matches (brand, model).
 * Returns 0..100. ≥60 is considered a match.
 */
function matchScore(normCompetitorTitle, ourBrand, ourModel) {
  const titleLower = normModel(normCompetitorTitle);
  // Brand check
  const brandTokens = ourBrand.split(' ').filter(t => t.length > 2);
  const brandMatch = brandTokens.length === 0 || brandTokens.every(t => titleLower.includes(t));
  if (!brandMatch) return 0;

  // Model token check
  const modelTokens = ourModel.split(' ').filter(Boolean);
  if (modelTokens.length === 0) return 0;
  const hits = modelTokens.filter(t => titleLower.includes(t)).length;
  const score = Math.round((hits / modelTokens.length) * 80) + (brandMatch ? 20 : 0);
  return score;
}

/** Extract a model name from a product title given the brand. */
function extractModel(title, brand) {
  let t = String(title ?? '');
  // Remove brand name prefixes/suffixes (case-insensitive)
  const brandWords = brand.split(' ').filter(w => w.length > 2);
  for (const w of brandWords) {
    t = t.replace(new RegExp(`\\b${w}\\b`, 'gi'), '');
  }
  // Remove common noise words
  t = t.replace(/\b(carpets?|vloerkleed|vloerkleden|tapijt|carpet|karpet|rug)\b/gi, '');
  return t.replace(/\s+/g, ' ').trim();
}

/**
 * Given a URL slug, score how well it matches brand + model.
 * Used for sitemap-based discovery.
 */
function slugMatchScore(url, brand, model) {
  const slug = url.toLowerCase();
  const modelSlug = normModel(model).replace(/\s+/g, '-');
  const modelTokens = normModel(model).split(' ').filter(Boolean);
  const modelHits = modelTokens.filter(t => slug.includes(t)).length;
  const modelScore = modelTokens.length ? (modelHits / modelTokens.length) : 0;

  const brandTokens = normBrand(brand).split(' ').filter(t => t.length > 2);
  const brandHit = brandTokens.some(t => slug.includes(t));

  return Math.round(modelScore * 70 + (brandHit ? 30 : 0));
}

module.exports = {
  normBrand, normModel, parseSize, sizeKey, fmtEuro, euroNum,
  isRealPrice, isVanaf, matchScore, extractModel, slugMatchScore, BRAND_ALIASES,
  detectShape, designNumbers, numbersCompatible, hasModelNameToken, containsAllTokens,
  pageMatchesEntry,
};
