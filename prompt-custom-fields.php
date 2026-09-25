<?php
/**
 * Plugin Name:       Prompt Custom Fields
 * Description:       Campos personalizados con la arquitectura de ACF PRO, administrables al 100% por MCP (Abilities API + MCP Adapter, REST o WP-CLI). Compatible con get_field() e importador de ACF.
 * Version:           1.3.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Text Domain:       pcf
 * License:           GPLv2 or later
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'PCF_VERSION' ) ) {
	return;
}

define( 'PCF_VERSION', '1.3.0' );
define( 'PCF_FILE', __FILE__ );
define( 'PCF_PATH', plugin_dir_path( __FILE__ ) );
define( 'PCF_URL', plugin_dir_url( __FILE__ ) );

/**
 * Clase principal (equivalente a la clase ACF).
 */
final class PCF {

	/** @var PCF */
	private static $instance;

	/** @var array Configuración. */
	public $settings = array();

	/** @var PCF_Field[] Tipos de campo registrados. */
	public $field_types = array();

	/** @var PCF_Location[] Reglas de ubicación registradas. */
	public $locations = array();

	/** @var array Grupos locales (PHP / JSON), indexados por key. */
	public $local_groups = array();

	/** @var array Almacén temporal de datos de bloque. */
	public $block_data = array();

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
			self::$instance->setup();
		}
		return self::$instance;
	}

	private function setup() {
		$this->settings = array(
			'capability'      => 'manage_options',
			'json'            => true,
			'save_json'       => '',
			'load_json'       => array(),
			'acf_compat'      => true,
			'blocks_dirs'     => array(),
			'rest_values'     => true,
			'google_maps_key' => '',
		);

		$inc = PCF_PATH . 'includes/';

		// Núcleo.
		require_once $inc . 'pcf-helper-functions.php';
		require_once $inc . 'pcf-meta-functions.php';
		require_once $inc . 'pcf-field-functions.php';
		require_once $inc . 'pcf-field-group-functions.php';
		require_once $inc . 'pcf-value-functions.php';
		require_once $inc . 'pcf-internal-post-type-functions.php';
		require_once $inc . 'class-pcf-local-json.php';

		// Tipos de campo.
		require_once $inc . 'fields/class-pcf-field.php';
		foreach ( glob( $inc . 'fields/class-pcf-field-*.php' ) as $file ) {
			require_once $file;
		}

		// Ubicaciones.
		require_once $inc . 'locations/class-pcf-location.php';
		foreach ( glob( $inc . 'locations/class-pcf-location-*.php' ) as $file ) {
			require_once $file;
		}

		// API pública para temas.
		require_once $inc . 'api/api-template.php';

		// Registros.
		require_once $inc . 'post-types/class-pcf-post-types.php';
		require_once $inc . 'options/class-pcf-options-pages.php';
		require_once $inc . 'blocks/class-pcf-blocks.php';

		// Formularios.
		require_once $inc . 'forms/class-pcf-renderer.php';
		require_once $inc . 'forms/class-pcf-form-post.php';
		require_once $inc . 'forms/class-pcf-form-taxonomy.php';
		require_once $inc . 'forms/class-pcf-form-user.php';

		// Importar / exportar.
		require_once $inc . 'import/class-pcf-exporter.php';
		require_once $inc . 'import/class-pcf-acf-importer.php';

		// Capa MCP: registro único de operaciones + 3 transportes.
		require_once $inc . 'mcp/class-pcf-operations.php';
		require_once $inc . 'mcp/operations-schema.php';
		require_once $inc . 'mcp/operations-content.php';
		require_once $inc . 'mcp/operations-registry.php';
		require_once $inc . 'mcp/class-pcf-abilities.php';
		require_once $inc . 'mcp/class-pcf-rest.php';
		require_once $inc . 'mcp/class-pcf-mcp-config.php';
		require_once $inc . 'rest-api/class-pcf-rest-values.php';

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once $inc . 'cli/class-pcf-cli.php';
		}

		if ( is_admin() ) {
			require_once $inc . 'admin/class-pcf-admin.php';
			require_once $inc . 'admin/class-pcf-duplicate.php';
		}

		// after_setup_theme: así el tema puede desactivar la compatibilidad con el filtro pcf/settings/acf_compat.
		add_action( 'after_setup_theme', array( $this, 'plugins_loaded' ), 0 );
		add_action( 'init', array( $this, 'init' ), 5 );
	}

	/**
	 * La capa de compatibilidad se carga cuando todos los plugins están cargados,
	 * así nunca choca con ACF si está activo.
	 */
	public function plugins_loaded() {
		if ( pcf_get_setting( 'acf_compat' ) && ! class_exists( 'ACF' ) && ! function_exists( 'get_field' ) && ! self::acf_is_being_activated() ) {
			require_once PCF_PATH . 'includes/compat/acf-compat.php';
		}
	}

	/**
	 * Evita declarar get_field() en la misma petición en la que se activa ACF
	 * (ACF declara sus funciones sin comprobar si existen).
	 */
	public static function acf_is_being_activated() {
		$targets = array();
		// wp-admin: plugins.php?action=activate / activate-selected.
		if ( isset( $_REQUEST['action'] ) || isset( $_REQUEST['action2'] ) ) { // phpcs:ignore
			foreach ( array( 'plugin', 'checked' ) as $k ) {
				if ( isset( $_REQUEST[ $k ] ) ) { // phpcs:ignore
					$targets = array_merge( $targets, (array) wp_unslash( $_REQUEST[ $k ] ) ); // phpcs:ignore
				}
			}
		}
		// WP-CLI: wp plugin activate advanced-custom-fields-pro.
		if ( defined( 'WP_CLI' ) && WP_CLI && ! empty( $GLOBALS['argv'] ) && in_array( 'activate', $GLOBALS['argv'], true ) ) {
			$targets = array_merge( $targets, $GLOBALS['argv'] );
		}
		// Activación por REST (/wp/v2/plugins).
		if ( ! empty( $_SERVER['REQUEST_URI'] ) && false !== strpos( wp_unslash( $_SERVER['REQUEST_URI'] ), 'wp/v2/plugins' ) ) { // phpcs:ignore
			$targets[] = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore
		}
		foreach ( $targets as $t ) {
			if ( is_string( $t ) && false !== strpos( $t, 'advanced-custom-fields' ) ) {
				return true;
			}
		}
		return false;
	}

	public function init() {
		load_plugin_textdomain( 'pcf', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );

		// Registrar tipos de campo y ubicaciones incluidas.
		foreach ( get_declared_classes() as $class ) {
			if ( 0 !== strpos( $class, 'PCF_' ) || ( new ReflectionClass( $class ) )->isAbstract() ) {
				continue;
			}
			if ( is_subclass_of( $class, 'PCF_Field' ) ) {
				$this->register_field_type( new $class() );
			} elseif ( is_subclass_of( $class, 'PCF_Location' ) ) {
				$this->register_location( new $class() );
			}
		}

		/**
		 * Punto de extensión: registrar tipos, ubicaciones y grupos locales.
		 * Equivalente a acf/init.
		 */
		do_action( 'pcf/include_field_types', $this );
		do_action( 'pcf/init' );
		if ( pcf_get_setting( 'acf_compat' ) && ! class_exists( 'ACF' ) ) {
			do_action( 'acf/init' );
			do_action( 'acf/include_fields' );
		}
	}

	public function register_field_type( PCF_Field $type ) {
		$this->field_types[ $type->name ] = $type;
	}

	public function register_location( PCF_Location $location ) {
		$this->locations[ $location->name ] = $location;
	}
}

/**
 * Acceso global a la instancia.
 *
 * @return PCF
 */
function pcf() {
	return PCF::instance();
}

pcf();

register_activation_hook(
	__FILE__,
	function () {
		update_option( 'pcf_version', PCF_VERSION );
		flush_rewrite_rules();
	}
);
