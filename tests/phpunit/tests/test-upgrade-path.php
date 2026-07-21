<?php
/**
 * Upgrade-path covenant: a site coming from 2.5.x/2.6.0 must upgrade to v3
 * with its settings byte-stable, stay on the legacy JS engine, and keep its
 * exact enqueue behavior. Fresh installs get the server engine.
 *
 * @group covenant
 * @group upgrade
 */
class Test_Upgrade_Path extends WP_UnitTestCase {

	private $opts_backup;

	public function set_up() {
		parent::set_up();
		global $bodhi_svgs_options;
		$this->opts_backup = $bodhi_svgs_options;
	}

	public function tear_down() {
		global $bodhi_svgs_options;
		$bodhi_svgs_options = $this->opts_backup;
		update_option( 'bodhi_svgs_settings', $this->opts_backup );
		update_option( 'bodhi_svgs_plugin_version', BODHI_SVGS_VERSION );
		parent::tear_down();
	}

	/**
	 * Simulate loading v3 code on a site whose DB was written by an older
	 * version: put the old state in the DB, rebuild the runtime global the
	 * way svg-support.php does on load, then run the upgrade routine.
	 */
	private function simulate_upgrade_from( $old_version, array $old_settings ) {
		global $bodhi_svgs_options;

		update_option( 'bodhi_svgs_plugin_version', $old_version );
		update_option( 'bodhi_svgs_settings', $old_settings );

		$bodhi_svgs_options = bodhi_svgs_apply_setting_defaults( $old_settings );

		bodhi_svgs_version_updates();

		return get_option( 'bodhi_svgs_settings' );
	}

	/** Realistic 2.6.0 settings permutations (as stored by 2.6.0). */
	public function settings_permutations() {
		return array(
			'defaults-only site'  => array( array(
				'sanitize_svg_front_end'   => 'on',
				'restrict'                 => array( 'administrator' ),
				'sanitize_on_upload_roles' => array(),
			) ),
			'everything-on site'  => array( array(
				'sanitize_svg_front_end'   => 'on',
				'restrict'                 => array( 'administrator', 'editor' ),
				'sanitize_on_upload_roles' => array( 'administrator' ),
				'advanced_mode'            => 'on',
				'css_target'               => 'custom-svg',
				'minify_svg'               => 'on',
				'frontend_css'             => 'on',
				'skip_nested_svg'          => '1',
				'js_foot_choice'           => 'on',
				'use_vanilla_js'           => 'on',
				'use_expanded_js'          => 'on',
				'force_inline_svg'         => 'on',
				'auto_insert_class'        => 'on',
				'del_plugin_data'          => 'on',
			) ),
			'front-end sanitize off' => array( array(
				'sanitize_svg_front_end'   => false,
				'restrict'                 => array( 'administrator' ),
				'sanitize_on_upload_roles' => array( 'none' ),
				'advanced_mode'            => 'on',
			) ),
		);
	}

	/**
	 * @dataProvider settings_permutations
	 */
	public function test_upgrade_preserves_settings_and_stays_on_legacy_engine( $old_settings ) {
		$stored = $this->simulate_upgrade_from( '2.6.0', $old_settings );

		// Every key the old site had keeps its exact value.
		foreach ( $old_settings as $key => $value ) {
			$this->assertSame( $value, $stored[ $key ], "setting '$key' must survive the upgrade unchanged" );
		}

		// The upgrade must NOT opt the site into the new engine.
		$this->assertArrayNotHasKey( 'render_mode', $stored, 'upgrades must not gain a render_mode' );
		$this->assertSame( 'legacy', bodhi_svgs_render_mode() );

		// Version marker updated.
		$this->assertSame( BODHI_SVGS_VERSION, get_option( 'bodhi_svgs_plugin_version' ) );
	}

