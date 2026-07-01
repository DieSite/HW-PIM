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
 * Filter een lijst van URL's op het voorkomen van één of meer keywords.
 * Normaliseert alles naar lowercase vóór vergelijking.
 */
function filterByKeywords(urls, keywords) {
  const kws = keywords.map(k => k.toLowerCase());
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

module.exports = { fetchSitemapUrls, filterByKeywords, extractLinks, extractJsonLdPrice, parsePriceStr };
