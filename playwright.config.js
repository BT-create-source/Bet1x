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
// Extensions to load for the local server. On CI (setup-php) they are built in, and loading them twice
// prints a startup warning that can leak into responses, so CI passes PHP_EXT_FLAGS="".
const PHP_EXT = process.env.PHP_EXT_FLAGS !== undefined ? process.env.PHP_EXT_FLAGS : '-d extension=pdo_pgsql -d extension=curl';

module.exports = defineConfig({
  testDir: 'e2e',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  retries: process.env.CI ? 1 : 0,
  // One test at a time: every test signs in as the same test account, and two games played on it at
  // once (e.g. the desktop and phone Chicken Road tests) would interfere. The whole suite takes ~4 min.
  workers: 1,
  // On CI the 'github' reporter also posts each failure as an annotation on the run, readable without
  // downloading anything.
  reporter: process.env.CI
    ? [['github'], ['list'], ['html', { open: 'never', outputFolder: 'e2e-report' }]]
    : [['list'], ['html', { open: 'never', outputFolder: 'e2e-report' }]],
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
    command: `${PHP} ${PHP_EXT} -d display_startup_errors=0 -S 127.0.0.1:${PORT} router.php`.replace(/\s+/g, ' '),
    url: `${BASE}/api/health`,
    reuseExistingServer: !process.env.CI,
    timeout: 30_000,
  },
});
