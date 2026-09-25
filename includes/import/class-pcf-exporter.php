<?php
/**
 * Exportación de grupos y elementos internos a JSON (formato compatible con ACF)
 * o a código PHP para registrar en el tema.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_Exporter {

	/**
	 * Recoge los elementos a exportar.
	 *
	 * @param array $keys Keys; vacío = todo.
	 */
	public static function collect( $keys = array() ) {
		$out = array();
		foreach ( pcf_get_field_groups() as $g ) {
			if ( ! $keys || in_array( $g['key'], $keys, true ) ) {
				$out[] = self::clean( $g );
			}
		}
		foreach ( array_keys( pcf_internal_kinds() ) as $kind ) {
			foreach ( pcf_get_internal_items( $kind ) as $item ) {
				if ( ! $keys || in_array( $item['key'], $keys, true ) ) {
					$out[] = self::clean( $item );
				}
			}
		}
		return $out;
	}

	public static function clean( $item ) {
		unset( $item['ID'], $item['local'], $item['local_source'], $item['local_file'], $item['local_modified'], $item['modified'] );
		if ( isset( $item['fields'] ) ) {
			$item['fields'] = pcf_map_fields(
				$item['fields'],
				function ( $f ) {
					unset( $f['parent'], $f['_group'] );
					return $f;
				}
			);
		}
		return $item;
	}

	public static function to_json( $keys = array() ) {
		return pcf_json_encode( self::collect( $keys ) );
	}

	public static function to_php( $keys = array(), $use_acf_names = false ) {
		$items = self::collect( $keys );
		$code  = array();
		$fn    = $use_acf_names ? 'acf_add_local_field_group' : 'pcf_add_local_field_group';
		$hook  = $use_acf_names ? 'acf/include_fields' : 'pcf/init';

		$groups = array_filter(
			$items,
			function ( $i ) {
				return 0 === strpos( $i['key'], 'group_' );
			}
		);
		if ( $groups ) {
			$code[] = "add_action( '{$hook}', function() {";
			foreach ( $groups as $g ) {
				$code[] = "\t{$fn}( " . self::export_var( $g, 1 ) . ' );';
				$code[] = '';
			}
			$code[] = '} );';
			$code[] = '';
		}

		foreach ( $items as $i ) {
			if ( 0 === strpos( $i['key'], 'post_type_' ) ) {
				$args           = $i['args'];
				$args['labels'] = array_merge( PCF_Post_Types::labels( $i['singular'], $i['plural'] ), (array) pcf_maybe_get( $args, 'labels', array() ) );
				if ( ! empty( $i['taxonomies'] ) ) {
					$args['taxonomies'] = $i['taxonomies'];
				}
				$code[] = "add_action( 'init', function() {\n\tregister_post_type( " . var_export( $i['post_type'], true ) . ', ' . self::export_var( $args, 1 ) . " );\n} );\n";
			} elseif ( 0 === strpos( $i['key'], 'taxonomy_' ) ) {
				$args           = $i['args'];
				$args['labels'] = array_merge( PCF_Post_Types::labels( $i['singular'], $i['plural'] ), (array) pcf_maybe_get( $args, 'labels', array() ) );
				$code[]         = "add_action( 'init', function() {\n\tregister_taxonomy( " . var_export( $i['taxonomy'], true ) . ', ' . self::export_var( $i['object_type'], 1 ) . ', ' . self::export_var( $args, 1 ) . " );\n} );\n";
			} elseif ( 0 === strpos( $i['key'], 'ui_options_page_' ) ) {
				$page = array_diff_key( $i, array_flip( array( 'key', 'title', 'active' ) ) );
				$code[] = "add_action( '{$hook}', function() {\n\t" . ( $use_acf_names ? 'acf_add_options_page' : 'pcf_add_options_page' ) . '( ' . self::export_var( $page, 1 ) . " );\n} );\n";
			}
		}
		return "<?php\n" . implode( "\n", $code );
	}

	/**
	 * var_export con sintaxis corta e indentación con tabuladores.
	 */
	public static function export_var( $var, $indent = 0 ) {
		$pad = str_repeat( "\t", $indent );
		if ( is_array( $var ) ) {
			if ( ! $var ) {
				return 'array()';
			}
			$list  = ! pcf_is_assoc( $var );
			$lines = array();
			foreach ( $var as $k => $v ) {
				$lines[] = $pad . "\t" . ( $list ? '' : var_export( $k, true ) . ' => ' ) . self::export_var( $v, $indent + 1 ) . ',';
			}
			return "array(\n" . implode( "\n", $lines ) . "\n" . $pad . ')';
		}
		if ( null === $var ) {
			return 'null';
		}
		return var_export( $var, true );
	}
}
