<?php
/**
 * API de plantillas con prefijo pcf_. La capa de compatibilidad (compat/acf-compat.php)
 * expone los mismos nombres que ACF (get_field, have_rows...) cuando ACF no está activo.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------------
 * Valores
 * ------------------------------------------------------------------------ */

function pcf_value( $selector, $post_id = false, $format_value = true, $escape_html = false ) {
	$post_id = pcf_get_valid_post_id( $post_id );
	$field   = pcf_get_field( $selector, $post_id );

	if ( ! $field ) {
		// Sin definición: devolver el meta en bruto (mismo comportamiento que ACF).
		$value = pcf_get_metadata( $post_id, $selector );
		return null === $value ? null : $value;
	}

	$value = pcf_get_value( $post_id, $field );
	if ( $format_value ) {
		$value = pcf_format_value( $value, $post_id, $field, $escape_html );
	}
	return $value;
}

function pcf_the_value( $selector, $post_id = false, $format_value = true ) {
	$value = pcf_value( $selector, $post_id, $format_value, true );
	if ( is_array( $value ) ) {
		$value = implode( ', ', array_filter( $value, 'is_scalar' ) );
	}
	if ( is_bool( $value ) ) {
		$value = $value ? '1' : '';
	}
	echo wp_kses_post( (string) $value ); // phpcs:ignore
}

function pcf_field_object( $selector, $post_id = false, $format_value = true, $load_value = true, $escape_html = false ) {
	$post_id = pcf_get_valid_post_id( $post_id );
	$field   = pcf_get_field( $selector, $post_id );
	if ( ! $field ) {
		return false;
	}
	if ( $load_value ) {
		$field['value'] = pcf_get_value( $post_id, $field );
		if ( $format_value ) {
			$field['value'] = pcf_format_value( $field['value'], $post_id, $field, $escape_html );
		}
	}
	return $field;
}

function pcf_values( $post_id = false, $format_value = true ) {
	$post_id = pcf_get_valid_post_id( $post_id );
	$values  = pcf_get_object_values( $post_id, $format_value );
	return $values ? $values : false;
}

function pcf_field_objects( $post_id = false, $format_value = true, $load_value = true ) {
	$post_id = pcf_get_valid_post_id( $post_id );
	$objects = pcf_get_object_values( $post_id, $format_value, true );
	return $objects ? $objects : false;
}

function pcf_set_value( $selector, $value, $post_id = false ) {
	$post_id = pcf_get_valid_post_id( $post_id );
	$field   = pcf_get_field( $selector, $post_id );
	if ( ! $field ) {
		if ( 0 === strpos( (string) $selector, 'field_' ) ) {
			return false;
		}
		return (bool) pcf_update_metadata( $post_id, $selector, $value );
	}
	return pcf_update_value( $value, $post_id, $field );
}

function pcf_delete_field_value( $selector, $post_id = false ) {
	$post_id = pcf_get_valid_post_id( $post_id );
	$field   = pcf_get_field( $selector, $post_id );
	if ( ! $field ) {
		return (bool) pcf_delete_metadata( $post_id, $selector );
	}
	return pcf_delete_value( $post_id, $field );
}

/* ---------------------------------------------------------------------------
 * Loops (have_rows / the_row)
 * ------------------------------------------------------------------------ */

function &pcf_loops() {
	static $stack = array();
	return $stack;
}

/**
 * Campos de la fila actual de un loop.
 */
function pcf_loop_row_fields( $loop ) {
	$field = $loop['field'];
	switch ( $field['type'] ) {
		case 'flexible_content':
			$name = pcf_maybe_get( pcf_maybe_get( $loop['value'], $loop['i'], array() ), 'acf_fc_layout' );
			foreach ( (array) $field['layouts'] as $layout ) {
				if ( $layout['name'] === $name ) {
					return $layout['sub_fields'];
				}
			}
			return array();
		case 'clone':
			return pcf_get_field_type( 'clone' )->get_cloned_fields( $field );
		default:
			return (array) pcf_maybe_get( $field, 'sub_fields', array() );
	}
}

/**
 * Prefijo de nombre de la fila actual ("rep_0", o "grupo" en groups).
 */
function pcf_loop_row_prefix( $loop ) {
	return $loop['single'] ? $loop['field']['name'] : $loop['field']['name'] . '_' . $loop['i'];
}

function pcf_find_sub_field( $fields, $selector ) {
	foreach ( $fields as $sub ) {
		if ( $sub['name'] === $selector || $sub['key'] === $selector ) {
			return $sub;
		}
		if ( 'clone' === $sub['type'] && 'seamless' === pcf_maybe_get( $sub, 'display' ) && empty( $sub['prefix_name'] ) ) {
			foreach ( pcf_get_field_type( 'clone' )->get_cloned_fields( $sub ) as $c ) {
				if ( $c['name'] === $selector || $c['key'] === $selector ) {
					return $c;
				}
			}
		}
	}
	return null;
}

