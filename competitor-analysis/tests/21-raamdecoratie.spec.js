/**
 * raamdecoratie.com – Plissehordeur enkel  ❌ niet geautomatiseerd te scrapen
 *
 * URL: https://www.raamdecoratie.com/plissehordeur-enkel.html
 *
 * De site staat achter een Cloudflare-uitdaging ("Just a moment... / Even
 * geduld...") die met gewoon `curl` een 403 geeft en met headless Chromium
 * ook na 30s wachten niet zelf wegvalt (anders dan bv. hamstrahorren, waar de
 * challenge wél binnen ~20s headless verdwijnt). We omzeilen bot-bescherming
 * bewust NIET (geen anti-detectie-flags, geen stealth-plugins, geen IP-trucs
 * — zelfde afspraak als bommelwonen/lowikmeubelen in de catalog-volledig-
 * suite). Eerlijk label i.p.v. n.v.t., zodat duidelijk is DAT hier een prijs
 * bestaat maar niet automatisch op te halen is (in plaats van "verkoopt dit
 * niet" of "geen online prijs").
 */

const { test } = require('@playwright/test');
const { registerLabel } = require('./_vanaf');

registerLabel(test, { comp: 'raamdecoratie.com', label: 'Geblokkeerd (Cloudflare)' });
