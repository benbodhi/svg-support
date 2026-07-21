// @ts-check
const { chromium } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

/**
 * Log in to wp-admin once as the default wp-env admin (admin / password) and
 * persist the auth cookies so every spec starts authenticated.
 */
module.exports = async () => {
	const baseURL = process.env.WP_BASE_URL || 'http://localhost:8888';
	const user = process.env.WP_ADMIN_USER || 'admin';
	const pass = process.env.WP_ADMIN_PASS || 'password';

	const artifacts = path.join( __dirname, 'artifacts' );
	fs.mkdirSync( artifacts, { recursive: true } );

	const browser = await chromium.launch();
	const page = await browser.newPage();

	await page.goto( `${ baseURL }/wp-login.php` );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await page.click( '#wp-submit' );
	await page.waitForSelector( '#wpadminbar', { timeout: 30000 } );

	await page.context().storageState( { path: path.join( artifacts, 'admin-state.json' ) } );
	await browser.close();
};