function pcf_have_rows( $selector, $post_id = false ) {
	$stack  = &pcf_loops();
	$active = $stack ? $stack[ count( $stack ) - 1 ] : null;
	$pid    = ( false === $post_id && $active ) ? $active['post_id'] : pcf_get_valid_post_id( $post_id );

	$is_active = $active && $active['selector'] === $selector && $active['post_id'] === $pid;
	$sub       = null;

	if ( ! $is_active && $active && $active['i'] >= 0 && false === $post_id ) {
		$sub = pcf_find_sub_field( pcf_loop_row_fields( $active ), $selector );
	}

	if ( $is_active ) {
		// Continuar loop activo.
	} elseif ( $sub ) {
		$row   = pcf_maybe_get( $active['value'], $active['i'], array() );
		$sub   = pcf_sub_field_for( $sub, pcf_loop_row_prefix( $active ) );
		$value = pcf_row_value( $row, $sub );
		$stack[] = pcf_new_loop( $selector, $pid, $sub, $value );
	} else {
		// ¿Loop existente más abajo en la pila? (se rompió un loop anidado)
		$found = false;
		for ( $j = count( $stack ) - 1; $j >= 0; $j-- ) {
			if ( $stack[ $j ]['selector'] === $selector && $stack[ $j ]['post_id'] === $pid ) {
				$stack = array_slice( $stack, 0, $j + 1 );
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			$field = pcf_get_field( $selector, $pid );
			if ( ! $field || ! in_array( $field['type'], array( 'repeater', 'flexible_content', 'group', 'clone' ), true ) ) {
				return false;
			}
			$stack[] = pcf_new_loop( $selector, $pid, $field, pcf_get_value( $pid, $field ) );
		}
	}

	$loop = &$stack[ count( $stack ) - 1 ];
	if ( $loop['i'] + 1 < count( $loop['value'] ) ) {
		return true;
	}
	array_pop( $stack );
	return false;
}

function pcf_new_loop( $selector, $post_id, $field, $value ) {
	$single = in_array( $field['type'], array( 'group', 'clone' ), true );
	if ( $single ) {
		$value = $value ? array( (array) $value ) : array();
	}
	return array(
		'selector' => $selector,
		'post_id'  => $post_id,
		'field'    => $field,
		'value'    => is_array( $value ) ? array_values( $value ) : array(),
		'i'        => -1,
		'single'   => $single,
	);
}

function pcf_the_row( $format = false ) {
	$stack = &pcf_loops();
	if ( ! $stack ) {
		return false;
	}
	$loop = &$stack[ count( $stack ) - 1 ];
	$loop['i']++;
	return pcf_get_row( $format );
}

function pcf_get_row( $format = false ) {
	$stack = &pcf_loops();
	if ( ! $stack ) {
		return false;
	}
	$loop = $stack[ count( $stack ) - 1 ];
	$row  = pcf_maybe_get( $loop['value'], $loop['i'], false );
	if ( ! $row || ! $format ) {
		return $row;
	}
	$out = array();
	foreach ( pcf_loop_row_fields( $loop ) as $sub ) {
		$out[ $sub['name'] ] = pcf_sub_value( $sub['key'] );
	}
	if ( isset( $row['acf_fc_layout'] ) ) {
		$out = array( 'acf_fc_layout' => $row['acf_fc_layout'] ) + $out;
	}
	return $out;
}

function pcf_row_index() {
	$stack = &pcf_loops();
	if ( ! $stack ) {
		return 0;
	}
	$loop = $stack[ count( $stack ) - 1 ];
	return $loop['single'] ? 0 : $loop['i'] + (int) apply_filters( 'pcf/settings/row_index_offset', 1 );
}

function pcf_row_layout() {
	$row = pcf_get_row();
	return is_array( $row ) ? pcf_maybe_get( $row, 'acf_fc_layout', false ) : false;
}

function pcf_reset_rows() {
	$stack = &pcf_loops();
	array_pop( $stack );
	return true;
}

function pcf_sub_field_object( $selector, $format_value = true, $load_value = true, $escape_html = false ) {
	$stack = &pcf_loops();
	if ( ! $stack ) {
		return false;
	}
	$loop = $stack[ count( $stack ) - 1 ];
	$sub  = pcf_find_sub_field( pcf_loop_row_fields( $loop ), $selector );
	if ( ! $sub ) {
		return false;
	}
	$row = pcf_maybe_get( $loop['value'], $loop['i'], array() );
	$s   = pcf_sub_field_for( $sub, pcf_loop_row_prefix( $loop ) );
	if ( $load_value ) {
		$exists = false;
		$value  = pcf_row_value( $row, $s, $exists );
		if ( ! $exists && 'clone' !== $sub['type'] ) {
			$value = pcf_get_value( $loop['post_id'], $s );
		}
		if ( $format_value ) {
			$value = pcf_format_value( $value, $loop['post_id'], $s, $escape_html );
		}
		$s['value'] = $value;
	}
	return $s;
}

function pcf_sub_value( $selector, $format_value = true, $escape_html = false ) {
	$obj = pcf_sub_field_object( $selector, $format_value, true, $escape_html );
	return $obj ? $obj['value'] : null;
}

function pcf_the_sub_value( $selector, $format_value = true ) {
	$value = pcf_sub_value( $selector, $format_value, true );
	if ( is_array( $value ) ) {
		$value = implode( ', ', array_filter( $value, 'is_scalar' ) );
	}
	echo wp_kses_post( (string) $value ); // phpcs:ignore
}

/* ---------------------------------------------------------------------------
 * Escritura de filas
 * ------------------------------------------------------------------------ */

function pcf_rows_raw( $selector, $post_id ) {
	$field = pcf_get_field( $selector, $post_id );
	if ( ! $field ) {
		return array( null, array() );
	}
	$rows = pcf_get_value( $post_id, $field );
	return array( $field, is_array( $rows ) ? array_values( $rows ) : array() );
}

function pcf_add_row( $selector, $row = array(), $post_id = false ) {
	$post_id          = pcf_get_valid_post_id( $post_id );
	list( $f, $rows ) = pcf_rows_raw( $selector, $post_id );
	if ( ! $f ) {
		return false;
	}
	$rows[] = $row;
	pcf_update_value( $rows, $post_id, $f );
	return count( $rows );
}

function pcf_update_row( $selector, $i = 1, $row = array(), $post_id = false ) {
	$post_id          = pcf_get_valid_post_id( $post_id );
	list( $f, $rows ) = pcf_rows_raw( $selector, $post_id );
	$idx              = (int) $i - 1;
	if ( ! $f || ! isset( $rows[ $idx ] ) ) {
		return false;
	}
	$rows[ $idx ] = array_merge( $rows[ $idx ], (array) $row );
	return pcf_update_value( $rows, $post_id, $f );
}

function pcf_delete_row( $selector, $i = 1, $post_id = false ) {
	$post_id          = pcf_get_valid_post_id( $post_id );
	list( $f, $rows ) = pcf_rows_raw( $selector, $post_id );
	$idx              = (int) $i - 1;
	if ( ! $f || ! isset( $rows[ $idx ] ) ) {
		return false;
	}
	array_splice( $rows, $idx, 1 );
	return pcf_update_value( $rows, $post_id, $f );
}

/**
 * update_sub_field: selector string (dentro de un loop) o ruta
 * array( 'repetidor', 2, 'subcampo' ) con índices base 1.
 */
function pcf_update_sub_value( $selector, $value, $post_id = false ) {
	if ( is_array( $selector ) ) {
		$post_id = pcf_get_valid_post_id( $post_id );
		$field   = pcf_get_field( array_shift( $selector ), $post_id );
		$name    = $field ? $field['name'] : '';
		$fields  = $field ? pcf_loop_row_fields( array( 'field' => $field, 'value' => pcf_get_value( $post_id, $field ), 'i' => 0 ) ) : array();
		while ( $field && $selector ) {
			$part = array_shift( $selector );
			if ( is_numeric( $part ) ) {
				$name .= '_' . ( (int) $part - 1 );
				if ( 'flexible_content' === $field['type'] ) {
					$loop   = array( 'field' => $field, 'value' => pcf_get_value( $post_id, $field ), 'i' => (int) $part - 1 );
					$fields = pcf_loop_row_fields( $loop );
				}
				continue;
			}
			$sub = pcf_find_sub_field( $fields, $part );
			if ( ! $sub ) {
				return false;
			}
			$sub['_name'] = $sub['name'];
			$sub['name']  = $name . '_' . $sub['name'];
			$field        = $sub;
			$name         = $sub['name'];
			$fields       = (array) pcf_maybe_get( $sub, 'sub_fields', array() );
		}
		return $field ? pcf_update_value( $value, $post_id, $field ) : false;
	}

	$obj = pcf_sub_field_object( $selector, false, false );
	if ( ! $obj ) {
		return false;
	}
	$stack = &pcf_loops();
	$loop  = $stack[ count( $stack ) - 1 ];
	return pcf_update_value( $value, $post_id ? pcf_get_valid_post_id( $post_id ) : $loop['post_id'], $obj );
}
