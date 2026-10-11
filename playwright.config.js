// Browser tests for bet1x (Playwright). See TESTING.md.
//
//   npm run test:ui            start the PHP site locally on :5077 and run every test, desktop + phone
//   npm run test:ui:report     open the last HTML report (screenshots of every page are attached)
//   BASE_URL=https://bet1x.biz npm run test:ui:live   read-only checks against a deployed site
//
// The local / CI run uses the database in php-backend/.env (or the DB_* environment variables in CI)
// and the simulated cricket league, so there is always "live" cricket to test against. Never point a
// full run at production: it places test bets with the e2e test account.
const { defineConfig, devices } = require('@playwright/test');

const PORT = process.env.E2E_PORT || 5077;
const BASE = process.env.BASE_URL || `http://127.0.0.1:${PORT}`;
const PHP = process.env.PHP_BIN || 'php';

module.exports = defineConfig({
  testDir: 'e2e',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  retries: process.env.CI ? 1 : 0,
  // PHP's built-in server answers one request at a time on Windows; CI (Linux) runs it with several workers.
  workers: process.env.CI ? 3 : 1,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'e2e-report' }]],
  use: {
    baseURL: BASE,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1366, height: 820 } } },
    { name: 'mobile', use: { ...devices['Pixel 7'] } },
  ],
  webServer: process.env.BASE_URL ? undefined : {
    command: `${PHP} -d extension=pdo_pgsql -d extension=curl -S 127.0.0.1:${PORT} router.php`,
    url: `${BASE}/api/health`,
    reuseExistingServer: !process.env.CI,
    timeout: 30_000,
  },
});
