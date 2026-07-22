// @ts-check
/**
 * Block editor smoke test: the SVG block must register in the JS block registry
 * and its edit() must render without throwing. The front-end render is covered
 * by server-engine.spec.js; this catches a broken editor script
 * (registerBlockType / edit() errors) that PHP-side tests can't see.
 *
 * Uses the WordPress editor fixtures (admin/editor) rather than raw selectors
 * so it's resilient to editor-chrome changes across WP versions.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'SVG block — editor', () => {

	test( 'registers in the block registry with the right metadata', async ( { admin, page } ) => {
		await admin.createNewPost();

		const block = await page.evaluate( () => {
			const type = window.wp.blocks.getBlockType( 'svg-support/svg' );
			return type ? { name: type.name, title: type.title, category: type.category } : null;
		} );

		expect( block ).not.toBeNull();
		expect( block.name ).toBe( 'svg-support/svg' );
		expect( block.title ).toBe( 'SVG' );
		expect( block.category ).toBe( 'media' );
	} );

	test( 'inserts and renders its media placeholder without errors', async ( { admin, editor, page } ) => {
		const errors = [];
		page.on( 'pageerror', ( err ) => errors.push( err.message ) );

		await admin.createNewPost();
		await editor.insertBlock( { name: 'svg-support/svg' } );

		// edit() with no media chosen shows the MediaPlaceholder.
		const canvas = editor.canvas;
		await expect(
			canvas.getByRole( 'button', { name: /media library/i } ).first()
		).toBeVisible();

		expect( errors, 'no uncaught JS errors from the block script' ).toEqual( [] );
	} );
} );
