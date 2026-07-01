/**
 * excel.js – bouwt concurrenten-volledig.xlsx vanuit de DB.
 *
 * Formaat (zelfde stijl als karpettenExcel.js):
 *   - Per merk één sheet: "De Munk", "Eurogros", "Karpi", "Louis De Poortere", "Desso"
 *   - Rijen: (model, maat) met vaste afmeting
 *   - Kolommen: Merk | Model | Maat | Eigen prijs | <alle shops>
 *   - Kleuring t.o.v. eigen prijs: rood = concurrent goedkoper, groen = duurder
 *   - "Vanaf"-prijzen cursief, niet gekleurd
 *   - Bronnen-sheet per merk
 *   - Samenvatting-sheet: top goedkoopste concurrenten per merk
 *
 * Aanroepen:
 *   node catalog-volledig/excel.js
 */

const ExcelJS = require('exceljs');
const path    = require('path');
const { openDb, collectPrices } = require('./storage');
const { loadCatalog }  = require('./catalog');
const { isRealPrice, isVanaf, euroNum } = require('./normalize');
const { ALL_SHOP_KEYS } = require('./shops');

const CSV_PATH = process.env.CATALOG_CSV || path.join(__dirname, '..', '..', 'HW-PIM', 'Result_6.csv');
const OUT_FILE = path.join(__dirname, '..', 'concurrenten-volledig.xlsx');

// ── Stijlconstanten (zelfde als hordeuren/karpetten) ─────────────────────────
const BLUE     = 'FF2E75B6', WHITE = 'FFFFFFFF';
const ROW_A    = 'FFF2F7FB', ROW_B = 'FFFFFFFF';
const RED_BG   = 'FFFFC7CE', RED_TXT   = 'FF9C0006';
const GREEN_BG = 'FFC6EFCE', GREEN_TXT = 'FF006100';
const EQUAL_BG = 'FFFFEB9C', EQUAL_TXT = 'FF9C6500';
const EIGEN_BG = 'FFDDEBF7';
const BORDER   = { style: 'thin', color: { argb: 'FFDDDDDD' } };
const bordered = c => { c.border = { top: BORDER, bottom: BORDER, left: BORDER, right: BORDER }; };

const fmtEuro = n => `€ ${Number(n).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?=,))/g, '.')}`;

function styleHeader(row) {
  row.height = 36;
  row.eachCell(c => {
    c.font      = { bold: true, color: { argb: WHITE }, name: 'Arial', size: 9 };
    c.fill      = { type: 'pattern', pattern: 'solid', fgColor: { argb: BLUE } };
    c.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true };
    bordered(c);
  });
}

// Kolomlabels (afgekorte domeinnamen)
const SHOP_LABELS = {};
for (const key of ALL_SHOP_KEYS) {
  SHOP_LABELS[key] = key.replace('.nl', '').replace('.com', '').replace('www.', '').slice(0, 20);
}

/** Voeg een prijsvergelijking-sheet toe aan de workbook. */
function addPrijsSheet(wb, sheetName, rows, prices, shops) {
  const ws = wb.addWorksheet(sheetName);
  styleHeader(ws.addRow(['Merk', 'Model', 'Maat', 'Eigen prijs', ...shops.map(s => SHOP_LABELS[s] ?? s)]));
  ws.views = [{ state: 'frozen', xSplit: 4, ySplit: 1 }];

  rows.forEach(({ brand, model, sizeLabel, widthCm, heightCm, price, sku }, i) => {
    const waarden = shops.map(shop => {
      const r = prices[sku]?.[shop];
      return r?.priceStr ?? '';
    });
    const displayBrand = brand === 'Mart Visser|Karpi' ? 'Mart Visser' : brand;
    const row = ws.addRow([displayBrand, model, sizeLabel, fmtEuro(price), ...waarden]);
    row.height = 20;
    const bg = i % 2 === 0 ? ROW_A : ROW_B;

    row.eachCell((cell, colNum) => {
      cell.font      = { name: 'Arial', size: 9 };
      cell.fill      = { type: 'pattern', pattern: 'solid', fgColor: { argb: bg } };
      cell.alignment = { horizontal: colNum <= 2 ? 'left' : 'center', vertical: 'middle' };

      if (colNum === 4) {
        cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: EIGEN_BG } };
        cell.font = { name: 'Arial', size: 9, bold: true };
      } else if (colNum > 4) {
        const v = String(cell.value ?? '');
        const p = euroNum(v);
        if (p !== null && !isVanaf(v)) {
          const [fill, txt] = p < price ? [RED_BG, RED_TXT]
                            : p > price ? [GREEN_BG, GREEN_TXT]
                            : [EQUAL_BG, EQUAL_TXT];
          cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: fill } };
          cell.font = { name: 'Arial', size: 9, color: { argb: txt } };
        } else if (isVanaf(v)) {
          cell.font = { name: 'Arial', size: 8, italic: true };
        }
      }
      bordered(cell);
    });
  });

  // Kolombreedte
  ws.getColumn(1).width = 14;
  ws.getColumn(2).width = 22;
  ws.getColumn(3).width = 14;
  ws.getColumn(4).width = 12;
  shops.forEach((_, i) => { ws.getColumn(i + 5).width = 14; });

  return ws;
}

