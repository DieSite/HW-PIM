/**
 * normalize.test.js – unit tests voor de vorm-bewuste normalisatie/matching.
 *
 * Draaien: npm run volledig:test   (node --test, geen extra dependencies)
 */

const { test } = require('node:test');
const assert   = require('node:assert');
const fs       = require('node:fs');
const os       = require('node:os');
const path     = require('node:path');

const { detectShape, normModel, parseSize, designNumbers, numbersCompatible, hasModelNameToken, containsAllTokens, pageMatchesEntry } = require('./normalize');
const { loadCatalog } = require('./catalog');

test('detectShape herkent vormen in modelnaam, maat, titel en slug', () => {
  assert.equal(detectShape('Diamante 01 Oval'), 'ovaal');
  assert.equal(detectShape('Brush Ovale 13'), 'ovaal');
  assert.equal(detectShape('Ovaal 200 cm x 290 cm'), 'ovaal');
  assert.equal(detectShape('mart-visser-vloerkleed-brush-ovaal-200x290cm-beige'), 'ovaal');
  assert.equal(detectShape('Montgomery 2787 Rond'), 'rond');
  assert.equal(detectShape('Rond 200 cm'), 'rond');
  assert.equal(detectShape('rond-wollen-vloerkleed-vincere-04-de-munk-carpets'), 'rond');
  assert.equal(detectShape('Ø 160 cm'), 'rond');
  assert.equal(detectShape('Loper 80 x 300'), 'loper');
  assert.equal(detectShape('Gentle 13 Ellips'), 'ovaal');
  assert.equal(detectShape('Gentle 13 Organic'), 'organisch');
  assert.equal(detectShape('Vernon 18 - Honey Custard in Organische Vorm'), 'organisch');
  assert.equal(detectShape('Diamante 01', '200 cm x 250 cm'), null);
  // "rond"/"oval" mag niet in woorddelen matchen
  assert.equal(detectShape('Gironde 12'), null);
  assert.equal(detectShape('Ovalis'), null);
});

test('normModel stript vormwoorden zodat vorm een aparte matchdimensie is', () => {
  assert.equal(normModel('Diamante 01 Oval'), normModel('Diamante 01'));
  assert.equal(normModel('Montgomery 2787 Rond'), 'montgomery 2787');
  assert.equal(normModel('rond wollen vincere 04'), 'wollen vincere 04');
  // Accenten worden getranslitereerd, niet tot losse tokens gehakt
  assert.equal(normModel('Suède Shades 11 - Shimmer Sand'), 'suede shades 11 shimmer sand');
  assert.equal(normModel('Cendré 21'), 'cendre 21');
});

test('parseSize parseert rechthoek- en rondmaten', () => {
  assert.deepEqual(parseSize('200 cm x 290 cm'), { widthCm: 200, heightCm: 290 });
  assert.deepEqual(parseSize('Ovaal 200 cm x 290 cm'), { widthCm: 200, heightCm: 290 });
  assert.deepEqual(parseSize('Rond 200 cm'), { widthCm: 200, heightCm: 200 });
  assert.deepEqual(parseSize('200 cm rond'), { widthCm: 200, heightCm: 200 });
  assert.deepEqual(parseSize('Ø 160'), { widthCm: 160, heightCm: 160 });
  assert.equal(parseSize('Maatwerk'), null);
  assert.equal(parseSize('Rond Maatwerk'), null);
  assert.equal(parseSize('10 x 15 cm'), null);
});

test('designNumbers negeert maatparen en product-IDs', () => {
  assert.deepEqual(designNumbers('brush ocker brown 69'), ['69']);
  assert.deepEqual(designNumbers('mart visser cender vintage oker 69 142239675'), ['69']);
  assert.deepEqual(designNumbers('brush ovaal 200x290cm beige'), []);
  assert.deepEqual(designNumbers('aspen 7270 160-x-230-cm'), ['7270']);
  assert.deepEqual(designNumbers('gentle'), []);
});

