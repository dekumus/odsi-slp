/**
 * Playwright configuration for the end-to-end suite.
 *
 * Targets a running WordPress. By default that is the wp-env "tests" site on
 * port 8889; set ODSI_E2E_BASE_URL to point elsewhere (for example the
 * php -S server from bin/serve-local.sh).
 */
const { defineConfig, devices } = require( '@playwright/test' );

const baseURL = process.env.ODSI_E2E_BASE_URL || 'http://localhost:8889';

// Environments with a pre-installed Chromium (Claude Code on the web, some CI
// images) point at it with ODSI_E2E_CHROMIUM instead of downloading one.
const launchOptions = process.env.ODSI_E2E_CHROMIUM ? { executablePath: process.env.ODSI_E2E_CHROMIUM } : {};

module.exports = defineConfig( {
	testDir: __dirname,
	testMatch: /.*\.spec\.js/,
	timeout: 60_000,
	expect: { timeout: 10_000 },
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [ [ 'github' ], [ 'list' ] ] : 'list',
	use: {
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
		...devices[ 'Desktop Chrome' ],
		launchOptions,
	},
	outputDir: '../../.playwright/results',
} );
