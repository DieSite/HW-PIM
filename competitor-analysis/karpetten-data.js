/**
 * karpetten-data.js – gedeelde data voor de karpetten-concurrentieanalyse.
 *
 * Bevat de Top 10-ranking, de watchlist en per merk de shop×model-matrix met
 * de HANDMATIG geverifieerde onderzoeksdata (juni 2026). De Playwright-suite
 * (tests-karpetten/) scrapet live prijzen die in karpettenExcel.js als
 * overlay over deze matrices heen gelegd worden: een gescrapete €-prijs
 * vervangt het statische vinkje/de onderzoeksprijs in de cel.
 *
 * Shopnamen in de matrices = domeinnaam, identiek aan de `shop`-key die de
 * specs aan recordKarpet() meegeven — anders mist de overlay.
 */

const TOP10 = [
  ['1', 'vloerkledenloods.nl', 'Karpi + De Munk', '±13 van 26 modellen (breedste Karpi-range én 7/8 De Munk)',
   'Discounter; stapelkorting, maatwerk', 'Grootste totale overlap met ons assortiment, in twee merken tegelijk'],
  ['2', 'karpettenkelder.nl', 'Eurogros + De Munk', 'Eurogros-merkpagina (vermoedelijk volledig) + grootste De Munk-catalogus (332 SKU\'s)',
   'Laagsteprijsgarantie + afhaalkorting', 'Dieptespeler in twee van onze drie merken, agressieve prijsbelofte'],
  ['3', 'hetdesignhuys.nl', 'Eurogros + Karpi (MV)', '6/8 Eurogros + Lago en Prosper',
   'Structureel goedkoopste Eurogros (Aspen €429 vs RRP €525)', 'De prijsagressor op het Eurogros-assortiment'],
  ['4', 'volero.nl', 'Eurogros', '6/8 modellen (deels onder label "Antoin Carpets")',
   'Op RRP-niveau (Richmond 240x340 €1205)', 'Eén merk, maar de breedste Eurogros-dekking; groot vloerkleden-platform'],
  ['5', 'karpettenshop.nl', 'De Munk', '5/8 modellen + volledige maattabellen en maatwerk per m²',
   'Rond RRP', 'Eén merk, maar noemt zichzelf grootste De Munk-dealer — dé specialist op dit merk'],
  ['6', 'kleed.nl', 'De Munk', '4/8 modellen',
   'Wisselende kortingsacties (±17% gezien; juni 2026 geen korting actief)', 'Eén merk, maar dé kortingsspeler op De Munk — monitoren'],
  ['7', 'homedeco.nl', 'De Munk + Karpi (MV)', '5/8 De Munk + Mart Visser-categorie',
   'Rond RRP, gratis verzending', 'Twee merken bij een algemene woonwebshop met bereik'],
  ['8', 'vloerkledenvoordelig.nl', 'Karpi', '5/6 klassieke Karpi-modellen (Cisco, Galaxy, Lago, Marich, Olimpos)',
   '±10% onder RRP + "bel voor scherpste prijs"', 'Eén merk, maar dekt vrijwel de hele klassieke Karpi-lijn — die is verder dun verspreid'],
  ['9', 'woonboulevardpoortvliet.nl', 'Eurogros', 'Love Shaggy + Twilight 200x290 (eerder vermoede De Munk/Karpi-items bleken afwijkende maten of andere producten)',
   'Op RRP-niveau', 'Grote meubelboulevard met webshop; overlap kleiner dan eerst gedacht'],
  ['10', 'homecompanyshop.nl', 'Karpi (klassiek + MV)', 'Olimpos + Cendre/Vernon, maatwerk €155/m²',
   'Maatwerk-prijsanker gelijk aan dealerafspraak', 'Combineert klassiek Karpi met de Mart Visser-lijn incl. maatwerk'],
];

const WATCH = [
  ['floorpassion.nl', 'Karpi (MV)', 'Cavaro 200x290 €659 (~40% onder prijspeil elders) — scherpste losse MV-prijs'],
  ['gigameubel.nl', 'Karpi (MV)', 'Prosper 200x290 €589 op voorraad — bodem van de RRP-band'],
  ['bol.com', 'Karpi + De Munk', 'Marketplace-kanaal (officiële merkcategorieën); prijzen nu hoog (Marich €905), maar monitoren'],
  ['carpetright.nl', '"Mart Visser"', 'Voert een Mart Visser-pagina — mogelijk eigen gelicenseerde lijn, handmatig checken'],
];

