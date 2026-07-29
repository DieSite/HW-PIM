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

const { detectShape, normModel, parseSize, designNumbers, numbersCompatible, hasModelNameToken, containsAllTokens, pageMatchesEntry, colorWords, colorsCompatible } = require('./normalize');
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
    { normModel: 'prosper 21 cyprus white', mustHave: [] }
  ), true);
  // …maar de kleur in de slug telt wél mee: gigameubel noemt geen dessinnummer,
  // dus zonder kleurcheck kreeg élke Prosper-kleur de prijs van de witte pagina.
  assert.equal(pageMatchesEntry(
    'Mart Visser Vloerkleed Prosper kopen? &#9193; Giga Meubel',
    'https://www.gigameubel.nl/mart-visser-vloerkleed-prosper-155x230cm-wit',
    { normModel: 'prosper 25 black', mustHave: [] }
  ), false);
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

test('karpettenkelder prijst een maat zonder vormwoord als rechthoek, maar geen speciale vorm', () => {
  const { CUSTOM_SHOPS } = require('./shops');
  const kk = CUSTOM_SHOPS.find(s => s.key === 'karpettenkelder.nl');

  // Echte markup van karpettenkelder.nl: de vorm staat er soms wél
  // ("200 x 300 rechthoek") en soms níet ("250 x 300").
  const html = `
    <li class="maat-item-to-filter" data-title="200 x 300 rechthoek">
      <input name="CardItem.MaatId" value="24" data-prijs="2349.00" type="radio" />
    </li>
    <li class="maat-item-to-filter" data-title="250 x 300">
      <input name="CardItem.MaatId" value="28" data-prijs="2939.00" type="radio" />
    </li>
    <li class="maat-item-to-filter" data-title="200 x 290 ovaal">
      <input name="CardItem.MaatId" value="31" data-prijs="1899.00" type="radio" />
    </li>
    <li class="maat-item-to-filter" data-title="160 x 230 core speciale vorm">
      <input name="CardItem.MaatId" value="44" data-prijs="1199.00" type="radio" />
    </li>`;

  assert.equal(kk.getPrijs(html, 200, 300, 'rechthoek'), '€ 2.349,00');
  // Kale maat = rechthoek; vóór deze fix leverde dit null en dus een gat.
  assert.equal(kk.getPrijs(html, 250, 300, 'rechthoek'), '€ 2.939,00');
  assert.equal(kk.getPrijs(html, 200, 290, 'ovaal'), '€ 1.899,00');
  // Een onbekend achtervoegsel is géén rechthoek en mag geen prijs opleveren.
  assert.equal(kk.getPrijs(html, 160, 230, 'rechthoek'), null);
  // Een ovale maat mag nooit de rechthoekprijs pakken.
  assert.equal(kk.getPrijs(html, 250, 300, 'ovaal'), null);
});

test('colorsCompatible verwerpt botsende kleurNAMEN, NL en EN door elkaar', () => {
  // Love Shaggy onderscheidt zich alleen door een kleurwoord: zonder deze
  // check kregen Taupe/Antraciet/Lichtbruin de prijs van de beige pagina.
  assert.equal(colorsCompatible('love shaggy taupe', 'karpet love shaggy beige'), false);
  assert.equal(colorsCompatible('love shaggy beige', 'karpet love shaggy beige'), true);
  // NL/EN zijn dezelfde kleur; "Prosper 25 - Black" mag niet van de witte pagina.
  assert.equal(colorsCompatible('prosper 25 black', 'mart visser prosper 200x290cm wit'), false);
  assert.equal(colorsCompatible('prosper 25 black', 'mart visser prosper zwart'), true);
  // Geen kleur aan één van beide kanten = geen oordeel.
  assert.equal(colorsCompatible('montgomery 2787', 'montgomery 3372 grijs taupe zand'), true);
  assert.equal(colorsCompatible('sisal gold 22', 'sisal gold 22 antraciet grijs'), true);
  assert.deepEqual(colorWords('love shaggy lichtbruin'), ['lichtbruin']);
  // Lichtbruin en bruin zijn aparte productkleuren, geen synoniemen.
  assert.equal(colorsCompatible('love shaggy lichtbruin', 'karpet love shaggy bruin'), false);
});

