/**
 * globalTeardown.js – runs once after all tests complete.
 * Reads results.json and writes prijsvergelijking-plisse-hordeuren.xlsx.
 */

const fs      = require('fs');
const path    = require('path');
const ExcelJS = require('exceljs');
const { collectResults } = require('./tests/priceRecorder');

// Kolomvolgorde van de Excel (Prijsvergelijking + Bronnen), vastgesteld door de
// klant op 2026-07-30. "Eigen winkel" blijft bewust vooraan: dat is de
// referentiekolom waartegen de rest gekleurd wordt.
const COMPETITORS = [
  { key: 'plissehordeurenwebshop.nl',   label: 'Eigen winkel' },
  { key: 'horrengigant.nl',             label: 'Horrengigant' },
  { key: 'horren.com',                  label: 'Horren.com' },
  { key: 'koopje-horren.com',           label: 'Koopje-Horren' },
  { key: 'qniq.nl',                     label: 'Qniq' },
  { key: 'luxehorren.nl',               label: 'Luxehorren' },
  { key: 'horrentotaal.nl',             label: 'Horrentotaal' },
  { key: 'creon-kozijnen.nl',           label: 'Creon Kozijnen' },
  { key: 'horrenconcurrent.nl',         label: 'Horrenconcurrent' },
  { key: 'horrenstunter.nl',            label: 'Horrenstunter' },
  { key: 'decozijn.nl',                 label: 'Decozijn' },
  { key: 'handigehorren.nl',            label: 'Handige Horren' },
  { key: 'solanowonen.nl',              label: 'Solano Wonen' },
  { key: 'raamdecoratie.com',           label: 'Raamdecoratie' },
];

// Rijen komen rechtstreeks uit tests/sizes.js — één bron voor specs én Excel.
const { SIZES: SIZE_MAP } = require('./tests/sizes');
const SIZES = Object.entries(SIZE_MAP).map(([naam, { breedte, hoogte, type, gaas }]) => ({
  naam,
  afmeting: `${breedte}×${hoogte} mm`,
  type,
  gaas,
}));

// Bron-pagina per concurrent (gebruikt voor de "Bronnen"-tab). Eén string =
// zelfde pagina voor enkel en dubbel; anders { enkel, dubbel }. Houd dit in
// sync met de URL's in de specs (zelfde redundantie-afspraak als COMPETITORS).
const SOURCES = {
  'plissehordeurenwebshop.nl': {
    enkel:  'https://www.plissehordeurenwebshop.nl/hordeuren/maatwerk-enkele-plissehordeur/',
    dubbel: 'https://www.plissehordeurenwebshop.nl/hordeuren/maatwerk-dubbele-plissehordeur/',
  },
  'horrentotaal.nl': {
    enkel:  'https://horrentotaal.nl/products/plisse-hordeur',
    dubbel: 'https://horrentotaal.nl/products/dubbele-plisse-hordeur',
  },
  'horrengigant.nl': 'https://www.horrengigant.nl/configurator/deurplissehor.htm',
  'horren.com': {
    enkel:  'https://horren.com/hordeuren/plisse/se100',
    dubbel: 'https://horren.com/hordeuren/plisse/dubbel-se200',
  },
  'creon-kozijnen.nl': 'https://www.creon-kozijnen.nl/horren/plisse-hordeur',
  'qniq.nl': {
    enkel:  'https://qniq.nl/plisse-hordeur/',
    dubbel: 'https://qniq.nl/dubbele-plisse-hordeur/',
  },
  'handigehorren.nl': {
    enkel:  'https://www.handigehorren.nl/products/plisse-hordeur',
    dubbel: 'https://www.handigehorren.nl/products/dubbele-plisse-hordeur',
  },
  'luxehorren.nl': {
    enkel:  'https://www.luxehorren.nl/horren-bestellen/standaard-plisse-hordeur/',
    dubbel: 'https://www.luxehorren.nl/horren-bestellen/dubbele-plisse-hordeur/',
  },
  'koopje-horren.com': {
    enkel:  'https://www.koopje-horren.com/bruynzeel-plisse-hordeur-s900-op-maat',
    dubbel: 'https://www.koopje-horren.com/bruynzeel-dubbele-plisse-hordeur-s900-op-maat',
  },
  'horrenstunter.nl': {
    enkel:  'https://horrenstunter.nl/product/originele-plissehordeur/',
    dubbel: 'https://horrenstunter.nl/product/originele-plissehordeur-dubbel/',
  },
  'horrenconcurrent.nl': {
    enkel:  'https://horrenconcurrent.nl/product/enkele-plisse-hordeur-op-maat/',
    dubbel: 'https://horrenconcurrent.nl/product/dubbele-plisse-hordeur-op-maat/',
  },
  // Decozijn heeft geen dubbele-deurvariant; dezelfde pagina voor enkel en dubbel
  // ("Bronnen"-tab toont hem dan ook bij dubbele rijen, ook al is de rij zelf n.v.t.)
  'decozijn.nl': 'https://www.decozijn.nl/hordeur-op-maat/plisse/',
  'solanowonen.nl': 'https://www.solanowonen.nl/horren/hordeuren/plissehordeuren/keje-plissehordeur',
  'raamdecoratie.com': 'https://www.raamdecoratie.com/plissehordeur-enkel.html',
};

