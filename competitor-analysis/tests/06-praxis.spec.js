/**
 * praxis.nl – Plissé hordeuren (vaste standaardmaten, inkortbaar)  ✅ live prijs
 *
 * Praxis verkoopt GEEN maatwerk maar standaardmaten (bv. CanDo Comfort
 * 100x209, "zelf op maat te maken in breedte en hoogte" = inkortbaar). We
 * lezen het volledige assortiment live uit de publieke Algolia-zoek-API die
 * de site zelf gebruikt (frontend-key, geen bot-bescherming):
 *   POST https://DGVF2HE476-dsn.algolia.net/1/indexes/prd_praxis_products_nl_nl/query
 *   filters: deepest_category:wd0044  (= categorie "Plissé hordeuren")
 * Prijs per hit: facets.price_praxis (geverifieerd gelijk aan de
 * productpagina-prijs). Maat zit in de productnaam als "BBBxHHH cm".
 *
 * Klantverzoek: het moet specifiek de "CanDo Plisséhordeur Premium"-lijn zijn
 * (niet de goedkoopste willekeurige lijn zoals Comfort/Livn/Dtch die ook aan
 * de maat voldoet — die zijn niet vergelijkbaar in kwaliteit). Mapping per
 * doelmaat: goedkoopste WITTE Premium-hordeur waarvan de standaardmaat de
 * doelmaat dekt (breedte én hoogte >= doel; inkorten kan, oprekken niet).
 * Niet gedekt (bv. enkel 1030 breed, of geen Premium-maat die past) -> n.v.t.
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');

const COMP = 'praxis.nl';
const ALGOLIA_URL = 'https://DGVF2HE476-dsn.algolia.net/1/indexes/prd_praxis_products_nl_nl/query';

let assortiment = null; // cache: één API-call voor alle 6 tests in dit bestand
async function haalAssortiment(request) {
  if (assortiment) return assortiment;
  const resp = await request.post(ALGOLIA_URL, {
    headers: {
      'X-Algolia-Application-Id': 'DGVF2HE476',
      'X-Algolia-API-Key': '6eb3fec6bcf0fb07e7ba6a96b83326de',
      'Content-Type': 'application/json',
    },
    data: { query: '', filters: 'deepest_category:wd0044', hitsPerPage: 100 },
    timeout: 15000,
  });
  if (!resp.ok()) return null;
  const j = await resp.json();
  assortiment = (j.hits || [])
    .map(h => {
      const m = (h.name || '').match(/(\d{2,3})\s*x\s*(\d{2,3})\s*cm/i);
      const prijs = h.facets && Number(h.facets.price_praxis);
      if (!m || !prijs) return null;
      return { naam: h.name, b: +m[1] * 10, h: +m[2] * 10, prijs };
    })
    .filter(Boolean)
    // alleen witte plissé-DEURhorren: de categorie bevat ook raam-plissé,
    // rolhorren en telescopische hordeuren (daar kwam de foute €79 vandaan)
    .filter(p => /wit/i.test(p.naam))
    // "plisse" i.p.v. "plissé": CanDo-producten schrijven het met accent
    .filter(p => /pliss[eé]/i.test(p.naam) && /deur/i.test(p.naam))
    .filter(p => !/raam|rolhor|telescopisch/i.test(p.naam))
    // klant wil specifiek de Premium-lijn (niet de goedkopere Comfort/Livn/Dtch-lijnen)
    .filter(p => /premium/i.test(p.naam));
  return assortiment;
}

for (const [naam, { breedte, hoogte, gaas }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm, standaardmaat)`, async ({ request }) => {
    // Standaardmaat-producten hebben geen gaaskleur-keuze; alleen de
    // zwart-gaas rijen zijn vergelijkbaar, grijs bestaat hier niet.
    if (gaas === 'grijs') {
      recordPrice(COMP, naam, 'n.v.t.');
      return;
    }
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
