/**
 * Playwright configuration for the end-to-end suite.
 *
 * These tests drive a real browser against a real WordPress. They exist
 * because the unit suite cannot see the layer where this plugin actually
 * failed during development: an editor that navigates to the wrong post, a
 * link whose href is empty, a redirect that loops, a filter that was never
 * attached. Every one of those passed PHPUnit and broke in a browser.
 *
 * Requires `npm run env:start` first, so the tests run against the same
 * wp-env instance as the PHP suite.
 */

const { defineConfig, devices } = require( '@playwright/test' );

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';

module.exports = defineConfig( {
	testDir: './tests/e2e',
	// The staged-copy flows mutate shared site state (one shadow per post), so
	// tests cannot safely interleave.
	workers: 1,
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? 'github' : 'list',
	timeout: 60_000,
	expect: { timeout: 10_000 },

	use: {
		baseURL: BASE_URL,
		...devices[ 'Desktop Chrome' ],
		// Artifacts only for failures: enough to see what the browser saw
		// without producing a video of every green run.
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
		video: 'off',
		actionTimeout: 10_000,
	},

	projects: [
		{
			name: 'chromium',
			use: {
				...devices[ 'Desktop Chrome' ],
				storageState: './artifacts/storage-states/admin.json',
			},
			dependencies: [ 'setup' ],
		},
		{
			name: 'setup',
			testMatch: /auth\.setup\.js/,
		},
	],

	outputDir: './artifacts/test-results',
} );
