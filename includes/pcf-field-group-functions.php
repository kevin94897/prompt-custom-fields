<?php
/**
 * Grupos de campos. Se guardan en el CPT "pcf-field-group" (post_name = key,
 * post_content = JSON con ajustes y campos anidados) y se sincronizan con JSON local.
 * También se pueden registrar por PHP con pcf_add_local_field_group().
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

const PCF_GROUP_POST_TYPE = 'pcf-field-group';

function pcf_field_group_defaults() {
	return array(
		'key'                   => '',
		'title'                 => '',
		'fields'                => array(),
		'location'              => array(),
		'menu_order'            => 0,
		'position'              => 'normal',
		'style'                 => 'default',
		'label_placement'       => 'top',
		'instruction_placement' => 'label',
		'hide_on_screen'        => array(),
		'active'                => true,
		'description'           => '',
		'show_in_rest'          => 0,
	);
}

/**
 * Normaliza un grupo (y todos sus campos).
 */
function pcf_validate_field_group( $group ) {
	$group = wp_parse_args( (array) $group, pcf_field_group_defaults() );

	if ( empty( $group['key'] ) ) {
		$group['key'] = pcf_uniqid( 'group' );
	} elseif ( 0 !== strpos( $group['key'], 'group_' ) ) {
		$group['key'] = 'group_' . pcf_slugify( $group['key'] );
	}
	if ( '' === $group['title'] ) {
		$group['title'] = $group['key'];
	}

	$group['fields']     = array_values( array_map( 'pcf_validate_field', (array) $group['fields'] ) );
	$group['location']   = pcf_normalize_location( $group['location'] );
	$group['active']     = (bool) $group['active'];
	$group['menu_order'] = (int) $group['menu_order'];
	if ( ! is_array( $group['hide_on_screen'] ) ) {
		$group['hide_on_screen'] = array_filter( array( $group['hide_on_screen'] ) );
	}

	return apply_filters( 'pcf/validate_field_group', $group );
}

/**
 * Ubicación: acepta el formato ACF (OR de ANDs) y atajos:
 *  - {"param":"post_type","operator":"==","value":"page"}
 *  - [{"param":...}, {...}]  (un único grupo AND)
 *  - "post_type:page"
 */
function pcf_normalize_location( $location ) {
	if ( is_string( $location ) && false !== strpos( $location, ':' ) ) {
		list( $p, $v ) = explode( ':', $location, 2 );
		$location      = array( 'param' => $p, 'value' => $v );
	}
	if ( isset( $location['param'] ) ) {
		$location = array( array( $location ) );
	} elseif ( isset( $location[0]['param'] ) ) {
		$location = array( $location );
	}
	$out = array();
	foreach ( (array) $location as $and ) {
		$rules = array();
		foreach ( (array) $and as $rule ) {
			if ( empty( $rule['param'] ) ) {
				continue;
			}
			$rules[] = array(
				'param'    => (string) $rule['param'],
				'operator' => (string) pcf_maybe_get( $rule, 'operator', '==' ),
				'value'    => (string) pcf_maybe_get( $rule, 'value', '' ),
			);
		}
		if ( $rules ) {
			$out[] = $rules;
		}
	}
	return $out;
}

/* ------------------------------------------------------------------------
 * Grupos locales (PHP / JSON)
 * --------------------------------------------------------------------- */

function pcf_add_local_field_group( $group, $source = 'php' ) {
	if ( empty( $group['key'] ) ) {
		return false;
	}
	$group['local'] = $source;
	pcf()->local_groups[ $group['key'] ] = $group;
	pcf_flush_field_cache();
	return true;
}

function pcf_remove_local_field_group( $key ) {
	unset( pcf()->local_groups[ $key ] );
	pcf_flush_field_cache();
}

/* ------------------------------------------------------------------------
 * Lectura
 * --------------------------------------------------------------------- */

/**
 * Convierte un post del CPT a array de grupo.
 */
function pcf_group_from_post( WP_Post $post ) {
	$data = pcf_json_decode( $post->post_content );
	if ( ! $data ) {
		$data = array();
	}
	$data['ID']         = $post->ID;
	$data['key']        = $post->post_name;
	$data['title']      = $post->post_title;
	$data['menu_order'] = $post->menu_order;
	$data['active']     = 'publish' === $post->post_status;
	$data['modified']   = strtotime( $post->post_modified_gmt . ' UTC' );
	return $data;
}

/**
 * Cache de todos los grupos (sin filtrar).
 */
