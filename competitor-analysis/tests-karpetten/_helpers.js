/**
 * _helpers.js – scrape-adapters voor de karpetten-suite. Alles draait op de
 * Playwright `request`-fixture (geen browserpagina nodig): de meeste shops
 * zijn server-side gerenderd of hebben een publieke JSON-API.
 *
 *  - shopifyPrijs : zoekt het product via /search/suggest.json en leest de
 *                   EXACTE variantprijs voor de gevraagde maat uit
 *                   /products/<handle>.js. Geen maat-variant -> "Vanaf €".
 *  - paginaPrijs  : haalt één productpagina op en leest JSON-LD / meta /
 *                   itemprop-prijs. `maatExact: true` (maat zit in de URL of
 *                   het product is maatvast) -> "€ x", anders "Vanaf € x".
 *  - vindLink     : discovery-helper — eerste href op een (merk)pagina die
 *                   op een regex matcht (voor shops zonder vaste product-URL).
 *
 * Conventie (zelfde als hordeuren): nooit hangen, nooit gokken — geen
 * betrouwbare prijs = null, de spec registreert dan eerlijk n.v.t.
 */

const UA_HEADERS = {
  'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
  'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
  'Accept-Language': 'nl-NL,nl;q=0.9',
};

const fmt = n => `€ ${Number(n).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?=,))/g, '.')}`;

/** Shopify: zoek product -> exacte variantprijs voor `maat` (bv "200x290").
 *  Geeft { prijs, url } terug (url = gevonden productpagina) of null. */
async function shopifyPrijs(request, base, query, titelRe, maat) {
  const zoek = await request.get(
    `${base}/search/suggest.json?q=${encodeURIComponent(query)}&resources[type]=product&resources[limit]=8`,
    { headers: UA_HEADERS, timeout: 15000 });
  if (!zoek.ok()) return null;
  const producten = (await zoek.json())?.resources?.results?.products || [];
  const product = producten.find(p => titelRe.test(p.title || ''));
  if (!product) return null;
  const url = `${base}/products/${product.handle}`;

  const prod = await request.get(`${url}.js`, { headers: UA_HEADERS, timeout: 15000 });
  if (!prod.ok()) return null;
  const j = await prod.json();
  const maatRe = new RegExp(String(maat).replace('x', '\\s*[x×]\\s*'), 'i');
  const variant = (j.variants || []).find(v => maatRe.test(v.title || '') || maatRe.test(v.public_title || ''));
  if (variant) return { prijs: fmt(variant.price / 100), url };
  // per-maat product (maat in de producttitel zelf, bv "Allison 3293 200x290")
  // -> de productprijs IS de exacte maatprijs
  if (maatRe.test(j.title || '') && typeof j.price === 'number' && j.price > 5000) {
    return { prijs: fmt(j.price / 100), url };
  }
  if (typeof j.price === 'number' && j.price > 5000) return { prijs: `Vanaf ${fmt(j.price / 100)}`, url };
  return null;
}

/** Productpagina: JSON-LD offers -> meta og/product price -> itemprop. */
async function paginaPrijs(request, url, { maatExact = false } = {}) {
  const resp = await request.get(url, { headers: UA_HEADERS, timeout: 20000, maxRedirects: 5 });
  if (!resp.ok()) return null;
  const html = await resp.text();

  let prijs = null;
  for (const m of html.matchAll(/<script[^>]*application\/ld\+json[^>]*>([^]*?)<\/script>/gi)) {
    try {
      let j = JSON.parse(m[1]);
      const lijst = Array.isArray(j) ? j : (j['@graph'] || [j]);
      const prod = lijst.find(x => x && x['@type'] === 'Product');
      if (!prod || !prod.offers) continue;
      const offer = Array.isArray(prod.offers) ? prod.offers[0] : prod.offers;
      const spec = Array.isArray(offer.priceSpecification) ? offer.priceSpecification[0] : offer.priceSpecification;
      const p = Number(offer.price ?? offer.lowPrice ?? spec?.price);
      if (p > 50) { prijs = p; break; }
    } catch { /* volgende blok */ }
  }
  if (prijs == null) {
    const meta = html.match(/property="(?:og:price:amount|product:price:amount)" content="([\d.]+)"/i)
              || html.match(/itemprop="price" content="([\d.]+)"/i);
    if (meta) { const p = Number(meta[1]); if (p > 50) prijs = p; }
  }
  if (prijs == null) return null;
  return maatExact ? fmt(prijs) : `Vanaf ${fmt(prijs)}`;
}

/** WooCommerce variabel product: maatprijs uit het inline
 *  data-product_variations attribuut (HTML-escaped JSON). */
