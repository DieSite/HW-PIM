/**
 * Bewaakt de kolommen van de hordeuren-Excel: `node --test kolommen.test.js`
 * (of `npm run test:kolommen`).
 *
 * WAAROM: de koppeling spec -> Excelkolom is puur stringconventie. De spec
 * schrijft met `recordPrice(COMP, ...)` en globalTeardown zoekt die waarde op
 * via `COMPETITORS[].key`. Wijkt er één teken af, dan blijft de kolom leeg
 * ("–") zonder dat er iets faalt — pas in de gemailde Excel valt het op, na een
 * run van een kwartier. En omdat `RunHordeurenAnalysisJob` de specs met een glob
 * over `tests/*.spec.js` ontdekt, levert een nieuw spec-bestand wél een
 * queue-job maar géén kolom.
 *
 * Deze test draait browserloos in milliseconden en vangt dat vooraf af.
 *
 * De volgorde hieronder is een KLANTWENS (vastgesteld 30-07-2026, herbevestigd
 * 04-08-2026), geen kopie van de productiecode: bij een gewenste wijziging pas
 * je bewust beide kanten aan.
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const { COMPETITORS, SOURCES } = require('./globalTeardown');

/** "Eigen winkel" staat bewust vooraan: dat is de referentiekolom. */
const GEWENSTE_VOLGORDE = [
  'Eigen winkel',
  'Horrengigant',
  'Horren.com',
  'Koopje-Horren',
  'Qniq',
  'Luxehorren',
  'Horrentotaal',
  'Creon Kozijnen',
  'Horrenconcurrent',
  'Horrenstunter',
  'Decozijn',
  'Handige Horren',
  'Solano Wonen',
  'Raamdecoratie',
];

/** Bewust geschrapte bronnen; mogen niet terugsluipen. */
const VERWIJDERD = [
  { label: 'Praxis',  reden: 'klantverzoek 30-07-2026' },
  { label: 'Gamma',   reden: 'assortiment stopt bij 150 cm' },
];

const TESTS_DIR = path.join(__dirname, 'tests');

/**
 * De concurrent-key waar een spec naartoe schrijft, of null.
 *
 * Twee schrijfwijzen in omloop: de per-maat specs zetten `const COMP = '…'`, de
 * dunne specs op de factories uit `_vanaf.js` geven `{ comp: '…' }` mee.
 */
function compVanSpec(bestand) {
  const src = fs.readFileSync(path.join(TESTS_DIR, bestand), 'utf8');
  const m = src.match(/^const COMP\s*=\s*['"]([^'"]+)['"]/m)
    ?? src.match(/\bcomp:\s*['"]([^'"]+)['"]/);

  return m ? m[1] : null;
}

const specBestanden = fs.readdirSync(TESTS_DIR).filter((f) => f.endsWith('.spec.js'));

test('de kolommen staan in de door de klant vastgestelde volgorde', () => {
  assert.deepStrictEqual(COMPETITORS.map((c) => c.label), GEWENSTE_VOLGORDE);
});

test('geschrapte bronnen komen niet terug als kolom of spec', () => {
  for (const { label, reden } of VERWIJDERD) {
    assert.ok(
      !COMPETITORS.some((c) => c.label.toLowerCase() === label.toLowerCase()),
      `${label} hoort geen kolom te hebben (${reden})`
    );
    assert.ok(
      !specBestanden.some((f) => f.toLowerCase().includes(label.toLowerCase())),
      `${label} hoort geen spec-bestand te hebben (${reden})`
    );
  }
});

test('elke kolom heeft een spec die exact dezelfde key wegschrijft', () => {
  const comps = new Set(specBestanden.map(compVanSpec).filter(Boolean));

  for (const { key, label } of COMPETITORS) {
    assert.ok(
      comps.has(key),
      `kolom "${label}" verwacht recordPrice('${key}', ...), maar geen enkele spec gebruikt die key — de kolom zou leeg blijven`
    );
  }
});

test('elke spec hoort bij een kolom', () => {
  const keys = new Set(COMPETITORS.map((c) => c.key));

  for (const bestand of specBestanden) {
    const comp = compVanSpec(bestand);
    assert.ok(comp, `${bestand} heeft geen herkenbare \`const COMP = '…'\``);
    assert.ok(
      keys.has(comp),
      `${bestand} schrijft naar '${comp}', maar dat is geen kolom — de scrape kost wel een queue-job en levert niets op`
    );
  }
});

test('elke kolom heeft een bronpagina voor de Bronnen-tab', () => {
  for (const { key, label } of COMPETITORS) {
    const bron = SOURCES[key];
    assert.ok(bron, `kolom "${label}" (${key}) mist een SOURCES-entry`);

    const urls = typeof bron === 'string' ? [bron] : [bron.enkel, bron.dubbel];
    for (const url of urls) {
      assert.match(url ?? '', /^https:\/\//, `bronpagina van "${label}" is geen https-URL: ${url}`);
    }
  }
});
