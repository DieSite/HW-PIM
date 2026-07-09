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
  { key: 'luxaflex.nl',                 label: 'Luxaflex' },
  { key: 'creon-kozijnen.nl',           label: 'Creon Kozijnen' },
  { key: 'gamma.nl',                    label: 'Gamma' },
  { key: 'unilux.nl',                   label: 'Unilux' },
  { key: 'bruynzeelhomeproducts.nl',    label: 'Bruynzeel' },
  // --- extra concurrenten (toegevoegd) ---
  { key: 'qniq.nl',                     label: 'Qniq' },
  { key: 'handigehorren.nl',            label: 'Handige Horren' },
  { key: 'horrenmax.nl',                label: 'Horrenmax' },
  { key: 'luxehorren.nl',               label: 'Luxehorren' },
  { key: 'horrenbouw.nl',               label: 'Horrenbouw' },
  { key: 'koopje-horren.com',           label: 'Koopje-Horren' },
  { key: 'horrenstunter.nl',            label: 'Horrenstunter' },
  { key: 'horrenconcurrent.nl',         label: 'Horrenconcurrent' },
  { key: 'plissehordeur-discount.nl',   label: 'Plissé Discount' },
  { key: 'plissexxl.nl',                label: 'Plissé XXL' },
  { key: 'plisse-reus.nl',              label: 'Plissé Reus' },
  { key: 'plissetotaal.nl',             label: 'Plissetotaal' },
  { key: 'hamstrahorren.nl',            label: 'Hamstra Horren' },
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
  'luxaflex.nl': 'https://www.luxaflex.nl/',
  'creon-kozijnen.nl': 'https://www.creon-kozijnen.nl/horren/plisse-hordeur',
  'gamma.nl': 'https://www.gamma.nl/assortiment/l/deuren-ramen-trappen/horren/hordeuren/type-plisse-hordeur',
  'unilux.nl': 'https://www.unilux.nl/',
  'bruynzeelhomeproducts.nl': 'https://www.bruynzeelhomeproducts.nl/',
  'qniq.nl': {
    enkel:  'https://qniq.nl/plisse-hordeur/',
    dubbel: 'https://qniq.nl/dubbele-plisse-hordeur/',
  },
  'handigehorren.nl': {
    enkel:  'https://www.handigehorren.nl/products/plisse-hordeur',
    dubbel: 'https://www.handigehorren.nl/products/dubbele-plisse-hordeur',
  },
  'horrenmax.nl': 'https://horrenmax.nl/products/plisse-schuif-hordeur-op-maat-z35',
  'luxehorren.nl': {
    enkel:  'https://www.luxehorren.nl/horren-bestellen/standaard-plisse-hordeur/',
    dubbel: 'https://www.luxehorren.nl/horren-bestellen/dubbele-plisse-hordeur/',
  },
  'horrenbouw.nl': 'https://www.horrenbouw.nl/webshop/hordeuren/plisse-hordeur/',
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
  'plissehordeur-discount.nl': {
    enkel:  'https://www.plissehordeur-discount.nl/product/plisse-hordeur/',
    dubbel: 'https://www.plissehordeur-discount.nl/product/dubbele-plisse-hordeur/',
  },
  'plissexxl.nl': {
    enkel:  'https://plissexxl.nl/product/enkel-horren-deur-1/',
    dubbel: 'https://plissexxl.nl/product/dubbel-horren-deur/',
  },
  'plisse-reus.nl': 'https://www.plisse-reus.nl/',
  'plissetotaal.nl': 'https://plissetotaal.nl/',
  'hamstrahorren.nl': 'https://www.hamstrahorren.nl/plisse-hor/',
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
    ['Grijs leverbaar bij', 'Eigen winkel, Horren.com, Qniq, Luxehorren, Horrenbouw, Koopje-Horren en Horrentotaal (+€39); de rest levert alleen zwart gaas'],
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
    ['Horrengigant',      'ECHTE per-maat prijs: WebForms-configurator volledig doorlopen (let op: cm-invoer)'],
    ['Qniq',              'ECHTE configuratorprijs; qniq prijst per deurtype (enkel/dubbel), niet per exacte mm'],
    ['Horrenconcurrent',  'ECHTE per-maat prijs: WooCommerce/PEWC live totaal (prijs in maatbanden)'],
    ['Plissé Discount',   'ECHTE vaste prijs (enkel); MeasureWidth/Height wijzigen de prijs niet. Dubbel: geen losse productpagina'],
    ['Horrentotaal',      'ECHTE per-maat prijs: configurator-API (configurator.horrentotaal.nl/calculate) opgevangen'],
    ['Creon Kozijnen',    'ECHTE per-maat prijs: /product/price AJAX (keyup-invoer); enkel vast, dubbel in maatbanden'],
    ['Horrenstunter',     'ECHTE per-maat prijs: Gravity Forms .formattedTotalPrice (basis + maat-meerprijs), maatbanden. Dubbel: dekking door één enkele deur (max 1900 mm); breder -> n.v.t. (dubbel-formulier niet automatiseerbaar)'],
    ['Koopje-Horren',     'ECHTE vaste prijs per type (s900 op maat); geen breedte/hoogte-veld, prijs is maat-onafhankelijk'],
    ['Luxehorren',        'ECHTE per-maat prijs: TM Extra Product Options (Samenstellen) via JS gevuld, "Totaal prijs €…"'],
    ['Horrenbouw',        'ECHTE per-maat prijs: vaste breedte-banden (tot 96/110/130/160/190 cm), live ingelezen'],
    ['Horrenmax',         'Per-maat configurator (Shopify/DPO, cm-invoer, schuifhordeur) werkt, maar bot-bescherming blokkeert headless laden -> vaak n.v.t.'],
    ['Handige Horren',    'ECHTE per-maat prijs: Easify-maattoeslag live uit productpagina + Shopify basisprijs (maatbanden)'],
    ['Plissé XXL',        'ECHTE per-maat prijs: PEWC-formule (enkel b×h/22000, dubbel b×h/17000, afgerond omhoog) + basisprijs'],
    ['Horren.com',        'ECHTE per-maat prijs: validate-state API (SE-100/SE-200, cm-invoer, incl. btw)'],
    ['Praxis',            'Live prijs standaardmaten (Algolia-API): goedkoopste witte plissé-hordeur die de doelmaat dekt (inkortbaar); maten > aanbod -> n.v.t.'],
    ['Gamma',             'Live prijs standaardmaten (productpagina JSON-LD, Bruynzeel 700): goedkoopste wit product dat de doelmaat dekt; maten > aanbod -> n.v.t.'],
    ['Hamstra Horren',    'Via verkooppunten – merksite zonder webshop; site publiceert geen prijzen'],
    ['Plissetotaal',      'Site offline – geparkeerd domein ("Domein gereserveerd"), webshop bestaat niet meer'],
    ['Plissé Reus',       'Geen hordeur – verkoopt plissé gordijnen, geen hordeuren'],
    ['Unilux',            'Catalogus – bruto catalogusprijs, geen online configurator'],
    ['Luxaflex / Bruynzeel', 'Op aanvraag – geen online configurator / dealerverkoop'],
    ['', ''],
    ['Let op',            'ECHTE prijzen uit de configurators: Eigen winkel, Horrengigant, Horrentotaal, Horrenconcurrent, Horrenstunter, Creon, Luxehorren, Horrenbouw (per-maat) + Qniq, Plissé Discount, Koopje (vaste prijs per type). Horrenmax: configurator werkt maar wordt door bot-bescherming geblokkeerd. Overige tekstlabels zijn geen prijs.'],
  ].forEach(row => info.addRow(row));
  info.getColumn(1).width = 20;
  info.getColumn(2).width = 55;

  // ── Save ─────────────────────────────────────────────────────────────────
  const outFile = path.join(__dirname, 'prijsvergelijking-plisse-hordeuren.xlsx');
  await wb.xlsx.writeFile(outFile);
  console.log(`\n✅ Excel opgeslagen: prijsvergelijking-plisse-hordeuren.xlsx`);
};
