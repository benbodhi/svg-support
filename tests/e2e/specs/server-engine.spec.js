// @ts-check
const path = require( 'path' );
const { test, expect } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );

const BASE = process.env.WP_BASE_URL || 'http://localhost:8888';
const SETTINGS = '/wp-admin/options-general.php?page=svg-support';
const FIXTURE = path.join( __dirname, '..', '..', 'fixtures', 'benign', 'simple-icon.svg' );

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

/**
 * Put the site into a known rendering state via the real settings UI.
 *
 * @param {import('@playwright/test').Page} page
 * @param {'server'|'legacy'} mode
 */
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

async function resetSettings( page ) {
	await page.goto( SETTINGS );
	const advanced = page.locator( 'input[name="bodhi_svgs_settings[advanced_mode]"]' );
	if ( await advanced.isChecked() ) {
		await advanced.uncheck( { force: true } );
		await waitSaved( page );
	}
}

test.describe( 'v3 rendering pipeline (block + content images)', () => {
	let media;
	let post;

	test.beforeAll( async () => {
		media = await requestUtils.uploadMedia( FIXTURE );

		post = await requestUtils.createPost( {
			title: 'engine-e2e',
			status: 'publish',
			content:
				`<!-- wp:svg-support/inline-svg {"id":${ media.id },"url":"${ media.source_url }","width":"120px","alt":"Block graphic"} /-->\n` +
				`<!-- wp:html --><img class="style-svg" src="${ media.source_url }" alt="Front icon" width="48" height="48" /><!-- /wp:html -->`,
		} );
	} );

	test.afterAll( async ( { browser } ) => {
		const page = await browser.newPage();
		await resetSettings( page );
		await page.close();
	} );

	test( 'server mode: everything inlines in PHP, no swap script, attributes preserved', async ( { page } ) => {
		await setEngine( page, 'server' );

		await page.goto( post.link );

		// Both the block and the classed content image are true inline SVG.
		await expect( page.locator( 'svg.replaced-svg' ) ).toHaveCount( 2 );
		await expect( page.locator( 'img.style-svg' ) ).toHaveCount( 0 );

		// The legacy swap script and DOMPurify must NOT load.
		await expect( page.locator( 'script#bodhi_svg_inline-js' ) ).toHaveCount( 0 );
		await expect( page.locator( 'script#bodhi-dompurify-library-js' ) ).toHaveCount( 0 );

		// Attribute preservation — the whole point of the server engine.
		const contentSvg = page.locator( 'svg[aria-label="Front icon"]' );
		await expect( contentSvg ).toHaveCount( 1 );
		await expect( contentSvg ).toHaveAttribute( 'width', '48' );
		await expect( contentSvg ).toHaveAttribute( 'height', '48' );
		await expect( contentSvg ).toHaveAttribute( 'role', 'img' );

		// Block render: width control + block class + accessible name.
		const blockSvg = page.locator( 'svg.wp-block-svg-support-inline-svg' );
		await expect( blockSvg ).toHaveCount( 1 );
		await expect( blockSvg ).toHaveAttribute( 'width', '120px' );
		await expect( blockSvg ).toHaveAttribute( 'aria-label', 'Block graphic' );
	} );

	test( 'legacy mode: swap script loads, JS swaps in browser, block still server-renders', async ( { page } ) => {
		await setEngine( page, 'legacy' );

		await page.goto( post.link );

		// The classic engine ships its script (this is the legacy contract).
		await expect( page.locator( 'script#bodhi_svg_inline-js' ) ).toHaveCount( 1 );

		// The block renders inline server-side regardless of engine choice…
		await expect( page.locator( 'svg.wp-block-svg-support-inline-svg' ) ).toHaveCount( 1 );

		// …and the content image is swapped by JS after load.
		await expect( page.locator( 'svg.replaced-svg' ) ).toHaveCount( 2 );

		// Legacy swap drops alt/aria (locked, documented behavior — the JS
		// engine keeps its exact historical output).
		await expect( page.locator( 'svg[aria-label="Front icon"]' ) ).toHaveCount( 0 );
	} );
} );
