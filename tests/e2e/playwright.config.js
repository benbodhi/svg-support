// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );
const path = require( 'path' );

/**
 * Playwright config for the SVG Support e2e suite. Targets the wp-env dev site
 * (npm run env:start) on http://localhost:8888. In CI the same wp-env boot is
 * reused. Auth state is created once by tests/e2e/global-setup.js.
 *
 * Paths are absolute (via __dirname) so they resolve identically regardless of
 * the working directory the runner invokes Playwright from.
 */
const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';
const ARTIFACTS = path.join( __dirname, 'artifacts' );

module.exports = defineConfig( {
	testDir: path.join( __dirname, 'specs' ),
	outputDir: ARTIFACTS,
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: process.env.CI ? [ [ 'github' ], [ 'list' ] ] : [ [ 'list' ] ],
	globalSetup: require.resolve( './global-setup' ),
	use: {
		baseURL: BASE_URL,
		storageState: path.join( ARTIFACTS, 'admin-state.json' ),
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
	projects: [
		{ name: 'chromium', use: { ...devices[ 'Desktop Chrome' ] } },
	],
} );
