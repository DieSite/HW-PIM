/**
 * karpettenExcel.js – bouwt concurrenten-karpetten.xlsx in HETZELFDE formaat
 * als prijsvergelijking-plisse-hordeuren.xlsx:
 *
 *  - "Prijsvergelijking": producten (26 karpetten) als rijen, shops als
 *    kolommen. Kleuring t.o.v. onze adviesverkoopprijs: concurrent goedkoper
 *    = rood, duurder = groen, gelijk = geel. "Vanaf"-prijzen en onderzoeks-✓
 *    worden NIET gekleurd (niet maat-vergelijkbaar).
 *  - "Bronnen": zelfde matrix, cellen = de gescrapete productpagina (link).
 *  - "Top 10": de concurrentenranking + watchlist.
 *  - "Info": methode en legenda.
 *
 * buildKarpettenWorkbook(scraped) krijgt de output van collectKarpetten():
 * { shop: { model: { prijs, url } } }. Een echte €-prijs overschrijft het
 * onderzoeks-✓ in de cel; n.v.t. laat het ✓ staan.
 */

const ExcelJS = require('exceljs');
const path    = require('path');
const { TOP10, WATCH, MODELLEN, SHOPS, researchLookup } = require('./karpetten-data');

const BLUE  = 'FF2E75B6', WHITE = 'FFFFFFFF', ROW_A = 'FFF2F7FB', ROW_B = 'FFFFFFFF';
const RED_BG   = 'FFFFC7CE', RED_TXT   = 'FF9C0006';
const GREEN_BG = 'FFC6EFCE', GREEN_TXT = 'FF006100';
const EQUAL_BG = 'FFFFEB9C', EQUAL_TXT = 'FF9C6500';
const EIGEN_BG = 'FFDDEBF7';
const BORDER = { style: 'thin', color: { argb: 'FFDDDDDD' } };
const bordered = c => { c.border = { top: BORDER, bottom: BORDER, left: BORDER, right: BORDER }; };

const isRealPrice = v => /€\s*\d/.test(String(v || ''));
const isVanaf     = v => /^vanaf/i.test(String(v || '').trim());
function euroNum(v) {
  const m = String(v ?? '').match(/€\s*([\d.]+)(?:,(\d{2}))?/);
  return m ? parseFloat(m[1].replace(/\./g, '') + '.' + (m[2] || '00')) : null;
}
const fmt = n => `€ ${Number(n).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?=,))/g, '.')}`;

function styleHeader(row) {
  row.height = 36;
  row.eachCell(c => {
    c.font = { bold: true, color: { argb: WHITE }, name: 'Arial', size: 10 };
    c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: BLUE } };
    c.alignment = { horizontal: 'center', vertical: 'middle', wrapText: true };
    bordered(c);
  });
}