/** Voeg een bronnen-sheet toe. */
function addBronSheet(wb, sheetName, rows, prices, shops) {
  const bron = wb.addWorksheet(`${sheetName} – Bronnen`);
  styleHeader(bron.addRow(['Merk', 'Model', 'Maat', ...shops.map(s => SHOP_LABELS[s] ?? s)]));
  bron.views = [{ state: 'frozen', xSplit: 3, ySplit: 1 }];

  rows.forEach(({ brand, model, sizeLabel, sku }, i) => {
    const displayBrand = brand === 'Mart Visser|Karpi' ? 'Mart Visser' : brand;
    const row = bron.addRow([displayBrand, model, sizeLabel, ...shops.map(shop => {
      return prices[sku]?.[shop]?.url ?? '';
    })]);
    row.height = 18;
    const bg = i % 2 === 0 ? ROW_A : ROW_B;
    row.eachCell((cell, colNum) => {
      cell.font  = { name: 'Arial', size: 7 };
      cell.fill  = { type: 'pattern', pattern: 'solid', fgColor: { argb: bg } };
      cell.alignment = { horizontal: 'left', vertical: 'middle' };
      if (colNum > 3 && /^https?:\/\//.test(String(cell.value))) {
        const url = String(cell.value);
        cell.value = { text: url.replace(/^https?:\/\/(www\.)?/, '').slice(0, 60), hyperlink: url };
        cell.font  = { name: 'Arial', size: 7, color: { argb: 'FF0563C1' }, underline: true };
      }
      bordered(cell);
    });
  });
  bron.getColumn(1).width = 14;
  bron.getColumn(2).width = 22;
  bron.getColumn(3).width = 14;
  shops.forEach((_, i) => { bron.getColumn(i + 4).width = 40; });
}

// ── Hoofd ─────────────────────────────────────────────────────────────────────

async function buildExcel() {
  const db      = openDb();
  const catalog = loadCatalog(CSV_PATH);
  const prices  = collectPrices(db);

  // Alle shops die daadwerkelijk prijzen hebben in de DB
  const activeShops = ALL_SHOP_KEYS.filter(shop =>
    catalog.fixedEntries.some(e => isRealPrice(prices[e.sku]?.[shop]?.priceStr))
  );

  console.log(`Actieve shops met echte prijzen: ${activeShops.length}`);

  const wb = new ExcelJS.Workbook();
  wb.creator = 'Concurrentieanalyse Karpetten – Volledig';
  wb.created = new Date();

  // Per merk een sheet
  const MERKEN = ['De Munk', 'Eurogros', 'Karpi', 'Mart Visser', 'Louis De Poortere', 'Desso'];

  for (const merk of MERKEN) {
    const rows = catalog.fixedEntries.filter(e => {
      // "Mart Visser|Karpi" merkeentries mappen op 'Mart Visser'
      return e.brand === merk || (merk === 'Mart Visser' && e.brand === 'Mart Visser|Karpi');
    });
    if (!rows.length) continue;

    // Shops die minstens één echte prijs hebben voor dit merk
    const merkShops = activeShops.filter(shop =>
      rows.some(e => isRealPrice(prices[e.sku]?.[shop]?.priceStr))
    );
    if (!merkShops.length) {
      console.log(`  ${merk}: geen prijzen gevonden, sheet overgeslagen`);
      continue;
    }

    // Sorteer op model, dan maat
    rows.sort((a, b) => a.model.localeCompare(b.model) || a.widthCm - b.widthCm || a.heightCm - b.heightCm);

    console.log(`  ${merk}: ${rows.length} rijen, ${merkShops.length} shops`);
    addPrijsSheet(wb, merk, rows, prices, merkShops);
    addBronSheet(wb, merk, rows, prices, merkShops);
  }

  // Info-sheet
  const info = wb.addWorksheet('Info');
  [
    ['Gegenereerd op', new Date().toLocaleString('nl-NL')],
    ['Catalogus',  `${catalog.fixedEntries.length} vaste maten uit PIM-export`],
    ['Shops',      activeShops.join(', ')],
    [''],
    ['KLEURLEGENDA', ''],
    ['Rood',       'Concurrent is GOEDKOPER (per maat)'],
    ['Groen',      'Concurrent is DUURDER'],
    ['Geel',       'Exact gelijk'],
    ['Cursief',    '"Vanaf €" – kleinste maat, niet maat-vergelijkbaar → niet gekleurd'],
    ['Leeg',       'Shop verkoopt dit product niet (of nog niet gescrapet)'],
    [''],
    ['Draaien',    'node catalog-volledig/run.js  (index → prijzen → excel)'],
    ['Herstarten', 'node catalog-volledig/index-shops.js --reset'],
  ].forEach(r => info.addRow(r));
  info.getColumn(1).width = 18;
  info.getColumn(2).width = 90;

  await wb.xlsx.writeFile(OUT_FILE);
  console.log(`\n✅ Excel geschreven: ${OUT_FILE}`);
  return OUT_FILE;
}

buildExcel().catch(e => { console.error(e); process.exit(1); });

module.exports = { buildExcel };
