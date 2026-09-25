<?php
/**
 * Funciones de campos. Los campos usan el mismo esquema de array que ACF:
 * key, label, name, type, instructions, required, conditional_logic, wrapper,
 * default_value, sub_fields, layouts, ...
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Devuelve la instancia de un tipo de campo.
 *
 * @return PCF_Field|null
 */
function pcf_get_field_type( $type ) {
	return pcf_maybe_get( pcf()->field_types, $type );
}

function pcf_get_field_types() {
	return pcf()->field_types;
}

/**
 * Normaliza un campo (recursivo). Genera key y name si faltan.
 */
function pcf_validate_field( $field ) {
	if ( ! is_array( $field ) ) {
		return array();
	}

	// Alias amigables para prompts.
	if ( isset( $field['options'] ) && ! isset( $field['choices'] ) ) {
		$field['choices'] = $field['options'];
		unset( $field['options'] );
	}
	if ( isset( $field['fields'] ) && ! isset( $field['sub_fields'] ) ) {
		$field['sub_fields'] = $field['fields'];
		unset( $field['fields'] );
	}

	$field = wp_parse_args(
		$field,
		array(
			'key'               => '',
			'label'             => '',
			'name'              => '',
			'type'              => 'text',
			'instructions'      => '',
			'required'          => 0,
			'conditional_logic' => 0,
			'wrapper'           => array( 'width' => '', 'class' => '', 'id' => '' ),
		)
	);

	if ( empty( $field['key'] ) ) {
		$field['key'] = pcf_uniqid( 'field' );
	} elseif ( 0 !== strpos( $field['key'], 'field_' ) ) {
		$field['key'] = 'field_' . pcf_slugify( $field['key'] );
	}
	if ( '' === $field['label'] && '' !== $field['name'] ) {
		$field['label'] = ucwords( str_replace( '_', ' ', $field['name'] ) );
	}
	if ( '' === $field['name'] && ! in_array( $field['type'], array( 'tab', 'accordion', 'message' ), true ) ) {
		$field['name'] = pcf_slugify( $field['label'] );
	}

	$field['required'] = (int) (bool) $field['required'];
	$field['wrapper']  = wp_parse_args( (array) $field['wrapper'], array( 'width' => '', 'class' => '', 'id' => '' ) );

	if ( ! empty( $field['conditional_logic'] ) && is_array( $field['conditional_logic'] ) ) {
		$field['conditional_logic'] = pcf_normalize_conditional_logic( $field['conditional_logic'] );
	} else {
		$field['conditional_logic'] = 0;
	}

	$type = pcf_get_field_type( $field['type'] );
	if ( $type ) {
		$field = wp_parse_args( $field, $type->defaults );
		$field = $type->validate_field( $field );
	}

	if ( isset( $field['choices'] ) ) {
		$field['choices'] = pcf_normalize_choices( $field['choices'] );
	}

	if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
		$field['sub_fields'] = array_values( array_map( 'pcf_validate_field', $field['sub_fields'] ) );
	}

	if ( isset( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
		$layouts = array();
		foreach ( $field['layouts'] as $layout ) {
			$layout = wp_parse_args(
				$layout,
				array( 'key' => '', 'name' => '', 'label' => '', 'display' => 'block', 'sub_fields' => array(), 'min' => '', 'max' => '' )
			);
			if ( isset( $layout['fields'] ) ) {
				$layout['sub_fields'] = $layout['fields'];
				unset( $layout['fields'] );
			}
			if ( empty( $layout['key'] ) ) {
				$layout['key'] = pcf_uniqid( 'layout' );
			}
			if ( '' === $layout['name'] ) {
				$layout['name'] = pcf_slugify( $layout['label'] );
			}
			if ( '' === $layout['label'] ) {
				$layout['label'] = ucwords( str_replace( '_', ' ', $layout['name'] ) );
			}
			$layout['sub_fields'] = array_values( array_map( 'pcf_validate_field', (array) $layout['sub_fields'] ) );
			$layouts[]            = $layout;
		}
		$field['layouts'] = $layouts;
	}

	return apply_filters( 'pcf/validate_field', $field );
}

/**
 * Lógica condicional: acepta formato ACF (OR de ANDs) o una sola regla/lista AND.
 */
