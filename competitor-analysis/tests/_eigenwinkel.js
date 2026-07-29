/**
 * Prijsberekening voor de eigen winkel (01-plissehordeurenwebshop).
 *
 * Staat los van de browser zodat de rekenregel te testen is zonder de live
 * configurator: de spec haalt alleen de tabelregels op, dit bestand beslist
 * welke bedragen meetellen. Unit tests: `npm run test:eigenwinkel`.
 */

/** "€ -29,00" -> -29, "€ 1.234,50" -> 1234.5, "-" of onzin -> null. */
function parseEuro(text) {
  const m = String(text ?? '').replace(/ /g, ' ').match(/€\s*(-?)\s*([\d.]+),(\d{2})/);
  if (!m) return null;

  const value = parseFloat(m[2].replace(/\./g, '') + '.' + m[3]);

  return m[1] === '-' ? -value : value;
}

/**
 * De vergelijkbare prijs uit de `.configurator__totals`-tabel.
 *
 * = de kale Hordeur-regel MIN de lopende promokorting (bv. "Zomerkorting
 * 2026"), en dus NIET het Totaal.
 *
 * Waarom niet het Totaal: daar zit ook de afhaalkorting in ("Afhalen in
 * Gorinchem", −€5,20), en die krijgt alleen een klant die zelf komt ophalen.
 * Die zou onze prijs kunstmatig onder die van de concurrenten duwen.
 *
 * Waarom wél de promokorting: die geldt voor iedere klant, dus zonder haar
 * vergelijken we onze niet-geldende adviesprijs met de échte prijs van de
 * concurrent — precies andersom scheef. Voor 730×1970 zwart: Hordeur € 289,00
 * − € 29,00 = € 260,00 (Totaal is € 254,80 en dus te laag).
 *
 * LET OP bij het aanpassen: de Levering-regel heet zelf "Afhalen in Gorinchem
 * (extra korting)". Matchen op de tekst "korting" pakt hem er dus bij. Daarom
 * matchen we op de eerste kolom (de rijkop) en niet op de omschrijving.
 *
 * @param {string[][]} rows Celteksten per rij, met de doorgestreepte van-prijs
 *                          al verwijderd uit de Hordeur-regel.
 * @returns {number|null} Bedrag in euro, of null als er geen Hordeur-regel is.
 */
function eigenWinkelPrijs(rows) {
  const kop = (row) => String(row?.[0] ?? '').replace(/\s+/g, ' ').trim();
  const laatste = (row) => row?.[row.length - 1];

  const hordeur = (rows || []).find((row) => /^Hordeur$/i.test(kop(row)));
  const basis = hordeur ? parseEuro(laatste(hordeur)) : null;

  if (basis === null) return null;

  // Meerdere promoregels mogen gewoon optellen; ze staan als negatief bedrag.
  const korting = (rows || [])
    .filter((row) => /^Korting$/i.test(kop(row)))
    .map((row) => parseEuro(laatste(row)) ?? 0)
    .reduce((totaal, bedrag) => totaal + bedrag, 0);

  return Math.round((basis + korting) * 100) / 100;
}

/** 260 -> "€ 260,00", passend bij wat normalizePrice oplevert. */
function euro(value) {
  if (value === null || value === undefined || Number.isNaN(value)) return null;

  return '€ ' + value.toLocaleString('nl-NL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

module.exports = { parseEuro, eigenWinkelPrijs, euro };
