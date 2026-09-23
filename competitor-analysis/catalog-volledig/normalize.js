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

/**
 * Past deze variantmaat bij deze catalogusregel?
 *
 * Exact is de regel. De uitzondering is een winkel die een maatband anders
 * labelt dan wij: vloerkledenloods verkoopt de XS van Karpi Cisco als
 * "80 x 150 cm" waar ons PIM "80 x 160 cm" zegt — hetzelfde kleed, want de
 * vier andere maten van dat product matchen exact en de XS kost aan beide
 * kanten € 129. Zo'n alias staat per winkel in shops.js.
 *
 * De alias springt alleen in als de winkel onze exacte maat NIET voert. Voert
 * hij hem wel, dan is die de juiste en zou een alias een tweede, verkeerde
 * koppeling maken.
 *
 * @param {{widthCm: number, heightCm: number}} entry
 * @param {{widthCm: number, heightCm: number}} size          maat van de variant
 * @param {Set<string>} beschikbaar  "BxH" van alle varianten van dit product
 * @param {Array<{from: [number, number], to: [number, number]}>} aliases
 */
function sizeMatches(entry, size, beschikbaar = new Set(), aliases = []) {
  if (entry.widthCm === size.widthCm && entry.heightCm === size.heightCm) return true;
  if (beschikbaar.has(`${entry.widthCm}x${entry.heightCm}`)) return false;

  return aliases.some(a =>
    a.from[0] === entry.widthCm && a.from[1] === entry.heightCm &&
    a.to[0] === size.widthCm && a.to[1] === size.heightCm);
}

const SHAPE_WORDS_RE = /\b(ovaal|ovale|oval|ellipse?|rond|ronde|round|loper|lopers|runner|organic|organische?)\b/g;

/**
 * Een vormwoord staat niet altijd los. WooCommerce-varianten heten
 * "200x290ovaal" en "200rond-2", en `\b` vraagt een niet-woordteken — tussen
 * een cijfer en een letter staat die grens niet, dus zo'n variant gold als
 * rechthoek. Bij grootinvloeren.nl kostte dat de rechthoekige Anaheim 3243 de
 * ovaalprijs (€ 499 in plaats van € 480): beide varianten parseren als
 * 200x290, en wie het laatst wegschrijft wint. Een cijfer telt daarom óók als
 * begrens van het woord.
 */
const AFTER_DIGIT = '(?:\\b|(?<=\\d))';
const RE_OVAAL = new RegExp(AFTER_DIGIT + '(ovaal|ovale|oval|ellipse?)\\b');
const RE_ROND = new RegExp(AFTER_DIGIT + '(rond|ronde|round)\\b');
const RE_LOPER = new RegExp(AFTER_DIGIT + '(loper|lopers|runner)\\b');
const RE_ORGANISCH = new RegExp(AFTER_DIGIT + '(organic|organische?)\\b');

/**
 * Detect the rug shape from any text fragments (model name, size label,
 * competitor title, variant title, URL slug). Returns 'ovaal' | 'rond' |
 * 'loper' or null when no shape word is present (= rechthoek by default).
 */
function detectShape(...parts) {
  const s = parts.filter(Boolean).join(' ').toLowerCase();
  if (RE_OVAAL.test(s)) return 'ovaal';
  if (RE_ROND.test(s) || /ø|⌀/.test(s)) return 'rond';
  if (RE_LOPER.test(s)) return 'loper';
  if (RE_ORGANISCH.test(s)) return 'organisch';
  return null;
}

const RE_RECHTHOEK = new RegExp(AFTER_DIGIT + 'rechthoek(?:ig|ige)?\\b');

/**
 * Vorm van een PRODUCT (Shopify/Woo) waarvan de varianten verschillende vormen
 * kunnen zijn. Dit is de vorm die een variant zonder eigen vormwoord krijgt.
 *
 * youlikeitwonen.nl noemt één product "Spectrum 3333 Rechthoekig & Rond" met
 * varianten "200x290 cm" én "Rond 200 cm". `detectShape` op die titel zegt
 * 'rond', waardoor elke kale rechthoekmaat als rond telde en nergens meer op
 * matchte. Noemt de titel meerdere vormen, dan is een kale variant de
 * rechthoek — mits die in de titel staat. "Rond of Ovaal" zonder rechthoek is
 * écht dubbelzinnig: dan null, en moet elke variant zijn vorm zelf noemen.
 *
 * @returns {'rechthoek'|'ovaal'|'rond'|'loper'|'organisch'|null}
 */
