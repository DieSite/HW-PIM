/**
 * horrentotaal.nl – Plissé hordeur op maat  ✅ per-maat, zonder browserpagina
 *
 * De productpagina laadt een configurator-widget waarvan alle data van
 * `configurator.horrentotaal.nl` komt. Die host is gewoon met de
 * `request`-fixture te bevragen (geverifieerd 2026-07-30), dus we draaien geen
 * Shopify-pagina meer:
 *
 *   GET  /configurators                 -> alle configurators (maat-grenzen,
 *                                          optie-meerprijzen)
 *   POST /calculate/<slug>              -> {"totaalPrijs":"324.00", ...}
 *   GET  /active-discounts?productId=..  -> lopende winkelactie
 *
 * Slugs: `plisse` (enkel) en `dubbele_plisse_hordeur` (dubbel).
 *
 * WAAROM BROWSERLOOS (30-07-2026): de vorige versie opende per maat de
 * Shopify-productpagina en ving de calculate-respons af. Vanaf ±10 pageloads
 * antwoordt hun front met HTTP 429 `local_rate_limited`, waardoor de spec
 * stelselmatig 0 van de 34 cellen vulde. Nu raken we horrentotaal.nl nog
 * hooguit tweemaal per run (één keer per deurtype, voor het product-id).
 *
 * OVERSIZE: `calculate` weigert een te grote maat NIET — 3000 mm breed levert
 * gewoon de prijs van de grootste band (2000 mm). We toetsen daarom aan de
 * `afmeting`-grenzen uit hun eigen config (enkel 580–1900, dubbel 1160–3800,
 * hoogte 1791–2990) én eisen dat de teruggegeven `matchedBreedte/matchedHoogte`
 * onze maat daadwerkelijk dekt.
 *
 * OPTIES: zwart gaas is standaard en zit niet in de calculate-payload; de
 * widget telt optie-meerprijzen client-side op vanuit de config. Voor grijs
 * gaas lezen we die meerprijs live (enkel +€39, dubbel +€78, stand 2026-07);
 * ontbreekt de optie, dan n.v.t.
 *
 * KORTING: net zomin als de opties zit een lopende winkelactie in
 * `totaalPrijs`. De widget haalt die apart op bij
 * `GET /active-discounts?productId=<id>` en trekt hem client-side van
 * (basisprijs + opties) af; dát is de prijs die in de winkelwagen belandt.
 * Sinds 2026-07-29 doen wij hetzelfde — zonder die stap stond horrentotaal
 * 10% te duur in de vergelijking. De rekenregel staat in `_korting.js`
 * (unit tests: `npm run test:korting`). Het product-id verschilt per pagina,
 * dus we lezen het uit de winkel (`/products/<handle>.js`) en hardcoden het
 * nooit.
 *
 * Mislukt het ophalen van de kortingen, dan noteren we `n.v.t.` in plaats van
 * de bruto prijs: we weten dan niet óf er een actie loopt, en een te hoge
 * prijs stilletjes vastleggen is precies de fout die dit repareert. De
 * recorder is sticky en de batch herkanst, dus een gat vult zich vanzelf.
 *
 * URL enkel  : /products/plisse-hordeur
 * URL dubbel : /products/dubbele-plisse-hordeur
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');
const { priceAfterDiscount } = require('./_korting');

const COMP = 'horrentotaal.nl';
const API = 'https://configurator.horrentotaal.nl';
const SHOP = 'https://horrentotaal.nl';
const SLUGS = { enkel: 'plisse', dubbel: 'dubbele_plisse_hordeur' };
const HANDLES = { enkel: 'plisse-hordeur', dubbel: 'dubbele-plisse-hordeur' };

function fmt(n) { return `€ ${Number(n).toFixed(2).replace('.', ',')}`; }

/**
 * Kleine cache per worker. Alleen geslaagde ophaalacties worden bewaard: een
 * gecachete mislukking zou de rest van de run meeslepen.
 */
const cache = { config: {}, productId: {}, kortingen: {} };

async function haalConfig(request, slug) {
  if (cache.config[slug]) return cache.config[slug];

  const cfg = await request.get(`${API}/configurators/${slug}`, { timeout: 15000 })
    .then(r => (r.ok() ? r.json() : null))
    .catch(() => null);

  if (cfg) cache.config[slug] = cfg;
  return cfg;
}

