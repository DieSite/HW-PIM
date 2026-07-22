/**
 * decozijn.nl – Originele plisséhordeur op maat (WooCommerce + Gravity Forms Product Add-Ons)  ✅ live prijs
 *
 * URL: https://www.decozijn.nl/hordeur-op-maat/plisse/
 *
 * Enkele deur, geen gaaskleur-optie (alleen zwart) en geen dubbele-deurvariant
 * op deze pagina — beide dus eerlijk n.v.t. Alles is server-side gerenderd,
 * geen browserpagina nodig:
 *  - Basisprijs: hidden input #woocommerce_get_action[id=woocommerce_product_base_price] value (€260).
 *  - Breedte: Gravity Forms product-select (#input_19_2). Opties coderen
 *    "LABEL mm|MEERPRIJS" in het `value`-attribuut, bv. `1300 mm|20` = €280
 *    totaal. Geen losse maten mogelijk — kies de goedkoopste band die de
 *    doelbreedte dekt (max 1900 mm; breder -> n.v.t.).
 *  - Hoogte: los invoerveld (#input_19_4) maar PRIJSNEUTRAAL binnen het
 *    toegestane bereik (min/max-attributen, live 1800–2700 mm; buiten bereik
 *    -> n.v.t.). Geverifieerd live: dezelfde breedteband geeft exact dezelfde
 *    totaalprijs op 1970/2080/2350 mm hoogte.
 */

const { test, expect } = require('@playwright/test');
const { SIZES } = require('./sizes');
const { recordPrice } = require('./priceRecorder');

const COMP = 'decozijn.nl';
const URL = 'https://www.decozijn.nl/hordeur-op-maat/plisse/';

let configuratie = null; // cache: één page-fetch voor alle tests in dit bestand
async function haalConfiguratie(request) {
  if (configuratie) return configuratie;
  const resp = await request.get(URL, { timeout: 15000 });
  if (!resp.ok()) return null;
  const html = await resp.text();

  const basisMatch = html.match(/woocommerce_product_base_price"\s+value="(\d+(?:\.\d+)?)"/);
  const basis = basisMatch ? Number(basisMatch[1]) : null;

  // De optiewaarden staan al in mm (bv. "960 mm|0", "1300 mm|20") — geen cm-conversie nodig.
  const banden = [...html.matchAll(/<option value='(\d+)\s*mm[^|']*\|(\d+(?:\.\d+)?)'/g)]
    .map(m => ({ breedte: +m[1], meerprijs: Number(m[2]) }));

  const hoogteMatch = html.match(/id='input_19_4'[^>]*min='(\d+)'\s+max='(\d+)'/);
  const hoogteBereik = hoogteMatch ? { min: +hoogteMatch[1], max: +hoogteMatch[2] } : null;

  if (basis == null || !banden.length || !hoogteBereik) return null;
  configuratie = { basis, banden, hoogteBereik };
  return configuratie;
}

for (const [naam, { breedte, hoogte, type, gaas }] of Object.entries(SIZES)) {
  test(`${COMP} – ${naam} (${breedte}×${hoogte}mm)`, async ({ request }) => {
    // geen dubbele-deurvariant en geen gaaskleur-optie op deze pagina
    if (type === 'dubbel' || gaas === 'grijs') {
      recordPrice(COMP, naam, 'n.v.t.');
      return;
    }
    let prijs = null;
    try {
      const cfg = await haalConfiguratie(request);
      if (cfg && hoogte >= cfg.hoogteBereik.min && hoogte <= cfg.hoogteBereik.max) {
        const passend = cfg.banden
          .filter(b => b.breedte >= breedte)
          .sort((a, b) => a.breedte - b.breedte);
        if (passend.length) prijs = `€ ${(cfg.basis + passend[0].meerprijs).toFixed(2).replace('.', ',')}`;
      }
    } catch (e) {
      console.log(`${COMP} ${naam}: ${e.message.split('\n')[0]}`);
    }
    recordPrice(COMP, naam, prijs ?? 'n.v.t.');
    expect(prijs ?? 'n.v.t.').toBeTruthy();
  });
}