function productShape(...parts) {
  const s = parts.filter(Boolean).join(' ').toLowerCase().replace(/[-_]/g, ' ');
  const shapes = new Set([
    RE_OVAAL.test(s) && 'ovaal',
    (RE_ROND.test(s) || /ø|⌀/.test(s)) && 'rond',
    RE_LOPER.test(s) && 'loper',
    RE_ORGANISCH.test(s) && 'organisch',
  ].filter(Boolean));
  if (shapes.size === 0) return 'rechthoek';
  if (shapes.size === 1 && !RE_RECHTHOEK.test(s)) return [...shapes][0];
  return RE_RECHTHOEK.test(s) ? 'rechthoek' : null;
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
  // "-x-" is de slugvorm van WooCommerce-attributen ("attribute_pa_maat":
  // "160-x-230"). Zonder die vorm bleef bij caltabellotta.nl alleen de ene
  // maat over die ze als "200x290" schrijven.
  const m = s.match(/(\d+)\s*(?:cm)?\s*(?:-?[x×]-?|-)\s*(\d+)\s*(?:cm)?/i);
  if (m) {
    const w = Number(m[1]), h = Number(m[2]);
    // Sanity check: plausible rug sizes 50–600 cm
    if (w < 50 || h < 50 || w > 600 || h > 600) return null;
    return { widthCm: w, heightCm: h };
  }
  const r = s.match(/(?:\brond\b|\bronde\b|\bround\b|ø|⌀)[^0-9]{0,10}(\d{2,3})/i)
         || roundInMeters(s)
         || s.match(/(\d{2,3})\s*(?:cm)?\s*(?:\brond\b|\bronde\b|\bround\b)/i);
  if (r) {
    const d = Number(r[1]);
    if (d >= 50 && d <= 600) return { widthCm: d, heightCm: d };
  }
  return null;
}

/**
 * Doorsnede in meters: youlikeitwonen.nl schrijft "Rond 2 meter doorsnede" en
 * zelfs "Rond 2,40 cm doorsnede" (bedoeld: 2,40 m). Een getal onder de 10 met
 * een eenheid erachter is dus meters. Zónder eenheid telt het niet: in een
 * WooCommerce-slug als "240rond-2" is die 2 een volgnummer, geen maat.
 *
 * @returns {[string, string]|null}  zelfde vorm als een match: [_, cm]
 */