/** Het Shopify-product-id, nodig voor de kortingen. Eén keer per deurtype. */
async function haalProductId(request, type) {
  if (cache.productId[type]) return cache.productId[type];

  // De winkel rate-limit't (429 local_rate_limited); één rustige herkansing.
  for (let poging = 0; poging < 2; poging++) {
    const id = await request.get(`${SHOP}/products/${HANDLES[type]}.js`, { timeout: 15000 })
      .then(r => (r.ok() ? r.json() : null))
      .then(j => (j && j.id ? String(j.id) : null))
      .catch(() => null);

    if (id) {
      cache.productId[type] = id;
      return id;
    }
    await new Promise(r => setTimeout(r, 2000));
  }

  return null;
}

/**
 * De lopende acties voor dit product, of null als we het niet konden ophalen.
 * Een lege lijst betekent "geen actie" en is dus iets anders dan null.
 */
async function actieveKortingen(request, type, productId) {
  if (!productId) return null;
  if (cache.kortingen[type]) return cache.kortingen[type];

  const body = await request
    .get(`${API}/active-discounts?productId=${encodeURIComponent(productId)}`, { timeout: 15000 })
    .then(r => (r.ok() ? r.json() : null))
    .catch(() => null);

  if (!Array.isArray(body?.discounts)) return null;

  cache.kortingen[type] = body.discounts;
  return body.discounts;
}

/** Zoek recursief de optie met key `grijs_gaas` in de configurator-config. */
function vindGrijsPrijs(obj) {
  if (!obj || typeof obj !== 'object') return null;
  if (obj.key === 'grijs_gaas') return Number(obj.price) || 0;
  for (const v of Object.values(obj)) {
    const r = vindGrijsPrijs(v);
    if (r != null) return r;
  }
  return null;
}

/** De maat-grenzen uit de `afmeting`-stap van hun eigen config. */
function vindAfmetingGrenzen(cfg) {
  const stap = (cfg?.steps || []).find(s => s.type === 'dimensions');
  if (!stap?.breedte || !stap?.hoogte) return null;
  return { breedte: stap.breedte, hoogte: stap.hoogte };
}

function binnenGrenzen(grenzen, breedte, hoogte) {
  if (!grenzen) return false;
  return breedte >= grenzen.breedte.min && breedte <= grenzen.breedte.max
    && hoogte >= grenzen.hoogte.min && hoogte <= grenzen.hoogte.max;
}

/** Basisprijs uit de calculate-API, of null als de maat niet echt gedekt is. */
async function haalBasisPrijs(request, slug, breedte, hoogte) {
  const j = await request.post(`${API}/calculate/${slug}`, {
    headers: { 'Content-Type': 'application/json' },
    data: { breedte, hoogte, plaatsing: 'tussen_het_kozijn' },
    timeout: 15000,
  }).then(r => (r.ok() ? r.json() : null)).catch(() => null);

  const prijs = Number(j?.totaalPrijs);
  if (!isFinite(prijs) || prijs <= 0) return null;

  // De API rondt naar boven af naar de dichtstbijzijnde band, maar knijpt een
  // te grote maat stil terug naar de grootste band: dan dekt hij onze maat niet.
  if (Number(j.matchedBreedte) < breedte || Number(j.matchedHoogte) < hoogte) return null;

  return prijs;
}

for (const [naam, { breedte, hoogte, type, gaas }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ request }) => {
    let prijs = null;
    try {
      const slug = SLUGS[type];
      const cfg = await haalConfig(request, slug);

      if (cfg && binnenGrenzen(vindAfmetingGrenzen(cfg), breedte, hoogte)) {
        const basis = await haalBasisPrijs(request, slug, breedte, hoogte);
        const meerprijs = gaas === 'grijs' ? vindGrijsPrijs(cfg) : 0;
        const bruto = basis != null && meerprijs != null ? basis + meerprijs : null;

        if (bruto != null) {
          const productId = await haalProductId(request, type);
          const kortingen = await actieveKortingen(request, type, productId);

          if (kortingen === null) {
            console.log(`${COMP} ${naam}: kortingen niet op te halen (product-id ${productId ?? 'onbekend'}) — n.v.t.`);
          } else {
            prijs = fmt(priceAfterDiscount(bruto, kortingen));
          }
        }
      }
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