async function buildKarpettenWorkbook(scraped = {}) {
  const research = researchLookup();
  const wb = new ExcelJS.Workbook();
  wb.creator = 'Concurrentieanalyse karpetten';
  wb.created = new Date();

  // ── Prijsvergelijking (hordeuren-formaat) ────────────────────────────────
  const ws = wb.addWorksheet('Prijsvergelijking');
  styleHeader(ws.addRow(['Merk', 'Model', 'Maat', 'Eigen prijs (advies)', ...SHOPS.map(s => s.label)]));

  MODELLEN.forEach(({ merk, model, maat, eigen }, i) => {
    const waarden = SHOPS.map(({ key }) => {
      const live = scraped[key]?.[model];
      if (live && isRealPrice(live.prijs)) return live.prijs;
      return research[key]?.[model] ?? '';
    });
    const row = ws.addRow([merk, model, maat, fmt(eigen), ...waarden]);
    row.height = 24;
    const bg = i % 2 === 0 ? ROW_A : ROW_B;
    row.eachCell((cell, colNum) => {
      cell.font      = { name: 'Arial', size: 10 };
      cell.fill      = { type: 'pattern', pattern: 'solid', fgColor: { argb: bg } };
      cell.alignment = { horizontal: colNum <= 2 ? 'left' : 'center', vertical: 'middle' };

      if (colNum === 4) {                 // eigen prijs = referentiekolom
        cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: EIGEN_BG } };
        cell.font = { name: 'Arial', size: 10, bold: true };
      } else if (colNum > 4) {
        const v = String(cell.value || '');
        const p = euroNum(v);
        // alleen exacte (niet-"Vanaf") prijzen kleuren — vanafprijzen zijn de
        // kleinste maat en dus niet vergelijkbaar met onze 200x290-prijs
        if (p != null && !isVanaf(v)) {
          const [fill, txt] = p < eigen ? [RED_BG, RED_TXT]
                            : p > eigen ? [GREEN_BG, GREEN_TXT]
                            : [EQUAL_BG, EQUAL_TXT];
          cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: fill } };
          cell.font = { name: 'Arial', size: 10, color: { argb: txt } };
        } else if (isVanaf(v)) {
          cell.font = { name: 'Arial', size: 9, italic: true };
        }
      }
      bordered(cell);
    });
  });
  ws.getColumn(1).width = 10; ws.getColumn(2).width = 14;
  ws.getColumn(3).width = 9;  ws.getColumn(4).width = 13;
  SHOPS.forEach((_, i) => { ws.getColumn(i + 5).width = 14; });

  // ── Bronnen (zelfde matrix, cellen = gebruikte pagina) ───────────────────
  const bron = wb.addWorksheet('Bronnen');
  styleHeader(bron.addRow(['Merk', 'Model', 'Maat', ...SHOPS.map(s => s.label)]));
  MODELLEN.forEach(({ merk, model, maat }, i) => {
    const row = bron.addRow([merk, model, maat, ...SHOPS.map(({ key }) => scraped[key]?.[model]?.url ?? '')]);
    row.height = 22;
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
  bron.getColumn(1).width = 10; bron.getColumn(2).width = 14; bron.getColumn(3).width = 9;
  SHOPS.forEach((_, i) => { bron.getColumn(i + 4).width = 36; });

  // ── Top 10 + watchlist ───────────────────────────────────────────────────
  const top = wb.addWorksheet('Top 10');
  styleHeader(top.addRow(['#', 'Webshop', 'Merken', 'Dekking van ons assortiment', 'Prijsbeeld', 'Waarom belangrijk']));
  const zebra = (row, i, leftCols) => row.eachCell((c, n) => {
    c.font = { name: 'Arial', size: 10 };
    c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: i % 2 === 0 ? ROW_A : ROW_B } };
    c.alignment = { horizontal: n <= leftCols ? 'left' : 'left', vertical: 'middle', wrapText: true };
    bordered(c);
  });
  TOP10.forEach((r, i) => { const row = top.addRow(r); row.height = 34; zebra(row, i, 2); });
  top.addRow([]);
  const wh = top.addRow(['', 'WATCHLIST', '', '', '', '']);
  wh.getCell(2).font = { bold: true, name: 'Arial', size: 10 };
  WATCH.forEach((r, i) => { const row = top.addRow(['', r[0], r[1], r[2], '', '']); row.height = 26; zebra(row, i, 2); });
  top.getColumn(1).width = 4; top.getColumn(2).width = 26; top.getColumn(3).width = 24;
  top.getColumn(4).width = 52; top.getColumn(5).width = 36; top.getColumn(6).width = 56;

  // ── Info ─────────────────────────────────────────────────────────────────
  const info = wb.addWorksheet('Info');
  [
    ['Gegenereerd op', new Date().toLocaleString('nl-NL')],
    ['Assortiment', '26 goedlopende karpetten van huis-en-wonen.nl (8 Eurogros, 8 De Munk, 10 Karpi incl. Mart Visser)'],
    ['Eigen prijs', 'Adviesverkoopprijs 200x290 uit "Goedlopende karpetten.xlsx" — de referentie voor de kleuring'],
    ['', ''],
    ['KLEURLEGENDA', 'T.o.v. onze adviesprijs per model'],
    ['Rood', 'Concurrent is GOEDKOPER (live gescrapete, maat-exacte prijs)'],
    ['Groen', 'Concurrent is DUURDER'],
    ['Geel', 'Exact dezelfde prijs'],
    ['Cursief "Vanaf €"', 'Live gescrapet, maar prijs van de kleinste maat — niet maat-vergelijkbaar, dus niet gekleurd'],
    ['✓ (zonder prijs)', 'Aangeboden volgens handmatig webonderzoek (juni 2026), nog niet live gescrapet; ✓* = via merkpagina'],
    ['', ''],
    ['Scrape', 'npm run test:karpetten — Shopify-shops: exacte maatvariant-prijs; overige: JSON-LD productpagina'],
    ['Maten', 'Eurogros/Karpi: 200x290. De Munk: concurrenten prijzen 200x300 (het merk kent geen 200x290)'],
    ['Let op', 'Eurogros-producten circuleren ook onder labels "Antoin Carpets" en Lano; match op model + kleurcode + maattabel'],
  ].forEach(r => info.addRow(r));
  info.getColumn(1).width = 22; info.getColumn(2).width = 110;

  const outFile = path.join(__dirname, 'concurrenten-karpetten.xlsx');
  await wb.xlsx.writeFile(outFile);
  return outFile;
}

module.exports = { buildKarpettenWorkbook };