function roundInMeters(s) {
  const m = s.match(/(?:\brond\b|\bronde\b|\bround\b|ø|⌀)[^0-9]{0,10}(\d(?:[.,]\d{1,2})?)\s*(?:m|meter|cm)\b/i);
  return m ? [m[0], String(Math.round(parseFloat(m[1].replace(',', '.')) * 100))] : null;
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
 *
 * `a` is ONS model. Zijn eerste nummer is het dessinnummer en dat moet in `b`
 * staan; één willekeurig gedeeld nummer is niet genoeg. "Kades 4354-300" en
 * "Kades 4309-300" delen de collectiecode 300, en zo kreeg de 4354 bij
 * dfmwonen.nl de prijs van de 4309-pagina.
 */
function numbersCompatible(a, b) {
  const na = designNumbers(a), nb = designNumbers(b);
  return !na.length || !nb.length || nb.includes(na[0]);
}

/**
 * Materiaal-, techniek- en productsoortwoorden. Ze staan in zowel onze
 * modelnamen als in vrijwel elke concurrenttitel en onderscheiden dus géén
 * model: "Sisal Gold 22" mag niet matchen op "Sisal vloerkleed Loop grijs 22"
 * puur omdat beide "sisal" bevatten.
 */
const GENERIC_MODEL_WORDS = new Set([
  'sisal', 'wollen', 'wol', 'hoogpolig', 'laagpolig', 'kortpolig', 'vloerkleed',
  'vloerkleden', 'karpet', 'karpetten', 'tapijt', 'carpet', 'carpets', 'rug',
  'berber', 'shaggy', 'katoen', 'buitenkleed', 'kleed', 'jute', 'vintage',
]);

/**
 * De eigenlijke modelnaam (eerste onderscheidende, niet-numerieke token, bv.
 * "prosper" in "prosper 69 vintage copper") moet in de competitor-titel/slug
 * voorkomen. Generieke materiaalwoorden tellen niet mee als modelnaam;
 * alleen als het model uitsluitend daaruit bestaat vallen we erop terug.
 */
function hasModelNameToken(text, catModel) {
  const tokens = String(catModel ?? '').split(' ').filter(Boolean);
  const words = tokens.filter(t => t.length > 2 && !/^\d+$/.test(t));
  const first = words.find(t => !GENERIC_MODEL_WORDS.has(t)) ?? words[0] ?? tokens[0];
  return !first || String(text ?? '').toLowerCase().includes(first);
}

/**
 * Kleurnamen, NL en EN naar één noemer. Modellen die zich alléén door een
 * kleurWOORD onderscheiden ("Love Shaggy Taupe" vs "Love Shaggy Beige",
 * "Prosper 25 - Black" vs de witte Prosper) hebben geen kleurnummer waarop
 * numbersCompatible kan aanslaan.
 */
const COLOR_ALIASES = {
  // Mart Visser gebruikt Engelse én Duitse kleurnamen in zijn modelnamen
  // ("Cendre 21 - Grau"), terwijl de shops Nederlands schrijven.
  zwart: 'zwart', black: 'zwart', schwarz: 'zwart',
  wit: 'wit', white: 'wit', weiss: 'wit',
  grijs: 'grijs', grey: 'grijs', gray: 'grijs', grau: 'grijs',
  antraciet: 'antraciet', anthracite: 'antraciet', anthrazit: 'antraciet',
  bruin: 'bruin', brown: 'bruin', braun: 'bruin', lichtbruin: 'lichtbruin',
  beige: 'beige', taupe: 'taupe', creme: 'creme', cream: 'creme',
  zand: 'zand', sand: 'zand', naturel: 'naturel', natural: 'naturel', ivory: 'ivory',
  blauw: 'blauw', blue: 'blauw', groen: 'groen', green: 'groen',
  rood: 'rood', red: 'rood', roze: 'roze', pink: 'roze',
  geel: 'geel', yellow: 'geel', oker: 'oker', ocker: 'oker', ochre: 'oker',
  oranje: 'oranje', orange: 'oranje', paars: 'paars', purple: 'paars',
  goud: 'goud', gold: 'goud', zilver: 'zilver', silver: 'zilver',
  cognac: 'cognac', terra: 'terra', olive: 'olive', olijf: 'olive',
};

/** De genormaliseerde kleurnamen in een stuk tekst. */
function colorWords(str) {
  const out = new Set();
  for (const t of String(str ?? '').toLowerCase().split(/[^a-z]+/)) {
    if (COLOR_ALIASES[t]) out.add(COLOR_ALIASES[t]);
  }
  return [...out];
}

/**
 * True als kleurNAMEN elkaar niet tegenspreken. Zonder kleurnaam aan één van
 * beide kanten is er geen oordeel (true) — een concurrenttitel die de kleur
 * niet noemt mag niet massaal afgekeurd worden.
 */
function colorsCompatible(a, b) {
  const ca = colorWords(a), cb = colorWords(b);
  return !ca.length || !cb.length || ca.some(c => cb.includes(c));
}

/**
 * Is er POSITIEF bewijs dat dit dezelfde kleurvariant is — een gedeeld
 * dessinnummer of een gedeelde kleurnaam?
 *
 * `numbersCompatible` en `colorsCompatible` vallen bewust open als één van
 * beide kanten niets noemt: de meeste concurrenten noemen lang niet altijd een
 * kleur, en massaal afkeuren zou de dekking slopen. Bij een shop die noch een
 * dessinnummer in slug of titel zet (gigameubel: "…-prosper-200x290cm-wit")
 * betekent dat echter dat élke kleur van een model op dezelfde pagina uitkomt.
 * Zulke shops eisen daarom bewijs in plaats van de afwezigheid van tegenspraak.
 */
function hasDiscriminator(catModel, text, colour = '') {
  const na = designNumbers(catModel), nb = designNumbers(text);
  if (na.length && nb.length && na.some(n => nb.includes(n))) return true;

  const ca = colorWords(catModel + ' ' + colour), cb = colorWords(text);
  return ca.length > 0 && cb.length > 0 && ca.some(c => cb.includes(c));
}

/**
 * Alle identiteitseisen voor het koppelen van één catalogusmodel aan één
 * competitor-tekst (titel, variant-titel of slug), op één plek zodat de vijf
 * aanroepplekken niet uit elkaar kunnen lopen.
 *
 * `requireDiscriminator` (per shop ingesteld in shops.js) maakt de koppeling
 * bewijs-gedreven in plaats van tegenspraak-gedreven.
 */
function modelIdentityMatches(catModel, text, mustHave, { requireDiscriminator = false, colour = '', mustNotHave = [], nameWords, distinctWords, mustNotHaveWithoutNumber = [], competitorModel } = {}) {
  return hasModelNameToken(text, catModel)
    && numbersCompatible(catModel, text)
    // LET OP: de PIM-kleur (`Kleuren` op de parent) gaat NIET in deze
    // tegenspraak-check. Dat is een grove categorie ("Beige"), geen
    // marketingnaam: "Oasis 15" staat in het PIM als Beige terwijl de shop hem
    // "Oasis cloud grey 15" noemt. Meegeteld hier keurde dat vier terechte
    // koppelingen af (Craft/Cavaro/Oasis/Suède Shades, dessinnummer gelijk).
    // Als POSITIEF bewijs is hij wél bruikbaar — zie hasDiscriminator.
    && colorsCompatible(catModel, text)
    && containsAllTokens(text, mustHave)
    && containsNoWords(text, mustNotHave)
    && wordsCarryIdentity(catModel, text, { nameWords, distinctWords, forbidden: mustNotHaveWithoutNumber, competitorModel })
    && (! requireDiscriminator || hasDiscriminator(catModel, text, colour));
}

/**
 * De identiteitsopties die bij één catalogusentry horen, zodat de
 * aanroepplekken ze niet elk los hoeven door te geven.
 */
function identityOptionsFor(entry) {
  return {
    colour: entry?.colour ?? '',
    mustNotHave: entry?.mustNotHave ?? [],
    mustNotHaveWithoutNumber: entry?.mustNotHaveWithoutNumber ?? [],
    nameWords: entry?.nameWords,
    distinctWords: entry?.distinctWords,
  };
}

/**
 * Noemt de concurrent geen dessinnummer terwijl ons model er wel een heeft,
 * dan zijn de woorden de enige identiteit — en is één gedeeld kleurwoord te
 * weinig. karpetwereld.nl heeft per Mart Visser-kleur een pagina zonder
 * nummer ("vloerkleed-prosper-wolf-grey"); "Prosper 37 – Indigo Grey",
 * "24 – Grey Light" en "64 – Grey Custard" kwamen daar allemaal op uit.
 *
 * Dan moet er een woord in staan dat ons model van zijn naamgenoten
 * onderscheidt (`distinctWords`, uit loadCatalog: "white" voor Prosper 21, want
 * geen andere Prosper is wit; niet "grey"). Heeft het model zo'n woord niet,
 * dan alle woorden van de naam. Kleurwoorden mogen in een andere taal
 * (white ≡ wit). En geen woord van een langere naamgenoot: "Prosper 65 –
 * Copper" is niet "Prosper 69 – Vintage Copper".
 *
 * Zonder catalogusinformatie (`nameWords` ontbreekt) geen oordeel.
 */
function wordsCarryIdentity(catModel, text, { nameWords, distinctWords = [], forbidden = [], competitorModel } = {}) {
  if (!nameWords) return true;
  if (!designNumbers(catModel).length || designNumbers(text).length) return true;

  const tokens = new Set(String(text ?? '').toLowerCase().split(/[^a-z0-9]+/));
  const textColours = colorWords(text);
  const present = t => tokens.has(t) || (COLOR_ALIASES[t] !== undefined && textColours.includes(COLOR_ALIASES[t]));

  const identified = distinctWords.length ? distinctWords.some(present) : nameWords.every(present);
  return identified && !forbidden.some(w => tokens.has(w)) && !unexplainedWords(catModel, competitorModel).length;
}

/** Woorden van merken, die in een concurrenttitel niets over het model zeggen. */
const BRAND_WORDS = new Set(
  Object.entries(BRAND_ALIASES).flat().join(' ').split(/[^a-z]+/).filter(Boolean)
);

/**
 * Woorden in de modelnaam van de CONCURRENT die onze naam niet verklaart.
 *
 * "Distinct" gaat over óns assortiment: wij voeren geen Prosper 33 Turquoise
 * Blue, dus "blue" onderscheidt onze 31 Powder Blue van zijn naamgenoten. Maar
 * karpetwereld.nl noemt zijn pagina "Prosper Turquise Blue", en "turquise"
 * staat nergens in onze naam: dat is tegenspraak. Alleen bruikbaar waar de
 * modelnaam van de concurrent schoon is (titel zonder merk, uit de Shopify- en
 * WooCommerce-indexers); paginatitels en slugs dragen te veel ruis.
 */
function unexplainedWords(catModel, competitorModel) {
  if (!competitorModel) return [];
  const ours = new Set(String(catModel).split(' '));
  const ourColours = colorWords(catModel);
  return String(competitorModel).split(' ').filter(t => t
    && !/\d/.test(t)
    && !ours.has(t)
    && !GENERIC_MODEL_WORDS.has(t)
    && !BRAND_WORDS.has(t)
    // Een kleurwoord spreekt alleen tegen als onze naam zelf een kleur noemt:
    // "Derbe 72220-300" noemt er geen, dus "Derbe 72220 Rood" is geen
    // tegenspraak (en 72220 telt als vijfcijferig nummer niet als dessin).
    && !(COLOR_ALIASES[t] !== undefined && (!ourColours.length || ourColours.includes(COLOR_ALIASES[t]))));
}

/**
 * True als geen van deze woorden als los woord in de tekst staat. Spiegel van
 * `mustHave`: bestaat naast "Kapiti 172" ook "Kapiti Black 172", dan is een
 * tekst met "black" de Black-lijn en niet ons basismodel (€ 989 tegen € 935 bij
 * dfmwonen.nl, en wie het laatst schreef won). Als los woord, anders sneuvelt
 * "blackpool" op "black".
 */
function containsNoWords(text, words) {
  const tokens = new Set(String(text ?? '').toLowerCase().split(/[^a-z0-9]+/));
  const colours = colorWords(text);
  return !(words ?? []).some(w => tokens.has(w) || sameColour(w, colours));
}

/** True als alle (verplichte) tokens in de tekst voorkomen. Lege lijst = altijd true. */
function containsAllTokens(text, tokens) {
  const t = String(text ?? '').toLowerCase();
  const colours = colorWords(t);
  return (tokens ?? []).every(tok => t.includes(tok) || sameColour(tok, colours));
}

/**
 * Is `word` een kleur die (in een andere taal) al in de tekst staat?
 * onlineslaapcomfort.nl noemt Kapiti Black "kapiti-zwart-175": zonder dit
 * miste "Kapiti Black" zijn verplichte "black", en pakte het gewone Kapiti 175
 * de pagina van de Black-lijn.
 */
function sameColour(word, textColours) {
  return COLOR_ALIASES[word] !== undefined && textColours.includes(COLOR_ALIASES[word]);
}

/**
 * Vervang hele woorden/woordgroepen volgens een vertaaltabel per winkel.
 * onlineslaapcomfort.nl machinevertaalt de collectienamen ("vervagende wereld"
 * = Fading World, "antiek" = Antiquarian); het dessinnummer staat er nog wel
 * bij, dus de identiteitscheck blijft even streng.
 *
 * @param {string} text
 * @param {Object<string, string>} [aliases]  { "vervagende wereld": "fading world" }
 */
function applyWordAliases(text, aliases) {
  let out = String(text ?? '');
  for (const [from, to] of Object.entries(aliases ?? {})) {
    const pattern = from.split(' ').map(w => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('[\\s_-]+');
    out = out.replace(new RegExp(`(^|[^a-z])${pattern}(?=[^a-z]|$)`, 'gi'), (_, pre) => pre + to);
  }
  return out;
}

/**
 * Tweede verdedigingslinie bij het prijzen van custom shops: klopt de
 * opgehaalde PAGINA (titel + url) met de catalogusentry? De slug mist soms het
 * dessinnummer dat de titel wél toont — zo kreeg "Fading World Babylon 8545"
 * de prijs van de "Pink Flash 8261"-pagina. HTML-entities worden gestript
 * zodat "&#9193;" geen nep-dessinnummer wordt.
 */
function pageMatchesEntry(title, url, entry, { anyShape = false, ...opts } = {}) {
  const clean = String(title ?? '').replace(/&#\d+;/g, ' ').replace(/&[a-z]+;/gi, ' ');
  const text = normModel(clean) + ' ' + String(url ?? '').toLowerCase();
  const pageShape = detectShape(clean, url) ?? 'rechthoek';
  // `anyShape`: één pagina voor alle vormen (woonwebwinkel.com); dan beslist
  // getPrijs over de vorm, want die leest de vorm per maatoptie.
  return modelIdentityMatches(entry.normModel, text, entry.mustHave, { ...identityOptionsFor(entry), ...opts })
    && (anyShape || pageShape === (entry.shape ?? 'rechthoek'));
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
  sizeMatches,
  normBrand, normModel, parseSize, sizeKey, fmtEuro, euroNum,
  isRealPrice, isVanaf, matchScore, extractModel, slugMatchScore, BRAND_ALIASES,
  detectShape, productShape, designNumbers, numbersCompatible, hasModelNameToken, containsAllTokens, containsNoWords, applyWordAliases, identityOptionsFor, wordsCarryIdentity,
  pageMatchesEntry, colorWords, colorsCompatible, modelIdentityMatches, hasDiscriminator, GENERIC_MODEL_WORDS,
};
