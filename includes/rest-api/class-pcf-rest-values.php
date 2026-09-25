<?php
/**
 * Expone los valores de campos en la REST API de WordPress (propiedad "pcf", y "acf"
 * cuando la compatibilidad está activa) para grupos con show_in_rest.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_REST_Values {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ), 20 );
	}

	public static function register() {
		if ( ! pcf_get_setting( 'rest_values' ) ) {
			return;
		}
		$props = array( 'pcf' );
		if ( pcf_acf_bridge_enabled() ) {
			$props[] = 'acf';
		}
		$objects = array();
		foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $pt ) {
			$objects[ $pt ] = 'post';
		}
		foreach ( get_taxonomies( array( 'show_in_rest' => true ) ) as $tax ) {
			$objects[ $tax ] = 'term';
		}
		$objects['user'] = 'user';

		foreach ( $objects as $object => $kind ) {
			foreach ( $props as $prop ) {
				register_rest_field(
					$object,
					$prop,
					array(
						'get_callback'    => function ( $data ) use ( $kind ) {
							return PCF_REST_Values::get( PCF_REST_Values::post_id( $data, $kind ) );
						},
						'update_callback' => function ( $value, $obj ) use ( $kind ) {
							return PCF_REST_Values::update( $value, PCF_REST_Values::post_id( $obj, $kind ) );
						},
						'schema'          => array(
							'description' => 'Campos de Prompt Custom Fields (grupos con show_in_rest).',
							'type'        => 'object',
							'context'     => array( 'view', 'edit' ),
						),
					)
				);
			}
		}
	}

	public static function post_id( $obj, $kind ) {
		if ( $obj instanceof WP_Post ) {
			return $obj->ID;
		}
		if ( $obj instanceof WP_Term ) {
			return 'term_' . $obj->term_id;
		}
		if ( $obj instanceof WP_User ) {
			return 'user_' . $obj->ID;
		}
		$id = is_array( $obj ) ? (int) pcf_maybe_get( $obj, 'id', 0 ) : 0;
		return 'post' === $kind ? $id : $kind . '_' . $id;
	}

	protected static function rest_groups( $post_id ) {
		return array_filter(
			pcf_get_field_groups( pcf_screen_for_post_id( $post_id ) ),
			function ( $g ) {
				return ! empty( $g['show_in_rest'] );
			}
		);
	}

	public static function get( $post_id ) {
		$out = array();
		foreach ( self::rest_groups( $post_id ) as $g ) {
			foreach ( $g['fields'] as $f ) {
				$type = pcf_get_field_type( $f['type'] );
				if ( $type && $type->has_value ) {
					$out[ $f['name'] ] = pcf_value_to_input( pcf_get_value( $post_id, $f ), $f );
				}
			}
		}
		return $out ? $out : new stdClass();
	}

	public static function update( $values, $post_id ) {
		if ( ! is_array( $values ) ) {
			return true;
		}
		$fields = array();
		foreach ( self::rest_groups( $post_id ) as $g ) {
			$fields = array_merge( $fields, $g['fields'] );
		}
		$errors = array();
		foreach ( $values as $name => $value ) {
			$field = pcf_find_field_in( $fields, $name, 'name' );
			if ( ! $field ) {
				continue;
			}
			$valid = pcf_validate_value( $value, $field );
			if ( true !== $valid ) {
				$errors[] = $valid;
				continue;
			}
			pcf_update_value( $value, $post_id, $field );
		}
		if ( $errors ) {
			return new WP_Error( 'pcf_rest_invalid', implode( ' ', $errors ), array( 'status' => 400 ) );
		}
		return true;
	}
}

PCF_REST_Values::init();
