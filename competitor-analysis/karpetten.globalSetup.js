/**
 * karpetten.globalSetup.js – sticky accumulatie zoals bij de hordeuren:
 * results-parts-karpetten/ blijft staan tussen runs (echte prijzen blijven,
 * mislukte cellen krijgen een nieuwe kans). RESET_RESULTS=1 voor schone start.
 */
const { clearKarpetParts } = require('./tests-karpetten/recorder');

module.exports = async function globalSetup() {
  if (process.env.RESET_RESULTS === '1') clearKarpetParts();
};