function pcf_normalize_conditional_logic( $logic ) {
	if ( isset( $logic['field'] ) ) {
		$logic = array( array( $logic ) );
	} elseif ( isset( $logic[0]['field'] ) ) {
		$logic = array( $logic );
	}
	$out = array();
	foreach ( $logic as $group ) {
		$rules = array();
		foreach ( (array) $group as $rule ) {
			if ( empty( $rule['field'] ) ) {
				continue;
			}
			$rules[] = array(
				'field'    => (string) $rule['field'],
				'operator' => (string) pcf_maybe_get( $rule, 'operator', '==' ),
				'value'    => (string) pcf_maybe_get( $rule, 'value', '' ),
			);
		}
		if ( $rules ) {
			$out[] = $rules;
		}
	}
	return $out ? $out : 0;
}

/**
 * Recorre un árbol de campos y aplica $fn a cada uno. $fn recibe ($field, $path)
 * y puede devolver: el campo (modificado), null para eliminarlo.
 */
function pcf_map_fields( array $fields, callable $fn, $path = array() ) {
	$out = array();
	foreach ( $fields as $field ) {
		$result = $fn( $field, $path );
		if ( null === $result ) {
			continue;
		}
		$field = $result;
		if ( ! empty( $field['sub_fields'] ) ) {
			$field['sub_fields'] = pcf_map_fields( $field['sub_fields'], $fn, array_merge( $path, array( $field['key'] ) ) );
		}
		if ( ! empty( $field['layouts'] ) ) {
			foreach ( $field['layouts'] as $i => $layout ) {
				$field['layouts'][ $i ]['sub_fields'] = pcf_map_fields( (array) $layout['sub_fields'], $fn, array_merge( $path, array( $field['key'], $layout['key'] ) ) );
			}
		}
		$out[] = $field;
	}
	return $out;
}

/**
 * Busca un campo en un árbol por key (o por name en el primer nivel si $by = 'name').
 */
function pcf_find_field_in( array $fields, $selector, $by = 'key' ) {
	foreach ( $fields as $field ) {
		if ( isset( $field[ $by ] ) && $field[ $by ] === $selector ) {
			return $field;
		}
		if ( 'key' !== $by ) {
			continue;
		}
		if ( ! empty( $field['sub_fields'] ) && ( $found = pcf_find_field_in( $field['sub_fields'], $selector, $by ) ) ) {
			return $found;
		}
		if ( ! empty( $field['layouts'] ) ) {
			foreach ( $field['layouts'] as $layout ) {
				if ( ( $found = pcf_find_field_in( (array) $layout['sub_fields'], $selector, $by ) ) ) {
					return $found;
				}
			}
		}
	}
	return null;
}

/**
 * Inserta un campo en el árbol.
 *
 * @param array       $fields   Árbol.
 * @param array       $new      Campo nuevo.
 * @param string|null $parent   Key del campo padre (repeater/group/flexible) o null = raíz.
 * @param string|null $layout   Key o name del layout (flexible content).
 * @param int|null    $position Índice; null = al final.
 * @param bool        $found    Salida: si se encontró el padre.
 */
function pcf_insert_field( array $fields, array $new, $parent = null, $layout = null, $position = null, &$found = false ) {
	$insert = function ( $list ) use ( $new, $position ) {
		$list = array_values( (array) $list );
		if ( null === $position || $position < 0 || $position > count( $list ) ) {
			$list[] = $new;
		} else {
			array_splice( $list, (int) $position, 0, array( $new ) );
		}
		return $list;
	};

	if ( ! $parent ) {
		$found = true;
		return $insert( $fields );
	}

	return pcf_map_fields(
		$fields,
		function ( $field ) use ( $parent, $layout, $insert, &$found ) {
			if ( $field['key'] !== $parent ) {
				return $field;
			}
			if ( ! empty( $field['layouts'] ) || 'flexible_content' === $field['type'] ) {
				foreach ( (array) $field['layouts'] as $i => $l ) {
					if ( null === $layout || $l['key'] === $layout || $l['name'] === $layout ) {
						$field['layouts'][ $i ]['sub_fields'] = $insert( $l['sub_fields'] );
						$found                                = true;
						break;
					}
				}
				return $field;
			}
			$field['sub_fields'] = $insert( pcf_maybe_get( $field, 'sub_fields', array() ) );
			$found               = true;
			return $field;
		}
	);
}

/**
 * Nombre de un subcampo con el formato de almacenamiento de ACF.
 */
function pcf_sub_field_for( $sub_field, $prefix ) {
	$sub_field['_name'] = $sub_field['name'];
	$sub_field['name']  = $prefix . '_' . $sub_field['name'];
	return $sub_field;
}

/**
 * Obtiene un campo por key o nombre. Si se da nombre, se usa la referencia guardada
 * del objeto o los grupos visibles para ese objeto.
 */
