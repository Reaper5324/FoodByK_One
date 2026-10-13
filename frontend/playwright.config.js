const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests/ui',
  fullyParallel: true,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: 'http://127.0.0.1:4173',
    browserName: 'chromium',
    trace: 'retain-on-failure',
  },
  webServer: {
    command: 'python -m http.server 4173 --directory .',
    url: 'http://127.0.0.1:4173',
    cwd: __dirname,
    reuseExistingServer: !process.env.CI,
    timeout: 15_000,
  },
});
