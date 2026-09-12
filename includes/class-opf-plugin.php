<?php
/**
 * Core plugin bootstrap.
 *
 * @package open-product-fields-for-woocommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 */
final class OPF_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var OPF_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Field type registry.
	 *
	 * @var OPF_Field_Types|null
	 */
	public $field_types;

	/**
	 * Singleton accessor.
	 *
	 * @return OPF_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up hooks. Direct instantiation is not supported.
	 */
	private function __construct() {
		register_activation_hook( OPF_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( OPF_FILE, array( $this, 'deactivate' ) );

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'plugins_loaded', array( $this, 'bootstrap' ), 20 );
	}

	/**
	 * Load services once all plugins are available.
	 */
	public function bootstrap() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'wc_missing_notice' ) );
			return;
		}

		require_once OPF_DIR . 'includes/class-opf-field-types.php';
		$this->field_types = new OPF_Field_Types();

		// Frontend + cart logic are wired up here as they are built.
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'opf', false, dirname( plugin_basename( OPF_FILE ) ) . '/languages' );
	}

	/**
	 * Activation defaults.
	 */
	public function activate() {
		if ( ! get_option( 'opf_version' ) ) {
			add_option( 'opf_version', OPF_VERSION );
		}
	}

	/**
	 * Deactivation cleanup.
	 */
	public function deactivate() {
	}

	/**
	 * WooCommerce missing notice.
	 */
	public function wc_missing_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'Open Product Fields requires WooCommerce to be installed and active.', 'opf' );
		echo '</p></div>';
	}
}
