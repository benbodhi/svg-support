<?php
/**
 * The compatibility covenant, encoded: bodhi_svgs_settings_sanitize() must
 * behave identically to how it did in 2.6.0, because it is the single gate both
 * the classic options.php save and the AJAX autosave run through. Any change to
 * option shapes, defaults, or legacy-value handling would silently alter what a
 * million existing sites store. These tests lock that behavior.
 *
 * @group settings
 * @group covenant
 */
class Test_Settings_Sanitize extends WP_UnitTestCase {

	public function test_function_exists() {
		$this->assertTrue( function_exists( 'bodhi_svgs_settings_sanitize' ) );
	}

	/* ---- css_target ---- */

	public function test_css_target_is_sanitized_and_attr_escaped() {
		$out = bodhi_svgs_settings_sanitize( array( 'css_target' => 'style-svg' ) );
		$this->assertSame( 'style-svg', $out['css_target'] );
	}

	public function test_css_target_strips_markup() {
		$out = bodhi_svgs_settings_sanitize( array( 'css_target' => '"><script>alert(1)</script>' ) );
		// sanitize_text_field strips the tags; esc_attr encodes the quote.
		$this->assertStringNotContainsString( '<script', $out['css_target'] );
		$this->assertStringNotContainsString( '"', $out['css_target'] );
	}

	/* ---- sanitize_svg_front_end (the one key stored as false, not absent) ---- */

	public function test_front_end_sanitize_on_preserved() {
		$out = bodhi_svgs_settings_sanitize( array( 'sanitize_svg_front_end' => 'on' ) );
		$this->assertSame( 'on', $out['sanitize_svg_front_end'] );
	}

	public function test_front_end_sanitize_absent_becomes_false() {
		$out = bodhi_svgs_settings_sanitize( array() );
		$this->assertFalse( $out['sanitize_svg_front_end'] );
	}

	public function test_front_end_sanitize_unexpected_value_becomes_false() {
		$out = bodhi_svgs_settings_sanitize( array( 'sanitize_svg_front_end' => '1' ) );
		$this->assertFalse( $out['sanitize_svg_front_end'] );
	}

	/* ---- restrict: absent -> ['none'], strings coerced to array ---- */

	public function test_restrict_absent_becomes_none_sentinel() {
		$out = bodhi_svgs_settings_sanitize( array() );
		$this->assertSame( array( 'none' ), $out['restrict'] );
	}

	public function test_restrict_array_preserved() {
		$out = bodhi_svgs_settings_sanitize( array( 'restrict' => array( 'administrator', 'editor' ) ) );
		$this->assertSame( array( 'administrator', 'editor' ), $out['restrict'] );
	}

	public function test_restrict_legacy_string_coerced_to_array() {
		// Historical installs stored 'on' / 'none' as scalars. (array) must not fatal.
		$out = bodhi_svgs_settings_sanitize( array( 'restrict' => 'administrator' ) );
		$this->assertSame( array( 'administrator' ), $out['restrict'] );
	}

	/* ---- sanitize_on_upload_roles: absent -> ['none'] ---- */

	public function test_bypass_roles_absent_becomes_none_sentinel() {
		$out = bodhi_svgs_settings_sanitize( array() );
		$this->assertSame( array( 'none' ), $out['sanitize_on_upload_roles'] );
	}

	public function test_bypass_roles_array_preserved() {
		$out = bodhi_svgs_settings_sanitize( array( 'sanitize_on_upload_roles' => array( 'administrator' ) ) );
		$this->assertSame( array( 'administrator' ), $out['sanitize_on_upload_roles'] );
	}

	/* ---- checkbox 'on' keys pass through untouched ---- */

	public function test_checkbox_on_values_pass_through() {
		$in = array(
			'advanced_mode'   => 'on',
			'minify_svg'      => 'on',
			'frontend_css'    => 'on',
			'js_foot_choice'  => 'on',
			'use_vanilla_js'  => 'on',
			'use_expanded_js' => 'on',
			'force_inline_svg' => 'on',
			'auto_insert_class' => 'on',
			'del_plugin_data' => 'on',
			'skip_nested_svg' => '1',
		);
		$out = bodhi_svgs_settings_sanitize( $in );
		foreach ( $in as $k => $v ) {
			$this->assertSame( $v, $out[$k], "checkbox key $k must pass through unchanged" );
		}
	}

	/**
	 * Full round-trip on a realistic settings array: every one of the 14
	 * documented keys present, asserting the exact stored shape. This is the
	 * canary — if the option contract drifts, this fails.
	 */
	public function test_full_realistic_payload_shape() {
		$in = array(
			'restrict'               => array( 'administrator' ),
			'sanitize_on_upload_roles' => array(),
			'sanitize_svg_front_end' => 'on',
			'advanced_mode'          => 'on',
			'css_target'             => 'style-svg',
			'minify_svg'             => 'on',
			'frontend_css'           => 'on',
			'skip_nested_svg'        => '1',
			'js_foot_choice'         => 'on',
			'use_vanilla_js'         => 'on',
			'use_expanded_js'        => 'on',
			'force_inline_svg'       => 'on',
			'auto_insert_class'      => 'on',
			'del_plugin_data'        => 'on',
		);
		$out = bodhi_svgs_settings_sanitize( $in );

		$this->assertSame( array( 'administrator' ), $out['restrict'] );
		$this->assertSame( array(), $out['sanitize_on_upload_roles'] );
		$this->assertSame( 'on', $out['sanitize_svg_front_end'] );
		$this->assertSame( 'style-svg', $out['css_target'] );
		$this->assertSame( '1', $out['skip_nested_svg'] );
		// No key is dropped.
		foreach ( array_keys( $in ) as $k ) {
			$this->assertArrayHasKey( $k, $out );
		}
	}
}
