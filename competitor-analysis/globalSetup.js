/**
 * globalSetup.js – runs once before all tests.
 *
 * We bewaren results-parts/ BEWUST tussen runs: live concurrent-sites zijn flaky,
 * dus meerdere runs vullen samen (sticky) een complete dataset. Echte prijzen
 * blijven staan; alleen ontbrekende/n.v.t.-cellen krijgen een nieuwe kans.
 *
 * Schone start? Zet RESET_RESULTS=1 (of verwijder results-parts/ handmatig).
 */

const fs   = require('fs');
const path = require('path');
const { clearParts } = require('./tests/priceRecorder');

module.exports = async function globalSetup() {
  const resultsFile = path.join(__dirname, 'results.json');
  if (fs.existsSync(resultsFile)) fs.unlinkSync(resultsFile);
  if (process.env.RESET_RESULTS === '1') clearParts();
};
