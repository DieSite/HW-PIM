/**
 * explore.js – ad-hoc DOM inspector for a single configurator URL.
 * Usage: node explore.js <url> [outName]      (headless; HEADED=1 voor zichtbare browser)
 * Dumps: page title, all inputs/selects/buttons with their attributes,
 * any element whose text looks like a price (€), and a screenshot.
 */
const { chromium } = require('@playwright/test');
const fs = require('fs');

(async () => {
  const url = process.argv[2];
  const outName = process.argv[3] || 'explore';
  if (!url) { console.error('need url'); process.exit(1); }

  const browser = await chromium.launch({ headless: process.env.HEADED !== '1' });
  const page = await browser.newPage({ locale: 'nl-NL', ignoreHTTPSErrors: true });
  page.setDefaultTimeout(20000);

  try {
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
    // try cookie accept
    for (const re of [/accepteer/i, /akkoord/i, /alles toestaan/i, /accept all/i, /ok/i]) {
      try { await page.getByRole('button', { name: re }).first().click({ timeout: 1500 }); break; } catch {}
    }
    await page.waitForTimeout(2500);

    const report = await page.evaluate(() => {
      const txt = (el) => (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 80);
      const attrs = (el) => {
        const o = {};
        for (const a of el.attributes) o[a.name] = a.value;
        return o;
      };
      const inputs = [...document.querySelectorAll('input')].map(el => ({
        type: el.type, name: el.name, id: el.id, placeholder: el.placeholder,
        cls: el.className, value: el.value,
      }));
      const selects = [...document.querySelectorAll('select')].map(el => ({
        name: el.name, id: el.id, cls: el.className,
        options: [...el.options].map(o => o.text.trim()).slice(0, 12),
      }));
      const buttons = [...document.querySelectorAll('button, a[role=button], [class*=button]')]
        .map(el => txt(el)).filter(Boolean).slice(0, 60);
      const priceEls = [...document.querySelectorAll('*')]
        .filter(el => el.children.length === 0 && /€\s*\d/.test(el.textContent || ''))
        .map(el => ({ tag: el.tagName, cls: el.className, id: el.id, text: txt(el) }))
        .slice(0, 30);
      return { title: document.title, url: location.href, inputs, selects, buttons, priceEls };
    });

    fs.writeFileSync(`${outName}.json`, JSON.stringify(report, null, 2));
    await page.screenshot({ path: `${outName}.png`, fullPage: false }).catch(() => {});
    console.log(JSON.stringify({ title: report.title, url: report.url,
      nInputs: report.inputs.length, nSelects: report.selects.length,
      nButtons: report.buttons.length, nPriceEls: report.priceEls.length }, null, 2));
  } catch (e) {
    console.error('ERROR', e.message);
  } finally {
    await browser.close();
  }
})();
