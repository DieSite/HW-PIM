/**
 * volledig.globalTeardown.js – herbouw concurrenten-volledig.xlsx na de
 * browser-spec-run (de prijzen zitten al in de SQLite-DB).
 */
const { execSync } = require('child_process');
const path = require('path');

module.exports = async function globalTeardown() {
  execSync(`node ${path.join(__dirname, 'excel.js')}`, { stdio: 'inherit' });
};
