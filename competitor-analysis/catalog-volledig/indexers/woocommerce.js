/**
 * woocommerce.js – generieke WooCommerce-indexer.
 *
 * Zoekt producten via de WooCommerce Store API (publiek, geen auth nodig):
 *   GET /wp-json/wc/store/v1/products?search=<merk>&per_page=100&page=N
 *
 * Voor elk gevonden product:
 *   1. Haal de productpagina op
 *   2. Lees data-product_variations (inline JSON) voor maat+prijs per variant
 *   3. Als er geen inline JSON is (>30 variaties): sla de URL op in de index
 *      voor een latere "precisie" fetch via wc-ajax (zie fetch-prices.js)
 *
 * TERUGVAL OP DE HELE CATALOGUS. Zoeken op merknaam werkt alleen bij winkels
 * die het merk in de producttitel zetten ("Karpi Karpet Bermuda" bij
 * meubelcity). grootinvloeren.nl noemt exact hetzelfde kleed "Vloerkleed Upton
 * 9191" en karpetwereld.nl "Napoli 12" — daar levert `?search=Eurogros`
 * respectievelijk `?search=De Munk` nul producten op, en de merkcheck op de
 * titel gooide wat er tóch doorkwam alsnog weg. Beide winkels stonden dus
 * volledig ingericht in shops.js en leverden nul prijzen, zonder dat iets
 * daarover klaagde. Levert het zoeken op merk niets op, dan bladeren we de
 * catalogus door en matchen we de titel op onze eigen modelnamen.
 *
 * Retourneert { indexed, priced }.
 */

const { getText, getJson, sleep } = require('../http');
const { normBrand, normModel, parseSize, fmtEuro, extractModel, matchScore, detectShape, modelIdentityMatches, identityOptionsFor } = require('../normalize');
const { upsertIndex, recordPrice } = require('../storage');

/** Bovengrens op het doorbladeren, zodat een winkel met een enorme catalogus de run niet opeet. */
const MAX_CATALOG_PAGES = 60;

/**
 * Haal variatie-JSON op uit HTML (data-product_variations attribuut).
 * Geeft array terug, of null als niet aanwezig.
 */