function pcf_get_field_groups_cache( $flush = false ) {
	static $cache = null;
	if ( $flush ) {
		$cache = null;
		return array();
	}
	if ( null !== $cache ) {
		return $cache;
	}

	$groups = array();

	if ( did_action( 'init' ) || post_type_exists( PCF_GROUP_POST_TYPE ) ) {
		$posts = get_posts(
			array(
				'post_type'              => PCF_GROUP_POST_TYPE,
				'post_status'            => array( 'publish', 'pcf-disabled' ),
				'posts_per_page'         => -1,
				'orderby'                => 'menu_order title',
				'order'                  => 'ASC',
				'suppress_filters'       => true,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $posts as $post ) {
			$groups[ $post->post_name ] = pcf_group_from_post( $post );
		}
	}

	foreach ( pcf()->local_groups as $key => $local ) {
		if ( isset( $groups[ $key ] ) ) {
			// El grupo en BD manda; guardamos la info de la versión local.
			$groups[ $key ]['local_source'] = $local['local'];
			if ( isset( $local['modified'] ) ) {
				$groups[ $key ]['local_modified'] = (int) $local['modified'];
			}
			if ( isset( $local['local_file'] ) ) {
				$groups[ $key ]['local_file'] = $local['local_file'];
			}
			continue;
		}
		$local['ID']    = 0;
		$groups[ $key ] = $local;
	}

	foreach ( $groups as $key => $g ) {
		$groups[ $key ] = array_merge( pcf_validate_field_group( $g ), array_intersect_key( $g, array_flip( array( 'ID', 'local', 'local_source', 'local_file', 'modified', 'local_modified' ) ) ) );
	}

	uasort(
		$groups,
		function ( $a, $b ) {
			return $a['menu_order'] <=> $b['menu_order'] ?: strcasecmp( $a['title'], $b['title'] );
		}
	);

	$cache = $groups;
	return $cache;
}

/**
 * Obtiene grupos. Si $filter tiene contexto (post_type, post_id, taxonomy...)
 * devuelve sólo los visibles y activos para ese contexto.
 */
function pcf_get_field_groups( $filter = array() ) {
	$groups = array_values( pcf_get_field_groups_cache() );
	if ( empty( $filter ) ) {
		return $groups;
	}
	return array_values(
		array_filter(
			$groups,
			function ( $group ) use ( $filter ) {
				return $group['active'] && pcf_get_field_group_visibility( $group, $filter );
			}
		)
	);
}

/**
 * Obtiene un grupo por key, ID o título.
 */
function pcf_get_field_group( $selector ) {
	$all = pcf_get_field_groups_cache();
	if ( is_numeric( $selector ) ) {
		foreach ( $all as $g ) {
			if ( (int) $g['ID'] === (int) $selector ) {
				return $g;
			}
		}
		return null;
	}
	if ( isset( $all[ $selector ] ) ) {
		return $all[ $selector ];
	}
	foreach ( $all as $g ) {
		if ( 0 === strcasecmp( $g['title'], (string) $selector ) ) {
			return $g;
		}
	}
	return null;
}

/**
 * Campos de un grupo (acepta grupo o key).
 */
function pcf_get_fields( $group ) {
	if ( ! is_array( $group ) ) {
		$group = pcf_get_field_group( $group );
	}
	return $group ? (array) $group['fields'] : array();
}

/* ------------------------------------------------------------------------
 * Escritura
 * --------------------------------------------------------------------- */

/**
 * Crea o actualiza un grupo en BD. Devuelve el grupo guardado o WP_Error.
 */
function pcf_update_field_group( $group ) {
	$group = pcf_validate_field_group( $group );

	$errors = pcf_check_field_group( $group );
	if ( is_wp_error( $errors ) ) {
		return $errors;
	}

	$existing = get_posts(
		array(
			'post_type'        => PCF_GROUP_POST_TYPE,
			'name'             => $group['key'],
			'post_status'      => 'any',
			'posts_per_page'   => 1,
			'suppress_filters' => true,
		)
	);

	$content = $group;
	unset( $content['ID'], $content['key'], $content['title'], $content['menu_order'], $content['active'], $content['local'], $content['local_source'], $content['local_file'], $content['modified'], $content['local_modified'] );

	$postarr = array(
		'post_type'    => PCF_GROUP_POST_TYPE,
		'post_status'  => $group['active'] ? 'publish' : 'pcf-disabled',
		'post_title'   => $group['title'],
		'post_name'    => $group['key'],
		'post_excerpt' => sanitize_title( $group['title'] ),
		'post_content' => wp_slash( pcf_json_encode( $content ) ),
		'menu_order'   => $group['menu_order'],
	);

	// Evitar que kses altere el JSON.
	$kses = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
	if ( $kses ) {
		kses_remove_filters();
	}

	if ( $existing ) {
		$postarr['ID'] = $existing[0]->ID;
		$id            = wp_update_post( $postarr, true );
	} else {
		$id = wp_insert_post( $postarr, true );
	}

	if ( $kses ) {
		kses_init_filters();
	}

	if ( is_wp_error( $id ) ) {
		return $id;
	}

	pcf_flush_field_cache();
	$saved = pcf_get_field_group( $group['key'] );

	do_action( 'pcf/update_field_group', $saved );
	return $saved;
}

/**
 * Comprobaciones de integridad: keys duplicadas y tipos desconocidos.
 */
function pcf_check_field_group( $group ) {
	$keys   = array();
	$errors = new WP_Error();
	$names  = array();

	foreach ( $group['fields'] as $f ) {
		if ( '' !== $f['name'] && ! in_array( $f['type'], array( 'tab', 'accordion', 'message' ), true ) ) {
			if ( isset( $names[ $f['name'] ] ) ) {
				$errors->add( 'duplicate_name', sprintf( 'Nombre de campo duplicado en el primer nivel: "%s".', $f['name'] ) );
			}
			$names[ $f['name'] ] = true;
		}
	}

	pcf_map_fields(
		$group['fields'],
		function ( $field ) use ( &$keys, $errors ) {
			if ( isset( $keys[ $field['key'] ] ) ) {
				$errors->add( 'duplicate_key', sprintf( 'Key duplicada: %s', $field['key'] ) );
			}
			$keys[ $field['key'] ] = true;
			if ( ! pcf_get_field_type( $field['type'] ) ) {
				$errors->add( 'invalid_type', sprintf( 'Tipo de campo desconocido "%s" en %s. Usa list-field-types.', $field['type'], $field['key'] ) );
			}
			return $field;
		}
	);

	return $errors->has_errors() ? $errors : true;
}

function pcf_get_field_group_post_id( $key ) {
	$posts = get_posts(
		array(
			'post_type'        => PCF_GROUP_POST_TYPE,
			'name'             => $key,
			'post_status'      => 'any',
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'suppress_filters' => true,
		)
	);
	return $posts ? (int) $posts[0] : 0;
}

function pcf_delete_field_group( $key ) {
	$group = pcf_get_field_group( $key );
	if ( ! $group ) {
		return new WP_Error( 'not_found', 'Grupo no encontrado.' );
	}
	if ( ! empty( $group['ID'] ) ) {
		wp_delete_post( $group['ID'], true );
	}
	pcf_remove_local_field_group( $group['key'] );
	pcf_flush_field_cache();
	do_action( 'pcf/delete_field_group', $group );
	return true;
}

function pcf_duplicate_field_group( $key, $new_title = '' ) {
	$group = pcf_get_field_group( $key );
	if ( ! $group ) {
		return new WP_Error( 'not_found', 'Grupo no encontrado.' );
	}
	$map  = array();
	$copy = $group;
	unset( $copy['ID'], $copy['local'], $copy['local_source'], $copy['local_file'] );
	$copy['key']   = pcf_uniqid( 'group' );
	$copy['title'] = $new_title ? $new_title : $group['title'] . ' (copia)';
	$rekey         = function ( $field ) use ( &$map ) {
		$new                  = pcf_uniqid( 'field' );
		$map[ $field['key'] ] = $new;
		$field['key']         = $new;
		foreach ( (array) pcf_maybe_get( $field, 'layouts', array() ) as $i => $l ) {
			$field['layouts'][ $i ]['key'] = pcf_uniqid( 'layout' );
		}
		return $field;
	};
	$copy['fields'] = pcf_map_fields( $group['fields'], $rekey );
	// Reapuntar lógica condicional.
	$copy['fields'] = pcf_map_fields(
		$copy['fields'],
		function ( $field ) use ( $map ) {
			if ( is_array( $field['conditional_logic'] ) ) {
				foreach ( $field['conditional_logic'] as $gi => $g ) {
					foreach ( $g as $ri => $r ) {
						if ( isset( $map[ $r['field'] ] ) ) {
							$field['conditional_logic'][ $gi ][ $ri ]['field'] = $map[ $r['field'] ];
						}
					}
				}
			}
			return $field;
		}
	);
	return pcf_update_field_group( $copy );
}

/* ------------------------------------------------------------------------
 * Visibilidad
 * --------------------------------------------------------------------- */

/**
 * Evalúa las reglas de ubicación de un grupo frente a un contexto.
 */
function pcf_get_field_group_visibility( $group, $screen ) {
	if ( empty( $group['location'] ) ) {
		return false;
	}
	$screen = pcf_prepare_screen( $screen );

	foreach ( $group['location'] as $and ) {
		$ok = true;
		foreach ( $and as $rule ) {
			$location = pcf_maybe_get( pcf()->locations, $rule['param'] );
			if ( ! $location || ! $location->match( $rule, $screen, $group ) ) {
				$ok = false;
				break;
			}
		}
		if ( $ok ) {
			return true;
		}
	}
	return false;
}

function pcf_prepare_screen( $screen ) {
	$screen = wp_parse_args(
		$screen,
		array(
			'lang'    => '',
			'ajax'    => false,
			'post_id' => 0,
		)
	);
	if ( ! empty( $screen['post_id'] ) && empty( $screen['post_type'] ) ) {
		$screen['post_type'] = get_post_type( $screen['post_id'] );
	}
	return apply_filters( 'pcf/location/screen', $screen );
}