function sourceFor(key, type) {
  const s = SOURCES[key];
  if (!s) return null;
  return typeof s === 'string' ? s : s[type] ?? null;
}

const BLUE   = 'FF2E75B6';
const WHITE  = 'FFFFFFFF';
const ROW_A  = 'FFF2F7FB';
const ROW_B  = 'FFFFFFFF';
const BORDER = { style: 'thin', color: { argb: 'FFDDDDDD' } };

// Signaalkleuren t.o.v. de eigen winkel (Excel-stijl "goed/slecht"):
// concurrent GOEDKOPER dan wij = rood (prijsdruk), DUURDER = groen.
const RED_BG    = 'FFFFC7CE', RED_TXT   = 'FF9C0006';
const GREEN_BG  = 'FFC6EFCE', GREEN_TXT = 'FF006100';
const EQUAL_BG  = 'FFFFEB9C', EQUAL_TXT = 'FF9C6500'; // exact gelijk = geel

/** "€ 1.234,56" -> 1234.56, anders null (labels zoals "Op aanvraag"). */
function euroNum(v) {
  const m = String(v ?? '').match(/€\s*([\d.]+)(?:,(\d{2}))?/);
  if (!m) return null;
  return parseFloat(m[1].replace(/\./g, '') + '.' + (m[2] || '00'));
}

function bordered(cell) {
  cell.border = { top: BORDER, bottom: BORDER, left: BORDER, right: BORDER };
}

