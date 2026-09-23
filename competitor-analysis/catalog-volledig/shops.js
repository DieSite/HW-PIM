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
    // Zij labelen de XS-band van Karpi als "80 x 150 cm" waar ons PIM
    // "80 x 160 cm" zegt. Dat het hetzelfde kleed is, blijkt uit het product
    // zelf: de vier andere maten matchen exact én de XS kost aan beide kanten
    // € 129 (Cisco 63, geverifieerd 09-09-2026). Springt alleen in als zij
    // onze exacte maat niet voeren — zie sizeMatches in normalize.js.
    sizeAliases: [{ from: [80, 160], to: [80, 150] }],
  },
  {
    key:    'hetdesignhuys.nl',
    base:   'https://hetdesignhuys.nl',
    brands: ['Eurogros', 'Karpi', 'Mart Visser'],
    // Zelfde XS-band als vloerkledenloods: 21 varianten op "80 x 150 cm" en
    // geen enkele op 80 x 160.
    sizeAliases: [{ from: [80, 160], to: [80, 150] }],
  },
  {
    key:    'youlikeitwonen.nl',
    base:   'https://youlikeitwonen.nl',
    brands: ['Eurogros'],
    // Vendor is overal "You Like It Wonen" en geen titel noemt een merk
    // ("Vloerkleed Spectrum 3333 Rechthoekig & Rond"). Hun ~40 kleden zijn op
    // twee lijnen na (Mad Men, Bouquet — die voeren wij niet) allemaal
    // Eurogros: Amado/Anaheim/Glow/Spectrum/Vienna, geverifieerd 23-09-2026.
    // De modelguards (naam + dessinnummer) doen daarna het echte werk.
    fallbackBrand: 'Eurogros',
    productTags:   ['vloerkleed'],
  },
  {
    key:    'dfmwonen.nl',
    base:   'https://dfmwonen.nl',
    brands: ['Eurogros'],
    // Vendor is de distributeur: Fading World, Antiquarian, Meditation, Cities
    // e.d. staan er als "Eurogros", terwijl ze in ons PIM onder Louis De
    // Poortere vallen (geverifieerd 23-09-2026, ~370 kleden).
    vendorAliases: { Eurogros: ['Louis De Poortere'] },
  },
  {
    key:    'mooierthuis.nl',
    base:   'https://www.mooierthuis.nl',
    brands: ['Eurogros'],
    // ~10.000 producten; na ~6 snelle pagina's volgt een 429-botcheck. De
    // collectie "vloerkleden" (605 kleden, 451 Eurogros) is 3 pagina's, en
    // daartussen pauzeren we ruim.
    collection:    'vloerkleden',
    pageDelayMs:   5000,
    // Net als bij dfmwonen: Fading World, Antiquarian, Meditation e.d. staan
    // er als vendor Eurogros.
    vendorAliases: { Eurogros: ['Louis De Poortere'] },
  },
  {
    key:    'mt-sfeeridee.nl',
    base:   'https://mt-sfeeridee.nl',
    brands: ['Eurogros'],
    // Vendor is de eigen winkelnaam; ~360 kleden, vrijwel allemaal Eurogros
    // plus Louis De Poortere (Structures, Cities, Nuance). 6.500 producten in
    // totaal, dus alleen de collectie.
    fallbackBrand: 'Eurogros',
    vendorAliases: { Eurogros: ['Louis De Poortere'] },
    collection:    'vloerkleden',
    pageDelayMs:   2000,
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
    key:    'meubelcity.nl',
    base:   'https://www.meubelcity.nl',
    brands: ['Karpi'],
  },
  {
    key:    'grootinvloeren.nl',
    base:   'https://www.grootinvloeren.nl',
    brands: ['Eurogros'],
  },
  {
    // Eén product per model met een Kleur × Formaat-matrix; de prijs hangt
    // alleen aan de maat ("attribute_kleur": ""). Zie offeredColours in
    // indexers/woocommerce.js.
    key:    'kledenwereld.nl',
    base:   'https://kledenwereld.nl',
    brands: ['Mart Visser', 'Karpi'],
  },
  {
    // Merk staat alleen in de categorie, niet in de titel ("Vloerkleed Royce
    // 63"), dus de indexer valt terug op het doorbladeren van de catalogus.
    key:    'caltabellotta.nl',
    base:   'https://www.caltabellotta.nl',
    brands: ['Karpi', 'Louis De Poortere'],
  },
  {
    // ~1.000 kleden, veel onder een eigen fantasienaam ("Rivali 9326",
    // "Freenvi 9210") met het echte dessinnummer. Alleen de kleden onder hun
    // echte naam ("Kapiti Black 172") zijn met zekerheid te koppelen.
    key:    'disena.nl',
    base:   'https://disena.nl',
    brands: ['Eurogros', 'Louis De Poortere'],
  },
  {
    // ~65 kleden van Eurogros, Mart Visser en Desso tussen de meubels.
    key:    'joldersma-wonen.nl',
    base:   'https://joldersma-wonen.nl',
    brands: ['Eurogros', 'Mart Visser', 'Desso'],
  },
  {
    // ~175 kleden, vooral Galaxy (Eurogros) en Plush (Karpi); geen merk in
    // de titels, dus de catalogus wordt doorgebladerd (7.600 producten).
    key:    'maxwonen.nl',
    base:   'https://www.maxwonen.nl',
    brands: ['Eurogros', 'Karpi'],
    // 429 na ~120 snelle productpagina's
    pageDelayMs: 1500,
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
    // benoemt de vorm ("200 x 290 rechthoek" / "… ovaal"), dus match die mee.
    // De vorm ONTBREEKT alleen bij rechthoeken (data-title="250 x 300"): die
    // kale variant telt als rechthoek, maar een onbekend achtervoegsel
    // ("200 x 290 core speciale vorm") nadrukkelijk niet.
    getPrijs(html, w, h, shape = 'rechthoek') {
      const maat = `${w} x ${h}`;
      const vorm = shape === 'rechthoek' ? `(?: ${shape})?` : ` ${shape}`;
      const m = html.match(new RegExp(`data-title="${maat}${vorm}"[\\s\\S]{0,600}?data-prijs="([\\d.,]+)"`, 'i'));
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
    // Deze winkel heeft geen sitemap: /sitemap.xml geeft een 404-pagina en
    // robots.txt noemt er geen. Het overzicht zet de productlinks wél gewoon
    // in de HTML, 35 pagina's van ~39 producten.
    listUrl:      'https://www.karpettenshop.nl/karpetten.html',
    listPages:    40,
    listPageParam: 'p',
    linkRe:       /https:\/\/www\.karpettenshop\.nl\/karpetten\/[^"#?]+\.html/,
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
    // Kaal /sitemap.xml geeft hier een HTML-pagina (200, geen <loc>); de
    // echte productsitemap zit achter ?type=products — 6.045 URL's.
    sitemapUrl: 'https://www.vloerkledenvoordelig.nl/sitemap.xml?type=products',
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
    // Rechthoeken en ovalen staan er als "Afmeting: 200x290 cm" (de vorm zit in
    // de pagina, niet in het label), maar ronde kleden als "Afmeting: 200 cm
    // rond". Zonder die tweede vorm bleef élk rond kleed hier prijsloos, terwijl
    // de pagina gewoon bestaat en geïndexeerd wordt.
    getPrijs(html, w, h, shape) {
      const maat = shape === 'rond' ? `${w} cm rond` : `${w}x${h} cm`;
      const m = html.match(new RegExp(
        `<option value="\\d+"[^>]*data-price="([\\d.]+)"[^>]*>Afmeting: ${maat}`,
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
    // Slug noch titel draagt een dessinnummer ("…-prosper-200x290cm-wit"), dus
    // zonder positief kleurbewijs komt elke kleur van een model op dezelfde
    // pagina uit. Eis bewijs i.p.v. de afwezigheid van tegenspraak.
    requireDiscriminator: true,
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

  // ── vivaldixl.nl (WooCommerce, maar via de sitemap) ──────────────────────
  //
  // Staat hier en niet bij WOOCOMMERCE_SHOPS: hun Store API geeft HTTP 500
  // zodra een zoekopdracht daadwerkelijk producten oplevert (een lege uitslag
  // geeft netjes 200 met []). De indexer zag daardoor alleen maar fouten of
  // niets en de winkel leverde structureel nul prijzen.
  //
  // Let op de omvang voordat je hier tijd in steekt: het is een tuinmeubel-
  // winkel met 1.001 producten waarvan er zes vloerkleden zijn, en daarvan is
  // er één van onze merken (Mart Visser Vernon Warm Olive 160x230). De prijs
  // staat in JSON-LD, de maat in de URL — verder niets bijzonders.
  {
    key:        'vivaldixl.nl',
    base:       'https://www.vivaldixl.nl',
    brands:     ['Karpi', 'Mart Visser'],
    sitemapUrl: 'https://www.vivaldixl.nl/product-sitemap.xml',
    brandKeys:  ['mart-visser', 'vernon', 'cendre', 'cavaro', 'prosper', 'karpi'],
    fromUrl:    true,
    getPrijs:   null,
    sizeFromUrl(url) {
      const m = url.match(/(\d{2,3})-x-(\d{2,3})/) || url.match(/(\d{2,3})x(\d{2,3})/);
      return m ? { widthCm: Number(m[1]), heightCm: Number(m[2]) } : null;
    },
    detectBrand(url) {
      if (/mart-visser|vernon|cendre|cavaro|prosper/i.test(url)) return 'Mart Visser';
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
    // Bewust géén brandKeys: hun slugs zijn inconsistent. 168 De Munk-producten
    // dragen "de-munk" in de URL, maar 59 andere niet ("nuovo-arbitro-vloerkleed"
    // naast "de-munk-nuovo-basilio-vloerkleed") en die vielen allemaal weg.
    // detectBrand geeft hier sowieso altijd De Munk terug, dus het voorfilter
    // voegde niets toe behalve dat gat; de modelguards doen het echte werk.
    brandKeys:  undefined,
    // Prijs staat in een custom size-select: value="2.00 x 3.00|1439".
    // Ronde kleden staan er als diameter met een Ø: value="2.00 Ø|1375".
    // Zonder die tweede vorm leverde élk rond kleed hier n.v.t. — alle negen
    // Intorno-modellen stonden netjes geïndexeerd en bleven toch prijsloos.
    getPrijs(html, w, h, shape) {
      const meters = (cm) => (cm / 100).toFixed(2);
      const key = shape === 'rond' ? `${meters(w)} Ø` : `${meters(w)} x ${meters(h)}`;
      const re = new RegExp(`value="${key.replace(/[.|]/g, '\\$&').replace(/ /g, '\\s*')}\\|(\\d+(?:[.,]\\d+)?)"`, 'i');
      const m = html.match(re);
      return m ? fmt(parsePriceStr(m[1])) : null;
    },
    detectBrand(_url) { return 'De Munk'; },
    overridesWoocommerce: true, // dit shop staat ook in WOOCOMMERCE_SHOPS; custom getPrijs overschrijft
  },

  // ── woonwebwinkel.com (Magento, maat als custom option) ─────────────────
  //
  // Eén pagina per model; de maat is een Magento "custom option" met een
  // toeslag of korting t.o.v. de basisprijs (Twilight 2211: basis € 349 =
  // 160x230, "200x290cm" +€ 210, "065x130cm" −€ 260, "200cm rond" +€ 60).
  // De getoonde <option>-lijst mist de negatieve bedragen; optionConfig in de
  // pagina heeft ze allemaal.
  {
    key:        'woonwebwinkel.com',
    base:       'https://woonwebwinkel.com',
    brands:     ['Eurogros', 'Louis De Poortere'],
    sitemapUrl: 'https://woonwebwinkel.com/sitemap.xml',
    brandKeys:  ['vloerkleed', 'karpet', 'tapijt'],
    // Alle vormen op één pagina ("200 rond", "160x230 ovaal"): de vorm komt
    // uit de maatoptie, niet uit de pagina.
    mixedShapes: true,
    getPrijs(html, w, h, shape = 'rechthoek') {
      const base = Number(html.match(/property="product:price:amount" content="([\d.]+)"/i)?.[1]);
      const config = html.match(/"optionConfig":\s*(\{[\s\S]*?\}\}\})\s*,/)?.[1];
      if (!base || !config) return null;
      let options;
      try { options = JSON.parse(config); } catch { return null; }
      for (const group of Object.values(options)) {
        for (const option of Object.values(group)) {
          const name = String(option?.name ?? '').toLowerCase();
          const optionShape = /ovaal/.test(name) ? 'ovaal' : /rond/.test(name) ? 'rond' : 'rechthoek';
          const size = name.match(/(\d{2,3})\s*x\s*(\d{2,3})/);
          const round = name.match(/(\d{2,3})\s*(?:cm)?\s*rond/);
          const [ow, oh] = size ? [Number(size[1]), Number(size[2])] : round ? [Number(round[1]), Number(round[1])] : [];
          if (ow === w && oh === h && optionShape === shape) {
            return fmt(base + Number(option.prices?.finalPrice?.amount ?? 0));
          }
        }
      }
      return null;
    },
    detectBrand(url) {
      if (/fading-world|structures|atlantic|antique|antiquarian|medaillon|meditation|cities|sakura|nuance|papercut|chess|fresque/i.test(url)) return 'Louis De Poortere';
      return 'Eurogros';
    },
    slugAliases: { medaillon: 'medallion' },
  },

  // ── onlineslaapcomfort.nl (Magento, één pagina per maat) ────────────────
  //
  // Beddenwinkel met 12.414 URL's; de kleden staan er als
  // "anaheim-3243-200x290-ovaal-5414452131048": model, dessin, maat, vorm en
  // EAN (of een eigen nummer) in de URL. Prijs uit de product:price-meta.
  // Louis De Poortere-kleden hebben EAN-prefix 5420073 en machinevertaalde
  // namen ("vervagende-wereld-8261" = Fading World Medallion 8261); de
  // vertaaltabel hieronder maakt ze koppelbaar, het dessinnummer moet nog
  // steeds kloppen.
  {
    key:        'onlineslaapcomfort.nl',
    base:       'https://www.onlineslaapcomfort.nl',
    brands:     ['Eurogros', 'Louis De Poortere'],
    sitemapUrl: 'https://www.onlineslaapcomfort.nl/media/sitemap/onlineslaapcomfort_sitemap.xml',
    brandKeys:  undefined,
    urlFilter:  /-(?:\d{2,3}-?x-?\d{2,3}(?:-cm)?|\d{3}-?rond)(?:-ovaal)?-\d{6,}$/,
    fromUrl:    true,
    getPrijs:   null,
    sizeFromUrl(url) {
      const r = url.match(/-(\d{2,3})-?x-?(\d{2,3})(?:-cm)?(?:-ovaal)?-\d{6,}$/);
      if (r) return { widthCm: Number(r[1]), heightCm: Number(r[2]) };
      const d = url.match(/-(\d{3})-?rond-\d{6,}$/);
      return d ? { widthCm: Number(d[1]), heightCm: Number(d[1]) } : null;
    },
    detectBrand(url) {
      return /-5420073\d+$/.test(url) ? 'Louis De Poortere' : 'Eurogros';
    },
    slugAliases: {
      'vervagende wereld': 'fading world',
      'atlantische strepen': 'atlantic streaks',
      antiek: 'antiquarian',
      structuren: 'structures',
      meditatie: 'meditation',
      lagune: 'lagoon',
      koraal: 'coral',
      steden: 'cities',
      londen: 'london',
      parijs: 'paris',
      schemering: 'twilight',
      schaakspel: 'chess',
      schaak: 'chess',
      papierknip: 'papercut',
      fresco: 'fresque',
      kreeft: 'lobster',
      tijger: 'tiger',
    },
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
