/**
 * Maten voor de prijsvergelijking. Alle maten in millimeters (mm),
 * gemeten tussen het kozijn ("in de dag").
 *
 * Twee reeksen:
 *
 * 1. De zes oorspronkelijke generieke maten (zwart gaas):
 *    - Klein  = 73 × 197 cm, Middel = 87 × 212 cm, Groot = 103 × 227 cm
 *    - Dubbel: Klein = 143 × 197, Middel = 163 × 212, Groot = 183 × 227
 *
 * 2. Het eigen assortiment (typecodes 96E t/m 190N, RAL 9010):
 *    elke typecode als ENKELE deur én als DUBBELE deur op 2× de breedte,
 *    telkens met zwart én grijs gaas. Specs die geen grijze gaaskleur
 *    kunnen kiezen bij een concurrent noteren daar eerlijk n.v.t.
 *
 * Elke entry: { breedte, hoogte, type: 'enkel'|'dubbel', gaas: 'zwart'|'grijs' }.
 */

const SIZES = {
  'Enkele klein':    { breedte: 730,  hoogte: 1970, type: 'enkel',  gaas: 'zwart' },
  'Enkele middel':   { breedte: 870,  hoogte: 2120, type: 'enkel',  gaas: 'zwart' },
  'Enkele groot':    { breedte: 1030, hoogte: 2270, type: 'enkel',  gaas: 'zwart' },
  'Dubbele klein':   { breedte: 1430, hoogte: 1970, type: 'dubbel', gaas: 'zwart' },
  'Dubbele middel':  { breedte: 1630, hoogte: 2120, type: 'dubbel', gaas: 'zwart' },
  'Dubbele groot':   { breedte: 1830, hoogte: 2270, type: 'dubbel', gaas: 'zwart' },
};

/** Eigen assortiment: typecode -> enkele-deurmaat (mm). */
const EIGEN_TYPES = [
  { code: '96E',  breedte: 960,  hoogte: 2080 },
  { code: '96O',  breedte: 960,  hoogte: 2380 },
  { code: '110O', breedte: 1100, hoogte: 2380 },
  { code: '130E', breedte: 1300, hoogte: 2080 },
  { code: '130N', breedte: 1300, hoogte: 2350 },
  { code: '160N', breedte: 1600, hoogte: 2350 },
  { code: '190N', breedte: 1900, hoogte: 2350 },
];

for (const { code, breedte, hoogte } of EIGEN_TYPES) {
  for (const gaas of ['zwart', 'grijs']) {
    SIZES[`${code} ${gaas} gaas`] = { breedte, hoogte, type: 'enkel', gaas };
  }
}
for (const { code, breedte, hoogte } of EIGEN_TYPES) {
  for (const gaas of ['zwart', 'grijs']) {
    SIZES[`Dubbel ${code} ${gaas} gaas`] = { breedte: breedte * 2, hoogte, type: 'dubbel', gaas };
  }
}

module.exports = { SIZES, EIGEN_TYPES };