async function wooVariatiePrijs(request, url, maat) {
  const resp = await request.get(url, { headers: UA_HEADERS, timeout: 20000, maxRedirects: 5 });
  if (!resp.ok()) return null;
  const html = await resp.text();
  const m = html.match(/data-product_variations="([^"]+)"/);
  if (!m) return null;
  let variaties;
  try {
    variaties = JSON.parse(m[1].replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&#?\w+;/g, s => ({ '&lt;': '<', '&gt;': '>' }[s] ?? s)));
  } catch { return null; }
  // "200x290" moet ook "200-x-290-cm", "200 x 290" en "200x290-cm" matchen
  const maatRe = new RegExp(String(maat).replace('x', '[-\\s]*[x×][-\\s]*'), 'i');
  const matches = variaties.filter(v => maatRe.test(JSON.stringify(v.attributes || {})));
  // sommige shops voeren naast rechthoek ook "200x290ovaal" -> rechthoek eerst
  const variant = matches.find(v => !/ovaal|rond|vierkant/i.test(JSON.stringify(v.attributes || {}))) ?? matches[0];
  if (!variant || typeof variant.display_price !== 'number') return null;
  return fmt(variant.display_price);
}

/** WooCommerce Store API (publiek): variabel product -> variant-prijs.
 *  /wp-json/wc/store/products/<id> -> variations[] -> /products/<varId>. */
async function wooStorePrijs(request, base, productId, maat) {
  const maatRe = new RegExp(String(maat).replace('x', '[-\\s]*[x×][-\\s]*'), 'i');
  const prod = await request.get(`${base}/wp-json/wc/store/products/${productId}`, { headers: UA_HEADERS, timeout: 15000 });
  if (!prod.ok()) return null;
  const j = await prod.json();
  const variant = (j.variations || []).find(v => maatRe.test(JSON.stringify(v.attributes || [])));
  if (!variant) return null;
  const det = await request.get(`${base}/wp-json/wc/store/products/${variant.id}`, { headers: UA_HEADERS, timeout: 15000 });
  if (!det.ok()) return null;
  const d = await det.json();
  const p = Number(d.prices?.price);
  const mu = Number(d.prices?.currency_minor_unit ?? 2);
  return p > 0 ? fmt(p / 10 ** mu) : null;
}

/** WooCommerce wc-ajax get_variation (voor producten met >30 variaties). */
async function wooAjaxPrijs(request, base, productId, attrs) {
  const form = { product_id: String(productId), ...attrs };
  const resp = await request.post(`${base}/?wc-ajax=get_variation`, { headers: UA_HEADERS, form, timeout: 15000 });
  if (!resp.ok()) return null;
  const j = await resp.json().catch(() => null);
  return j && typeof j.display_price === 'number' ? fmt(j.display_price) : null;
}

/** Generiek: prijs via een shop-specifieke regex op de pagina-HTML.
 *  `pattern` = RegExp-bron met de prijs in capture-groep 1 (Lightspeed-shops:
 *  variant-JSON of Afmeting-dropdown met absolute prijs per maat). */
async function regexPrijs(request, url, pattern) {
  const resp = await request.get(url, { headers: UA_HEADERS, timeout: 20000, maxRedirects: 5 });
  if (!resp.ok()) return null;
  const html = await resp.text();
  const m = html.match(new RegExp(pattern, 'i'));
  if (!m) return null;
  // NL-formaat "2.169,00" (komma aanwezig -> punten zijn duizendtallen) én
  // JSON-formaat "2169" / "1299.00" correct parsen
  let s = String(m[1]);
  if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
  const p = parseFloat(s);
  return p > 50 ? fmt(p) : null;
}

/** Thema-custom <select name="size"> met opties "2.00 x 3.00|1439"
 *  (vloerkledenspecialist.nl). `meters` bv "2.00 x 3.00". */
async function sizeSelectPrijs(request, url, meters) {
  const resp = await request.get(url, { headers: UA_HEADERS, timeout: 20000, maxRedirects: 5 });
  if (!resp.ok()) return null;
  const html = await resp.text();
  const re = new RegExp(`value="${meters.replace(/[.|]/g, '\\$&').replace(/ /g, '\\s*')}\\|(\\d+(?:[.,]\\d+)?)"`, 'i');
  const m = html.match(re);
  return m ? fmt(parseFloat(m[1].replace(',', '.'))) : null;
}

/** Eerste absolute link op `paginaUrl` die op linkRe matcht (discovery). */
async function vindLink(request, paginaUrl, linkRe) {
  const resp = await request.get(paginaUrl, { headers: UA_HEADERS, timeout: 20000, maxRedirects: 5 });
  if (!resp.ok()) return null;
  const html = await resp.text();
  for (const m of html.matchAll(/href="([^"]+)"/gi)) {
    if (linkRe.test(m[1])) {
      return m[1].startsWith('http') ? m[1] : new URL(m[1], paginaUrl).href;
    }
  }
  return null;
}

