<?php
/**
 * Valores de campos.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Valor "crudo" (tal como se guarda, con subcampos resueltos por key).
 */
function pcf_get_value( $post_id, $field ) {
	$pre = pcf_filter( 'pre_load_value', null, $post_id, $field );
	if ( null !== $pre ) {
		return $pre;
	}

	$type  = pcf_get_field_type( $field['type'] );
	$value = pcf_get_metadata( $post_id, $field['name'] );

	if ( null === $value && isset( $field['default_value'] ) && '' !== $field['default_value'] ) {
		$value = $field['default_value'];
	}

	if ( $type ) {
		$value = $type->load_value( $value, $post_id, $field );
	}

	$value = pcf_filter( "load_value/type={$field['type']}", $value, $post_id, $field );
	$value = pcf_filter( "load_value/name={$field['name']}", $value, $post_id, $field );
	$value = pcf_filter( "load_value/key={$field['key']}", $value, $post_id, $field );
	return pcf_filter( 'load_value', $value, $post_id, $field );
}

/**
 * Formatea un valor para la plantilla (según return_format, etc.).
 */
function pcf_format_value( $value, $post_id, $field, $escape_html = false ) {
	$type = pcf_get_field_type( $field['type'] );
	if ( $type ) {
		$value = $type->format_value( $value, $post_id, $field, $escape_html );
	}
	$value = pcf_filter( "format_value/type={$field['type']}", $value, $post_id, $field );
	$value = pcf_filter( "format_value/name={$field['name']}", $value, $post_id, $field );
	$value = pcf_filter( "format_value/key={$field['key']}", $value, $post_id, $field );
	return pcf_filter( 'format_value', $value, $post_id, $field );
}

/**
 * Guarda un valor. Acepta subcampos indexados por key o por name.
 */
function pcf_update_value( $value, $post_id, $field ) {
	$type = pcf_get_field_type( $field['type'] );
	if ( ! $type || ! $type->has_value ) {
		return false;
	}

	$value = pcf_filter( "update_value/type={$field['type']}", $value, $post_id, $field, $value );
	$value = pcf_filter( "update_value/name={$field['name']}", $value, $post_id, $field, $value );
	$value = pcf_filter( "update_value/key={$field['key']}", $value, $post_id, $field, $value );
	$value = pcf_filter( 'update_value', $value, $post_id, $field, $value );

	$value = $type->update_value( $value, $post_id, $field );
	if ( null === $value ) {
		return false;
	}

	pcf_update_metadata( $post_id, $field['name'], $value );
	pcf_update_metadata( $post_id, $field['name'], $field['key'], true );
	return true;
}

function pcf_delete_value( $post_id, $field ) {
	$type = pcf_get_field_type( $field['type'] );
	if ( $type ) {
		$type->delete_value( $post_id, $field );
	}
	pcf_delete_metadata( $post_id, $field['name'] );
	pcf_delete_metadata( $post_id, $field['name'], true );
	do_action( 'pcf/delete_value', $post_id, $field['name'], $field );
	return true;
}

/**
 * Lee el valor de una fila (array indexado por key o name) para un subcampo.
 */
function pcf_row_value( $row, $sub_field, &$exists = null ) {
	$row    = (array) $row;
	$exists = true;
	if ( array_key_exists( $sub_field['key'], $row ) ) {
		return $row[ $sub_field['key'] ];
	}
	$name = isset( $sub_field['_name'] ) ? $sub_field['_name'] : $sub_field['name'];
	if ( array_key_exists( $name, $row ) ) {
		return $row[ $name ];
	}
	$exists = false;
	return null;
}

/**
 * Guarda un conjunto de valores para un objeto.
 *
 * @param mixed $post_id  ID lógico.
 * @param array $values   [field_key|field_name => valor].
 * @param array $fields   Opcional: limitar a estos campos.
 * @return array          Informe [updated => [...], errors => [...]]
 */
function pcf_save_values( $post_id, $values, $fields = null ) {
	$report = array( 'updated' => array(), 'errors' => array() );

	foreach ( (array) $values as $selector => $value ) {
		$field = null;
		if ( is_array( $fields ) ) {
			$field = pcf_find_field_in( $fields, $selector, 0 === strpos( $selector, 'field_' ) ? 'key' : 'name' );
		}
		if ( ! $field ) {
			$field = pcf_get_field( $selector, $post_id );
		}
		if ( ! $field ) {
			$report['errors'][ $selector ] = 'Campo no encontrado.';
			continue;
		}
		$valid = pcf_validate_value( $value, $field );
		if ( true !== $valid ) {
			$report['errors'][ $selector ] = $valid;
			continue;
		}
		pcf_update_value( $value, $post_id, $field );
		$report['updated'][] = $field['name'];
	}

	do_action( 'pcf/save_post', $post_id );
	if ( pcf_get_setting( 'acf_compat' ) && ! class_exists( 'ACF' ) ) {
		do_action( 'acf/save_post', $post_id );
	}
	return $report;
}

/**
 * Valida un valor. Devuelve true o mensaje de error.
 */
function pcf_validate_value( $value, $field ) {
	$valid = true;
	$empty = ( null === $value || '' === $value || array() === $value || false === $value );
	if ( ! empty( $field['required'] ) && $empty && 'true_false' !== $field['type'] ) {
		/* translators: %s: etiqueta */
		$valid = sprintf( __( '%s es obligatorio.', 'pcf' ), $field['label'] );
	}
	$type = pcf_get_field_type( $field['type'] );
	if ( true === $valid && $type && ! $empty ) {
		$valid = $type->validate_value( true, $value, $field );
	}
	$valid = pcf_filter( "validate_value/type={$field['type']}", $valid, $value, $field );
	$valid = pcf_filter( "validate_value/key={$field['key']}", $valid, $value, $field );
	return pcf_filter( 'validate_value', $valid, $value, $field );
}

/**
 * Valores de todos los campos visibles para un objeto.
 *
 * @param bool $format Formatear (como get_field) o crudo.
 */
function pcf_get_object_values( $post_id, $format = true, $with_objects = false ) {
	$out    = array();
	$seen   = array();
	$groups = pcf_get_field_groups( pcf_screen_for_post_id( $post_id ) );
	foreach ( $groups as $group ) {
		foreach ( $group['fields'] as $field ) {
			$type = pcf_get_field_type( $field['type'] );
			if ( ! $type || ! $type->has_value || isset( $seen[ $field['name'] ] ) ) {
				continue;
			}
			$seen[ $field['name'] ] = true;
			$value                  = pcf_get_value( $post_id, $field );
			if ( $format ) {
				$value = pcf_format_value( $value, $post_id, $field );
			}
			if ( $with_objects ) {
				$field['value']        = $value;
				$out[ $field['name'] ] = $field;
			} else {
				$out[ $field['name'] ] = $value;
			}
		}
	}

	// Campos con referencia guardada que ya no están en grupos visibles.
	foreach ( pcf_get_meta_refs( $post_id ) as $name => $key ) {
		if ( isset( $out[ $name ] ) ) {
			continue;
		}
		$field = pcf_get_field( $key );
		if ( ! $field || ! empty( $field['parent'] ) && 0 !== strpos( $field['parent'], 'group_' ) ) {
			continue;
		}
		$field['name'] = $name;
		$value         = pcf_get_value( $post_id, $field );
		$value         = $format ? pcf_format_value( $value, $post_id, $field ) : $value;
		if ( $with_objects ) {
			$field['value'] = $value;
			$out[ $name ]   = $field;
		} else {
			$out[ $name ] = $value;
		}
	}
	return $out;
}
