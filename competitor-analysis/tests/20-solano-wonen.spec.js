/**
 * solanowonen.nl – KEJE plissé hordeur op maat  ✅ per-maat
 *
 * URL: https://www.solanowonen.nl/horren/hordeuren/plissehordeuren/keje-plissehordeur
 *
 * Product gewisseld op 30-07-2026 (klantverzoek). Tot dan stond hier de
 * Luxaflex Volare (€ 448 voor 730×1970) — een dealerproduct in een heel andere
 * prijsklasse, en de enige van hun plissé hordeuren ZONDER de optie "Op maat
 * zagen". Zonder die optie levert Solano een zelf in te korten bouwpakket en is
 * de prijs niet vergelijkbaar met de kant-en-klare deuren van de andere
 * bronnen — dezelfde afweging als bij Handige Horren. Daarom nu de Keje
 * plissehordeur mét "Op maat zagen: Ja" (+ € 24,95).
 *
 * Real per-mm price via een JSON-API, geen browserpagina nodig — de
 * configurator post naar `POST /services/getProductConfiguration`
 * (form-encoded: csrfToken + productId + options[ID]=variationId/waarde).
 * Bootstrap: GET de productpagina voor `csrfToken` (uit de hidden input) +
 * sessiecookie — dit moet PER TEST opnieuw: Playwright geeft elke `test()` een
 * eigen, lege `request`-context, dus de token cachen zonder de bijbehorende
 * cookie (van diezelfde GET) geeft vanaf de tweede test een sessieloze en dus
 * foutieve POST.
 *
 * Optie-ID's (live uit de pagina gehaald, geverifieerd met echte API-calls):
 *  - 3916 (Deurtype): 31363 = Enkele deur, 31366 = Dubbele deur.
 *  - 3919/3922 (Breedte/Hoogte): in **centimeter**, niet mm.
 *  - 3925/3928 (Profielkleur): 31372 = standaard kleuren, 31387 = Zuiverwit
 *    (RAL 9010) — zelfde conventie als de andere bronnen, geen meerprijs.
 *  - 3937 (enkel) / 4840 (dubbel) Montage: 31399 resp. 40241 = In het kozijn.
 *  - 4842 (Op maat zagen): 40245 = Nee, 40246 = Ja (+ € 24,95).
 *
 * KORTING: de 20% winkelkorting zit al in `total` (advies € 302,50 →
 * € 242,00), net zoals hun productpagina hem toont. Er is dus niets extra af
 * te trekken — anders dan bij horrentotaal, waar de actie buiten de API om
 * gaat.
 *
 * Gaaskleur: dit product heeft GEEN gaaskleur-optie (alleen zwart), dus alle
 * grijs-rijen zijn eerlijk n.v.t.
 *
 * Grenzen: enkel max 190×275 cm, dubbel max 380×275 cm. Vertrouw daarbij niet
 * op eigen aannames maar op de API zelf: alleen als het `errors`-object leeg is
 * én `priceGroupDetails.inRange` waar is, is de prijs geldig — een te grote
 * maat levert namelijk gewoon een (verkeerd) totaalbedrag op.
 *
 * `fittingAmount` (optionele opmeet/montageservice aan huis) is BEWUST niet in
 * de genoteerde prijs meegenomen — dat is een losse dienst, geen onderdeel van
 * het product zelf (net als verzendkosten bij andere bronnen).
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');

const COMP = 'solanowonen.nl';
const PRODUCT_URL = 'https://www.solanowonen.nl/horren/hordeuren/plissehordeuren/keje-plissehordeur';
const API_URL = 'https://www.solanowonen.nl/services/getProductConfiguration';

const DEURTYPE = { enkel: '31363', dubbel: '31366' };
const MONTAGE_OPTIE = { enkel: '3937', dubbel: '4840' };
const MONTAGE_IN_HET_KOZIJN = { enkel: '31399', dubbel: '40241' };
const OP_MAAT_ZAGEN_JA = '40246';

async function haalCsrfToken(request) {
  const resp = await request.get(PRODUCT_URL, { timeout: 15000 });
  if (!resp.ok()) return null;
  const html = await resp.text();
  const m = html.match(/name="csrfToken"\s+value="([^"]+)"/);
  return m ? m[1] : null;
}

async function haalPrijs(request, breedteMm, hoogteMm, type) {
  const token = await haalCsrfToken(request);
  if (!token) return null;

  const resp = await request.post(API_URL, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    form: {
      csrfToken: token,
      productId: '991',
      taxRate: '21',
      'options[3916]': DEURTYPE[type],
      'options[3919]': String(Math.round(breedteMm / 10)),
      'options[3922]': String(Math.round(hoogteMm / 10)),
      'options[3925]': '31372',
      'options[3928]': '31387',
      [`options[${MONTAGE_OPTIE[type]}]`]: MONTAGE_IN_HET_KOZIJN[type],
      'options[4842]': OP_MAAT_ZAGEN_JA,
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
    // Geen gaaskleur-optie op dit product: alleen zwart leverbaar.
    if (gaas === 'grijs') {
      recordPrice(COMP, naam, 'n.v.t.');
      return;
    }
    let prijs = null;
    try {
      prijs = await haalPrijs(request, breedte, hoogte, type);
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
