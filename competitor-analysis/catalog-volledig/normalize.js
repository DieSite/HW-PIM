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

/** Normalize model name for fuzzy matching. */
function normModel(raw) {
  return String(raw ?? '').toLowerCase()
    .replace(/[^a-z0-9]/g, ' ')
    .replace(/\s+/g, ' ').trim();
}

/**
 * Parse a size string to { widthCm, heightCm } or null.
 * Handles: "200 cm x 290 cm", "200x290", "200-x-290", "200 x 290 cm", "200x290cm"
 */
function parseSize(str) {
  if (!str) return null;
  const m = String(str).match(/(\d+)\s*(?:cm)?\s*[-x×]\s*(\d+)\s*(?:cm)?/i);
  if (!m) return null;
  const w = Number(m[1]), h = Number(m[2]);
  // Sanity check: plausible rug sizes 50–600 cm
  if (w < 50 || h < 50 || w > 600 || h > 600) return null;
  return { widthCm: w, heightCm: h };
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
};
