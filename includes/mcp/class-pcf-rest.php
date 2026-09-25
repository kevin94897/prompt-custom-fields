<?php
/**
 * Transporte 2: REST API propia (/wp-json/pcf/v1). La usa el servidor MCP en Node
 * (mcp-server/) autenticado con una Application Password.
 *
 *  GET  /pcf/v1/operations          Lista de operaciones con su JSON Schema.
 *  GET  /pcf/v1/operations/{name}   Descripción de una operación.
 *  POST /pcf/v1/operations/{name}   Ejecuta la operación (cuerpo JSON = input).
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_REST {

	const NS = 'pcf/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/operations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_operations' ),
				'permission_callback' => array( __CLASS__, 'can_list' ),
			)
		);
		register_rest_route(
			self::NS,
			'/operations/(?P<name>[a-z0-9-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'describe_operation' ),
					'permission_callback' => array( __CLASS__, 'can_list' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'run_operation' ),
					'permission_callback' => 'is_user_logged_in',
				),
			)
		);
	}

	public static function can_list() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Autenticación requerida (usuario + Application Password).', array( 'status' => 401 ) );
		}
		return current_user_can( 'edit_posts' );
	}

	public static function list_operations() {
		$out = array();
		foreach ( PCF_Operations::all() as $op ) {
			if ( PCF_Operations::check_permission( $op ) || 'content' === $op['permission'] ) {
				$out[] = PCF_Operations::describe( $op );
			}
		}
		return rest_ensure_response(
			array(
				'plugin'     => 'prompt-custom-fields',
				'version'    => PCF_VERSION,
				'site'       => home_url(),
				'operations' => $out,
			)
		);
	}

	public static function describe_operation( WP_REST_Request $request ) {
		$op = PCF_Operations::get( $request['name'] );
		if ( ! $op ) {
			return new WP_Error( 'pcf_unknown_operation', 'Operación desconocida.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( PCF_Operations::describe( $op ) );
	}

	public static function run_operation( WP_REST_Request $request ) {
		$input = $request->get_json_params();
		if ( null === $input ) {
			$input = $request->get_body_params();
		}
		if ( isset( $input['input'] ) && 1 === count( $input ) && is_array( $input['input'] ) ) {
			$input = $input['input'];
		}
		$result = PCF_Operations::execute( $request['name'], (array) $input );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( ! is_array( $data ) || empty( $data['status'] ) ) {
				$result->add_data( array_merge( (array) $data, array( 'status' => 400 ) ) );
			}
			return $result;
		}
		return rest_ensure_response( array( 'ok' => true, 'result' => $result ) );
	}
}

PCF_REST::init();
