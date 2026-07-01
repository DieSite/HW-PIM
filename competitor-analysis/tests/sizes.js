/**
 * Niet-standaard afmetingen voor de prijsvergelijking.
 * Alle maten zijn in millimeters (mm).
 *
 * Stelregel:
 *  - Klein  = 73 × 197 cm  → 730 × 1970 mm
 *  - Middel = 87 × 212 cm  → 870 × 2120 mm
 *  - Groot  = 103 × 227 cm → 1030 × 2270 mm
 *
 * Dubbele deuren: dezelfde hoogtes, breedte is totale dagopening
 *  - Klein  = 143 × 197 cm → 1430 × 1970 mm
 *  - Middel = 163 × 212 cm → 1630 × 2120 mm
 *  - Groot  = 183 × 227 cm → 1830 × 2270 mm
 */

const SIZES = {
  'Enkele klein':    { breedte: 730,  hoogte: 1970, type: 'enkel' },
  'Enkele middel':   { breedte: 870,  hoogte: 2120, type: 'enkel' },
  'Enkele groot':    { breedte: 1030, hoogte: 2270, type: 'enkel' },
  'Dubbele klein':   { breedte: 1430, hoogte: 1970, type: 'dubbel' },
  'Dubbele middel':  { breedte: 1630, hoogte: 2120, type: 'dubbel' },
  'Dubbele groot':   { breedte: 1830, hoogte: 2270, type: 'dubbel' },
};

module.exports = { SIZES };
