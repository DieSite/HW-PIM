/**
 * sitemap.js – hulpfuncties voor sitemap-gebaseerde product-discovery.
 *
 * Veel webshops die geen clean API bieden, hebben wel een /sitemap.xml of
 * /sitemap_index.xml. We crawlen die, filteren URL's op merkkeywords en
 * modnaam-tokens, en geven product-URL's terug.
 */

const { getText, sleep } = require('../http');

/**
 * Haal alle URL's op uit een (geneste) sitemap.
 * Ondersteunt sitemap-index-bestanden (die naar andere .xml-sitemaps linken).
 *
 * @param {string}   rootUrl  – bijv. "https://www.shop.nl/sitemap.xml"
 * @param {number}   maxUrls  – stop na dit aantal URL's (default 50 000)
 * @returns {Promise<string[]>}
 */
async function fetchSitemapUrls(rootUrl, maxUrls = 50_000) {
  const visited = new Set();
  const urls    = [];

  async function crawl(url) {
    if (visited.has(url) || urls.length >= maxUrls) return;
    visited.add(url);

    let xml;
    try { xml = await getText(url, { timeout: 30_000 }); } catch { return; }

    // Sitemap-index: verwijzingen naar andere .xml-bestanden
    const subMaps = [...xml.matchAll(/<sitemap>[\s\S]*?<loc>([^<]+)<\/loc>/gi)].map(m => m[1].trim());
    for (const sub of subMaps) {
      if (urls.length >= maxUrls) break;
      await crawl(sub);
      await sleep(100);
    }

    // Gewone URL's
    for (const m of xml.matchAll(/<url>[\s\S]*?<loc>([^<]+)<\/loc>/gi)) {
      if (urls.length >= maxUrls) break;
      const u = m[1].trim();
      if (!visited.has(u)) urls.push(u);
    }
  }

  await crawl(rootUrl);
  return urls;
}

/**
 * Grof voorfilter op de URL-lijst, zodat de modellenlus niet over tienduizenden
 * URL's hoeft. Géén trefwoorden betekent géén filter: de strenge guards in
 * discover.js beslissen dan alleen. Dat is bewust — een lege lijst gooide
 * eerder álles weg, waardoor een shop zonder brandKeys stilzwijgend niets
 * indexeerde.
 */
function filterByKeywords(urls, keywords) {
  const kws = (keywords ?? []).map(k => k.toLowerCase());
  if (kws.length === 0) return [...urls];

  return urls.filter(u => {
    const l = u.toLowerCase();
    return kws.some(k => l.includes(k));
  });
}

/**
 * Haal alle hrefs op een HTML-pagina op die op linkRe matchen.
 * Retourneert absolute URL's.
 */
function extractLinks(html, baseUrl, linkRe) {
  const links = [];
  const base  = new URL(baseUrl);
  for (const m of html.matchAll(/href="([^"]+)"/gi)) {
    if (linkRe.test(m[1])) {
      try {
        links.push(new URL(m[1], base).href);
      } catch { /* ongeldige URL */ }
    }
  }
  return [...new Set(links)];
}

/**
 * Haal de prijs op via JSON-LD of meta-tags (als de URL de maat al bevat).
 */
function extractJsonLdPrice(html, minPrice = 50) {
  for (const m of html.matchAll(/<script[^>]*application\/ld\+json[^>]*>([\s\S]*?)<\/script>/gi)) {
    try {
      let j = JSON.parse(m[1]);
      const list = Array.isArray(j) ? j : (j['@graph'] ?? [j]);
      const prod = list.find(x => x?.['@type'] === 'Product');
      if (!prod?.offers) continue;
      const offer = Array.isArray(prod.offers) ? prod.offers[0] : prod.offers;
      const spec  = Array.isArray(offer.priceSpecification) ? offer.priceSpecification[0] : offer.priceSpecification;
      const p     = Number(offer.price ?? offer.lowPrice ?? spec?.price);
      if (p > minPrice) return p;
    } catch { /* volgende blok */ }
  }
  // Fallback: meta-tags
  const meta = html.match(/property="(?:og:price:amount|product:price:amount)" content="([\d.]+)"/i)
            || html.match(/itemprop="price" content="([\d.]+)"/i);
  if (meta) { const p = Number(meta[1]); if (p > minPrice) return p; }
  return null;
}

/**
 * Parse een prijsstring (NL-formaat "1.299,00" of JSON "1299") naar float.
 */
function parsePriceStr(str) {
  let s = String(str ?? '').trim();
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
  const p = parseFloat(s);
  return p > 0 ? p : null;
}

/** De URL van pagina N van een overzicht; pagina 1 is de URL zelf. */
function listPageUrl(listUrl, page, listPageParam = 'p') {
  if (page <= 1) return listUrl;

  return listUrl + (listUrl.includes('?') ? '&' : '?') + `${listPageParam}=${page}`;
}

/**
 * Product-URL's uit een doorgebladerde categoriepagina.
 *
 * Niet elke winkel heeft een bruikbare sitemap: karpettenshop.nl serveert op
 * /sitemap.xml een 404-pagina en heeft er nergens één, maar zijn overzicht
 * (/karpetten.html?p=N) zet de productlinks gewoon in de HTML. Voor zulke
 * winkels is dit het alternatief — zelfde uitkomst, andere bron.
 *
 * Stopt zodra een pagina niets nieuws meer oplevert, zodat een winkel die bij
 * een te hoog paginanummer de eerste pagina blijft teruggeven de crawl niet
 * eindeloos rekt.
 *
 * @param {{listUrl: string, listPages?: number, listPageParam?: string, linkRe: RegExp}} cfg
 * @returns {Promise<string[]>}
 */
async function fetchListUrls({ listUrl, listPages = 50, listPageParam = 'p', linkRe }) {
  const found = new Set();
  let leeg = 0;

  for (let page = 1; page <= listPages; page++) {
    const url = listPageUrl(listUrl, page, listPageParam);

    let html;
    try { html = await getText(url); } catch (e) {
      console.warn(`  ⚠ lijstpagina ${page} mislukt: ${e.message}`);
      break;
    }

    // Tellen wat de pagina zélf bevat, niet wat er nieuw is: een overzicht
    // kan tussen twee verzoeken van volgorde wisselen en dan bevat een pagina
    // alleen al geziene producten. Op "geen nieuwe" stoppen kapte de crawl van
    // karpettenshop op 184 van de ~700 producten af.
    const opPagina = [...html.matchAll(new RegExp(linkRe.source, 'g'))].map(m => m[0]);
    for (const u of opPagina) found.add(u);

    leeg = opPagina.length === 0 ? leeg + 1 : 0;
    if (leeg >= 2) break;

    await sleep(300);
  }

  return [...found];
}

module.exports = { fetchSitemapUrls, fetchListUrls, listPageUrl, filterByKeywords, extractLinks, extractJsonLdPrice, parsePriceStr };