test('hasModelNameToken laat zich niet foppen door een generiek materiaalwoord', () => {
  // "Sisal Gold 22" kreeg de prijs van "Sisal vloerkleed Loop grijs 22" omdat
  // beide met "sisal" beginnen; het onderscheidende woord is "gold".
  assert.equal(hasModelNameToken('sisal vloerkleed loop grijs 22 karpi', 'sisal gold 22'), false);
  assert.equal(hasModelNameToken('sisal gold 22 antraciet grijs', 'sisal gold 22'), true);
  assert.equal(hasModelNameToken('vloerkleed sisal gold beige 14 karpi', 'sisal gold 14'), true);
  // Het omgekeerde blijft kloppen: "Loop 22" hoort wél bij die pagina.
  assert.equal(hasModelNameToken('sisal vloerkleed loop grijs 22 karpi', 'loop 22'), true);
  // Bestaat het model alleen uit generieke woorden, dan blijft de oude
  // terugval gelden (anders zou er niets meer te matchen zijn).
  assert.equal(hasModelNameToken('hoogpolig vloerkleed karpi', 'hoogpolig'), true);
});

test('pageMatchesEntry keurt de verkeerde kleurpagina af', () => {
  const entry = m => ({ normModel: normModel(m), shape: 'rechthoek', mustHave: [] });
  assert.equal(pageMatchesEntry('Karpet Love Shaggy beige',
    'https://hetdesignhuys.nl/products/karpet-love-shaggy-beige', entry('Love Shaggy Taupe')), false);
  assert.equal(pageMatchesEntry('Karpet Love Shaggy beige',
    'https://hetdesignhuys.nl/products/karpet-love-shaggy-beige', entry('Love Shaggy Beige')), true);
  assert.equal(pageMatchesEntry('Sisal vloerkleed Loop grijs 22 - Karpi',
    'https://vloerkledenloods.nl/products/sisal-vloerkleed-loop-grijs-22-karpi', entry('Sisal Gold 22')), false);
  assert.equal(pageMatchesEntry('Sisal vloerkleed Loop grijs 22 - Karpi',
    'https://vloerkledenloods.nl/products/sisal-vloerkleed-loop-grijs-22-karpi', entry('Loop 22')), true);
});

test('audit-prices keurt oude rijen af die de huidige guards niet meer halen', () => {
  const { judge } = require('./audit-prices');
  const entry = (m, shape = 'rechthoek') => ({ normModel: normModel(m), mustHave: [], shape });
  const row = { sku: 'X.1', shop: 's', price_str: '€ 100,00', url: 'https://s/p' };

  // Kleurbotsing die vóór de kleurcheck binnenkwam
  assert.equal(judge(row, entry('Love Shaggy Taupe'), { title: 'Karpet Love Shaggy beige', platform: 'shopify' }), 'identity');
  assert.equal(judge(row, entry('Love Shaggy Beige'), { title: 'Karpet Love Shaggy beige', platform: 'shopify' }), null);
  // Dessinbotsing die vóór numbersCompatible binnenkwam
  assert.equal(judge(row, entry('Montgomery 2787'), { title: 'Montgomery 3372 grijs', platform: 'custom' }), 'identity');
  // Kleurnummer blijft leidend: kleur 13 mag de 48-pagina niet krijgen.
  assert.equal(judge(row, entry('Brush Ovale 13', 'ovaal'), { title: 'Brush Ovale 48 - Faded Pink', platform: 'shopify' }), 'identity');
  // Vorm telt alleen bij custom shops: bij Shopify zit de vorm in de
  // variant-titel, dus een ovale entry mag daar niet op een paginatitel
  // zónder vormwoord sneuvelen — bij een custom shop juist wél.
  const zonderVorm = 'Mart Visser vloerkleed Brush ocker brown 69';
  assert.equal(judge(row, entry('Brush 69', 'ovaal'), { title: zonderVorm, platform: 'shopify' }), null);
  assert.equal(judge(row, entry('Brush 69', 'ovaal'), { title: zonderVorm, platform: 'custom' }), 'shape');
  assert.equal(judge(row, entry('Vernon 18', 'rechthoek'), { title: 'Vernon 18 in Organische Vorm', platform: 'custom' }), 'shape');
  // Ontbrekende catalogus/index-rij
  assert.equal(judge(row, null, { title: 'x', platform: 'custom' }), 'unknown-sku');
  assert.equal(judge(row, entry('Gentle 13'), null), 'no-index-row');
});