/**
 * Spec-factory (zelfde idee als tests/_vanaf.js): registreert één test per
 * item. item = { model, ... } plus één van:
 *   { url, maatExact }                          -> paginaPrijs
 *   { zoek, titelRe, maat, base }               -> shopifyPrijs
 *   { vindOp, linkRe, maatExact }               -> vindLink + paginaPrijs
 *   { wooVariaties: url, maat }                 -> wooVariatiePrijs (exact)
 *   { wooStore: productId, maat, base, url }    -> wooStorePrijs (exact)
 *   { wooAjax: productId, attrs, base, url }    -> wooAjaxPrijs (exact)
 *   { sizeSelect: url, meters }                 -> sizeSelectPrijs (exact)
 *   { regexUrl: url, pattern, vanaf? }          -> regexPrijs (groep 1 = prijs;
 *                                                  vanaf: true -> "Vanaf €", voor
 *                                                  shops zonder maatprijs)
 *   { browserUrl: url, pattern }                -> echte paginalaad (Cloudflare)
 *                                                  + regex op de gerenderde HTML
 */
function registerKarpetten(test, expect, { shop, base, items }) {
  const { recordKarpet } = require('./recorder');
  for (const item of items) {
    test(`${shop} – ${item.model}`, async ({ page, request }) => {
      let prijs = null, bron = item.url ?? null;
      try {
        if (item.zoek) {
          const r = await shopifyPrijs(request, base, item.zoek, item.titelRe, item.maat);
          if (r) { prijs = r.prijs; bron = r.url; }
        } else if (item.wooVariaties) {
          prijs = await wooVariatiePrijs(request, item.wooVariaties, item.maat);
          bron = item.wooVariaties;
        } else if (item.wooStore) {
          prijs = await wooStorePrijs(request, item.base ?? base, item.wooStore, item.maat);
        } else if (item.wooAjax) {
          prijs = await wooAjaxPrijs(request, item.base ?? base, item.wooAjax, item.attrs);
        } else if (item.sizeSelect) {
          prijs = await sizeSelectPrijs(request, item.sizeSelect, item.meters);
          bron = item.sizeSelect;
        } else if (item.regexUrl) {
          prijs = await regexPrijs(request, item.regexUrl, item.pattern);
          if (prijs && item.vanaf) prijs = `Vanaf ${prijs}`;
          bron = item.regexUrl;
        } else if (item.browserStore) {
          // Cloudflare-shop met open Woo Store API: één echte paginalaad om de
          // challenge te passeren, dan in-page fetch van de zoek-API.
          await page.goto(item.browserStore, { waitUntil: 'domcontentloaded', timeout: 30000 });
          await page.waitForFunction(() => !/even geduld|just a moment/i.test(document.title), { timeout: 20000 }).catch(() => {});
          const res = await page.evaluate(async ({ zoek, maat }) => {
            const r = await fetch(`/wp-json/wc/store/v1/products?search=${encodeURIComponent(zoek)}&per_page=20`);
            if (!r.ok) return null;
            const maatRe = new RegExp(maat.replace('x', '[-\\s]*[x×][-\\s]*'), 'i');
            for (const p of await r.json()) {
              if (!maatRe.test(p.name) || /ovaal|rond|vierkant/i.test(p.name)) continue;
              const c = Number(p.prices?.price), mu = Number(p.prices?.currency_minor_unit ?? 2);
              if (c > 0) return { prijs: c / 10 ** mu, url: p.permalink };
            }
            return null;
          }, { zoek: item.zoekStore, maat: item.maat }).catch(() => null);
          if (res) { prijs = fmt(res.prijs); bron = res.url; }
        } else if (item.browserUrl) {
          // Cloudflare-challenge: echte paginalaad, titel-poll, dan regex
          await page.goto(item.browserUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
          await page.waitForFunction(() => !/even geduld|just a moment/i.test(document.title), { timeout: 20000 }).catch(() => {});
          const html = await page.content();
          const m = html.match(new RegExp(item.pattern, 'i'));
          if (m) {
            let s = String(m[1]);
            if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
            const p = parseFloat(s);
            if (p > 50) prijs = fmt(p);
          }
          bron = item.browserUrl;
        } else if (item.vindOp) {
          const url = await vindLink(request, item.vindOp, item.linkRe);
          if (url) {
            prijs = item.pattern
              ? await regexPrijs(request, url, item.pattern)
              : await paginaPrijs(request, url, { maatExact: !!item.maatExact });
            bron = url;
          }
        } else if (item.url) {
          prijs = await paginaPrijs(request, item.url, { maatExact: !!item.maatExact });
        }
      } catch (e) {
        console.log(`${shop} ${item.model}: ${e.message.split('\n')[0]}`);
      }
      recordKarpet(shop, item.model, prijs ?? 'n.v.t.', prijs ? bron : null);
      expect(prijs ?? 'n.v.t.').toBeTruthy();
    });
  }
}

module.exports = {
  shopifyPrijs, paginaPrijs, vindLink, registerKarpetten, UA_HEADERS,
  wooVariatiePrijs, wooStorePrijs, wooAjaxPrijs, sizeSelectPrijs, regexPrijs,
};
