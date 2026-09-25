<?php
/**
 * Funciones auxiliares.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lee un ajuste. Filtrable con pcf/settings/{name}.
 */
function pcf_get_setting( $name, $default = null ) {
	$settings = pcf()->settings;
	$value    = array_key_exists( $name, $settings ) ? $settings[ $name ] : $default;

	if ( 'save_json' === $name && '' === $value ) {
		$value = get_stylesheet_directory() . '/pcf-json';
	}
	if ( 'load_json' === $name && empty( $value ) ) {
		$value = array( pcf_get_setting( 'save_json' ) );
	}
	if ( 'blocks_dirs' === $name && empty( $value ) ) {
		$value = array_unique( array( get_stylesheet_directory() . '/blocks', get_template_directory() . '/blocks' ) );
	}

	return apply_filters( "pcf/settings/{$name}", $value );
}

function pcf_update_setting( $name, $value ) {
	pcf()->settings[ $name ] = $value;
}

/**
 * Genera una key única con el mismo formato que ACF (field_xxxxxxxxxxxxx).
 */
function pcf_uniqid( $prefix = 'field' ) {
	static $last = '';
	do {
		$id = substr( md5( uniqid( '', true ) . wp_rand() ), 0, 13 );
	} while ( $id === $last );
	$last = $id;
	return $prefix . '_' . $id;
}

/**
 * Convierte un texto en slug con guion bajo ("Título Hero" -> "titulo_hero").
 */
function pcf_slugify( $text, $sep = '_' ) {
	$text = remove_accents( (string) $text );
	$text = strtolower( $text );
	$text = preg_replace( '/[^a-z0-9]+/', $sep, $text );
	return trim( $text, $sep );
}

/**
 * Obtiene un valor de un array con valor por defecto.
 */
function pcf_maybe_get( $array, $key, $default = null ) {
	return ( is_array( $array ) && array_key_exists( $key, $array ) ) ? $array[ $key ] : $default;
}

function pcf_is_assoc( $array ) {
	return is_array( $array ) && array_keys( $array ) !== range( 0, count( $array ) - 1 );
}

/**
 * Normaliza choices: lista, array asociativo o texto "valor : Etiqueta" por línea.
 */
function pcf_normalize_choices( $choices ) {
	if ( is_string( $choices ) ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $choices ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( false !== strpos( $line, ' : ' ) ) {
				list( $k, $v ) = array_map( 'trim', explode( ' : ', $line, 2 ) );
				$out[ $k ] = $v;
			} else {
				$out[ $line ] = $line;
			}
		}
		return $out;
	}
	if ( ! is_array( $choices ) ) {
		return array();
	}
	if ( ! pcf_is_assoc( $choices ) ) {
		$out = array();
		foreach ( $choices as $c ) {
			if ( is_array( $c ) ) {
				$value         = (string) pcf_maybe_get( $c, 'value', pcf_maybe_get( $c, 'label', '' ) );
				$out[ $value ] = (string) pcf_maybe_get( $c, 'label', $value );
			} else {
				$out[ (string) $c ] = (string) $c;
			}
		}
		return $out;
	}
	return $choices;
}

function pcf_get_array( $value ) {
	if ( is_array( $value ) ) {
		return $value;
	}
	if ( null === $value || '' === $value || false === $value ) {
		return array();
	}
	return array( $value );
}

/**
 * Decodifica JSON de forma tolerante.
 */
function pcf_json_decode( $json ) {
	if ( is_array( $json ) ) {
		return $json;
	}
	$data = json_decode( (string) $json, true );
	return is_array( $data ) ? $data : null;
}

function pcf_json_encode( $data ) {
	return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}

/**
 * Atributos HTML a partir de un array.
 */
function pcf_esc_attrs( $attrs ) {
	$html = '';
	foreach ( $attrs as $k => $v ) {
		if ( null === $v || false === $v ) {
			continue;
		}
		if ( true === $v ) {
			$html .= ' ' . esc_attr( $k );
			continue;
		}
		if ( is_array( $v ) ) {
			$v = wp_json_encode( $v );
		}
		$html .= sprintf( ' %s="%s"', esc_attr( $k ), esc_attr( $v ) );
	}
	return $html;
}

function pcf_current_user_can_admin() {
	return current_user_can( pcf_get_setting( 'capability' ) );
}

/**
 * Lista de post types públicos para choices.
 */
function pcf_get_post_type_choices() {
	$out = array();
	foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $pt ) {
		if ( 0 === strpos( $pt->name, 'pcf-' ) ) {
			continue;
		}
		$out[ $pt->name ] = $pt->labels->singular_name;
	}
	return $out;
}

function pcf_get_taxonomy_choices() {
	$out = array();
	foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $tax ) {
		$out[ $tax->name ] = $tax->labels->singular_name;
	}
	return $out;
}

/**
 * Registro de errores en modo debug.
 */
function pcf_log( ...$args ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[PCF] ' . wp_json_encode( $args ) ); // phpcs:ignore
	}
}

/**
 * apply_filters con puente a los hooks acf/* cuando la compatibilidad está activa
 * (así los filtros existentes del tema, p.ej. acf/format_value, siguen funcionando).
 */
function pcf_filter( $tag, $value, ...$args ) {
	$value = apply_filters( 'pcf/' . $tag, $value, ...$args );
	if ( pcf_acf_bridge_enabled() ) {
		$value = apply_filters( 'acf/' . $tag, $value, ...$args );
	}
	return $value;
}

function pcf_acf_bridge_enabled() {
	static $on = null;
	if ( null === $on && did_action( 'after_setup_theme' ) ) {
		$on = pcf_get_setting( 'acf_compat' ) && ! class_exists( 'ACF' );
	}
	return (bool) $on;
}