test('audit-prices weigert --fix als catalogus of index incompleet is', () => {
  const { structuralBrake } = require('./audit-prices');
  const rows = (n, reason, shop = 'a.nl') => Array.from({ length: n }, () => ({ reason, shop }));
  const spread = new Map([['a.nl', 5000], ['b.nl', 4768]]);

  // Normale run: een paar vervallen SKU's mag opgeruimd worden.
  assert.equal(structuralBrake(rows(50, 'unknown-sku'), 9768, spread).refuse, false);
  // Halve PIM-export mislukt: niet de hele voorraad weggooien.
  assert.equal(structuralBrake(rows(4800, 'unknown-sku'), 9768, spread).refuse, true);
  // Eén kapotte crawl (lege index voor die shop) mag niet in het totaal
  // verdrinken: 600 van 5000 bij a.nl is 12%, over alle shops maar 6%. Die shop
  // wordt OVERGESLAGEN, de rest wordt wél opgeruimd — anders blokkeert één
  // stukke shop het opruimen van echte fouten bij alle andere.
  const oneShopBroken = structuralBrake(rows(600, 'no-index-row', 'a.nl'), 9768, spread);
  assert.equal(oneShopBroken.refuse, false);
  assert.deepEqual([...oneShopBroken.skipShops], ['a.nl']);

  // De per-shop-verhouding gaat over de AFKEURINGEN, niet over het aantal
  // rijen — anders is een shop van 12 rijen onbeschermd tegen een mislukte
  // crawl, terwijl één vervallen SKU daar al 8% van de shop is.
  const tiny = new Map([['klein.nl', 12], ['groot.nl', 9756]]);

  // Volledig mislukte crawl bij de kleine shop: overslaan.
  assert.deepEqual([...structuralBrake(rows(12, 'no-index-row', 'klein.nl'), 9768, tiny).skipShops], ['klein.nl']);
  // Gedeeltelijk mislukt (11 van de 12 afkeuringen én 11 van de 12 rijen):
  // ook overslaan. Een rij-drempel alléén liet deze 12 rijen wél verwijderen.
  assert.deepEqual([...structuralBrake(
    [...rows(11, 'no-index-row', 'klein.nl'), ...rows(1, 'identity', 'klein.nl')], 9768, tiny).skipShops], ['klein.nl']);
  // Werkende index met wat ruis: 2 structureel op 20 afkeuringen = 10%, niet
  // boven de limiet, dus gewoon opruimen.
  const tinyNoise = structuralBrake(
    [...rows(2, 'unknown-sku', 'klein.nl'), ...rows(18, 'identity', 'klein.nl')], 9768, tiny);
  assert.equal(tinyNoise.refuse, false);
  assert.deepEqual([...tinyNoise.skipShops], []);

  // ── De normale bedrijfsvoering moet gewoon opgeruimd worden ──────────────
  // Een handvol vervallen producten bij een grote shop is 100% van de
  // afkeuringen maar een verwaarloosbaar deel van de shop. Alleen op de
  // afkeur-verhouding remmen maakte --fix hier een no-op — precies wanneer je
  // hem wél wilt draaien.
  const real = new Map([['karpettenkelder.nl', 4383], ['vloerkledenloods.nl', 2959]]);
  assert.deepEqual([...structuralBrake(rows(5, 'unknown-sku', 'karpettenkelder.nl'), 9768, real).skipShops], []);
  assert.deepEqual([...structuralBrake(rows(1, 'no-index-row', 'karpettenkelder.nl'), 9768, real).skipShops], []);
  // En de echte vondsten ertussen mogen al helemaal niet gesmoord worden.
  const steady = structuralBrake(
    [...rows(5, 'unknown-sku', 'karpettenkelder.nl'), ...rows(14, 'identity', 'karpettenkelder.nl')], 9768, real);
  assert.deepEqual([...steady.skipShops], []);
  // 40 ontbrekende indexrijen + 3 vondsten bij een shop van 2.959 = 1,4% van de
  // shop: nog steeds opruimen, niet de hele shop sparen.
  assert.deepEqual([...structuralBrake(
    [...rows(40, 'no-index-row', 'vloerkledenloods.nl'), ...rows(3, 'identity', 'vloerkledenloods.nl')],
    9768, real).skipShops], []);

  // Echte vondsten tellen NIET mee — die mogen nooit door een limiet worden
  // weggedrukt, hoeveel het er ook zijn.
  assert.equal(structuralBrake(rows(9000, 'identity'), 9768, spread).refuse, false);
  assert.deepEqual([...structuralBrake(rows(9000, 'identity'), 9768, spread).skipShops], []);
  assert.equal(structuralBrake(rows(9000, 'shape'), 9768, spread).refuse, false);
  assert.equal(structuralBrake(rows(9000, 'size'), 9768, spread).refuse, false);
  // Drempel is instelbaar (globaal 49%, per shop 96% — beide onder 99).
  assert.equal(structuralBrake(rows(4800, 'unknown-sku'), 9768, spread, 99).refuse, false);
  // Lege run remt niet.
  assert.equal(structuralBrake([], 0, new Map()).refuse, false);
});