test('numbersCompatible verwerpt botsende kleurnummers', () => {
  // Brush Ovale-bug: kleur 13/32 kregen de prijs van de 69-pagina
  assert.equal(numbersCompatible('brush 13', 'brush ocker brown 69'), false);
  assert.equal(numbersCompatible('brush 13', 'brush chalk beige 13'), true);
  // Zero-padding is hetzelfde kleurnummer; een echt ander nummer niet
  assert.equal(numbersCompatible('casablanca 1', 'casablanca c 01 beige creme'), true);
  assert.equal(numbersCompatible('diamante 01', 'de munk carpets diamante 1 141125241'), true);
  assert.equal(numbersCompatible('milano 11', 'de munk carpets milano 1 141125287'), false);
  assert.equal(numbersCompatible('brush 32', 'brush ovaal 200x290cm beige'), true); // slug zonder kleurnummer: geen oordeel
  assert.equal(numbersCompatible('aspen 7270', 'aspen 7270 160 230'), true);
  assert.equal(numbersCompatible('gentle', 'gentle 13'), true);
});

test('hasModelNameToken eist de modelnaam zelf in titel of slug', () => {
  // kleed.nl-bug: "Prosper 69 - Vintage Copper" koppelde aan de Cendre-pagina
  assert.equal(hasModelNameToken('mart visser cender vintage oker 69 142239675', 'prosper 69 vintage copper'), false);
  assert.equal(hasModelNameToken('mart visser prosper vintage copper 69', 'prosper 69 vintage copper'), true);
  assert.equal(hasModelNameToken('brush chalk beige 13', 'brush 13'), true);
  assert.equal(hasModelNameToken('wollen vloerkleed diamante 01 de munk carpets', 'diamante 01'), true);
  assert.equal(hasModelNameToken('wollen vloerkleed milano 04 de munk carpets', 'diamante 01'), false);
});

test('structuurvarianten krijgen verplichte extra tokens (mustHave)', () => {
  const csv = [
    'KP00350.1,Karpi,Gentle 13,160 cm x 230 cm,599',
    'KP00350.Plaza.1,Karpi,Gentle 13 Plaza,160 cm x 230 cm,919',
    'KP00350.Organic.1,Karpi,Gentle 13 Organic,160 cm x 230 cm,919',
    'KP00350.Ellips.1,Karpi,Gentle 13 Ellips,160 cm x 230 cm,919',
    'KP00200.1,Mart Visser,Prosper 69 - Vintage Copper,160 cm x 230 cm,359',
  ].join('\n');
  const tmp = path.join(os.tmpdir(), `catalog-musthave-test-${process.pid}.csv`);
  fs.writeFileSync(tmp, csv);
  try {
    const catalog = loadCatalog(tmp);

    // "Gentle 13 Plaza" mag alleen matchen als "plaza" in de competitor-tekst staat
    const plaza = catalog.bySku.get('KP00350.Plaza.1');
    assert.deepEqual(plaza.mustHave, ['plaza']);
    assert.equal(containsAllTokens('hoogpolig vloerkleed gentle 13 karpi', plaza.mustHave), false);
    assert.equal(containsAllTokens('gentle 13 plaza karpi', plaza.mustHave), true);

    // Het basismodel zelf heeft geen verplichte extra tokens
    assert.deepEqual(catalog.bySku.get('KP00350.1').mustHave, []);

    // "Ellips" en "Organic" zijn vormen, geen structuurwoorden: zelfde
    // model-key, andere shape
    assert.equal(catalog.bySku.get('KP00350.Ellips.1').shape, 'ovaal');
    assert.equal(catalog.bySku.get('KP00350.Organic.1').shape, 'organisch');
    assert.equal(catalog.bySku.get('KP00350.Organic.1').normModel, 'gentle 13');

    // Zonder kale naamgenoot geen verplichte tokens (kleurnaam-suffix blijft optioneel)
    assert.deepEqual(catalog.bySku.get('KP00200.1').mustHave, []);
  } finally {
    fs.unlinkSync(tmp);
  }
});

