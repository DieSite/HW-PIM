/**
 * concurrenten-karpetten.js – herbouwt concurrenten-karpetten.xlsx handmatig,
 * met de laatst gescrapete prijzen uit results-parts-karpetten/ als overlay.
 *
 *   node concurrenten-karpetten.js
 *
 * De normale route is `npm run test:karpetten` (scrapet live en herbouwt de
 * Excel in de teardown); dit script is alleen voor een rebuild zonder scrape
 * (bv. na het aanpassen van de Top 10 of de matrices in karpetten-data.js).
 */
const { collectKarpetten } = require('./tests-karpetten/recorder');
const { buildKarpettenWorkbook } = require('./karpettenExcel');

buildKarpettenWorkbook(collectKarpetten()).then(f => console.log('✅ opgeslagen:', f));
