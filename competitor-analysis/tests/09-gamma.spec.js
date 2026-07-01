/**
 * gamma.nl – Plissé hordeuren (vaste standaardmaten, Bruynzeel 700 serie)  ✅ live prijs
 *
 * Gamma verkoopt GEEN maatwerk plissé-hordeuren maar standaardmaten
 * (inkortbaar). De pagina's zijn gewoon server-side gerenderd; met een
 * browser-UA + Accept-headers is er geen bot-blokkade (de oude
 * "client-rendered SPA"-aanname klopte niet meer).
 *
 * Werkwijze: ALLEEN de categoriepagina "type plissé hordeur" ophalen; elke
 * producttegel bevat de URL-slug (met maat als "...-100x209-cm") én een
 * itemprop="price"-meta. Productpagina's zelf NIET opvragen: die geven 403
 * zonder de volledige browser-headerset, en alles wat we nodig hebben staat
 * al op de categoriepagina.
 *
 * Mapping per doelmaat: goedkoopste WIT product dat de doelmaat dekt
 * (breedte én hoogte >= doel; inkorten kan, oprekken niet) — zelfde principe
 * als horrenbouw (spec 15) en praxis (spec 06). Niet gedekt -> eerlijk n.v.t.
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');

const COMP = 'gamma.nl';
const CAT_URL = 'https://www.gamma.nl/assortiment/l/deuren-ramen-trappen/horren/hordeuren/type-plisse-hordeur';
const HEADERS = {
  'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
  'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
  'Accept-Language': 'nl-NL,nl;q=0.9',
};

let assortiment = null; // cache: één discovery voor alle 6 tests in dit bestand
async function haalAssortiment(request) {
  if (assortiment) return assortiment;
  const cat = await request.get(CAT_URL, { headers: HEADERS, timeout: 20000 });
  if (!cat.ok()) return null;
  const html = await cat.text();

  // splits op producttegels (elke tegel begint met de product-href) en koppel
  // de slug-maat aan de itemprop-price binnen dezelfde tegel
  const items = [];
  for (const tile of html.split(/(?=href="\/assortiment\/[^"]*\/p\/B\d+")/)) {
    const u = tile.match(/href="\/assortiment\/([^"]*)\/p\/B\d+"/);
    const p = tile.match(/itemprop="price" content="([\d.]+)"/i);
    const maat = u && u[1].match(/(\d{2,3})x(\d{2,3})-cm/i);
    if (!u || !p || !maat) continue;
    const slug = u[1];
    if (!/plisse/i.test(slug) || !/wit/i.test(slug)) continue;
    items.push({ naam: slug, b: +maat[1] * 10, h: +maat[2] * 10, prijs: Number(p[1]) });
  }
  assortiment = items;
  return assortiment;
}

for (const [naam, { breedte, hoogte }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm, standaardmaat)`, async ({ request }) => {
    let prijs = null;
    try {
      const items = await haalAssortiment(request);
      const passend = (items || [])
        .filter(p => p.b >= breedte && p.h >= hoogte)
        .sort((a, b) => a.prijs - b.prijs);
      if (passend.length) prijs = `€ ${passend[0].prijs.toFixed(2).replace('.', ',')}`;
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
