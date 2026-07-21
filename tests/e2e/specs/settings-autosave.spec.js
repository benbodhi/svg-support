// @ts-check
const { test, expect } = require( '@playwright/test' );

const SETTINGS = '/wp-admin/options-general.php?page=svg-support';
const ADVANCED = 'input[name="bodhi_svgs_settings[advanced_mode]"]';
const CSS_TARGET = 'input[name="bodhi_svgs_settings[css_target]"]';

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
			await toggle.uncheck( { force: true } );
			await expect( page.locator( '#svgs-savestate' ) ).toHaveAttribute( 'data-state', 'saved', { timeout: 6000 } );
		}

		const firstAdvanced = page.locator( '.svgs-advanced' ).first();
		await expect( firstAdvanced ).toBeHidden();

		// Turn Advanced Mode ON — sections should reveal immediately (no reload).
		await toggle.check( { force: true } );
		await expect( firstAdvanced ).toBeVisible();

		// The save-state chip should reach "saved".
		await expect( page.locator( '#svgs-savestate' ) ).toHaveAttribute( 'data-state', 'saved', { timeout: 6000 } );

		// Persistence: reload and confirm it stuck.
		await page.reload();
		await expect( page.locator( ADVANCED ) ).toBeChecked();
		await expect( page.locator( '.svgs-advanced' ).first() ).toBeVisible();

		// Restore OFF so we leave the site as we found it.
		await page.locator( ADVANCED ).uncheck( { force: true } );
		await expect( page.locator( '#svgs-savestate' ) ).toHaveAttribute( 'data-state', 'saved', { timeout: 6000 } );
	} );

	test( 'css_target text input autosaves and persists', async ( { page } ) => {
		await page.goto( SETTINGS );

		const input = page.locator( CSS_TARGET );
		await expect( input ).toHaveCount( 1 );

		const value = 'style-svg-e2e';
		await input.fill( value );
		// Typing debounce is 800ms; the chip confirms the save landed.
		await expect( page.locator( '#svgs-savestate' ) ).toHaveAttribute( 'data-state', 'saved', { timeout: 8000 } );

		await page.reload();
		await expect( page.locator( CSS_TARGET ) ).toHaveValue( value );

		// Restore empty (falls back to the default "style-svg" at render time).
		await page.locator( CSS_TARGET ).fill( '' );
		await expect( page.locator( '#svgs-savestate' ) ).toHaveAttribute( 'data-state', 'saved', { timeout: 8000 } );
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
