<?php
/**
 * Registro único de operaciones. Cada operación se expone automáticamente por:
 *  1. Abilities API (pcf/{op}) -> MCP Adapter (servidor por defecto y servidor propio /wp-json/mcp/pcf)
 *  2. REST (/wp-json/pcf/v1/operations/{op}) -> servidor MCP en Node (mcp-server/)
 *  3. WP-CLI (wp pcf run {op}) -> servidor MCP en Node en modo CLI
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_Operations {

	/** @var array */
	protected static $ops = array();

	/**
	 * @param string $name Nombre en kebab-case.
	 * @param array  $args {
	 *   label, description, properties (JSON Schema), required (array),
	 *   callback (callable $input): array|WP_Error,
	 *   permission: 'admin' | 'content' | callable,
	 *   readonly, destructive, idempotent (bool)
	 * }
	 */
	public static function register( $name, $args ) {
		self::$ops[ $name ] = wp_parse_args(
			$args,
			array(
				'name'        => $name,
				'label'       => $name,
				'description' => '',
				'properties'  => array(),
				'required'    => array(),
				'callback'    => null,
				'permission'  => 'admin',
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
				'group'       => 'general',
			)
		);
	}

	public static function all() {
		return self::$ops;
	}

	public static function get( $name ) {
		return pcf_maybe_get( self::$ops, $name );
	}

	public static function input_schema( $op ) {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $op['properties'] ? $op['properties'] : new stdClass(),
			'additionalProperties' => false,
		);
		if ( $op['required'] ) {
			$schema['required'] = array_values( $op['required'] );
		}
		return $schema;
	}

	/**
	 * Esquema en formato array para la Abilities API (propiedades como array).
	 */
	public static function input_schema_array( $op ) {
		$schema               = self::input_schema( $op );
		$schema['properties'] = (array) $op['properties'];
		if ( ! $op['required'] ) {
			$schema['default'] = array();
		}
		return $schema;
	}

	public static function describe( $op ) {
		return array(
			'name'         => $op['name'],
			'ability'      => 'pcf/' . $op['name'],
			'label'        => $op['label'],
			'description'  => $op['description'],
			'group'        => $op['group'],
			'input_schema' => self::input_schema( $op ),
			'annotations'  => array(
				'readOnlyHint'    => (bool) $op['readonly'],
				'destructiveHint' => (bool) $op['destructive'],
				'idempotentHint'  => (bool) $op['idempotent'],
			),
		);
	}

	public static function check_permission( $op, $input = array() ) {
		if ( is_callable( $op['permission'] ) ) {
			return (bool) call_user_func( $op['permission'], $input );
		}
		if ( 'content' === $op['permission'] ) {
			if ( ! is_user_logged_in() ) {
				return false;
			}
			$object = pcf_maybe_get( (array) $input, 'object' );
			return null === $object ? current_user_can( 'edit_posts' ) : pcf_can_edit_object( $object );
		}
		return current_user_can( pcf_get_setting( 'capability' ) );
	}

	/**
	 * Ejecuta una operación.
	 *
	 * @return array|WP_Error
	 */
	public static function execute( $name, $input = array(), $check_permission = true ) {
		$op = self::get( $name );
		if ( ! $op ) {
			return new WP_Error( 'pcf_unknown_operation', sprintf( 'Operación desconocida: %s', $name ), array( 'status' => 404 ) );
		}
		$input = is_array( $input ) ? $input : (array) pcf_json_decode( $input );

		if ( $check_permission && ! self::check_permission( $op, $input ) ) {
			return new WP_Error( 'pcf_forbidden', 'No tienes permisos para esta operación (usa un usuario administrador).', array( 'status' => 403 ) );
		}

		$valid = rest_validate_value_from_schema( $input, self::input_schema_array( $op ), 'input' );
		if ( is_wp_error( $valid ) ) {
			$valid->add_data( array( 'status' => 400 ) );
			return $valid;
		}

		try {
			$result = call_user_func( $op['callback'], $input );
		} catch ( Throwable $e ) {
			return new WP_Error( 'pcf_exception', $e->getMessage(), array( 'status' => 500, 'file' => $e->getFile() . ':' . $e->getLine() ) );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		do_action( 'pcf/operation_executed', $name, $input, $result );
		return $result;
	}
}

/**
 * ¿Puede el usuario actual editar el objeto (post_id lógico)?
 */
function pcf_can_edit_object( $object ) {
	$d = pcf_decode_post_id( pcf_get_valid_post_id( $object ) );
	switch ( $d['type'] ) {
		case 'post':
			// Si no existe, se deja pasar para que la operación responda "no encontrado".
			if ( ! $d['id'] || ! get_post( $d['id'] ) ) {
				return current_user_can( 'edit_posts' );
			}
			return current_user_can( 'edit_post', $d['id'] );
		case 'term':
			return current_user_can( 'edit_term', $d['id'] );
		case 'user':
			return current_user_can( 'edit_user', $d['id'] );
		case 'comment':
			return current_user_can( 'edit_comment', $d['id'] );
		case 'option':
			$page = pcf_get_options_page_by_post_id( $d['id'] );
			return current_user_can( $page ? $page['capability'] : 'manage_options' );
	}
	return false;
}

/* --------------------------------------------------------------------------
 * Helpers de esquema
 * ----------------------------------------------------------------------- */

function pcf_schema_any( $description = '' ) {
	return array(
		'type'        => array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ),
		'description' => $description,
	);
}

function pcf_schema_object_id( $description = '' ) {
	return array(
		'type'        => array( 'integer', 'string' ),
		'description' => $description ? $description : 'Objeto destino: ID de post (123), "options" (o el post_id de una options page), "term_5", "user_1" o "comment_3".',
	);
}

function pcf_schema_str( $description, $extra = array() ) {
	return array_merge( array( 'type' => 'string', 'description' => $description ), $extra );
}

function pcf_schema_bool( $description, $default = null ) {
	$s = array( 'type' => 'boolean', 'description' => $description );
	if ( null !== $default ) {
		$s['default'] = $default;
	}
	return $s;
}

function pcf_schema_int( $description, $extra = array() ) {
	return array_merge( array( 'type' => 'integer', 'description' => $description ), $extra );
}

function pcf_schema_obj( $description ) {
	return array( 'type' => 'object', 'description' => $description, 'additionalProperties' => true );
}

function pcf_schema_arr( $description, $items = null ) {
	$s = array( 'type' => 'array', 'description' => $description );
	if ( $items ) {
		$s['items'] = $items;
	}
	return $s;
}

function pcf_schema_location() {
	return array(
		'type'        => array( 'array', 'object', 'string' ),
		'description' => 'Reglas de ubicación. Formato ACF: [[{"param":"post_type","operator":"==","value":"page"}]] (OR de grupos AND). Atajos: {"param":"post_type","value":"page"} o "post_type:page". Ver list-location-rules.',
	);
}

function pcf_schema_fields() {
	return array(
		'type'        => 'array',
		'description' => 'Campos con formato ACF: {"label":"Título","name":"titulo","type":"text", ...ajustes del tipo}. Contenedores: "sub_fields" (group/repeater) y "layouts" (flexible_content). "key" es opcional (se genera field_xxx). Ver list-field-types.',
		'items'       => array( 'type' => 'object', 'additionalProperties' => true ),
	);
}
