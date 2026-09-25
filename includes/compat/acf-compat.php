<?php
/**
 * Compatibilidad con la API de ACF. Sólo se carga si ACF NO está activo
 * (ver PCF::plugins_loaded), de modo que un tema escrito para ACF funciona igual.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

// Plantillas.
function get_field( $selector, $post_id = false, $format_value = true, $escape_html = false ) {
	return pcf_value( $selector, $post_id, $format_value, $escape_html );
}
function the_field( $selector, $post_id = false, $format_value = true ) {
	pcf_the_value( $selector, $post_id, $format_value );
}
function get_field_object( $selector, $post_id = false, $format_value = true, $load_value = true, $escape_html = false ) {
	return pcf_field_object( $selector, $post_id, $format_value, $load_value, $escape_html );
}
function get_fields( $post_id = false, $format_value = true, $escape_html = false ) {
	return pcf_values( $post_id, $format_value );
}
function get_field_objects( $post_id = false, $format_value = true, $load_value = true, $escape_html = false ) {
	return pcf_field_objects( $post_id, $format_value, $load_value );
}
function have_rows( $selector, $post_id = false ) {
	return pcf_have_rows( $selector, $post_id );
}
function the_row( $format = false ) {
	return pcf_the_row( $format );
}
function get_row( $format = false ) {
	return pcf_get_row( $format );
}
function get_row_index() {
	return pcf_row_index();
}
function get_row_layout() {
	return pcf_row_layout();
}
function reset_rows() {
	return pcf_reset_rows();
}
function get_sub_field( $selector = '', $format_value = true, $escape_html = false ) {
	return pcf_sub_value( $selector, $format_value, $escape_html );
}
function the_sub_field( $field_name, $format_value = true ) {
	pcf_the_sub_value( $field_name, $format_value );
}
function get_sub_field_object( $selector, $format_value = true, $load_value = true, $escape_html = false ) {
	return pcf_sub_field_object( $selector, $format_value, $load_value, $escape_html );
}
function has_sub_field( $selector, $post_id = false ) {
	$r = pcf_have_rows( $selector, $post_id );
	if ( $r ) {
		pcf_the_row();
	}
	return $r;
}
function has_sub_fields( $selector, $post_id = false ) {
	return has_sub_field( $selector, $post_id );
}
function update_field( $selector, $value, $post_id = false ) {
	return pcf_set_value( $selector, $value, $post_id );
}
function delete_field( $selector, $post_id = false ) {
	return pcf_delete_field_value( $selector, $post_id );
}
function update_sub_field( $selector, $value, $post_id = false ) {
	return pcf_update_sub_value( $selector, $value, $post_id );
}
function add_row( $selector, $row = false, $post_id = false ) {
	return pcf_add_row( $selector, (array) $row, $post_id );
}
function update_row( $selector, $i = 1, $row = false, $post_id = false ) {
	return pcf_update_row( $selector, $i, (array) $row, $post_id );
}
function delete_row( $selector, $i = 1, $post_id = false ) {
	return pcf_delete_row( $selector, $i, $post_id );
}
function add_sub_row( $selector, $row = false, $post_id = false ) {
	return pcf_add_row( is_array( $selector ) ? end( $selector ) : $selector, (array) $row, $post_id );
}
function delete_sub_field( $selector, $post_id = false ) {
	return pcf_update_sub_value( $selector, '', $post_id );
}

// Registro de grupos / campos / opciones / bloques.
if ( ! function_exists( 'acf_add_local_field_group' ) ) {
	function acf_add_local_field_group( $group ) {
		return pcf_add_local_field_group( $group, 'php' );
	}
	function acf_add_local_field( $field ) {
		$parent = pcf_maybe_get( $field, 'parent', '' );
		$group  = pcf_maybe_get( pcf()->local_groups, $parent );
		if ( ! $group ) {
			return false;
		}
		$group['fields'][] = $field;
		return pcf_add_local_field_group( $group, $group['local'] );
	}
	function acf_get_field_groups( $filter = array() ) {
		return pcf_get_field_groups( $filter );
	}
	function acf_get_field_group( $id ) {
		return pcf_get_field_group( $id );
	}
	function acf_get_fields( $parent ) {
		if ( is_array( $parent ) && isset( $parent['sub_fields'] ) ) {
			return $parent['sub_fields'];
		}
		return pcf_get_fields( is_array( $parent ) ? $parent['key'] : $parent );
	}
	function acf_get_field( $selector ) {
		return pcf_get_field( $selector );
	}
	function acf_get_setting( $name, $default = null ) {
		return pcf_get_setting( $name, $default );
	}
	function acf_update_setting( $name, $value ) {
		$map = array( 'save_json' => 'save_json', 'load_json' => 'load_json', 'capability' => 'capability', 'google_api_key' => 'google_maps_key' );
		pcf_update_setting( pcf_maybe_get( $map, $name, $name ), $value );
	}
	function acf_add_options_page( $page = '' ) {
		return pcf_add_options_page( $page );
	}
	function acf_add_options_sub_page( $page = '' ) {
		return pcf_add_options_sub_page( $page );
	}
	function acf_register_block_type( $block ) {
		return pcf_register_block_type( $block, 'acf' );
	}
	function acf_register_block( $block ) {
		return pcf_register_block_type( $block, 'acf' );
	}
	function acf_get_block_fields( $block ) {
		return pcf_get_block_fields( is_array( $block ) ? $block['name'] : $block );
	}
	function acf_get_attachment( $attachment ) {
		return pcf_get_attachment( is_object( $attachment ) ? $attachment->ID : $attachment );
	}
	function acf_form_head() {}
	function acf_form( $args = array() ) {
		echo '<!-- acf_form() no está soportado por Prompt Custom Fields -->';
	}
	function acf_get_valid_post_id( $post_id = 0 ) {
		return pcf_get_valid_post_id( $post_id );
	}
	function acf_slugify( $str = '', $glue = '-' ) {
		return pcf_slugify( $str, $glue );
	}
	function acf_maybe_get( $array = array(), $key = 0, $default = null ) {
		return pcf_maybe_get( $array, $key, $default );
	}
	function acf_esc_html( $string = '' ) {
		return wp_kses_post( $string );
	}
}

// Hooks de ACF que suelen usar los temas.
add_filter(
	'pcf/settings/save_json',
	function ( $path ) {
		return apply_filters( 'acf/settings/save_json', $path );
	}
);
add_filter(
	'pcf/settings/load_json',
	function ( $paths ) {
		return apply_filters( 'acf/settings/load_json', $paths );
	}
);
add_action(
	'pcf/render_field',
	function ( $field ) {
		do_action( 'acf/render_field', $field );
	}
);
