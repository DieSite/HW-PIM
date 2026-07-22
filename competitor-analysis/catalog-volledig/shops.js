/**
 * shops.js – configuratie per competitor:
 *
 *  platform   – 'shopify' | 'woocommerce' | 'custom'
 *  base       – basis-URL
 *  brands     – de merken die we bij deze shop verwachten
 *  sitemapUrl – startpunt voor sitemap-crawl (custom shops)
 *  brandKeys  – URL-substrings die op een van onze merken wijzen (filter)
 *  getPrijs   – (html, widthCm, heightCm) -> priceStr | null  (custom shops)
 *              voor "vanaf"-shops: geeft altijd "Vanaf €…" terug
 *  fromUrl    – true als de prijs direct uit de URL-pagina komt (geen maat-regex)
 *              (de URL zelf bevat de maat al)
 *  browser    – true als de shop Cloudflare-bescherming heeft en een echte
 *               browser vereist (wordt overgeslagen in de headless indexer)
 *
 * Shops zonder getPrijs maar met platform='custom' gebruiken JSON-LD/meta
 * vanuit de URL-pagina (fromUrl=true vereist).
 */

const { parsePriceStr } = require('./indexers/sitemap');

const fmt = n => {
  const p = Number(n);
  if (!Number.isFinite(p) || p <= 0) return null;
  return `€ ${p.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?=,))/g, '.')}`;
};

// ── Shopify ──────────────────────────────────────────────────────────────────

const SHOPIFY_SHOPS = [
  {
    key:    'vloerkledenloods.nl',
    base:   'https://vloerkledenloods.nl',
    brands: ['De Munk', 'Karpi', 'Mart Visser'],
  },
  {
    key:    'hetdesignhuys.nl',
    base:   'https://hetdesignhuys.nl',
    brands: ['Eurogros', 'Karpi', 'Mart Visser'],
  },
];

// ── WooCommerce ───────────────────────────────────────────────────────────────

const WOOCOMMERCE_SHOPS = [
  {
    key:    'karpetwereld.nl',
    base:   'https://karpetwereld.nl',
    brands: ['De Munk', 'Mart Visser'],
  },
  {
    key:    'plaisierinterieur.nl',
    base:   'https://www.plaisierinterieur.nl',
    brands: ['De Munk'],
  },
  {
    key:    'vloerkledenspecialist.nl',
    base:   'https://vloerkledenspecialist.nl',
    brands: ['De Munk'],
  },
  {
    key:    'vivaldixl.nl',
    base:   'https://www.vivaldixl.nl',
    brands: ['Karpi', 'Mart Visser'],
  },
  {
    key:    'meubelcity.nl',
    base:   'https://www.meubelcity.nl',
    brands: ['Karpi'],
  },
  {
    key:    'grootinvloeren.nl',
    base:   'https://www.grootinvloeren.nl',
    brands: ['Eurogros'],
  },
];

// ── Custom shops (sitemap + regex) ────────────────────────────────────────────