function extractVariations(html) {
  const m = html.match(/data-product_variations="([^"]+)"/);
  if (!m) return null;
  try {
    return JSON.parse(
      m[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&')
          .replace(/&#(\d+);/g, (_, c) => String.fromCharCode(c))
    );
  } catch { return null; }
}

/**
 * Eén pagina van de complete catalogus (zonder zoekterm), met herhaalpogingen.
 *
 * Probeert de Store API v1 en v2; sommige shops draaien alleen v1.
 *
 * Vroeger gaf elke fout een lege lijst terug, en die las het doorbladeren als
 * "einde van de catalogus". Op 23-09-2026 stopte grootinvloeren.nl zo om 04:05
 * na anderhalve minuut met 233 van de ~1.280 prijzen, zonder één regel in de
 * log, en `--prune` haalde daarna 1.016 koppelingen weg. Alleen een HTTP 400
 * betekent echt "voorbij de laatste pagina" (WooCommerce:
 * rest_post_invalid_page_number); elke andere fout wordt herhaald en daarna
 * gegooid, zodat een halve index als fout in de log staat.
 */
async function wooPage(base, page, shop = base, { fetchJson = getJson, retryDelaysMs = [5000, 15000, 30000] } = {}) {
  for (let attempt = 0; ; attempt++) {
    let lastError = null;

    for (const ver of ['v1', 'v2']) {
      try {
        const url = `${base}/wp-json/wc/store/${ver}/products?per_page=100&page=${page}&status=publish`;
        const json = await fetchJson(url);
        if (Array.isArray(json)) return json;
        lastError = new Error('antwoord is geen productlijst');
      } catch (e) {
        if (/^HTTP 400\b/.test(e.message)) return [];
        lastError = e;
      }
    }

    if (attempt >= retryDelaysMs.length) {
      throw new Error(`${shop} catalogus pagina ${page} blijft falen (${lastError.message}); index is ONVOLLEDIG`);
    }
    console.warn(`  [woo] ${shop} catalogus p${page}: ${lastError.message}, opnieuw over ${retryDelaysMs[attempt] / 1000}s`);
    await sleep(retryDelaysMs[attempt]);
  }
}

/**
 * Lijkt deze titel op een model uit onze catalogus van dit merk?
 *
 * Dezelfde token-overlap die verderop de prijs koppelt, hier als voorfilter:
 * een winkel doorbladeren betekent duizenden producten zien, en alleen voor de
 * kandidaten hoeft de productpagina opgehaald te worden. Bij grootinvloeren
 * scheelt dat 1.848 paginaloads. De strenge guards volgen daarna gewoon.
 */
function looksLikeCatalogModel(model, nb, catalogModels) {
  const modTokens = model.split(' ').filter(Boolean);

  for (const key of catalogModels.keys()) {
    if (!key.startsWith(nb + '|')) continue;

    const catModel  = key.split('|')[1];
    const catTokens = catModel.split(' ').filter(Boolean);
    const fwdHits   = catTokens.filter(t => model.includes(t)).length;
    const revHits   = modTokens.filter(t => catModel.includes(t)).length;

    if (fwdHits >= Math.min(2, catTokens.length) || revHits >= Math.min(2, modTokens.length)) return true;
  }

  return false;
}

async function wooSearch(base, search, page) {
  for (const ver of ['v1', 'v2']) {
    try {
      const url = `${base}/wp-json/wc/store/${ver}/products?search=${encodeURIComponent(search)}&per_page=100&page=${page}&status=publish`;
      const json = await getJson(url);
      if (Array.isArray(json)) return json;
    } catch { /* probeer volgende versie */ }
  }
  return [];
}

async function indexWooCommerce(db, { shop, base, brands, catalogModels, bySku, requireDiscriminator, pageDelayMs = 150 }) {
  const identityOpts = { requireDiscriminator };
  const normBrands  = brands.map(b => normBrand(b));
  let indexed = 0, priced = 0, seen = 0, failedPages = 0, apiBatches = 0;
  const done = new Set();
  /** Variaties die nog een prijs uit de Store API moeten krijgen: { id, apply } */
  let pending = [];

  for (let bi = 0; bi < brands.length; bi++) {
    const brand     = brands[bi];
    const nb        = normBrands[bi];
    let page = 1;

    while (true) {
      let products;
      try {
        products = await wooSearch(base, brand, page);
      } catch (e) {
        console.warn(`  [woo] ${shop} zoek "${brand}" p${page}: ${e.message}`);
        break;
      }
      if (!products.length) break;

      for (const p of products) {
        const titleNorm = normModel(p.name ?? '');
        // Sla producten over die niet echt bij dit merk horen
        if (!nb.split(' ').every(t => t.length < 3 || titleNorm.includes(t) || p.name?.toLowerCase().includes(t))) continue;

        seen++;
        done.add(p.id);
        await indexProduct(p, brand, nb);
      }

      await flushPending();
      if (products.length < 100) break;
      page++;
      await sleep(400);
    }
  }

  // Blader daarna altijd de catalogus door en match op modelnaam. Veel winkels
  // zetten het merk niet in hun titels, en vroeger gebeurde dit alleen als de
  // merkzoekopdracht níéts vond. disena.nl zet "Louis De Poortere" wél in 17
  // titels, en daarmee bleven de ~980 andere kleden onbekeken, waaronder 78
  // onder hun echte naam (Kapiti Black, Spectrum, Viotta…).
  {
    console.log(`  [woo] ${shop}: ${seen} via de merkzoekopdracht, nu de catalogus doorbladeren`);

    for (let page = 1; page <= MAX_CATALOG_PAGES; page++) {
      const products = await wooPage(base, page, shop);
      if (!products.length) break;

      for (const p of products) {
        if (done.has(p.id) || !looksLikeRug(p, brands)) continue;
        const name = p.name ?? '';

        for (let bi = 0; bi < brands.length; bi++) {
          const nb = normBrands[bi];
          if (!looksLikeCatalogModel(normModel(extractModel(name, brands[bi])), nb, catalogModels)) continue;

          await indexProduct(p, brands[bi], nb);
          break;
        }
      }

      await flushPending();
      if (products.length < 100) break;
      await sleep(400);
    }
  }
  await flushPending();

  if (failedPages) {
    console.warn(`  ⚠ [woo] ${shop}: ${failedPages} productpagina's niet opgehaald — hun prijzen ontbreken deze run`);
  }
  console.log(`  [woo] ${shop}: ${apiBatches} prijsverzoeken via de Store API`);

  return { indexed, priced };

  /**
   * Haal de prijzen van de verzamelde variaties op, 100 per verzoek.
   *
   * Vroeger kostte elk variabel product een paginaverzoek; bij maxwonen.nl
   * (~460 kleden) leverde dat een 429 op, ook met 1,5 s pauze, en een run van
   * een uur. De Store API geeft de prijs van 100 variaties in één antwoord.
   * Alleen ids die we vroegen tellen: met een lege of onbekende include-lijst
   * geeft de API willekeurige variaties terug.
   */
  async function flushPending() {
    while (pending.length) {
      const batch = pending.splice(0, 100);
      const wanted = new Map(batch.map(b => [b.id, b]));
      let items = [];
      try {
        items = await getJson(`${base}/wp-json/wc/store/v1/products?include=${[...wanted.keys()].join(',')}&type=variation&per_page=100`);
        apiBatches++;
      } catch (e) {
        console.warn(`  [woo] ${shop}: variatieprijzen niet opgehaald (${e.message})`);
      }
      for (const item of Array.isArray(items) ? items : []) {
        const b = wanted.get(item.id);
        const price = storeApiPrice(item);
        if (b && price) b.apply(fmtEuro(price));
      }
      await sleep(pageDelayMs);
    }
  }

  /** Indexeer één product en leg de variantprijzen vast die bij ons passen. */
  async function indexProduct(p, brand, nb) {
    const name         = decodeEntities(p.name ?? '');
    const model        = normModel(extractModel(name, brand));
    const url          = p.permalink ?? `${base}/?p=${p.id}`;
    const productShape = detectShape(name, url) ?? 'rechthoek';

    upsertIndex(db, { shop, normBrand: nb, normModel: model, title: name, url, platform: 'woocommerce', shape: productShape });
    indexed++;

    /** Koppel één maat+vorm+prijs van deze pagina aan de catalogus. */
    const couple = (size, shape, priceStr, colourText = '') => {
      for (const [key, entries] of catalogModels) {
        if (!key.startsWith(nb + '|')) continue;
        const catModel  = key.split('|')[1];
        const catTokens = catModel.split(' ').filter(Boolean);
        const modTokens = model.split(' ').filter(Boolean);
        const fwdHits   = catTokens.filter(t => model.includes(t)).length;
        const revHits   = modTokens.filter(t => catModel.includes(t)).length;
        if (fwdHits < Math.min(2, catTokens.length) && revHits < Math.min(2, modTokens.length)) continue;
        const idText = model + ' ' + url.toLowerCase() + ' ' + colourText;
        for (const entry of entries) {
          if (entry.widthCm === size.widthCm && entry.heightCm === size.heightCm && entry.shape === shape
              && modelIdentityMatches(catModel, idText, entry.mustHave, { ...identityOpts, ...identityOptionsFor(entry), competitorModel: model })) {
            recordPrice(db, entry.sku, shop, priceStr, url);
            priced++;
          }
        }
      }
    };

    // Eén product per maat, zonder variaties (maxwonen.nl: "Vloerkleed Galaxy 10
    // Beige 200×290 cm"). Prijs en naam staan al in de Store API, dus geen
    // paginaverzoek: 786 daarvan leverden bij maxwonen een 429 op. Zonder maat
    // in naam of URL ("Brush Ovale" bij joldersma) valt er niets te koppelen.
    if (p.type === 'simple') {
      const size  = parseSize(name) ?? parseSize(url) ?? singleSizeAttribute(p);
      const price = storeApiPrice(p);
      if (size && price) couple(size, productShape, fmtEuro(price));
      return;
    }

    const offered = offeredColours(p);

    // Variaties staan in de Store API: prijs in batches (flushPending), maat,
    // vorm en kleur uit de variatie-attributen van het product zelf.
    if (Array.isArray(p.variations) && p.variations.length) {
      for (const v of p.variations) {
        const values = (v.attributes ?? []).map(a => String(a.value ?? ''));
        const size = parseSize(values.join(' ')) ?? values.map(x => parseSize(x)).find(Boolean);
        if (!size) continue;
        const shape = detectShape(values.join(' ')) ?? productShape;
        const colourAttrs = Object.fromEntries((v.attributes ?? []).map(a => [a.name, a.value ?? '']));
        const colourText = colourIdentity({ attributes: colourAttrs }, offered);
        pending.push({ id: v.id, apply: priceStr => couple(size, shape, priceStr, colourText) });
      }
      return;
    }

    // Geen variaties in de API (kledenwereld: "elke kleur"-variaties; meubelcity
    // Plush): terug naar de inline variatie-JSON op de productpagina.
    let html = null;
    try { html = await getText(url); } catch { failedPages++; }

    if (html) {
      const variations = extractVariations(html);
      // "false" als WooCommerce de variaties niet inline zet (te veel)
      for (const v of Array.isArray(variations) ? variations : []) {
        const attrs = JSON.stringify(v.attributes ?? {});
        const size  = parseSize(attrs) ?? parseSize(Object.values(v.attributes ?? {}).join(' '));
        if (!size) continue;
        const priceStr = typeof v.display_price === 'number' ? fmtEuro(v.display_price) : null;
        if (!priceStr) continue;
        couple(size, detectShape(attrs) ?? productShape, priceStr, colourIdentity(v, offered));
      }
    }
    // maxwonen.nl geeft na ~120 snelle productpagina's een 429; per winkel
    // instelbaar (shops.js `pageDelayMs`).
    await sleep(pageDelayMs);
  }
}

/**
 * Is dit een kleed? Bij het doorbladeren van een hele winkel matchen meubels
 * op onze modelnamen ("Salontafel Maya Ray" op Eurogros Maya), en elk daarvan
 * kostte een paginaverzoek: bij maxwonen.nl 786 stuks en een 429.
 *
 * Een merknaam van ons telt ook: maxwonen zet kleden als "Acryl 7274" in de
 * categorie "Uncategorized Eurogros", zonder het woord vloerkleed.
 */
function looksLikeRug(p, brands = []) {
  const text = decodeEntities([p.name, ...(p.categories ?? []).map(c => c.name)].join(' ')).toLowerCase();
  if (/klee?d|karpet|tapijt|\brugs?\b/.test(text)) return true;
  return brands.some(b => text.includes(String(b).toLowerCase()));
}

/** HTML-entities in Store API-namen ("&#8216;Milano&#8217;", "140&#215;200"). */
function decodeEntities(str) {
  return String(str)
    .replace(/&#(\d+);/g, (_, c) => String.fromCharCode(Number(c)))
    .replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#039;|&apos;/g, "'");
}

/**
 * De maat van een simple product dat hem alleen als eigenschap noemt
 * (joldersma-wonen.nl: "Vloerkleed 'Christi 3888'" met Afmetingen = 200 x 290
 * cm). Alleen bij precies één maat: noemt het product er meer voor één prijs
 * ("Prosper Grey Custard": vijf maten), dan is niet te zeggen welke het is.
 */
function singleSizeAttribute(p) {
  const sizes = (p.attributes ?? [])
    .flatMap(a => (a.terms ?? []).map(t => parseSize(decodeEntities(t.name ?? ''))))
    .filter(Boolean);
  const unique = new Set(sizes.map(s => `${s.widthCm}x${s.heightCm}`));
  return unique.size === 1 ? sizes[0] : null;
}

/** Prijs uit de Store API (in centen, `currency_minor_unit`), of null. */
function storeApiPrice(p) {
  const raw = Number(p.prices?.price);
  if (!Number.isFinite(raw) || raw <= 0) return null;
  return raw / 10 ** Number(p.prices?.currency_minor_unit ?? 2);
}

const COLOUR_ATTR = /kleur|colou?r/i;

/**
 * Kleur van één variatie, uit de variatie-attributen ("attribute_kleur").
 *
 * kledenwereld.nl verkoopt één product per model ("Mart Visser Prosper") met
 * een Kleur × Formaat-matrix. Titel en URL noemen geen kleur, dus zonder dit
 * kwam élke Prosper-kleur uit ons PIM op dezelfde prijs uit — ook kleuren die
 * de winkel helemaal niet voert.
 */
function variantColour(v) {
  return Object.entries(v.attributes ?? {})
    .filter(([key]) => COLOUR_ATTR.test(key))
    .map(([, value]) => String(value ?? ''))
    .join(' ').replace(/[-_]/g, ' ').toLowerCase().trim();
}

/**
 * Alle kleuren waaruit de klant kan KIEZEN (Store API `attributes[].terms`,
 * alleen attributen met `has_variations`).
 *
 * Een beschrijvend kleurattribuut telt niet: grootinvloeren.nl zet op de
 * pagina van "LoveShaggy LichtBruin" Kleur = Bruin, Taupe als eigenschap, en
 * dan kreeg onze Love Shaggy Taupe de lichtbruin-prijs.
 */
function offeredColours(p) {
  return (p.attributes ?? [])
    .filter(a => a.has_variations && COLOUR_ATTR.test(a.name ?? ''))
    .flatMap(a => (a.terms ?? []).map(t => t.name ?? ''))
    .join(' ').toLowerCase();
}

/**
 * Wat een variatie over zijn kleur zegt, voor de identiteitscheck.
 *
 * Varieert het product niet op kleur, dan niets: dan zegt de titel het al.
 * Een LEGE variatie-kleur ("attribute_kleur": "") betekent in WooCommerce
 * "elke kleur, zelfde prijs"; dan telt de lijst waaruit je kunt kiezen, zodat
 * een kleur van ons die de winkel niet voert geen prijs krijgt.
 */
function colourIdentity(v, offered) {
  const variesOnColour = Object.keys(v.attributes ?? {}).some(key => COLOUR_ATTR.test(key));
  if (!variesOnColour) return '';
  return variantColour(v) || offered;
}

module.exports = { indexWooCommerce, extractVariations, variantColour, offeredColours, colourIdentity, wooPage, decodeEntities, storeApiPrice, looksLikeRug, singleSizeAttribute };
