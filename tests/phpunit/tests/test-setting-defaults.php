<?php
/**
 * Locks the load-time defaults contract. v3.0 moved these from
 * write-on-every-request to an in-memory merge (bodhi_svgs_apply_setting_defaults)
 * persisted once on activation/upgrade (bodhi_svgs_persist_settings). The merged
 * VALUES must be identical to what 2.6.0 wrote to the database.
 *
 * @group settings
 * @group covenant
 */
class Test_Setting_Defaults extends WP_UnitTestCase {

	public function test_empty_options_get_security_defaults() {
		$out = bodhi_svgs_apply_setting_defaults( array() );

		$this->assertSame( 'on', $out['sanitize_svg_front_end'], 'front-end sanitization defaults on' );
		$this->assertSame( array( 'administrator' ), $out['restrict'], 'uploads default to admins only' );
		$this->assertSame( array(), $out['sanitize_on_upload_roles'], 'nobody bypasses sanitization by default' );
	}

	public function test_legacy_restrict_on_string_maps_to_administrator() {
		$out = bodhi_svgs_apply_setting_defaults( array( 'restrict' => 'on' ) );
		$this->assertSame( array( 'administrator' ), $out['restrict'] );
	}

	public function test_legacy_restrict_none_string_maps_to_sentinel() {
		$out = bodhi_svgs_apply_setting_defaults( array( 'restrict' => 'none' ) );
		$this->assertSame( array( 'none' ), $out['restrict'] );
	}

	public function test_legacy_bypass_none_string_maps_to_sentinel() {
		$out = bodhi_svgs_apply_setting_defaults( array( 'sanitize_on_upload_roles' => 'none' ) );
		$this->assertSame( array( 'none' ), $out['sanitize_on_upload_roles'] );
	}

	public function test_existing_values_are_untouched() {
		$in = array(
			'restrict'                 => array( 'editor' ),
			'sanitize_on_upload_roles' => array( 'administrator' ),
			'sanitize_svg_front_end'   => false, // stored-false (feature off) must survive
			'advanced_mode'            => 'on',
			'css_target'               => 'my-svg',
		);
		$out = bodhi_svgs_apply_setting_defaults( $in );

		$this->assertSame( array( 'editor' ), $out['restrict'] );
		$this->assertSame( array( 'administrator' ), $out['sanitize_on_upload_roles'] );
		$this->assertFalse( $out['sanitize_svg_front_end'], 'explicit false (off) must not be re-defaulted to on' );
		$this->assertSame( 'on', $out['advanced_mode'] );
		$this->assertSame( 'my-svg', $out['css_target'] );
	}

	public function test_persist_writes_merged_defaults_and_strips_legacy_key() {
		global $bodhi_svgs_options;
		$backup = $bodhi_svgs_options;

		$bodhi_svgs_options                 = bodhi_svgs_apply_setting_defaults( array() );
		$bodhi_svgs_options['sanitize_svg'] = 'on'; // legacy pre-2.5 key

		bodhi_svgs_persist_settings();

		$stored = get_option( 'bodhi_svgs_settings' );
		$this->assertIsArray( $stored );
		$this->assertSame( 'on', $stored['sanitize_svg_front_end'] );
		$this->assertSame( array( 'administrator' ), $stored['restrict'] );
		$this->assertArrayNotHasKey( 'sanitize_svg', $stored, 'legacy key must never be re-persisted' );

		$bodhi_svgs_options = $backup;
		update_option( 'bodhi_svgs_settings', $backup );
	}

	public function test_version_update_persists_defaults_once() {
		global $bodhi_svgs_options;
		$backup = $bodhi_svgs_options;

		// Simulate an upgraded site: old version stored, sparse settings in DB.
		update_option( 'bodhi_svgs_plugin_version', '2.5.9' );
		update_option( 'bodhi_svgs_settings', array( 'advanced_mode' => 'on' ) );
		$bodhi_svgs_options = bodhi_svgs_apply_setting_defaults( array( 'advanced_mode' => 'on' ) );

		bodhi_svgs_version_updates();

		$this->assertSame( BODHI_SVGS_VERSION, get_option( 'bodhi_svgs_plugin_version' ) );
		$stored = get_option( 'bodhi_svgs_settings' );
		$this->assertSame( 'on', $stored['advanced_mode'], 'existing settings survive the upgrade persist' );
		$this->assertSame( array( 'administrator' ), $stored['restrict'], 'defaults are persisted on upgrade' );

		$bodhi_svgs_options = $backup;
		update_option( 'bodhi_svgs_settings', $backup );
	}
}
