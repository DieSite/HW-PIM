/**
 * horren.com – Plissé hordeur SE-100 (enkel) / SE-200 (dubbel)  ✅ per-maat
 *
 * Laravel + Vue SPA, maar de prijs komt uit een gewone JSON-API die ook
 * zonder browser werkt (geverifieerd 2026-06):
 *   1. GET  https://horren.com/hordeuren/plisse/<slug>   -> CSRF-token uit
 *      <meta name="csrf-token"> + sessiecookies (zelfde request-context!)
 *   2. POST https://horren.com/product/validate-state/<code>  met de volledige
 *      configuratie-state -> { price: { price: <consumentenprijs incl. btw> },
 *      complete: true, messages: [] }
 *
 * LET OP: maten in CENTIMETERS (mm/10), gemeten als deuropening; in_frame "1"
 * = tussen het kozijn (in de dag). Standaardopties: RAL 9010 glans, zwart gaas
 * (wire_id 1), geen handgreep, schroeven (fixation 2 — "geen powertape").
 * `meta.price` echoot de basisprijs (269 enkel / 538 dubbel).
 * GEEN www. gebruiken (301) en NIET /product/add-to-cart aanroepen.
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');

const COMP = 'horren.com';
const PRODUCTS = {
  enkel:  { url: 'https://horren.com/hordeuren/plisse/se100',        code: 'SE-100', basis: 269 },
  dubbel: { url: 'https://horren.com/hordeuren/plisse/dubbel-se200', code: 'SE-200', basis: 538 },
};

const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

async function haalPrijs(request, { url, code, basis }, breedte, hoogte) {
  // 1. sessie + CSRF-token bootstrappen (cookies blijven in deze request-context)
  const pageResp = await request.get(url, { headers: { 'User-Agent': UA }, timeout: 20000 });
  const html = await pageResp.text();
  const csrf = (html.match(/<meta name="csrf-token" content="([^"]+)"/) || [])[1];
  if (!csrf) return null;

  // 2. volledige configuratie valideren -> per-maat prijs
  const body = {
    meta:  { device: 'desktop', webshop_type: 'particulier', customer_id: null, amount: 1, price: basis },
    specs: {
      in_frame: { in_frame: '1' },                                              // tussen het kozijn
      maten_simpel_deur: { width_opening: breedte / 10, height_opening: hoogte / 10 }, // cm!
      SE_fixation: { fixation: '2' },                                           // schroeven (geen tape)
      color: { color: 'RAL 9010', color_type: 1 },
      gaas: { wire_id: '1' },                                                   // zwart
      handgreep: { handgreepjes: '0' },                                         // geen
      onderstrip: { onderstrip: '3' },                                          // standaard grijs
      kenmerk: { room: '' },
    },
  };
  const resp = await request.post(`https://horren.com/product/validate-state/${code}`, {
    headers: {
      'User-Agent': UA,
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-CSRF-TOKEN': csrf,
      'X-Requested-With': 'XMLHttpRequest',
    },
    data: body,
    timeout: 20000,
  });
  if (!resp.ok()) return null;
  const j = await resp.json();
  if (!j || j.complete !== true || (j.messages || []).length) return null;
  const p = j.price && j.price.price;
  return typeof p === 'number' && p > 50 ? `€ ${p.toFixed(2).replace('.', ',')}` : null;
}

for (const [naam, { breedte, hoogte, type }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ request }) => {
    let prijs = null;
    try {
      prijs = await haalPrijs(request, PRODUCTS[type], breedte, hoogte);
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
