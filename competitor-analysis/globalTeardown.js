/**
 * globalTeardown.js – runs once after all tests complete.
 * Reads results.json and writes prijsvergelijking-plisse-hordeuren.xlsx.
 */

const fs      = require('fs');
const path    = require('path');
const ExcelJS = require('exceljs');
const { collectResults } = require('./tests/priceRecorder');

const COMPETITORS = [
  { key: 'plissehordeurenwebshop.nl',   label: 'Eigen winkel' },
  { key: 'horrentotaal.nl',             label: 'Horrentotaal' },
  { key: 'horrengigant.nl',             label: 'Horrengigant' },
  { key: 'horren.com',                  label: 'Horren.com' },
  { key: 'praxis.nl',                   label: 'Praxis' },
  { key: 'creon-kozijnen.nl',           label: 'Creon Kozijnen' },
  // --- extra concurrenten (toegevoegd) ---
  { key: 'qniq.nl',                     label: 'Qniq' },
  { key: 'handigehorren.nl',            label: 'Handige Horren' },
  { key: 'luxehorren.nl',               label: 'Luxehorren' },
  { key: 'koopje-horren.com',           label: 'Koopje-Horren' },
  { key: 'horrenstunter.nl',            label: 'Horrenstunter' },
  { key: 'horrenconcurrent.nl',         label: 'Horrenconcurrent' },
  { key: 'decozijn.nl',                 label: 'Decozijn' },
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
  'praxis.nl': 'https://www.praxis.nl/hout-ramen-trappen-deuren/horren/hordeuren/plisse-hordeuren',
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
  'solanowonen.nl': 'https://www.solanowonen.nl/horren/hordeuren/plissehordeuren/luxaflex-plisse-hordeur-volare',
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
    ['Grijs leverbaar bij', 'Eigen winkel, Horren.com, Qniq, Luxehorren, Koopje-Horren, Horrentotaal (+€39) en Horrengigant (geen meerprijs); de rest levert alleen zwart gaas'],
    ['Typecodes',         '96E t/m 190N = eigen assortiment (enkele deur); "Dubbel <type>" = dubbele deur op 2× de breedte'],
    ['', ''],
    ['KLEURLEGENDA',      'T.o.v. de eigen winkel per maat'],
    ['Rood',              'Concurrent is GOEDKOPER dan de eigen winkel (prijsdruk)'],
    ['Groen',             'Concurrent is DUURDER dan de eigen winkel'],
    ['Geel',              'Exact dezelfde prijs'],
    ['Geen kleur',        'Geen vergelijkbare prijs (label zoals Op aanvraag / n.v.t.)'],
    ['', ''],
    ['METHODE PER BRON',  ''],
    ['Eigen winkel',      'ECHTE per-maat prijs uit de configurator (Hordeur-regel, excl. afhaalkorting/promo)'],
    ['Horrengigant',      'ECHTE per-maat prijs: WebForms-configurator volledig doorlopen (let op: cm-invoer), incl. gaaskleur (Zwart/Grijs, geen meerprijs)'],
    ['Qniq',              'ECHTE configuratorprijs; qniq prijst per deurtype (enkel/dubbel), niet per exacte mm'],
    ['Horrenconcurrent',  'ECHTE per-maat prijs: WooCommerce/PEWC live totaal (prijs in maatbanden)'],
    ['Horrentotaal',      'ECHTE per-maat prijs: configurator-API (configurator.horrentotaal.nl/calculate) opgevangen'],
    ['Creon Kozijnen',    'ECHTE per-maat prijs: /product/price AJAX (keyup-invoer); enkel vast, dubbel in maatbanden'],
    ['Horrenstunter',     'ECHTE per-maat prijs: Gravity Forms .formattedTotalPrice (basis + maat-meerprijs), maatbanden. Dubbel: dekking door één enkele deur (max 1900 mm); breder -> n.v.t. (dubbel-formulier niet automatiseerbaar)'],
    ['Koopje-Horren',     'ECHTE vaste prijs (Bruynzeel Plissé Hordeur s900 op maat); geen breedte/hoogte-veld, prijs is maat-onafhankelijk'],
    ['Luxehorren',        'ECHTE per-maat prijs: TM Extra Product Options (Samenstellen) via JS gevuld, "Totaal prijs €…". Product is "Standaard Plissé hordeur" (niet Royal — geverifieerd 2026-07-22, geen model-keuze op deze pagina)'],
    ['Handige Horren',    'ECHTE per-maat prijs: Easify-maattoeslag live uit productpagina + Shopify basisprijs (maatbanden), + €20 optie "Op maat zagen: Ja" (kant-en-klare deur, vergelijkbaar met de andere bronnen)'],
    ['Horren.com',        'ECHTE per-maat prijs: validate-state API (SE-100/SE-200, cm-invoer, incl. btw)'],
    ['Praxis',            'Live prijs standaardmaten (Algolia-API): goedkoopste WITTE "Plisséhordeur Premium" die de doelmaat dekt (inkortbaar); maten > aanbod of geen Premium-maat -> n.v.t.'],
    ['Decozijn',          'ECHTE prijs: vaste breedtebanden (960/1300/1600/1900mm) uit de Gravity Forms product-select, hoogte is prijsneutraal binnen 1800–2700mm. Alleen enkele deur, geen gaaskleur-optie -> dubbel/grijs = n.v.t.'],
    ['Solano Wonen',      'ECHTE per-maat prijs (Luxaflex Volare): JSON-API getProductConfiguration, incl. enkel/dubbel en gaaskleur (geen meerprijs voor grijs). API keurt sommige combinaties af op een niet-triviale breedte×hoogte-matrix (bv. 190×2350mm te groot voor enkele deur) -> n.v.t.'],
    ['Raamdecoratie',     'Geblokkeerd door Cloudflare (bot-uitdaging valt headless niet weg, zelfs niet na 30s wachten); bewust niet omzeild — geen prijs opgehaald, geen "verkoopt dit niet"'],
    ['', ''],
    ['Let op',            'ECHTE prijzen uit de configurators: Eigen winkel, Horrengigant, Horrentotaal, Horrenconcurrent, Horrenstunter, Creon, Luxehorren, Solano Wonen (per-maat) + Qniq, Koopje, Decozijn (vaste prijs per type/band). Overige tekstlabels zijn geen prijs.'],
  ].forEach(row => info.addRow(row));
  info.getColumn(1).width = 20;
  info.getColumn(2).width = 55;

  // ── Save ─────────────────────────────────────────────────────────────────
  const outFile = path.join(__dirname, 'prijsvergelijking-plisse-hordeuren.xlsx');
  await wb.xlsx.writeFile(outFile);
  console.log(`\n✅ Excel opgeslagen: prijsvergelijking-plisse-hordeuren.xlsx`);
};
