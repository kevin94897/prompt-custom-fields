<?php
/**
 * Almacenamiento de metadatos. Usa exactamente el mismo formato que ACF:
 *  - Post:   post meta "nombre" + "_nombre" => field_key
 *  - Término: term meta (post_id "term_5" o "category_5")
 *  - Usuario: user meta (post_id "user_1")
 *  - Comentario: comment meta (post_id "comment_3")
 *  - Opciones: wp_options "options_nombre" + "_options_nombre"
 *  - Bloque: datos en memoria del bloque ("block_xxx")
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resuelve el post_id "lógico" a partir de lo que recibe la API de plantillas.
 */
function pcf_get_valid_post_id( $post_id = 0 ) {
	$pre = apply_filters( 'pcf/pre_load_post_id', null, $post_id );
	if ( null !== $pre ) {
		return $pre;
	}

	if ( is_object( $post_id ) ) {
		if ( $post_id instanceof WP_Post ) {
			return $post_id->ID;
		}
		if ( $post_id instanceof WP_Term ) {
			return 'term_' . $post_id->term_id;
		}
		if ( $post_id instanceof WP_User ) {
			return 'user_' . $post_id->ID;
		}
		if ( $post_id instanceof WP_Comment ) {
			return 'comment_' . $post_id->comment_ID;
		}
		return 0;
	}

	if ( ! $post_id ) {
		// Contexto de bloque activo.
		if ( ! empty( pcf()->block_data['__current'] ) ) {
			return pcf()->block_data['__current'];
		}
		$post_id = (int) get_the_ID();
		if ( ! $post_id && ( is_category() || is_tag() || is_tax() ) ) {
			$post_id = 'term_' . get_queried_object_id();
		} elseif ( ! $post_id && is_author() ) {
			$post_id = 'user_' . get_queried_object_id();
		}
	}

	if ( 'option' === $post_id ) {
		$post_id = 'options';
	}

	if ( is_numeric( $post_id ) ) {
		$post_id = (int) $post_id;
	}

	return apply_filters( 'pcf/validate_post_id', $post_id );
}

/**
 * Descompone un post_id en [tipo, id].
 */
function pcf_decode_post_id( $post_id ) {
	if ( is_numeric( $post_id ) ) {
		return array( 'type' => 'post', 'id' => (int) $post_id );
	}
	if ( is_string( $post_id ) ) {
		if ( 0 === strpos( $post_id, 'block_' ) ) {
			return array( 'type' => 'block', 'id' => $post_id );
		}
		if ( preg_match( '/^(term|user|comment)_(\d+)$/', $post_id, $m ) ) {
			return array( 'type' => $m[1], 'id' => (int) $m[2] );
		}
		// Formato ACF antiguo: {taxonomy}_{term_id}.
		if ( preg_match( '/^(.+)_(\d+)$/', $post_id, $m ) && taxonomy_exists( $m[1] ) ) {
			return array( 'type' => 'term', 'id' => (int) $m[2] );
		}
		return array( 'type' => 'option', 'id' => $post_id );
	}
	return array( 'type' => 'post', 'id' => 0 );
}

function pcf_get_metadata( $post_id, $name, $hidden = false ) {
	$d    = pcf_decode_post_id( $post_id );
	$name = ( $hidden ? '_' : '' ) . $name;

	switch ( $d['type'] ) {
		case 'option':
			return get_option( $d['id'] . '_' . $name, null );
		case 'block':
			$data = pcf_maybe_get( pcf()->block_data, $d['id'], array() );
			return array_key_exists( $name, $data ) ? $data[ $name ] : null;
		default:
			if ( ! metadata_exists( $d['type'], $d['id'], $name ) ) {
				return null;
			}
			return get_metadata( $d['type'], $d['id'], $name, true );
	}
}

function pcf_update_metadata( $post_id, $name, $value, $hidden = false ) {
	$d    = pcf_decode_post_id( $post_id );
	$name = ( $hidden ? '_' : '' ) . $name;

	switch ( $d['type'] ) {
		case 'option':
			return update_option( $d['id'] . '_' . $name, $value, apply_filters( 'pcf/options_autoload', false, $name ) );
		case 'block':
			pcf()->block_data[ $d['id'] ][ $name ] = $value;
			return true;
		default:
			// wp_slash: update_metadata aplica wp_unslash internamente.
			return update_metadata( $d['type'], $d['id'], $name, wp_slash( $value ) );
	}
}

function pcf_delete_metadata( $post_id, $name, $hidden = false ) {
	$d    = pcf_decode_post_id( $post_id );
	$name = ( $hidden ? '_' : '' ) . $name;

	switch ( $d['type'] ) {
		case 'option':
			return delete_option( $d['id'] . '_' . $name );
		case 'block':
			unset( pcf()->block_data[ $d['id'] ][ $name ] );
			return true;
		default:
			return delete_metadata( $d['type'], $d['id'], $name );
	}
}

/**
 * Todas las referencias nombre => field_key guardadas para un objeto.
 */
function pcf_get_meta_refs( $post_id ) {
	$d    = pcf_decode_post_id( $post_id );
	$refs = array();

	if ( 'option' === $d['type'] ) {
		global $wpdb;
		$prefix = $wpdb->esc_like( '_' . $d['id'] . '_' ) . '%';
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $prefix ), ARRAY_A ); // phpcs:ignore
		foreach ( $rows as $row ) {
			$refs[ substr( $row['option_name'], strlen( '_' . $d['id'] . '_' ) ) ] = $row['option_value'];
		}
		return $refs;
	}

	if ( 'block' === $d['type'] ) {
		foreach ( pcf_maybe_get( pcf()->block_data, $d['id'], array() ) as $k => $v ) {
			if ( '_' === substr( $k, 0, 1 ) && is_string( $v ) && 0 === strpos( $v, 'field_' ) ) {
				$refs[ substr( $k, 1 ) ] = $v;
			}
		}
		return $refs;
	}

	$all = get_metadata( $d['type'], $d['id'] );
	foreach ( (array) $all as $k => $v ) {
		if ( '_' === substr( $k, 0, 1 ) && isset( $v[0] ) && is_string( $v[0] ) && 0 === strpos( $v[0], 'field_' ) ) {
			$refs[ substr( $k, 1 ) ] = $v[0];
		}
	}
	return $refs;
}
