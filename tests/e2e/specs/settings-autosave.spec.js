// @ts-check
const { test, expect } = require( '@playwright/test' );

const SETTINGS = '/wp-admin/options-general.php?page=svg-support';
const ADVANCED = 'input[name="bodhi_svgs_settings[advanced_mode]"]';
const CSS_TARGET = 'input[name="bodhi_svgs_settings[css_target]"]';

/**
 * Resolve when the next autosave request lands.
 *
 * Arm this *before* the change that triggers the save. The save-state chip
 * lingers on "saved" for 2.5s after the previous change, so a test that only
 * waits for the chip can match that stale value and race ahead of a save whose
 * debounce (400ms on change, 800ms while typing) has not even fired yet.
 */
function nextAutosave( page ) {
	return page.waitForResponse(
		( res ) =>
			res.url().includes( 'admin-ajax.php' ) &&
			( res.request().postData() || '' ).includes( 'bodhi_svgs_autosave' ),
		{ timeout: 10000 }
	);
}

/**
 * Exercises the redesigned settings screen shipped in 2.6.0: debounced AJAX
 * autosave, the live Advanced Mode reveal, and the classic (no-JS) fallback
 * form. These are the new surfaces the covenant most needs to keep working.
 */
test.describe( 'SVG Support settings — autosave & reveal', () => {

	test( 'Advanced Mode reveals sections live, autosaves, and persists', async ( { page } ) => {
		await page.goto( SETTINGS );

		const toggle = page.locator( ADVANCED );
		await expect( toggle ).toHaveCount( 1 );

		// Normalize to OFF first so the test is deterministic.
		if ( await toggle.isChecked() ) {
			const normalized = nextAutosave( page );
			await toggle.uncheck( { force: true } );
			await normalized;
		}

		const firstAdvanced = page.locator( '.svgs-advanced' ).first();
		await expect( firstAdvanced ).toBeHidden();

		// Turn Advanced Mode ON — sections should reveal immediately (no reload).
		const savedOn = nextAutosave( page );
		await toggle.check( { force: true } );
		await expect( firstAdvanced ).toBeVisible();

		// The save lands, and the save-state chip reports it.
		await savedOn;
		await expect( page.locator( '#svgs-savestate' ) ).toHaveAttribute( 'data-state', 'saved', { timeout: 6000 } );

		// Persistence: reload and confirm it stuck.
		await page.reload();
		await expect( page.locator( ADVANCED ) ).toBeChecked();
		await expect( page.locator( '.svgs-advanced' ).first() ).toBeVisible();

		// Restore OFF so we leave the site as we found it.
		const restored = nextAutosave( page );
		await page.locator( ADVANCED ).uncheck( { force: true } );
		await restored;
	} );

	test( 'css_target text input autosaves and persists', async ( { page } ) => {
		await page.goto( SETTINGS );

		// css_target lives inside the Advanced Mode section, which is hidden
		// while Advanced Mode is off. Enable it first so the field is reachable.
		const toggle = page.locator( ADVANCED );
		if ( ! ( await toggle.isChecked() ) ) {
			const advancedOn = nextAutosave( page );
			await toggle.check( { force: true } );
			await advancedOn;
		}

		const input = page.locator( CSS_TARGET );
		await expect( input ).toBeVisible();

		const value = 'style-svg-e2e';
		// Typing debounce is 800ms; wait for the request itself, then confirm
		// the chip agrees.
		const savedValue = nextAutosave( page );
		await input.fill( value );
		await savedValue;
		await expect( page.locator( '#svgs-savestate' ) ).toHaveAttribute( 'data-state', 'saved', { timeout: 8000 } );

		await page.reload();
		await expect( page.locator( CSS_TARGET ) ).toHaveValue( value );

		// Restore empty (falls back to the default "style-svg" at render time).
		const cleared = nextAutosave( page );
		await page.locator( CSS_TARGET ).fill( '' );
		await cleared;

		// Leave Advanced Mode off so the site is as we found it.
		const advancedOff = nextAutosave( page );
		await page.locator( ADVANCED ).uncheck( { force: true } );
		await advancedOff;
	} );

	test( 'classic no-JS fallback form is intact', async ( { page } ) => {
		await page.goto( SETTINGS );

		// The form posts to options.php with the correct settings group nonce,
		// so saving still works if AJAX is blocked / JS disabled.
		const form = page.locator( 'form#svgs-settings-form' );
		await expect( form ).toHaveAttribute( 'action', /options\.php$/ );
		await expect( form.locator( 'input[name="option_page"][value="bodhi_svgs_settings_group"]' ) ).toHaveCount( 1 );
		await expect( form.locator( 'input[type="submit"]' ) ).toHaveCount( 1 );
	} );
} );