	public function test_ancient_site_with_legacy_string_shapes_normalizes_like_before() {
		// Pre-2.5 sites stored strings; 2.5+ load-time code normalized them.
		// The v3 in-memory merge + upgrade persist must produce the same result.
		$stored = $this->simulate_upgrade_from( '2.3', array(
			'restrict'                 => 'on',
			'sanitize_on_upload_roles' => 'none',
		) );

		$this->assertSame( array( 'administrator' ), $stored['restrict'] );
		$this->assertSame( array( 'none' ), $stored['sanitize_on_upload_roles'] );
		$this->assertSame( 'on', $stored['sanitize_svg_front_end'], 'security default fills in' );
		$this->assertArrayNotHasKey( 'render_mode', $stored );
	}

	/* ------------------------------------------------ enqueue equivalence */

	private function reset_scripts() {
		global $wp_scripts;
		$wp_scripts = new WP_Scripts();
	}

	public function test_upgraded_site_keeps_legacy_enqueue_behavior() {
		global $bodhi_svgs_options;

		$this->simulate_upgrade_from( '2.6.0', array(
			'sanitize_svg_front_end' => 'on',
			'restrict'               => array( 'administrator' ),
			'advanced_mode'          => 'on',
		) );
		$bodhi_svgs_options = bodhi_svgs_apply_setting_defaults( get_option( 'bodhi_svgs_settings' ) );

		$this->reset_scripts();
		bodhi_svgs_inline();
		bodhi_svgs_frontend_js();

		$this->assertTrue( wp_script_is( 'bodhi_svg_inline', 'enqueued' ), 'legacy swap script must still enqueue on upgraded sites' );
		$this->assertTrue( wp_script_is( 'bodhi-dompurify-library', 'enqueued' ), 'frontend DOMPurify must still enqueue on upgraded sites' );
	}

	public function test_server_mode_site_enqueues_no_frontend_scripts() {
		global $bodhi_svgs_options;

		$bodhi_svgs_options['advanced_mode'] = 'on';
		$bodhi_svgs_options['render_mode']   = 'server';

		$this->reset_scripts();
		bodhi_svgs_inline();
		bodhi_svgs_frontend_js();

		$this->assertFalse( wp_script_is( 'bodhi_svg_inline', 'enqueued' ), 'server mode must not load the swap script' );
		$this->assertFalse( wp_script_is( 'bodhi-dompurify-library', 'enqueued' ), 'server mode must not load frontend DOMPurify' );
	}

	/* -------------------------------------------------- activation paths */

	public function test_fresh_install_activation_gets_server_engine() {
		global $bodhi_svgs_options;

		delete_option( 'bodhi_svgs_settings' );
		delete_option( 'bodhi_svgs_plugin_version' );
		$bodhi_svgs_options = bodhi_svgs_apply_setting_defaults( array() );

		bodhi_svgs_plugin_activation();

		$stored = get_option( 'bodhi_svgs_settings' );
		$this->assertSame( 'server', $stored['render_mode'], 'fresh installs start on the server engine' );
		$this->assertSame( 'on', $stored['sanitize_svg_front_end'] );
		$this->assertSame( array( 'administrator' ), $stored['restrict'] );
	}

	public function test_reactivation_of_existing_site_does_not_switch_engine() {
		global $bodhi_svgs_options;

		update_option( 'bodhi_svgs_settings', array(
			'sanitize_svg_front_end' => 'on',
			'restrict'               => array( 'administrator' ),
			'advanced_mode'          => 'on',
		) );
		update_option( 'bodhi_svgs_plugin_version', '2.6.0' );
		$bodhi_svgs_options = bodhi_svgs_apply_setting_defaults( get_option( 'bodhi_svgs_settings' ) );

		bodhi_svgs_plugin_activation();

		$stored = get_option( 'bodhi_svgs_settings' );
		$this->assertArrayNotHasKey( 'render_mode', $stored, 'deactivate/reactivate must never flip an existing site to the new engine' );
		$this->assertSame( 'on', $stored['advanced_mode'] );
	}
}