module.exports = async function globalTeardown() {
  // Voeg de per-test deelbestanden samen (race-vrij) en bewaar de samenvatting.
  const results = collectResults();
  const resultsFile = path.join(__dirname, 'results.json');
  fs.writeFileSync(resultsFile, JSON.stringify(results, null, 2));

  const wb = new ExcelJS.Workbook();
  wb.creator  = 'Playwright prijsvergelijking';
  wb.created  = new Date();

  // ── Prijsvergelijking sheet ──────────────────────────────────────────────
  const ws = wb.addWorksheet('Prijsvergelijking');

  // Header
  const headerRow = ws.addRow([
    'Product', 'Afmeting', 'Gaas',
    ...COMPETITORS.map(c => c.label),
  ]);
  headerRow.height = 36;
  headerRow.eachCell(cell => {
    cell.font      = { bold: true, color: { argb: WHITE }, name: 'Arial', size: 10 };
    cell.fill      = { type: 'pattern', pattern: 'solid', fgColor: { argb: BLUE } };
    cell.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true };
    bordered(cell);
  });

  // Data rows
  SIZES.forEach(({ naam, afmeting, gaas }, i) => {
    const row = ws.addRow([
      naam, afmeting, gaas,
      ...COMPETITORS.map(c => results[c.key]?.[naam] ?? '–'),
    ]);
    row.height = 26;
    const bg = i % 2 === 0 ? ROW_A : ROW_B;
    const eigenPrijs = euroNum(results['plissehordeurenwebshop.nl']?.[naam]);
    row.eachCell((cell, colNum) => {
      cell.font      = { name: 'Arial', size: 10 };
      cell.fill      = { type: 'pattern', pattern: 'solid', fgColor: { argb: bg } };
      cell.alignment = { horizontal: colNum <= 3 ? 'left' : 'center', vertical: 'middle' };

      // Signaalkleur t.o.v. eigen winkel (kolom 4 = eigen winkel zelf: neutraal blauw accent)
      if (colNum === 4) {
        cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FFDDEBF7' } };
        cell.font = { name: 'Arial', size: 10, bold: true };
      } else if (colNum > 4 && eigenPrijs != null) {
        const p = euroNum(cell.value);
        if (p != null) {
          const [fill, txt] = p < eigenPrijs ? [RED_BG, RED_TXT]
                            : p > eigenPrijs ? [GREEN_BG, GREEN_TXT]
                            : [EQUAL_BG, EQUAL_TXT];
          cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: fill } };
          cell.font = { name: 'Arial', size: 10, color: { argb: txt } };
        }
      }
      bordered(cell);
    });
  });

  // Column widths
  ws.getColumn(1).width = 22;
  ws.getColumn(2).width = 14;
  ws.getColumn(3).width = 8;
  COMPETITORS.forEach((_, i) => { ws.getColumn(i + 4).width = 15; });

  // ── Bronnen sheet (zelfde matrix, cellen = gebruikte pagina per prijs) ───
  const bron = wb.addWorksheet('Bronnen');
  const bronHeader = bron.addRow(['Product', 'Afmeting', 'Gaas', ...COMPETITORS.map(c => c.label)]);
  bronHeader.height = 36;
  bronHeader.eachCell(cell => {
    cell.font      = { bold: true, color: { argb: WHITE }, name: 'Arial', size: 10 };
    cell.fill      = { type: 'pattern', pattern: 'solid', fgColor: { argb: BLUE } };
    cell.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true };
    bordered(cell);
  });
  SIZES.forEach(({ naam, afmeting, type, gaas }, i) => {
    const row = bron.addRow([
      naam, afmeting, gaas,
      ...COMPETITORS.map(c => sourceFor(c.key, type) ?? '–'),
    ]);
    row.height = 26;
    const bg = i % 2 === 0 ? ROW_A : ROW_B;
    row.eachCell((cell, colNum) => {
      cell.font      = { name: 'Arial', size: 8 };
      cell.fill      = { type: 'pattern', pattern: 'solid', fgColor: { argb: bg } };
      cell.alignment = { horizontal: 'left', vertical: 'middle' };
      if (colNum > 3 && /^https?:\/\//.test(String(cell.value))) {
        const url = String(cell.value);
        cell.value = { text: url.replace(/^https?:\/\/(www\.)?/, ''), hyperlink: url };
        cell.font  = { name: 'Arial', size: 8, color: { argb: 'FF0563C1' }, underline: true };
      }
      bordered(cell);
    });
  });
  bron.getColumn(1).width = 22;
  bron.getColumn(2).width = 14;
  bron.getColumn(3).width = 8;
  COMPETITORS.forEach((_, i) => { bron.getColumn(i + 4).width = 38; });

  // ── Info sheet ───────────────────────────────────────────────────────────
  const info = wb.addWorksheet('Info');
  [
    ['Gegenereerd op',    new Date().toLocaleString('nl-NL')],
    ['Maten zijn',        'Tussen het kozijn (mm)'],
    ['Standaard opties',  'RAL 9010 wit frame, geen handgreep, geen powertape; gaaskleur per regel (kolom Gaas)'],
    ['Gaas grijs',        'n.v.t. bij concurrenten die geen grijze gaaskleur aanbieden (eerlijke lege cel)'],
    ['Grijs leverbaar bij', 'Eigen winkel, Horren.com, Qniq, Luxehorren, Koopje-Horren (t/m 2600 mm hoog), Horrentotaal (+€39 enkel / +€78 dubbel) en Horrengigant (geen meerprijs); de rest levert alleen zwart gaas'],
    ['Typecodes',         '96E t/m 190N = eigen assortiment (enkele deur); "Dubbel <type>" = dubbele deur op 2× de breedte'],
    ['', ''],
    ['KLEURLEGENDA',      'T.o.v. de eigen winkel per maat'],
    ['Rood',              'Concurrent is GOEDKOPER dan de eigen winkel (prijsdruk)'],
    ['Groen',             'Concurrent is DUURDER dan de eigen winkel'],
    ['Geel',              'Exact dezelfde prijs'],
    ['Geen kleur',        'Geen vergelijkbare prijs (label zoals Op aanvraag / n.v.t.)'],
    ['', ''],
    ['METHODE PER BRON',  '(zelfde volgorde als de kolommen)'],
    ['Eigen winkel',      'ECHTE per-maat prijs uit de configurator: Hordeur-regel MIN de lopende promokorting (bv. "Zomerkorting 2026"), want die geldt voor iedere klant. Excl. de afhaalkorting uit het Totaal — die geldt alleen bij ophalen in Gorinchem'],
    ['Horrengigant',      'ECHTE per-maat prijs: WebForms-configurator volledig doorlopen (let op: cm-invoer), incl. gaaskleur (Zwart/Grijs, geen meerprijs)'],
    ['Horren.com',        'ECHTE per-maat prijs: validate-state API (SE-100/SE-200, cm-invoer, incl. btw)'],
    ['Koopje-Horren',     'ECHTE per-maat prijs (Bruynzeel Plissé Hordeur s900 op maat): de prijstabel van hun configurator staat in de productpagina; per maat de eerste band die de opening dekt. Grijs gaas gratis t/m 2600 mm hoog; "x"-combinaties maken ze niet -> n.v.t.'],
    ['Qniq',              'ECHTE configuratorprijs; qniq prijst per deurtype (enkel/dubbel), niet per exacte mm'],
    ['Luxehorren',        'ECHTE per-maat prijs: TM Extra Product Options (Samenstellen) via JS gevuld, "Totaal prijs €…". Product is "Standaard Plissé hordeur" (niet Royal — geverifieerd 2026-07-22, geen model-keuze op deze pagina)'],
    ['Horrentotaal',      'ECHTE per-maat prijs: configurator-API (configurator.horrentotaal.nl/calculate) rechtstreeks bevraagd, MIN de lopende winkelactie (active-discounts, bv. 10%), want die krijgt iedere klant zonder code — zoals bij de eigen winkel. Meerprijs grijs gaas telt mee in de korting; volumekorting (vanaf x stuks) niet'],
    ['Creon Kozijnen',    'ECHTE per-maat prijs: /product/price AJAX (keyup-invoer); enkel vast, dubbel in maatbanden'],
    ['Horrenconcurrent',  'ECHTE per-maat prijs: WooCommerce/PEWC live totaal (prijs in maatbanden)'],
    ['Horrenstunter',     'ECHTE per-maat prijs: Gravity Forms .formattedTotalPrice (basis + maat-meerprijs), maatbanden. Enkel én dubbel: deurkeuze in het formulier, breedte t/m 1900 mm (enkel) resp. 3800 mm (dubbel)'],
    ['Decozijn',          'ECHTE prijs: vaste breedtebanden (960/1300/1600/1900mm) uit de Gravity Forms product-select, hoogte is prijsneutraal binnen 1800–2700mm. Alleen enkele deur, geen gaaskleur-optie -> dubbel/grijs = n.v.t.'],
    ['Handige Horren',    'ECHTE per-maat prijs: Easify-maattoeslag live uit productpagina + Shopify basisprijs (maatbanden), + €20 optie "Op maat zagen: Ja" (kant-en-klare deur, vergelijkbaar met de andere bronnen)'],
    ['Solano Wonen',      'ECHTE per-maat prijs (Keje plissehordeur): JSON-API getProductConfiguration, incl. enkel/dubbel en de optie "Op maat zagen: Ja" — zonder die optie is het een zelf in te korten bouwpakket. Hun 20% winkelkorting zit al in het teruggegeven totaal en geldt óók over die optie (730×1970: adviesprijs €333,69 -> €266,95). Geen gaaskleur-optie -> grijs = n.v.t.; te grote maten keurt de API zelf af'],
    ['Raamdecoratie',     'Geblokkeerd door Cloudflare (bot-uitdaging valt headless niet weg, zelfs niet na 30s wachten); bewust niet omzeild — geen prijs opgehaald, geen "verkoopt dit niet"'],
    ['', ''],
    ['Let op',            'ECHTE prijzen uit de configurators: Eigen winkel, Horrengigant, Horren.com, Horrentotaal, Horrenconcurrent, Horrenstunter, Creon, Luxehorren, Handige Horren, Koopje-Horren, Solano Wonen (per-maat) + Qniq, Decozijn (prijs per type/band). Overige tekstlabels zijn geen prijs.'],
    ['', ''],
    ['WIJZIGINGEN',       'Doorgevoerde correcties op de bronnen, met het effect op de prijs van 730×1970 enkel'],
    ['30-07-2026',        'Koopje-Horren: prijstabel i.p.v. de getoonde vanaf-prijs. De €179 op hun pagina is de goedkoopste cel (580×1791), geen vaste prijs — ze stonden dus jarenlang te goedkoop in de vergelijking. Nu €233,00 (dubbel 1430×1970: €457,00). Product = Bruynzeel Plissé Hordeur s900, enkel én dubbel'],
    ['30-07-2026',        'Solano Wonen: van Luxaflex Volare naar de Keje plissehordeur, mét "Op maat zagen". De Volare was de enige zonder die optie (dus een bouwpakket) en een dealerproduct in een andere prijsklasse. €448 -> €266,95'],
    ['30-07-2026',        'Horrenstunter: dubbele deuren zijn wél te configureren (apart breedteveld t/m 3800 mm). Stonden eerst op de prijs van één enkele deur, of op n.v.t. boven 1900 mm'],
    ['30-07-2026',        'Horrentotaal: configurator-API rechtstreeks bevraagd i.p.v. via hun winkelpagina, die na ±10 laadbeurten met een rate limit antwoordde — de kolom vulde daardoor 0 van de 34 cellen'],
    ['30-07-2026',        'Praxis verwijderd als bron (klantverzoek); kolomvolgorde vastgesteld'],
    ['29-07-2026',        'Horrentotaal: lopende winkelactie (10%) wordt afgetrokken. Die zit niet in hun prijs-API maar wordt client-side verrekend en belandt wél in de winkelwagen; zonder deze stap stonden ze 10% te duur. €324,00 -> €291,60'],
    ['29-07-2026',        'Eigen winkel: onze eigen promokorting (10%) wordt afgetrokken, want die krijgt iedere klant. Daarvoor vergeleken we onze adviesprijs met de échte prijs van de concurrent — systematisch in ons nadeel. €289,00 -> €260,00'],
    ['04-08-2026',        'Alle bovenstaande punten opnieuw live gecontroleerd tegen de winkels; alle bedragen ongewijzigd. Raamdecoratie geeft nog steeds Cloudflare-403 (ook op hun sitemap), dus daar blijft de kolom leeg'],
  ].forEach(row => info.addRow(row));
  info.getColumn(1).width = 20;
  info.getColumn(2).width = 55;

  // ── Save ─────────────────────────────────────────────────────────────────
  const outFile = path.join(__dirname, 'prijsvergelijking-plisse-hordeuren.xlsx');
  await wb.xlsx.writeFile(outFile);
  console.log(`\n✅ Excel opgeslagen: prijsvergelijking-plisse-hordeuren.xlsx`);
};

// Playwright verwacht één functie als export; de tabellen hangen we ernaast zodat
// `kolommen.test.js` de kolomvolgorde en de spec-koppeling kan controleren zonder
// ze te kopiëren. Deze blijven dus de enige bron.
module.exports.COMPETITORS = COMPETITORS;
module.exports.SOURCES     = SOURCES;