// ── Per merk: shop × model. Celwaarde = onderzoeksdata (✓ of indicatieprijs);
//    live gescrapete prijzen overschrijven dit in de Excel-build. ────────────
const EUROGROS = {
  maat: '200x290',
  modellen: ['Aspen', 'Anaheim', 'Allison', 'Spectrum', 'Twilight', 'Richmond', 'Love Shaggy', 'Arizona'],
  // Aanwezigheid per shop volledig GEVERIFIEERD (volledige sitemaps/merkpagina's, juni 2026)
  shops: [
    ['volero.nl',                  ['✓', '✓', '', '✓', '✓', '✓', '✓', '✓']],
    ['hetdesignhuys.nl',           ['✓', '✓', '✓', '✓', '✓', '✓', '✓', '✓']],
    ['karpettenkelder.nl',         ['✓', '✓', '✓', '✓', '✓', '✓', '✓', '✓']],
    ['grootinvloeren.nl',          ['✓', '✓', '✓', '✓', '✓', '✓', '✓', '✓']],
    ['lowikmeubelen.nl',           ['✓', '', '', '', '', '', '', '']],
    ['bommelwonen.nl',             ['✓', '✓', '', '✓', '✓', '', '', '✓']],
    ['detafelaar.nl',              ['✓', '✓', '', '✓', '✓', '✓', '✓', '✓']],
    ['woonboulevardpoortvliet.nl', ['', '', '', '', '✓', '', '✓', '']],
  ],
  noot: 'Aanwezigheid geverifieerd via volledige sitemaps/merkpagina\'s (juni 2026); lege cel = aantoonbaar niet verkocht in 200x290. Spectrum 6656 ook bij hrsvloeren.nl en kledenonline.nl. Lowik voert Anaheim/Spectrum/Twilight/Richmond/Arizona alleen in afwijkende maten (240x330+/rond). Vervallen: wonenop10.nl en valeurhome.nl (geen vloerkleden meer), haco.nu (geen prijs/varianten), rispenswonen en bommelwonen-Allison (alleen 160x230). detafelaar = alleen vanafprijzen (geen maatkeuze online). LET OP: zelfde producten circuleren onder labels "Antoin Carpets" (Volero) en Lano — match op model + kleurcode + maattabel. Prijzen = 200x290.',
};

const DEMUNK = {
  maat: '200x300',
  modellen: ['Grande', 'Martello', 'Firenze', 'Genova', 'Vogue', 'Venezia', 'Lecce', 'Toscane'],
  shops: [
    ['vloerkledenloods.nl',        ['✓', '✓', '✓', '✓', '✓', '✓', '✓', '✓']],
    ['karpettenkelder.nl',         ['✓', '✓', '✓', '✓', '✓', '✓', '✓', '✓']],
    ['kleed.nl',                   ['✓', '✓', '✓', '✓', '✓', '✓', '✓', '✓']],
    ['vloerkledenspecialist.nl',   ['✓', '✓', '✓', '✓', '', '✓', '✓', '✓']],
    ['karpettenshop.nl',           ['', '', '✓', '✓', '✓', '✓', '', '✓']],
    ['plaisierinterieur.nl',       ['', '✓', '✓', '✓', '✓', '✓', '', '✓']],
    ['homedeco.nl',                ['✓', '✓', '✓', '', '', '', '✓', '']],
    ['karpetwereld.nl',            ['', '', '✓', '', '', '', '', '✓']],
    ['woonboulevardpoortvliet.nl', ['', '', '✓', '', '', '', '', '']],
    ['bol.com',                    ['', '', '✓', '', '', '✓', '', '']],
  ],
  noot: 'Aanwezigheid geverifieerd via volledige sitemaps/merkpagina\'s (juni 2026); lege cel = aantoonbaar niet verkocht (bol.com: niet verifieerbaar, IP-blokkade). De Munk verkoopt zelf NIET online (dealer-only). Standaardmaat is 200x300 (geen 200x290!); prijzen = 200x300. Kleed.nl: kortingsacties wisselen (juni 2026 geen korting actief). Poortvliet-Firenze draagt geen merknaam maar FI-kleurcodes (vrijwel zeker De Munk).',
};

