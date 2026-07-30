/**
 * Prijsberekening van koopje-horren.com (Bruynzeel plissé hordeur s900 op maat).
 *
 * Hun Magento draait de plugin `MageArray_Customprice`, die de hele
 * prijstabel als JSON in de productpagina zet:
 *
 *     "MageArray_Customprice/js/customprice": { "optionConfig": {
 *        "pricesheet": { "<hoogte>": { "<breedte>": "233", ... }, ... },
 *        "vals": { "row": [1791, ...], "col": [580, ...] },
 *        "row": { "min": 1791, "max": 2960 },  // hoogte-veld
 *        "col": { "min": 580,  "max": 1900 },  // breedte-veld
 *        "otheroptions": { ... },
 *        "includebaseprice": "0" } }
 *
 * De prijs is dus WEL maat-afhankelijk: 730×1970 kost € 233, niet de
 * getoonde vanaf-prijs van € 179 (= de goedkoopste cel, 580×1791). Tot
 * 2026-07 noteerde de spec die vanaf-prijs voor álle maten en stond
 * koopje-horren structureel te goedkoop in de vergelijking.
 *
 * Bewust buiten de browser gehouden zodat de rekenregel te testen is:
 * `npm run test:koopje` — zelfde opzet als `_eigenwinkel.js` / `_korting.js`.
 */

/**
 * Lees het `optionConfig`-object van de customprice-plugin uit de pagina-HTML.
 *
 * @param {string} html
 * @returns {object|null} het geparste optionConfig, of null als het ontbreekt
 */
function parseOptionConfig(html) {
  const marker = html.indexOf('MageArray_Customprice/js/customprice');
  if (marker === -1) return null;

  const key = html.indexOf('"optionConfig"', marker);
  if (key === -1) return null;

  const start = html.indexOf('{', key + '"optionConfig"'.length);
  if (start === -1) return null;

  const eind = eindeVanObject(html, start);
  if (eind === -1) return null;

  try {
    return JSON.parse(html.slice(start, eind + 1));
  } catch {
    return null;
  }
}

/** Index van de `}` die het object op `start` sluit, of -1. */
function eindeVanObject(tekst, start) {
  let diepte = 0;
  let inString = false;
  let escaped = false;

  for (let i = start; i < tekst.length; i++) {
    const c = tekst[i];

    if (inString) {
      if (escaped) { escaped = false; continue; }
      if (c === '\\') { escaped = true; continue; }
      if (c === '"') inString = false;
      continue;
    }

    if (c === '"') { inString = true; continue; }
    if (c === '{') diepte++;
    else if (c === '}' && --diepte === 0) return i;
  }

  return -1;
}

/**
 * De prijs voor een maat, of null als die maat niet leverbaar is.
 *
 * Bandregel (zoals de plugin zelf): de eerste rij ≥ hoogte en de eerste kolom
 * ≥ breedte. Een cel met `"x"` betekent "deze combinatie maken we niet" en een
 * maat buiten het min/max-bereik van het invoerveld wordt door hun formulier
 * geweigerd — beide gevallen leveren null op, nooit de goedkoopste cel.
 *
 * @param {object} cfg optionConfig uit parseOptionConfig()
 * @param {number} breedte mm
 * @param {number} hoogte mm
 * @returns {number|null}
 */
function prijsUitSheet(cfg, breedte, hoogte) {
  if (!cfg || !cfg.pricesheet) return null;

  const rijen = banden(cfg.vals?.row, Object.keys(cfg.pricesheet));
  const rij = kleinsteBandVanaf(rijen, hoogte, cfg.row);
  if (rij === null) return null;

  const kolommen = banden(cfg.vals?.col, Object.keys(cfg.pricesheet[rij] ?? {}));
  const kolom = kleinsteBandVanaf(kolommen, breedte, cfg.col);
  if (kolom === null) return null;

  const cel = cfg.pricesheet[rij]?.[kolom];
  const prijs = Number(cel);

  return isFinite(prijs) && prijs > 0 ? prijs : null;
}

/** Oplopende bandwaarden; `vals` is de bron, de sheet-sleutels de terugval. */
function banden(vals, sleutels) {
  const lijst = Array.isArray(vals) && vals.length ? vals : sleutels;

  return lijst.map(Number).filter(n => isFinite(n)).sort((a, b) => a - b);
}

/** De kleinste band ≥ waarde, of null buiten het toegestane veldbereik. */
function kleinsteBandVanaf(lijst, waarde, bereik) {
  if (!(waarde > 0) || !lijst.length) return null;
  if (bereik && (waarde < Number(bereik.min) || waarde > Number(bereik.max))) return null;

  const band = lijst.find(b => b >= waarde);

  return band === undefined ? null : String(band);
}

/**
 * De gaaskleur-optie met die naam, bv. `grijs`.
 *
 * Hun grijze gaas kost niets extra maar kan maar tot een bepaalde hoogte
 * ("Grijs (maximaal 2600mm hoog)"); die grens lezen we uit de optietitel in
 * plaats van hem te hardcoden, zodat een wijziging bij hen vanzelf meekomt.
 *
 * @param {object} cfg optionConfig uit parseOptionConfig()
 * @param {string} kleur bv. 'grijs' of 'zwart'
 * @returns {{titel: string, meerprijs: number, maxHoogte: number|null}|null}
 */
function gaasOptie(cfg, kleur) {
  const patroon = new RegExp(`^\\s*${kleur}\\b`, 'i');

  for (const optie of Object.values(cfg?.otheroptions ?? {})) {
    if (!optie || typeof optie !== 'object' || optie.type === 'field') continue;

    for (const variant of Object.values(optie)) {
      if (!variant || typeof variant !== 'object' || !patroon.test(variant.title ?? '')) continue;

      const max = /maximaal\s*(\d+)\s*mm/i.exec(variant.title);

      return {
        titel: variant.title,
        meerprijs: Number(variant.price) || 0,
        maxHoogte: max ? Number(max[1]) : null,
      };
    }
  }

  return null;
}

module.exports = { parseOptionConfig, prijsUitSheet, gaasOptie };