const CUSTOM_SHOPS = [
  // ── karpettenkelder.nl ──────────────────────────────────────────────────
  {
    key:        'karpettenkelder.nl',
    base:       'https://www.karpettenkelder.nl',
    brands:     ['Eurogros', 'De Munk', 'Karpi'],
    sitemapUrl: 'https://www.karpettenkelder.nl/sitemap.xml',
    brandKeys:  ['eurogros', 'de-munk-carpets', 'core-by-dersimo', 'desso', 'de-poortere'],
    // Prijs zit in data-prijs attribuut bij de maat-radio; de data-title
    // benoemt de vorm ("200 x 290 rechthoek" / "… ovaal"), dus match die mee
    getPrijs(html, w, h, shape = 'rechthoek') {
      const maat = `${w} x ${h}`;
      const m = html.match(new RegExp(`data-title="${maat} ${shape}"[\\s\\S]{0,600}?data-prijs="([\\d.,]+)"`, 'i'));
      if (!m) return null;
      return fmt(parsePriceStr(m[1]));
    },
    // Brand detecteren uit URL-pad
    detectBrand(url) {
      if (/eurogros/i.test(url))        return 'Eurogros';
      if (/de-munk/i.test(url))         return 'De Munk';
      if (/core-by-dersimo/i.test(url)) return 'Karpi';
      if (/desso/i.test(url))           return 'Desso';
      if (/poortere/i.test(url))        return 'Louis De Poortere';
      return null;
    },
  },

  // ── volero.nl ────────────────────────────────────────────────────────────
  {
    key:        'volero.nl',
    base:       'https://www.volero.nl',
    brands:     ['Eurogros', 'Louis De Poortere', 'Desso'],
    sitemapUrl: 'https://www.volero.nl/sitemap.xml',
    brandKeys:  ['eurogros', 'antoin', 'poortere', 'desso'],
    getPrijs(html, w, h) {
      const m = html.match(new RegExp(`>${w}x${h}cm\\s*[-–]\\s*€\\s*([\\d.,]+)<`, 'i'));
      if (!m) return null;
      return fmt(parsePriceStr(m[1]));
    },
    detectBrand(url) {
      if (/eurogros|antoin/i.test(url)) return 'Eurogros';
      if (/poortere/i.test(url))        return 'Louis De Poortere';
      if (/desso/i.test(url))           return 'Desso';
      return null;
    },
  },

  // ── karpettenshop.nl ─────────────────────────────────────────────────────
  {
    key:        'karpettenshop.nl',
    base:       'https://www.karpettenshop.nl',
    brands:     ['De Munk'],
    sitemapUrl: 'https://www.karpettenshop.nl/sitemap.xml',
    brandKeys:  ['de-munk-carpets'],
    getPrijs(html, w, h) {
      const maat = `${w} x ${h}`;
      const m = html.match(new RegExp(`>${maat}(?: cm)?<\\/label>[\\s\\S]{0,500}?<span class="price">€\\s?([\\d.,]+)<\\/span>`, 'i'));
      if (!m) return null;
      return fmt(parsePriceStr(m[1]));
    },
    detectBrand(_url) { return 'De Munk'; },
  },

  // ── kleed.nl (Lightspeed) ─────────────────────────────────────────────────
  {
    key:        'kleed.nl',
    base:       'https://www.kleed.nl',
    brands:     ['De Munk', 'Mart Visser'],
    sitemapUrl: 'https://www.kleed.nl/sitemap.xml',
    brandKeys:  ['de-munk', 'mart-visser', 'karpi'],
    getPrijs(html, w, h) {
      const maat = `${w} x ${h}`;
      // Lightspeed variant JSON: "price_incl":1299,...,"title":"Formaat: 200 x 300cm"
      const m = html.match(new RegExp(
        `"price_incl":([\\d.]+),"price_excl":[\\d.]+,"price_old":[\\d.]+[^}]*}[^{}]*"title":"Formaat: ${maat}\\s*cm"`,
        'i'
      ));
      if (!m) return null;
      return fmt(parsePriceStr(m[1]));
    },
    detectBrand(url) {
      if (/de-munk|munk/i.test(url)) return 'De Munk';
      if (/mart-visser/i.test(url))  return 'Mart Visser';
      if (/karpi/i.test(url))        return 'Karpi';
      return null;
    },
  },

  // ── homedeco.nl (maat in URL -> JSON-LD) ─────────────────────────────────
  {
    key:        'homedeco.nl',
    base:       'https://homedeco.nl',
    brands:     ['De Munk', 'Mart Visser'],
    sitemapUrl: 'https://homedeco.nl/sitemap.xml',
    brandKeys:  ['de-munk', 'munk', 'mart-visser'],
    fromUrl:    true,   // prijs via JSON-LD, maat al in de URL
    getPrijs:   null,   // wordnull: fetch-prices.js gebruikt extractJsonLdPrice
    sizeFromUrl(url) {
      // "/wollen-vloerkleed-firenze-22-de-munk-carpets-200-x-300-cm-l/"
      const m = url.match(/(\d{2,3})-x-(\d{2,3})-cm/i);
      return m ? { widthCm: Number(m[1]), heightCm: Number(m[2]) } : null;
    },
    detectBrand(url) {
      if (/de-munk|munk/i.test(url))   return 'De Munk';
      if (/mart-visser/i.test(url))     return 'Mart Visser';
      return null;
    },
  },

  // ── vloerkledenvoordelig.nl (maat in URL -> JSON-LD/meta) ────────────────
  {
    key:        'vloerkledenvoordelig.nl',
    base:       'https://www.vloerkledenvoordelig.nl',
    brands:     ['Karpi', 'Mart Visser'],
    sitemapUrl: 'https://www.vloerkledenvoordelig.nl/sitemap.xml',
    brandKeys:  ['karpi', 'mart-visser'],
    fromUrl:    true,
    getPrijs:   null,
    sizeFromUrl(url) {
      // "…-200x290_724709.html"
      const m = url.match(/[_-](\d{2,3})x(\d{2,3})[_.]/) || url.match(/(\d{2,3})x(\d{2,3})/);
      return m ? { widthCm: Number(m[1]), heightCm: Number(m[2]) } : null;
    },
    detectBrand(url) {
      if (/karpi/i.test(url))      return 'Karpi';
      if (/mart-visser/i.test(url)) return 'Mart Visser';
      return null;
    },
  },

  // ── woonboulevardpoortvliet.nl (maat in URL -> JSON-LD) ──────────────────
  {
    key:        'woonboulevardpoortvliet.nl',
    base:       'https://www.woonboulevardpoortvliet.nl',
    brands:     ['Eurogros', 'De Munk', 'Mart Visser'],
    sitemapUrl: 'https://www.woonboulevardpoortvliet.nl/sitemap.xml',
    brandKeys:  ['eurogros', 'love-shaggy', 'twilight', 'mart-visser', 'firenze', 'de-munk'],
    fromUrl:    true,
    getPrijs:   null,
    sizeFromUrl(url) {
      const m = url.match(/(\d{2,3})x(\d{2,3})/) || url.match(/(\d{2,3})-x-(\d{2,3})/);
      return m ? { widthCm: Number(m[1]), heightCm: Number(m[2]) } : null;
    },
    detectBrand(url) {
      if (/eurogros|love-shaggy|twilight|arizona|anaheim|aspen|allison|spectrum|richmond/i.test(url)) return 'Eurogros';
      if (/de-munk|firenze|venezia|grande|martello|genova|vogue|lecce|toscane/i.test(url)) return 'De Munk';
      if (/mart-visser|cendre|vernon|cavaro|prosper/i.test(url)) return 'Mart Visser';
      return null;
    },
  },

  // ── homecompanyshop.nl (Lightspeed) ──────────────────────────────────────
  {
    key:        'homecompanyshop.nl',
    base:       'https://www.homecompanyshop.nl',
    brands:     ['Karpi', 'Mart Visser'],
    sitemapUrl: 'https://www.homecompanyshop.nl/sitemap.xml',
    brandKeys:  ['karpi', 'mart-visser', 'olimpos', 'headlam'],
    getPrijs(html, w, h) {
      const maat = `${w} x ${h}`;
      // Lightspeed variant JSON bevat "price":{"price":659},...,"title":"Maat: Medium 200 x 290"
      const m = html.match(new RegExp(
        `"price":\\{"price":([\\d.]+)[^{}]{0,300}?"title":"[^"]*\\b${maat}\\b`,
        'i'
      ));
      if (!m) return null;
      return fmt(parsePriceStr(m[1]));
    },
    detectBrand(url) {
      if (/mart-visser/i.test(url)) return 'Mart Visser';
      if (/karpi|olimpos|headlam/i.test(url)) return 'Karpi';
      return null;
    },
  },

  // ── floorpassion.nl (Lightspeed) ─────────────────────────────────────────
  {
    key:        'floorpassion.nl',
    base:       'https://www.floorpassion.nl',
    brands:     ['Mart Visser', 'Louis De Poortere', 'Desso'],
    sitemapUrl: 'https://www.floorpassion.nl/sitemap.xml',
    brandKeys:  ['mart-visser', 'cendre', 'vernon', 'cavaro', 'prosper', 'poortere', 'desso'],
    getPrijs(html, w, h) {
      const maat = `${w}x${h}`;
      const m = html.match(new RegExp(
        `<option value="\\d+"[^>]*data-price="([\\d.]+)"[^>]*>Afmeting: ${maat} cm`,
        'i'
      ));
      if (!m) return null;
      return fmt(parsePriceStr(m[1]));
    },
    detectBrand(url) {
      if (/mart-visser|cendre|vernon|cavaro|prosper/i.test(url)) return 'Mart Visser';
      if (/poortere/i.test(url))  return 'Louis De Poortere';
      if (/desso/i.test(url))     return 'Desso';
      return null;
    },
  },

  // ── gigameubel.nl (maat in URL -> JSON-LD) ────────────────────────────────
  {
    key:        'gigameubel.nl',
    base:       'https://www.gigameubel.nl',
    brands:     ['Mart Visser', 'Karpi'],
    sitemapUrl: 'https://www.gigameubel.nl/sitemap.xml',
    brandKeys:  ['mart-visser', 'karpi', 'cendre', 'vernon', 'cavaro', 'prosper'],
    fromUrl:    true,
    getPrijs:   null,
    sizeFromUrl(url) {
      const m = url.match(/(\d{2,3})x(\d{2,3})(?:cm)?/i);
      return m ? { widthCm: Number(m[1]), heightCm: Number(m[2]) } : null;
    },
    detectBrand(url) {
      if (/mart-visser|cendre|vernon|cavaro|prosper/i.test(url)) return 'Mart Visser';
      if (/karpi/i.test(url)) return 'Karpi';
      return null;
    },
  },

  // ── boumanenpotter.nl (maat in URL -> JSON-LD) ───────────────────────────
  {
    key:        'boumanenpotter.nl',
    base:       'https://www.boumanenpotter.nl',
    brands:     ['Mart Visser'],
    sitemapUrl: 'https://www.boumanenpotter.nl/sitemap.xml',
    brandKeys:  ['mart-visser', 'vernon', 'cendre', 'cavaro', 'prosper'],
    fromUrl:    true,
    getPrijs:   null,
    sizeFromUrl(url) {
      const m = url.match(/(\d{2,3})x(\d{2,3})(?:cm)?/i);
      return m ? { widthCm: Number(m[1]), heightCm: Number(m[2]) } : null;
    },
    detectBrand(_url) { return 'Mart Visser'; },
  },

  // ── detafelaar.nl (Magento 2, "Vanaf" only) ───────────────────────────────
  {
    key:        'detafelaar.nl',
    base:       'https://www.detafelaar.nl',
    brands:     ['Eurogros', 'Louis De Poortere', 'Desso'],
    sitemapUrl: 'https://www.detafelaar.nl/sitemap.xml',
    brandKeys:  ['eurogros', 'poortere', 'desso', 'aspen', 'anaheim', 'twilight', 'richmond', 'spectrum', 'love-shaggy', 'arizona', 'allison'],
    getPrijs(html, _w, _h) {
      // Detafelaar heeft geen per-maat prijs: alles is "Vanaf"
      // Prijs staat in class="price" (met NBSP voor het bedrag)
      const m = html.match(/class="price">€[\s ]*([0-9][0-9.,]*)/);
      if (!m) return null;
      const p = fmt(parsePriceStr(m[1]));
      return p ? `Vanaf ${p}` : null;
    },
    vanaf: true,  // markeer zodat Excel niet kleurt
    detectBrand(url) {
      if (/eurogros|aspen|anaheim|twilight|richmond|spectrum|love-shaggy|arizona|allison/i.test(url)) return 'Eurogros';
      if (/poortere/i.test(url)) return 'Louis De Poortere';
      if (/desso/i.test(url))    return 'Desso';
      return null;
    },
  },

  // ── vloerkledenspecialist.nl (extra: custom size-select prijs) ───────────
  // WooCommerce voor discovery, maar prijs via custom <select name="size">
  {
    key:        'vloerkledenspecialist.nl',
    base:       'https://vloerkledenspecialist.nl',
    brands:     ['De Munk'],
    sitemapUrl: 'https://vloerkledenspecialist.nl/sitemap.xml',
    brandKeys:  ['de-munk', 'munk-carpets'],
    // Prijs staat in custom size-select: value="2.00 x 3.00|1439"
    getPrijs(html, w, h) {
      const meterW = (w / 100).toFixed(2);
      const meterH = (h / 100).toFixed(2);
      const meters = `${meterW} x ${meterH}`;
      const re = new RegExp(`value="${meters.replace(/[.|]/g, '\\$&').replace(/ /g, '\\s*')}\\|(\\d+(?:[.,]\\d+)?)"`, 'i');
      const m = html.match(re);
      return m ? fmt(parsePriceStr(m[1])) : null;
    },
    detectBrand(_url) { return 'De Munk'; },
    overridesWoocommerce: true, // dit shop staat ook in WOOCOMMERCE_SHOPS; custom getPrijs overschrijft
  },

  // ── bommelwonen.nl (Shopware 6, Cloudflare) ───────────────────────────────
  {
    key:        'bommelwonen.nl',
    base:       'https://www.bommelwonen.nl',
    brands:     ['Eurogros', 'Mart Visser'],
    sitemapUrl: 'https://www.bommelwonen.nl/sitemap.xml',
    brandKeys:  ['eurogros', 'twilight', 'aspen', 'anaheim', 'spectrum', 'arizona', 'mart-visser', 'cendre', 'vernon', 'prosper'],
    fromUrl:    true,
    browser:    true,  // Cloudflare: echte browser nodig
    getPrijs(html, _w, _h) {
      const m = html.match(/property="product:price:amount" content="([\d.]+)"/i);
      return m ? fmt(parsePriceStr(m[1])) : null;
    },
    sizeFromUrl(url) {
      const m = url.match(/(\d{2,3})x(\d{2,3})/i);
      return m ? { widthCm: Number(m[1]), heightCm: Number(m[2]) } : null;
    },
    detectBrand(url) {
      if (/eurogros|twilight|aspen|anaheim|spectrum|arizona|love-shaggy|richmond|allison/i.test(url)) return 'Eurogros';
      if (/mart-visser|cendre|vernon|cavaro|prosper/i.test(url)) return 'Mart Visser';
      return null;
    },
  },

  // ── lowikmeubelen.nl (Cloudflare) ─────────────────────────────────────────
  {
    key:        'lowikmeubelen.nl',
    base:       'https://www.lowikmeubelen.nl',
    brands:     ['Eurogros'],
    sitemapUrl: 'https://www.lowikmeubelen.nl/sitemap.xml',
    brandKeys:  ['eurogros', 'aspen', 'anaheim', 'spectrum', 'twilight', 'richmond', 'arizona'],
    fromUrl:    true,
    browser:    true,  // Cloudflare
    getPrijs(html, _w, _h) {
      // Prijs staat in <title>: "Aspen 7270 vloerkleed – 200×290 € 525,-"
      const m = html.match(/<title>[^<]*€\s*([0-9][0-9.,]*)/);
      return m ? fmt(parsePriceStr(m[1])) : null;
    },
    sizeFromUrl(url) {
      const m = url.match(/(\d{2,3})x(\d{2,3})(?:cm)?/i);
      return m ? { widthCm: Number(m[1]), heightCm: Number(m[2]) } : null;
    },
    detectBrand(_url) { return 'Eurogros'; },
  },
];

