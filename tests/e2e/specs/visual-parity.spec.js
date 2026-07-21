// @ts-check
/**
 * Visual parity: the legacy JS-swap engine and the server-side engine must
 * produce visually identical results on identical content. We render the same
 * classed SVG under each engine, screenshot the rendered element, and pixel-
 * diff the two. The whole selling point of the rewrite is "same output, better
 * delivery" — this proves the "same output" half.
 */
const path = require( 'path' );
const { test, expect } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );
const { PNG } = require( 'pngjs' );
const pixelmatch = require( 'pixelmatch' );

const BASE = process.env.WP_BASE_URL || 'http://localhost:8888';
const SETTINGS = '/wp-admin/options-general.php?page=svg-support';
const FIXTURE = path.join( __dirname, '..', '..', 'fixtures', 'benign', 'gradient.svg' );

/** @type {RequestUtils} */
let requestUtils;

test.beforeAll( async () => {
	requestUtils = await RequestUtils.setup( {
		baseURL: BASE,
		user: { username: 'admin', password: 'password' },
	} );
} );

async function waitSaved( page ) {
	await expect( page.locator( '#svgs-savestate' ) ).toHaveAttribute( 'data-state', 'saved', { timeout: 8000 } );
}

async function setEngine( page, mode ) {
	await page.goto( SETTINGS );
	const advanced = page.locator( 'input[name="bodhi_svgs_settings[advanced_mode]"]' );
	if ( ! ( await advanced.isChecked() ) ) {
		await advanced.check( { force: true } );
		await waitSaved( page );
	}
	const radio = page.locator( `input[name="bodhi_svgs_settings[render_mode]"][value="${ mode }"]` );
	if ( ! ( await radio.isChecked() ) ) {
		await radio.check( { force: true } );
		await waitSaved( page );
	}
}

test.describe( 'legacy vs server visual parity', () => {
	let post;

	test.beforeAll( async () => {
		const media = await requestUtils.uploadMedia( FIXTURE );
		post = await requestUtils.createPost( {
			title: 'parity',
			status: 'publish',
			content: `<!-- wp:html --><img class="style-svg" src="${ media.source_url }" width="100" height="100" /><!-- /wp:html -->`,
		} );
	} );

	test.afterAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await page.goto( SETTINGS );
		const advanced = page.locator( 'input[name="bodhi_svgs_settings[advanced_mode]"]' );
		if ( await advanced.isChecked() ) {
			await advanced.uncheck( { force: true } );
			await waitSaved( page );
		}
		await page.close();
	} );

	test( 'both engines render the same SVG identically', async ( { page } ) => {
		// Server render.
		await setEngine( page, 'server' );
		await page.goto( post.link );
		const serverSvg = page.locator( 'svg.replaced-svg' ).first();
		await expect( serverSvg ).toBeVisible();
		const serverShot = await serverSvg.screenshot();

		// Legacy render (JS swaps after load; wait for the swapped element).
		await setEngine( page, 'legacy' );
		await page.goto( post.link );
		const legacySvg = page.locator( 'svg.replaced-svg' ).first();
		await expect( legacySvg ).toBeVisible( { timeout: 10000 } );
		const legacyShot = await legacySvg.screenshot();

		// Pixel diff.
		const a = PNG.sync.read( serverShot );
		const b = PNG.sync.read( legacyShot );

		expect( a.width ).toBe( b.width );
		expect( a.height ).toBe( b.height );

		const diff = new PNG( { width: a.width, height: a.height } );
		const mismatched = pixelmatch( a.data, b.data, diff.data, a.width, a.height, { threshold: 0.1 } );

		const totalPixels = a.width * a.height;
		const ratio = mismatched / totalPixels;

		// Allow a tiny tolerance for anti-aliasing differences.
		expect( ratio, `${ ( ratio * 100 ).toFixed( 2 ) }% of pixels differ between engines` ).toBeLessThan( 0.01 );
	} );
} );