const KARPI = {
  maat: '200x290',
  modellen: ['Cisco', 'Galaxy', 'Lago', 'Marich', 'Olimpos', 'Bilal', 'MV Cendre', 'MV Vernon', 'MV Cavaro', 'MV Prosper'],
  shops: [
    ['vloerkledenloods.nl',        ['✓', '✓', '✓', '✓', '✓', '✓', '✓', '✓', '✓', '✓']],
    ['vloerkledenvoordelig.nl',    ['✓', '✓', '✓', '✓', '✓', '✓', '✓', '✓', '', '✓']],
    ['hetdesignhuys.nl',           ['', '✓', '✓', '', '', '✓', '✓', '✓', '✓', '✓']],
    ['meubelcity.nl',              ['✓', '✓', '✓', '✓', '', '✓', '', '', '', '']],
    ['homecompanyshop.nl',         ['', '✓', '', '', '✓', '', '✓', '✓', '', '✓']],
    ['vivaldixl.nl',               ['', '', '✓', '✓', '', '✓', '', '✓', '✓', '']],
    ['gigameubel.nl',              ['', '', '', '', '', '', '✓', '✓', '✓', '✓']],
    ['floorpassion.nl',            ['', '', '', '', '', '', '✓', '', '✓', '✓']],
    ['bommelwonen.nl',             ['', '', '', '', '', '', '✓', '✓', '', '✓']],
    ['kleed.nl',                   ['', '', '', '', '', '', '✓', '✓', '', '✓']],
    ['karpetwereld.nl',            ['', '', '', '', '', '', '✓', '', '', '✓']],
    ['boumanenpotter.nl',          ['', '', '', '', '', '', '', '✓', '', '']],
    ['karpettenkelder.nl',         ['✓', '', '', '✓', '', '✓', '', '', '', '']],
    ['bol.com',                    ['', '', '', '✓', '', '', '', '', '', '']],
  ],
  noot: 'Aanwezigheid geverifieerd via volledige sitemaps/merkpagina\'s (juni 2026); lege cel = aantoonbaar niet verkocht. Karpettenkelder voert Cisco/Marich/Bilal onder het white-label "Core by Dersimo" (zelfde kleurnummers). Homecompanyshop-Galaxy is gelabeld "Headlam" (Karpi\'s moederbedrijf). Floorpassion-Vernon en de enige Prosper bij Bouman&Potter/Poortvliet zijn alleen ROND (Ø200) — niet vergelijkbaar, dus niet opgenomen. Pas op voor naamgenoten: INHOUSE "Cavaro", Richmond Interiors-meubels, "San FranCISCO". Maatwerk-anker €155/m² ligt vast vanuit Karpi. Prijzen = 200x290.',
};

