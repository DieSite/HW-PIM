// @ts-check
/**
 * Playwright-config voor de KARPETTEN-suite (los van de hordeuren-suite):
 *   npm run test:karpetten
 * Zelfde conventies als playwright.config.js; eigen testDir, eigen
 * setup/teardown (schrijft concurrenten-karpetten.xlsx) en eigen
 * results-parts-karpetten/ met sticky accumulatie.
 */
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  globalSetup:    './karpetten.globalSetup.js',
  globalTeardown: './karpetten.globalTeardown.js',
  testDir: './tests-karpetten',
  timeout: 60_000,
  retries: 1,
  reporter: [['list']],
  use: {
    headless: process.env.HEADED === '1' ? false : true,
    actionTimeout: 10_000,
    viewport: { width: 1280, height: 900 },
    locale: 'nl-NL',
    ignoreHTTPSErrors: true,
    video: 'off',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
  outputDir: 'results-karpetten/',
});