test('pageMatchesEntry keurt de verkeerde productpagina af op de titel', () => {
  const babylon = { normModel: 'fading world babylon 8545', mustHave: [] };
  // volero-bug: slug zonder nummer, titel toont het echte (andere) dessin
  assert.equal(pageMatchesEntry(
    'Vintage vloerkleed - The Fading World Pink Flash 8261',
    'https://www.volero.nl/louis-de-poortere-vloerkleed-the-fading-world-pink.html',
    babylon
  ), false);
  assert.equal(pageMatchesEntry(
    'Vintage vloerkleed - The Fading World Babylon 8545',
    'https://www.volero.nl/louis-de-poortere-vloerkleed-the-fading-world-babylon.html',
    babylon
  ), true);
  // HTML-entities mogen geen nep-dessinnummer opleveren
  assert.equal(pageMatchesEntry(
    'Mart Visser Vloerkleed Prosper kopen? &#9193; Giga Meubel',
    'https://www.gigameubel.nl/mart-visser-vloerkleed-prosper-155x230cm-wit',
    { normModel: 'prosper 23 wolf grey', mustHave: [] }
  ), true);
  // Accenten in de titel breken de modelnaam-check niet
  assert.equal(pageMatchesEntry(
    'Mart Visser Suède Shades 11 - Shimmer Sand',
    'https://www.floorpassion.nl/mart-visser-suede-shades-11-shimmer-sand.html',
    { normModel: 'suede shades 11', mustHave: [] }
  ), true);
  // floorpassion-bug: de organisch-gevormde variant (€759) prijsde onze
  // rechthoekige Vernon (advies €429)
  assert.equal(pageMatchesEntry(
    'Vernon 18 - Honey Custard in Organische Vorm - Floorpassion',
    'https://www.floorpassion.nl/vernon-18-honey-custard-in-organische-vorm.html',
    { normModel: 'vernon 18 honey custard', mustHave: [], shape: 'rechthoek' }
  ), false);
  assert.equal(pageMatchesEntry(
    'Mart Visser Vloerkleed Vernon 18 - Honey Custard - Floorpassion',
    'https://www.floorpassion.nl/vernon-18-honey-custard.html',
    { normModel: 'vernon 18 honey custard', mustHave: [], shape: 'rechthoek' }
  ), true);
});

test('loadCatalog geeft elke entry een vorm en prijst ovaal/rond niet als rechthoek', () => {
  const csv = [
    'DMC0013.2,De Munk,Diamante 01,200 cm x 250 cm,979',
    'DMC0014.Oval.2,De Munk,Diamante 01 Oval,200 cm x 250 cm,1049',
    'ERG9561.Rond.1,Eurogros,Montgomery 2787 Rond,Rond 200 cm,299',
    'KP00350.R,Karpi,Gentle 13,Rond Maatwerk,209',
  ].join('\n');
  const tmp = path.join(os.tmpdir(), `catalog-shape-test-${process.pid}.csv`);
  fs.writeFileSync(tmp, csv);
  try {
    const catalog = loadCatalog(tmp);

    assert.equal(catalog.bySku.get('DMC0013.2').shape, 'rechthoek');
    assert.equal(catalog.bySku.get('DMC0014.Oval.2').shape, 'ovaal');
    assert.equal(catalog.bySku.get('ERG9561.Rond.1').shape, 'rond');
    assert.deepEqual(
      { w: catalog.bySku.get('ERG9561.Rond.1').widthCm, h: catalog.bySku.get('ERG9561.Rond.1').heightCm },
      { w: 200, h: 200 }
    );
    // Rond Maatwerk blijft maatwerk (geen vaste maat)
    assert.equal(catalog.bySku.get('KP00350.R').isMaatwerk, true);
    assert.equal(catalog.fixedEntries.length, 3);

    // Rechthoek en ovaal delen één model-key (vormwoorden gestript) maar
    // blijven onderscheidbaar via entry.shape — dezelfde maat, andere vorm.
    const key = catalog.bySku.get('DMC0013.2').normBrand + '|' + catalog.bySku.get('DMC0013.2').normModel;
    const entries = catalog.models.get(key);
    assert.equal(entries.length, 2);
    const shapes = new Set(entries.map(e => e.shape));
    assert.deepEqual([...shapes].sort(), ['ovaal', 'rechthoek']);
  } finally {
    fs.unlinkSync(tmp);
  }
});