function pcf_get_field( $selector, $post_id = false ) {
	if ( is_array( $selector ) ) {
		return $selector;
	}
	$selector = (string) $selector;

	$index = pcf_get_field_index();

	if ( 0 === strpos( $selector, 'field_' ) ) {
		if ( isset( $index[ $selector ] ) ) {
			return $index[ $selector ];
		}
		// Subcampo clonado: {clone_key}_{field_key}.
		if ( preg_match( '/^(field_[a-z0-9]+)_(field_.+)$/', $selector, $m ) && isset( $index[ $m[1] ], $index[ $m[2] ] ) ) {
			$sub         = $index[ $m[2] ];
			$sub['key']  = $selector;
			return $sub;
		}
		return null;
	}

	if ( false !== $post_id && null !== $post_id ) {
		$ref = pcf_get_metadata( $post_id, $selector, true );
		if ( is_string( $ref ) && isset( $index[ $ref ] ) ) {
			$field = $index[ $ref ];
			// Si es un campo clonado con prefijo, respetar el nombre guardado.
			$field['name'] = $selector;
			return $field;
		}
		foreach ( pcf_get_field_groups( pcf_screen_for_post_id( $post_id ) ) as $group ) {
			if ( ( $found = pcf_find_top_level_by_name( pcf_get_fields( $group ), $selector ) ) ) {
				return $found;
			}
		}
	}

	foreach ( pcf_get_field_groups() as $group ) {
		if ( ( $found = pcf_find_top_level_by_name( pcf_get_fields( $group ), $selector ) ) ) {
			return $found;
		}
	}
	return null;
}

/**
 * Busca por name en el primer nivel, incluyendo clones "seamless" sin prefijo.
 */
function pcf_find_top_level_by_name( $fields, $name ) {
	foreach ( $fields as $field ) {
		if ( $field['name'] === $name ) {
			return $field;
		}
		if ( 'clone' === $field['type'] && empty( $field['prefix_name'] ) && 'seamless' === pcf_maybe_get( $field, 'display' ) ) {
			$type = pcf_get_field_type( 'clone' );
			if ( $type && ( $found = pcf_find_top_level_by_name( $type->get_cloned_fields( $field ), $name ) ) ) {
				return $found;
			}
		}
	}
	return null;
}

/**
 * Índice key => campo de todos los grupos (DB + locales).
 */
function pcf_get_field_index( $flush = false ) {
	static $index = null;
	if ( $flush ) {
		$index = null;
		return array();
	}
	if ( null !== $index ) {
		return $index;
	}
	$index = array();
	foreach ( pcf_get_field_groups() as $group ) {
		pcf_map_fields(
			pcf_maybe_get( $group, 'fields', array() ),
			function ( $field, $path ) use ( &$index, $group ) {
				$field['parent']         = $path ? end( $path ) : $group['key'];
				$field['_group']         = $group['key'];
				$index[ $field['key'] ]  = $field;
				return $field;
			}
		);
	}
	return $index;
}

function pcf_flush_field_cache() {
	pcf_get_field_index( true );
	pcf_get_field_groups_cache( true );
	do_action( 'pcf/flush_cache' );
}

/**
 * Pantalla (contexto de ubicación) a partir de un post_id lógico.
 */
function pcf_screen_for_post_id( $post_id ) {
	$d = pcf_decode_post_id( $post_id );
	switch ( $d['type'] ) {
		case 'post':
			$post = get_post( $d['id'] );
			return $post ? array( 'post_id' => $post->ID, 'post_type' => $post->post_type ) : array();
		case 'term':
			$term = get_term( $d['id'] );
			return ( $term && ! is_wp_error( $term ) ) ? array( 'taxonomy' => $term->taxonomy, 'term_id' => $term->term_id ) : array();
		case 'user':
			return array( 'user_id' => $d['id'], 'user_form' => 'edit' );
		case 'comment':
			return array( 'comment' => get_comment_type( $d['id'] ) );
		case 'option':
			$slugs = array();
			foreach ( pcf_get_options_pages() as $page ) {
				if ( (string) $page['post_id'] === (string) $d['id'] ) {
					$slugs[] = $page['menu_slug'];
				}
			}
			return array( 'options_page' => $slugs ? $slugs : array( $d['id'] ) );
		case 'block':
			$block = pcf_maybe_get( pcf_maybe_get( pcf()->block_data, $d['id'], array() ), '__block' );
			return $block ? array( 'block' => $block ) : array();
	}
	return array();
}
