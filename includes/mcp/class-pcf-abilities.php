<?php
/**
 * Transporte 1: Abilities API de WordPress (6.9+) y MCP Adapter.
 *
 *  - Cada operación se registra como ability "pcf/{operacion}" con meta.mcp.public = true,
 *    de modo que aparece en el servidor por defecto del MCP Adapter
 *    (/wp-json/mcp/mcp-adapter-default-server → discover / get-info / execute).
 *  - Además se crea un servidor propio "/wp-json/mcp/pcf" donde cada operación es
 *    una tool directa con su esquema (menos pasos para el agente).
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_Abilities {

	const CATEGORY  = 'pcf';
	const SERVER_ID = 'pcf-server';
	const ROUTE     = 'pcf';

	public static function init() {
		// Si la Abilities API no existe (WP < 6.9 sin el plugin) estos hooks nunca se disparan
		// y los otros dos transportes siguen funcionando.
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
		add_action( 'mcp_adapter_init', array( __CLASS__, 'create_mcp_server' ) );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'parse_query_input' ), 10, 3 );
	}

	public static function register_category() {
		if ( function_exists( 'wp_register_ability_category' ) && ! wp_has_ability_category( self::CATEGORY ) ) {
			wp_register_ability_category(
				self::CATEGORY,
				array(
					'label'       => 'Prompt Custom Fields',
					'description' => 'Gestión de campos personalizados, post types, taxonomías, options pages, bloques y contenido (compatible con ACF).',
				)
			);
		}
	}

	public static function ability_name( $op_name ) {
		return 'pcf/' . $op_name;
	}

	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		foreach ( PCF_Operations::all() as $name => $op ) {
			if ( wp_has_ability( self::ability_name( $name ) ) ) {
				continue;
			}
			wp_register_ability(
				self::ability_name( $name ),
				array(
					'label'               => $op['label'],
					'description'         => $op['description'],
					'category'            => self::CATEGORY,
					'input_schema'        => PCF_Operations::input_schema_array( $op ),
					'execute_callback'    => function ( $input = null ) use ( $name ) {
						return PCF_Operations::execute( $name, is_array( $input ) ? $input : array(), false );
					},
					'permission_callback' => function ( $input = null ) use ( $op ) {
						return PCF_Operations::check_permission( $op, is_array( $input ) ? $input : array() );
					},
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'    => (bool) $op['readonly'],
							'destructive' => (bool) $op['destructive'],
							'idempotent'  => (bool) $op['idempotent'],
						),
						'mcp'          => array(
							'public' => (bool) apply_filters( 'pcf/mcp/public', true, $name ),
							'type'   => 'tool',
						),
					),
				)
			);
		}
	}

	/**
	 * Servidor MCP dedicado: /wp-json/mcp/pcf
	 */
	public static function create_mcp_server( $adapter ) {
		if ( ! apply_filters( 'pcf/mcp/create_server', true ) || ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}
		$transport = '\WP\MCP\Transport\HttpTransport';
		$errors    = '\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler';
		if ( ! class_exists( $transport ) ) {
			return;
		}
		$tools = array();
		foreach ( array_keys( PCF_Operations::all() ) as $name ) {
			$tools[] = self::ability_name( $name );
		}
		try {
			$adapter->create_server(
				self::SERVER_ID,
				'mcp',
				self::ROUTE,
				'Prompt Custom Fields',
				'Campos personalizados estilo ACF PRO administrables por MCP. Empieza con pcf-get-site-context y pcf-get-usage-guide.',
				PCF_VERSION,
				array( $transport ),
				class_exists( $errors ) ? $errors : null,
				null,
				$tools,
				array(),
				array()
			);
		} catch ( Throwable $e ) {
			pcf_log( 'mcp_adapter', $e->getMessage() );
		}
	}

	/**
	 * Las abilities de sólo lectura se ejecutan por GET y el input llega como string JSON
	 * en la query: se decodifica antes de validar.
	 */
	public static function parse_query_input( $response, $handler, $request ) {
		if ( false === strpos( $request->get_route(), '/abilities/pcf/' ) ) {
			return $response;
		}
		$input = $request->get_param( 'input' );
		if ( is_string( $input ) ) {
			$decoded = json_decode( $input, true );
			if ( is_array( $decoded ) ) {
				$request->set_param( 'input', $decoded );
			}
		}
		return $response;
	}

	/**
	 * Endpoints para mostrar en la pantalla de conexión.
	 */
	public static function endpoints() {
		return array(
			'default_server' => rest_url( 'mcp/mcp-adapter-default-server' ),
			'pcf_server'     => rest_url( 'mcp/' . self::ROUTE ),
			'abilities_api'  => function_exists( 'wp_register_ability' ),
			'mcp_adapter'    => class_exists( '\WP\MCP\Core\McpAdapter' ),
		);
	}
}

PCF_Abilities::init();
