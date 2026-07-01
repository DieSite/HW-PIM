/**
 * karpetten.globalTeardown.js – merge de per-test deelbestanden en herbouw
 * concurrenten-karpetten.xlsx met de live prijzen als overlay.
 */
const { collectKarpetten } = require('./tests-karpetten/recorder');
const { buildKarpettenWorkbook } = require('./karpettenExcel');

module.exports = async function globalTeardown() {
  await buildKarpettenWorkbook(collectKarpetten());
  console.log('\n✅ Excel bijgewerkt: concurrenten-karpetten.xlsx');
};
