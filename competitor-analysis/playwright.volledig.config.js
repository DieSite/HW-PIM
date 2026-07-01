// @ts-check
/**
 * Playwright-config voor de catalog-volledig BROWSER-shops (Cloudflare e.d.).
 *
 *   npm run volledig:browser
 *
 * Alleen de shops met `browser: true` in catalog-volledig/shops.js draaien hier
 * — de rest (Shopify/WooCommerce/sitemap) gaat via de headless node-pipeline
 * (npm run volledig). Schrijft naar dezelfde SQLite-DB (sticky) en herbouwt na
 * afloop concurrenten-volledig.xlsx.
 *
 * workers: 1 — Cloudflare-politeness én één SQLite-schrijver tegelijk.
 */
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  globalTeardown: './catalog-volledig/volledig.globalTeardown.js',
  testDir: './catalog-volledig/specs',
  timeout: 180_000,          // sitemap-discovery + veel paginaloads per shop
  retries: 0,
  workers: 1,
  reporter: [['list']],
  use: {
    headless: process.env.HEADED === '1' ? false : true,
    actionTimeout: 15_000,
    viewport: { width: 1280, height: 900 },
    locale: 'nl-NL',
    ignoreHTTPSErrors: true,
    video: 'off',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
  outputDir: 'results-volledig/',
});