// ── Extra non-spec shops ──────────────────────────────────────────────────────
// (watchlist-shops die ook meelopen)
const EXTRA_WOOCOMMERCE = [
  {
    key:    'vivaldixl.nl',
    base:   'https://www.vivaldixl.nl',
    brands: ['Karpi', 'Mart Visser'],
  },
  {
    key:    'meubelcity.nl',
    base:   'https://www.meubelcity.nl',
    brands: ['Karpi'],
  },
  {
    key:    'grootinvloeren.nl',
    base:   'https://www.grootinvloeren.nl',
    brands: ['Eurogros'],
  },
];

// Dedupliceer: vloerkledenspecialist.nl staat in beiden; de CUSTOM versie
// heeft `overridesWoocommerce: true` -> verwijder uit WOOCOMMERCE_SHOPS.
const finalWoo = WOOCOMMERCE_SHOPS.filter(s =>
  !CUSTOM_SHOPS.some(c => c.key === s.key && c.overridesWoocommerce)
);

module.exports = {
  SHOPIFY_SHOPS,
  WOOCOMMERCE_SHOPS: finalWoo,
  CUSTOM_SHOPS,
  ALL_SHOP_KEYS: [
    ...SHOPIFY_SHOPS.map(s => s.key),
    ...finalWoo.map(s => s.key),
    ...CUSTOM_SHOPS.map(s => s.key),
  ],
};
