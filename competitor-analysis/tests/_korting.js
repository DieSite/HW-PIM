/**
 * Actieve winkelkorting van horrentotaal.nl.
 *
 * Hun configurator haalt lopende acties op bij
 * `GET configurator.horrentotaal.nl/active-discounts?productId=<id>` en trekt
 * ze er client-side af; de calculate-API (`totaalPrijs`) kent ze NIET. Wie
 * alleen `totaalPrijs` noteert, zet horrentotaal dus te duur in de
 * vergelijking — stand 2026-07-29 met 10% precies één tiende te duur.
 *
 * Dit bestand spiegelt hun eigen rekenregel (functie `B0` in
 * plisse-configurator.js) en staat bewust buiten de browser zodat hij te
 * testen is: `npm run test:korting`. De prijs die zij uiteindelijk in de
 * winkelwagen leggen is
 *
 *     round((totaalPrijs + optiemeerprijzen) - korting, 2)
 *
 * dus de korting geldt óók over de meerprijs voor grijs gaas.
 *
 * Waarom we hem meerekenen: dezelfde afweging als bij onze eigen winkel (zie
 * `_eigenwinkel.js`, die de lopende promokorting eraf haalt). Een korting die
 * iedere klant zonder code krijgt is de échte prijs. Hem aan onze kant wél en
 * aan die van de concurrent níét aftrekken maakt de vergelijking structureel
 * in ons voordeel — precies de scheefheid die we bij onze eigen winkel al
 * hebben rechtgezet.
 */

/**
 * Kortingssoorten die voor één deur gelden.
 *
 * `volume` staat bewust niet in de lijst: die geldt pas vanaf een
 * drempelaantal ("Bij 5+ stuks") en wordt in de winkelwagen toegepast. Wij
 * vergelijken de prijs van één deur, dus die korting krijgt onze klant niet.
 * Hun eigen B0 negeert onbekende soorten om dezelfde reden.
 */
const TOEPASBAAR = new Set(['percentage', 'amount']);

/**
 * De gunstigste toepasbare korting, of null als er geen geldt.
 *
 * Kortingen STAPELEN NIET: net als bij hen wint de hoogste korting, precies
 * één. Een percentage is een fractie (0.1 = 10%), zo levert hun API hem aan
 * en zo rekent hun widget ("`Math.round(value*100)`% korting").
 *
 * @param {number} prijs Brutoprijs inclusief optiemeerprijzen.
 * @param {Array<{type: string, value: number, title?: string}>} kortingen
 * @returns {{type: string, value: number, title?: string, off: number}|null}
 */
function bestDiscount(prijs, kortingen) {
  if (!(prijs > 0) || !Array.isArray(kortingen)) return null;

  let beste = null;

  for (const korting of kortingen) {
    if (!korting || !TOEPASBAAR.has(korting.type)) continue;

    const waarde = Number(korting.value);
    if (!isFinite(waarde) || waarde <= 0) continue;

    // Een percentage van 100% of meer laat niets te verkopen over: dat is geen
    // actie maar een kapotte waarde. Hun eigen code zou er een prijs van €0 of
    // lager van maken; een onzinbedrag in een zakelijke vergelijking is erger
    // dan geen korting, dus slaan we hem over.
    if (korting.type === 'percentage' && waarde >= 1) continue;

    const off = korting.type === 'percentage' ? prijs * waarde : Math.min(waarde, prijs);

    if (off > (beste ? beste.off : 0)) beste = { ...korting, off };
  }

  return beste;
}

/**
 * De prijs die de klant vandaag betaalt: bruto min de gunstigste korting,
 * afgerond op centen zoals hun widget dat doet.
 *
 * @param {number} prijs Brutoprijs inclusief optiemeerprijzen.
 * @param {Array<{type: string, value: number}>} kortingen
 * @returns {number}
 */
function priceAfterDiscount(prijs, kortingen) {
  const beste = bestDiscount(prijs, kortingen);

  return Math.round((prijs - (beste ? beste.off : 0)) * 100) / 100;
}

module.exports = { bestDiscount, priceAfterDiscount };