test('audit-prices betrapt een maat-in-de-URL die niet bij de SKU hoort', () => {
  const { judge } = require('./audit-prices');
  const { CUSTOM_SHOPS } = require('./shops');
  const giga = CUSTOM_SHOPS.find(s => s.key === 'gigameubel.nl');
  const entry = { normModel: normModel('Prosper 21 Cyprus White'), mustHave: [], shape: 'rechthoek',
    widthCm: 200, heightCm: 290 };
  const idx = { title: 'Mart Visser Vloerkleed Prosper', platform: 'custom' };

  const ok = { sku: 'X.1', shop: 'gigameubel.nl', price_str: '€ 100,00',
    url: 'https://www.gigameubel.nl/mart-visser-vloerkleed-prosper-200x290cm-wit' };
  assert.equal(judge(ok, entry, idx, giga), null);

  // Zelfde product, andere maat in de URL: die prijs hoort niet bij deze SKU.
  const wrong = { ...ok, url: 'https://www.gigameubel.nl/mart-visser-vloerkleed-prosper-155x230cm-wit' };
  assert.equal(judge(wrong, entry, idx, giga), 'size');

  // Zonder shopconfig (of zonder maat in de URL) blijft het oordeel ongemoeid.
  assert.equal(judge(wrong, entry, idx, null), null);
});