// ── Rijen van de Prijsvergelijking-tab (zelfde opzet als de hordeuren-Excel:
//    producten als rijen, shops als kolommen). `eigen` = adviesverkoopprijs
//    200x290 uit "Goedlopende karpetten.xlsx" — de referentie voor de
//    rood/groen-kleuring. De Munk-concurrenten prijzen 200x300 (het merk kent
//    geen 200x290); dat staat in de maat-kolom. ────────────────────────────
const MODELLEN = [
  { merk: 'Eurogros', model: 'Aspen',       maat: '200x290', eigen: 525 },
  { merk: 'Eurogros', model: 'Anaheim',     maat: '200x290', eigen: 525 },
  { merk: 'Eurogros', model: 'Allison',     maat: '200x290', eigen: 525 },
  { merk: 'Eurogros', model: 'Spectrum',    maat: '200x290', eigen: 605 },
  { merk: 'Eurogros', model: 'Twilight',    maat: '200x290', eigen: 559 },
  { merk: 'Eurogros', model: 'Richmond',    maat: '200x290', eigen: 859 },
  { merk: 'Eurogros', model: 'Love Shaggy', maat: '200x290', eigen: 625 },
  { merk: 'Eurogros', model: 'Arizona',     maat: '200x290', eigen: 525 },
  { merk: 'De Munk',  model: 'Grande',      maat: '200x300', eigen: 1439 },
  { merk: 'De Munk',  model: 'Martello',    maat: '200x300', eigen: 1299 },
  { merk: 'De Munk',  model: 'Firenze',     maat: '200x300', eigen: 2169 },
  { merk: 'De Munk',  model: 'Genova',      maat: '200x300', eigen: 1489 },
  { merk: 'De Munk',  model: 'Vogue',       maat: '200x300', eigen: 1585 },
  { merk: 'De Munk',  model: 'Venezia',     maat: '200x300', eigen: 2169 },
  { merk: 'De Munk',  model: 'Lecce',       maat: '200x300', eigen: 1299 },
  { merk: 'De Munk',  model: 'Toscane',     maat: '200x300', eigen: 1585 },
  { merk: 'Karpi',    model: 'Cisco',       maat: '200x290', eigen: 607.50 },
  { merk: 'Karpi',    model: 'Galaxy',      maat: '200x290', eigen: 790 },
  { merk: 'Karpi',    model: 'Lago',        maat: '200x290', eigen: 780 },
  { merk: 'Karpi',    model: 'Marich',      maat: '200x290', eigen: 660.40 },
  { merk: 'Karpi',    model: 'Olimpos',     maat: '200x290', eigen: 1257.50 },
  { merk: 'Karpi',    model: 'Bilal',       maat: '200x290', eigen: 729 },
  { merk: 'Karpi',    model: 'MV Cendre',   maat: '200x290', eigen: 655 },
  { merk: 'Karpi',    model: 'MV Vernon',   maat: '200x290', eigen: 659 },
  { merk: 'Karpi',    model: 'MV Cavaro',   maat: '200x290', eigen: 659 },
  { merk: 'Karpi',    model: 'MV Prosper',  maat: '200x290', eigen: 589 },
];

// Kolomvolgorde: Top 10 eerst, dan watchlist/kleinere shops.
const SHOPS = [
  { key: 'vloerkledenloods.nl',        label: 'Vloerkledenloods' },
  { key: 'karpettenkelder.nl',         label: 'Karpettenkelder' },
  { key: 'hetdesignhuys.nl',           label: 'Het Designhuys' },
  { key: 'volero.nl',                  label: 'Volero' },
  { key: 'karpettenshop.nl',           label: 'Karpettenshop' },
  { key: 'kleed.nl',                   label: 'Kleed.nl' },
  { key: 'homedeco.nl',                label: 'Homedeco' },
  { key: 'vloerkledenvoordelig.nl',    label: 'Vloerkleden- voordelig' },
  { key: 'woonboulevardpoortvliet.nl', label: 'Wbl. Poortvliet' },
  { key: 'homecompanyshop.nl',         label: 'Home Company' },
  { key: 'floorpassion.nl',            label: 'Floorpassion' },
  { key: 'gigameubel.nl',              label: 'Gigameubel' },
  { key: 'boumanenpotter.nl',          label: 'Bouman & Potter' },
  { key: 'vivaldixl.nl',               label: 'Vivaldi XL' },
  { key: 'meubelcity.nl',              label: 'Meubelcity' },
  { key: 'grootinvloeren.nl',          label: 'Groot in Vloeren' },
  { key: 'bommelwonen.nl',             label: 'Bommel Wonen' },
  { key: 'lowikmeubelen.nl',           label: 'Lowik Meubelen' },
  { key: 'vloerkledenspecialist.nl',   label: 'Vloerkleden- specialist' },
  { key: 'karpetwereld.nl',            label: 'Karpetwereld' },
  { key: 'plaisierinterieur.nl',       label: 'Plaisier Interieur' },
  { key: 'detafelaar.nl',              label: 'De Tafelaar' },
  { key: 'bol.com',                    label: 'Bol.com' },
];

/** Onderzoeksdata uit de merk-matrices -> { shop: { model: '✓'/'✓*' } }. */
function researchLookup() {
  const out = {};
  for (const merk of [EUROGROS, DEMUNK, KARPI]) {
    for (const [shop, cellen] of merk.shops) {
      for (let i = 0; i < merk.modellen.length; i++) {
        if (cellen[i]) ((out[shop] ??= {})[merk.modellen[i]] ??= cellen[i]);
      }
    }
  }
  return out;
}

module.exports = { TOP10, WATCH, EUROGROS, DEMUNK, KARPI, MODELLEN, SHOPS, researchLookup };
