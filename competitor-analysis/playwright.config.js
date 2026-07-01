// @ts-check
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  globalSetup:  './globalSetup.js',
  globalTeardown: './globalTeardown.js',
  testDir: './tests',
  timeout: 60_000,
  retries: 1,
  reporter: [['list']],
  use: {
    headless: process.env.HEADED === '1' ? false : true, // standaard headless; HEADED=1 om browsers te zien
    actionTimeout: 10_000, // een element dat afgedekt blijft (bv. cookiebalk) mag een test nooit laten hangen
    viewport: { width: 1280, height: 900 },
    locale: 'nl-NL',
    ignoreHTTPSErrors: true,
    video: 'off',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
  outputDir: 'results/',
});
