// @ts-check
const { test, expect } = require( '@playwright/test' );

const SETTINGS = '/wp-admin/options-general.php?page=svg-support';
const KIT_ENDPOINT = 'https://app.kit.com/forms/9716438/subscriptions';

/**
 * Covers the Pro waitlist sign-up card added in 2.6.2. Every request to Kit is
 * intercepted, so the suite never creates a real subscriber and never needs
 * network access.
 *
 * The covenant these tests protect:
 *   - nothing is sent anywhere until an admin submits the form;
 *   - the card lives OUTSIDE the settings form, so it can never post settings
 *     or trip the autosave;
 *   - a failed sign-up leaves the form usable instead of swallowing the email.
 */
test.describe( 'SVG Support settings — Pro waitlist', () => {

	test( 'card posts to Kit, carries attribution, and is not nested in the settings form', async ( { page } ) => {
		await page.goto( SETTINGS );

		const form = page.locator( '#svgs-waitlist-form' );
		await expect( form ).toHaveAttribute( 'action', KIT_ENDPOINT );
		await expect( form ).toHaveAttribute( 'method', 'post' );

		// No-JS fallback opens Kit in a new tab rather than replacing wp-admin.
		await expect( form ).toHaveAttribute( 'target', '_blank' );

		await expect( page.locator( '#svgs-waitlist-email' ) ).toHaveAttribute( 'type', 'email' );
		await expect( form.locator( 'input[name="utm_source"][value="svg-support-plugin"]' ) ).toHaveCount( 1 );

		// A nested form would post the sidebar with the settings — and vice versa.
		const nested = await page.evaluate( () => {
			const settings = document.getElementById( 'svgs-settings-form' );
			const waitlist = document.getElementById( 'svgs-waitlist-form' );
			return settings.contains( waitlist );
		} );
		expect( nested ).toBe( false );

		// The confirmation slot starts genuinely hidden, not just [hidden].
		await expect( page.locator( '#svgs-waitlist-status' ) ).toBeHidden();
	} );

	test( 'signing up confirms inline without leaving the settings screen', async ( { page } ) => {
		let posted = null;

		await page.route( 'https://app.kit.com/**', async ( route ) => {
			posted = route.request().postData();
			await route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( { subscription: { id: 1 } } ),
			} );
		} );

		await page.goto( SETTINGS );
		await page.locator( '#svgs-waitlist-email' ).fill( 'e2e@example.com' );
		await page.locator( '#svgs-waitlist-form button[type="submit"]' ).click();

		const status = page.locator( '#svgs-waitlist-status' );
		await expect( status ).toBeVisible( { timeout: 6000 } );
		await expect( status ).not.toHaveClass( /is-error/ );

		// Form (and its "what gets sent" note) give way to the confirmation.
		await expect( page.locator( '#svgs-waitlist-form' ) ).toBeHidden();
		await expect( page.locator( '.svgs-waitlist-fine' ) ).toBeHidden();

		expect( posted ).toContain( 'e2e@example.com' );
		expect( posted ).toContain( 'svg-support-plugin' );

		// Still on the settings screen — the submit was backgrounded.
		expect( page.url() ).toContain( 'page=svg-support' );
	} );

	test( 'a quarantined submission is not reported as a sign-up', async ( { page } ) => {
		// Kit's spam guard answers 200 with a verdict, not a subscriber. Nobody
		// is subscribed and no email is sent until the linked check is passed.
		const guard = 'https://app.kit.com/forms/guards/abc123';
		await page.route( 'https://app.kit.com/**', ( route ) => {
			if ( route.request().url().includes( '/subscriptions' ) ) {
				return route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify( { status: 'quarantined', url: guard } ),
				} );
			}
			return route.fulfill( { status: 200, body: '' } );
		} );

		await page.goto( SETTINGS );
		await page.locator( '#svgs-waitlist-email' ).fill( 'e2e@example.com' );
		await page.locator( '#svgs-waitlist-form button[type="submit"]' ).click();

		const status = page.locator( '#svgs-waitlist-status' );
		await expect( status ).toBeVisible( { timeout: 6000 } );
		await expect( status ).not.toContainText( 'check your inbox' );

		// The person gets a way to finish, in a new tab.
		const link = status.locator( 'a' );
		await expect( link ).toHaveAttribute( 'href', guard );
		await expect( link ).toHaveAttribute( 'target', '_blank' );

		// The form stays put so they can retry after passing the check.
		await expect( page.locator( '#svgs-waitlist-form' ) ).toBeVisible();
		await expect( page.locator( '#svgs-waitlist-form button[type="submit"]' ) ).toBeEnabled();
	} );

	test( 'a guard URL that is not Kit is never linked', async ( { page } ) => {
		// The URL arrives from a third party and lands in wp-admin, so anything
		// off kit.com must be refused rather than rendered as a link.
		await page.route( 'https://app.kit.com/**', ( route ) =>
			route.fulfill( {
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify( { status: 'quarantined', url: 'https://evil.example.com/phish' } ),
			} )
		);

		await page.goto( SETTINGS );
		await page.locator( '#svgs-waitlist-email' ).fill( 'e2e@example.com' );
		await page.locator( '#svgs-waitlist-form button[type="submit"]' ).click();

		const status = page.locator( '#svgs-waitlist-status' );
		await expect( status ).toBeVisible( { timeout: 6000 } );
		await expect( status.locator( 'a' ) ).toHaveCount( 0 );
		await expect( page.locator( 'a[href*="evil.example.com"]' ) ).toHaveCount( 0 );

		// Refusing the link must not turn a quarantine into a fake success:
		// the submission still did not subscribe anyone.
		await expect( status ).toHaveClass( /is-error/ );
		await expect( status ).not.toContainText( 'check your inbox' );
		await expect( page.locator( '#svgs-waitlist-form' ) ).toBeVisible();
	} );

	test( 'a failed sign-up reports the error and leaves the form usable', async ( { page } ) => {
		await page.route( 'https://app.kit.com/**', ( route ) => route.fulfill( { status: 500, body: '' } ) );

		await page.goto( SETTINGS );
		await page.locator( '#svgs-waitlist-email' ).fill( 'e2e@example.com' );
		await page.locator( '#svgs-waitlist-form button[type="submit"]' ).click();

		const status = page.locator( '#svgs-waitlist-status' );
		await expect( status ).toBeVisible( { timeout: 6000 } );
		await expect( status ).toHaveClass( /is-error/ );

		await expect( page.locator( '#svgs-waitlist-form' ) ).toBeVisible();
		await expect( page.locator( '#svgs-waitlist-form button[type="submit"]' ) ).toBeEnabled();
	} );

	test( 'an invalid address never reaches Kit', async ( { page } ) => {
		let requests = 0;
		await page.route( 'https://app.kit.com/**', ( route ) => {
			requests++;
			return route.fulfill( { status: 200, contentType: 'application/json', body: '{}' } );
		} );

		await page.goto( SETTINGS );
		await page.locator( '#svgs-waitlist-email' ).fill( 'not-an-email' );
		await page.locator( '#svgs-waitlist-form button[type="submit"]' ).click();

		await expect( page.locator( '#svgs-waitlist-status' ) ).toBeHidden();
		expect( requests ).toBe( 0 );
	} );
} );
