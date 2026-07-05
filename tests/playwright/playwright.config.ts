import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright config for the cybersource_rest module's end-to-end test.
 *
 * Override the base URL for your environment, e.g.:
 *   CYBERSOURCE_REST_BASE_URL=https://dev.cybersource npx playwright test
 */
export default defineConfig({
  testDir: '.',
  fullyParallel: false,
  workers: 1,
  reporter: 'list',
  timeout: 60000,
  use: {
    baseURL: process.env.CYBERSOURCE_REST_BASE_URL || 'https://dev.cybersource',
    ignoreHTTPSErrors: true,
    screenshot: 'only-on-failure',
    trace: 'on-first-retry',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
