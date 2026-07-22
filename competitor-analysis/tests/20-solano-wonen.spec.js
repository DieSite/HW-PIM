/**
 * solanowonen.nl – Luxaflex Plissé Hordeur Volare (dealer, eigen shopplatform)  ✅ per-maat
 *
 * URL: https://www.solanowonen.nl/horren/hordeuren/plissehordeuren/luxaflex-plisse-hordeur-volare
 *
 * Real per-mm price via a JSON API, geen browserpagina nodig — de configurator
 * post naar `POST /services/getProductConfiguration` (form-encoded: csrfToken +
 * productId + options[ID]=variationId/waarde). Bootstrap: GET de productpagina
 * voor `csrfToken` (uit de hidden input) + sessiecookie — dit moet PER TEST
 * opnieuw: Playwright geeft elke `test()` een eigen, lege `request`-context,
 * dus de token cachen zonder de bijbehorende cookie (van diezelfde GET) geeft
 * vanaf de tweede test een sessieloze en dus foutieve POST.
 *
 * Belangrijkste optie-IDs (uit de live pagina gehaald, `explore.js` + DOM-dump):
 *  - 2307 (Deurtype): 21018 = Enkele deur, 21021 = Dubbele deur.
 *  - 2310/2313 (Breedte/Hoogte): in **centimeter**, niet mm.
 *  - 2325 (Type gaas): 21042 = Zwart (default), 21045 = Grijs — geverifieerd
 *    live via de API: exact dezelfde `total` voor zwart en grijs, dus geen
 *    meerprijs voor grijs gaas.
 *  - 2316/2319/4594/2958: profielkleur/montage/aansluitprofiel — op de
 *    standaardwaarden gelaten (wit RAL 9010, in het kozijn, geen
 *    aansluitprofiel), zelfde conventie als de andere bronnen.
 *
 * De API keurt combinaties af op basis van een niet-triviale breedte×hoogte-
 * matrix per deurtype (geen simpele losse min/max — 190×2350mm mist bv. net
 * voor "Enkele deur" met een "maximale oppervlakte overschreden"-foutmelding,
 * ook al past de breedte los gezien binnen de 60–360cm-range). Vertrouw dus
 * niet op eigen grenzen, maar op de API's eigen `errors`-object: alleen als
 * alle velden leeg zijn is de prijs geldig.
 *
 * `fittingAmount` (optionele opmeet/montageservice aan huis) is BEWUST niet in
 * de genoteerde prijs meegenomen — dat is een losse dienst, geen onderdeel van
 * het product zelf (net als verzendkosten bij andere bronnen).
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');

const COMP = 'solanowonen.nl';
const PRODUCT_URL = 'https://www.solanowonen.nl/horren/hordeuren/plissehordeuren/luxaflex-plisse-hordeur-volare';
const API_URL = 'https://www.solanowonen.nl/services/getProductConfiguration';

const DEURTYPE = { enkel: '21018', dubbel: '21021' };
const GAAS = { zwart: '21042', grijs: '21045' };

async function haalCsrfToken(request) {
  const resp = await request.get(PRODUCT_URL, { timeout: 15000 });
  if (!resp.ok()) return null;
  const html = await resp.text();
  const m = html.match(/name="csrfToken"\s+value="([^"]+)"/);
  return m ? m[1] : null;
}

async function haalPrijs(request, breedteMm, hoogteMm, type, gaas) {
  const token = await haalCsrfToken(request);
  if (!token) return null;

  const resp = await request.post(API_URL, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      csrfToken: token,
      productId: '581',
      taxRate: '21',
      'options[2307]': DEURTYPE[type],
      'options[2310]': String(Math.round(breedteMm / 10)),
      'options[2313]': String(Math.round(hoogteMm / 10)),
      'options[2316]': '21024',
      'options[2319]': '21030',
      'options[4594]': '37297',
      'options[2325]': GAAS[gaas],
      'options[2958]': '23919',
    },
    timeout: 10000,
  });
  if (!resp.ok()) return null;
  const j = await resp.json();
  const cfg = j.productConfiguration;
  if (!j.success || !cfg || !cfg.priceGroupDetails?.inRange) return null;
  if (Object.values(cfg.errors || {}).some(e => e)) return null;
  return cfg.totalText ?? null;
}

for (const [naam, { breedte, hoogte, type, gaas }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ request }) => {
    let prijs = null;
    try {
      prijs = await haalPrijs(request, breedte, hoogte, type, gaas);
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