test('shops zonder dessinnummer eisen positief kleurbewijs (gigameubel)', () => {
  const { hasDiscriminator } = require('./normalize');
  const { CUSTOM_SHOPS } = require('./shops');
  assert.equal(CUSTOM_SHOPS.find(s => s.key === 'gigameubel.nl').requireDiscriminator, true);

  const slug = 'mart visser prosper https://www.gigameubel.nl/mart-visser-vloerkleed-prosper-200x290cm-wit';
  const entry = m => ({ normModel: normModel(m), mustHave: [], shape: 'rechthoek' });
  const url = 'https://www.gigameubel.nl/mart-visser-vloerkleed-prosper-200x290cm-wit';
  const title = 'Mart Visser Vloerkleed Prosper kopen? &#9193; Giga Meubel';

  // "Prosper 11" staat wél op deze pagina qua modelnaam, maar noemt geen kleur
  // en de pagina geen dessinnummer: niets bewijst dat dit dezelfde kleur is.
  assert.equal(hasDiscriminator(normModel('Prosper 11'), slug), false);
  assert.equal(pageMatchesEntry(title, url, entry('Prosper 11'), { requireDiscriminator: true }), false);
  // Zonder de vlag blijft het oude, tolerante gedrag gelden.
  assert.equal(pageMatchesEntry(title, url, entry('Prosper 11')), true);
  // Gedeelde kleurnaam is wél bewijs.
  assert.equal(hasDiscriminator(normModel('Prosper 21 Cyprus White'), slug), true);
  assert.equal(pageMatchesEntry(title, url, entry('Prosper 21 Cyprus White'), { requireDiscriminator: true }), true);
  // Een gedeeld dessinnummer ook.
  assert.equal(hasDiscriminator(normModel('Aspen 7270'), 'karpet aspen 7270 beige'), true);
});

test('de PIM-kleur uit de catalogus beslist mee over de kleurvariant', () => {
  const { hasDiscriminator } = require('./normalize');
  // "Oasis 11" draagt geen kleurwoord in de naam; de concurrent noemt alleen de
  // kleur. Met de Kleuren-kolom uit het PIM is de koppeling wél te beslissen.
  const witPage = 'mart visser oasis https://www.gigameubel.nl/mart-visser-vloerkleed-oasis-200x290cm-wit';
  assert.equal(hasDiscriminator('oasis 11', witPage), false);
  assert.equal(hasDiscriminator('oasis 11', witPage, 'Wit'), true);
  assert.equal(hasDiscriminator('oasis 15', witPage, 'Beige'), false);

  const entry = (m, colour) => ({ normModel: normModel(m), mustHave: [], shape: 'rechthoek', colour });
  const url = 'https://www.gigameubel.nl/mart-visser-vloerkleed-oasis-200x290cm-wit';
  const title = 'Mart Visser Vloerkleed Oasis kopen? &#9193; Giga Meubel';
  assert.equal(pageMatchesEntry(title, url, entry('Oasis 11', 'Wit'), { requireDiscriminator: true }), true);
  // Beige kleed op een witte pagina: nu aantoonbaar fout i.p.v. onbeslisbaar.
  assert.equal(pageMatchesEntry(title, url, entry('Oasis 15', 'Beige'), { requireDiscriminator: true }), false);

  // De PIM-kleur mag NIET als tegenspraak gelden: het is een grove categorie,
  // geen marketingnaam. "Oasis 15" staat in het PIM als Beige terwijl de shop
  // hem "Oasis cloud grey 15" noemt — zelfde dessinnummer, terechte koppeling.
  // Dit meetellen in colorsCompatible keurde vier goede koppelingen af.
  const cloudGrey = { normModel: normModel('Oasis 15'), mustHave: [], shape: 'rechthoek', colour: 'Beige' };
  assert.equal(pageMatchesEntry(
    'Mart Visser vloerkleed Oasis cloud grey 15',
    'https://vloerkledenloods.nl/products/mart-visser-vloerkleed-oasis-cloud-grey-15',
    cloudGrey
  ), true);
});

test('loadCatalog leest de optionele Kleuren-kolom', () => {
  const csv = [
    'KP1.1,Mart Visser,Oasis 11,200 cm x 290 cm,599,Wit',
    'KP2.1,Mart Visser,Oasis 15,200 cm x 290 cm,599',   // oude CSV zonder kolom
  ].join('\n');
  const tmp = path.join(os.tmpdir(), `catalog-colour-test-${process.pid}.csv`);
  fs.writeFileSync(tmp, csv);
  try {
    const catalog = loadCatalog(tmp);
    assert.equal(catalog.bySku.get('KP1.1').colour, 'Wit');
    assert.equal(catalog.bySku.get('KP2.1').colour, '');
  } finally {
    fs.unlinkSync(tmp);
  }
});
