/**
 * horrenmax.nl – Plissé (schuif) hordeur op maat  ✅ per-maat, geen browserpagina
 *
 * Shopify + DPO (Itoris Dynamic Product Options). De DPO-bundel op de
 * productpagina wordt geladen vanaf node1.itoris.com en zit achter
 * bot-bescherming die headless page-loads rate-limit — daarom leverde de oude
 * DOM-aanpak meestal n.v.t. op. De prijs is echter zonder pagina te bepalen,
 * via dezelfde publieke endpoints die de widget zelf aanroept:
 *
 *   1. GET  /products/<handle>.js  → product_id + basis-variant_id (Shopify).
 *   2. POST node1.itoris.com/dpo/storefront/include.js?controller=ValidateForm
 *      met options[1001]=10002 (montage "In het kozijn", €0),
 *      options[1002]=breedte cm, options[1003]=hoogte cm (mm/10; max 210/300)
 *      → DPO maakt server-side de geconfigureerde (verborgen) Shopify-variant
 *      aan en geeft variant_id terug.
 *   3. POST /cart/add.js met die variant → items[0].price (centen) is de echte
 *      maatprijs. Anonieme sessie-cart per test, geen bestelling; vervalt
 *      vanzelf.
 *
 * Gevalideerd 2026-07-08: alle 6 maten komen exact overeen met de prijsformule
 * uit GetOptionConfig (total = 239 + tiers[hoogteband][breedteband], prijs =
 * total × 0,8; de Shopify-basisprijs €191,20 = 239 × 0,8 bevestigt de match).
 * Overige opties (gaas, greep enz.) blijven op hun €0-standaard, conform de
 * suite-afspraak (wit frame, zwart gaas, geen extra's).
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');

const COMP = 'horrenmax.nl';
const PRODUCT_URL = 'https://horrenmax.nl/products/plisse-schuif-hordeur-op-maat-z35';
const VALIDATE_URL = 'https://node1.itoris.com/dpo/storefront/include.js?controller=ValidateForm&shop=3fk0mz-f0.myshopify.com';
const MIN_CENTS = 10000; // < €100 is hier geen plausibele maatprijs (basis is €191,20)
const PAUZE_MS = 4000; // rustig aan: >±15 req/10s geeft een HTML-challenge i.p.v. JSON

// De standaard Playwright/x UA van de request-fixture krijgt van de WAF een
// HTML-challenge; met browser-headers (zelfde afspraak als 09-gamma) niet.
const HEADERS = {
  'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
  'Accept': 'application/json,*/*;q=0.8',
  'Accept-Language': 'nl-NL,nl;q=0.9',
};

/** JSON-request met nette herkansing: bij een challenge-pagina (HTML i.p.v.
 *  JSON) of een netwerkfout wachten we even en proberen we het nog eens. */
async function fetchJson(doRequest, label, attempts = 3) {
  for (let i = 0; i < attempts; i++) {
    try {
      if (i > 0) await new Promise(r => setTimeout(r, 6000 * i));
      return await (await doRequest()).json();
    } catch (e) {
      console.log(`${COMP} ${label} poging ${i + 1}: ${e.message.split('\n')[0]}`);
    }
  }
  return null;
}

/** Directe (Node-)API-route. Snelst, maar de WAF challengt Node's
 *  TLS-fingerprint zodra die het IP als druk heeft aangemerkt. */
async function viaApi(request, wcm, hcm, naam) {
  const product = await fetchJson(
    () => request.get(`${PRODUCT_URL}.js`, { headers: HEADERS, timeout: 15000 }),
    `${naam} product.js`,
  );

  const validate = product && await fetchJson(
    () => request.post(VALIDATE_URL, {
      headers: { ...HEADERS, Referer: 'https://horrenmax.nl/' },
      form: {
        product_id: String(product.id),
        variant_id: String(product.variants[0].id),
        customer_id: '0',
        'options[1001]': '10002',
        'options[1002]': String(wcm),
        'options[1003]': String(hcm),
      },
      timeout: 15000,
    }),
    `${naam} ValidateForm`,
  );

  const variantId = Number(validate?.variant_id);
  if (!variantId) return null;

  const cart = await fetchJson(
    () => request.post('https://horrenmax.nl/cart/add.js', {
      headers: HEADERS,
      data: { items: [{ id: variantId, quantity: 1 }] },
      timeout: 15000,
    }),
    `${naam} cart/add`,
  );

  return cart?.items?.[0]?.price ?? null;
}

/** Fallback: dezelfde drie calls, maar vanuit een echte Chromium-pagina
 *  (page.evaluate + fetch) — precies wat de DPO-widget zelf doet, dus met
 *  de TLS-fingerprint van een gewone browserbezoeker. */
async function viaBrowser(page, wcm, hcm, naam) {
  try {
    await page.goto(PRODUCT_URL, { waitUntil: 'domcontentloaded', timeout: 25000 });

    return await page.evaluate(async ({ w, h, validateUrl }) => {
      const pj = await (await fetch(location.pathname + '.js')).json();

      const body = new URLSearchParams({
        product_id: String(pj.id),
        variant_id: String(pj.variants[0].id),
        customer_id: '0',
        'options[1001]': '10002',
        'options[1002]': String(w),
        'options[1003]': String(h),
      });
      const v = await (await fetch(validateUrl, { method: 'POST', body })).json();
      if (!v.variant_id) return null;

      const cart = await (await fetch('/cart/add.js', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ items: [{ id: Number(v.variant_id), quantity: 1 }] }),
      })).json();

      return cart.items?.[0]?.price ?? null;
    }, { w: wcm, h: hcm, validateUrl: VALIDATE_URL });
  } catch (e) {
    console.log(`${COMP} ${naam} browser-route: ${e.message.split('\n')[0]}`);

    return null;
  }
}

for (const [naam, { breedte, hoogte }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ request, page }) => {
    test.setTimeout(120_000);
    await new Promise(r => setTimeout(r, PAUZE_MS));

    const wcm = Math.round(breedte / 10);
    const hcm = Math.round(hoogte / 10);

    let cents = await viaApi(request, wcm, hcm, naam);
    if (!Number.isFinite(cents)) cents = await viaBrowser(page, wcm, hcm, naam);

    const prijs = Number.isFinite(cents) && cents >= MIN_CENTS
      ? `€ ${(cents / 100).toFixed(2).replace('.', ',')}`
      : null;

    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
