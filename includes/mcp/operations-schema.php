<?php
/**
 * Implementación de operaciones de esquema.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

/* --------------------------------------------------------------------------
 * Utilidades
 * ----------------------------------------------------------------------- */

function pcf_op_field_tree( $fields ) {
	$out = array();
	foreach ( (array) $fields as $f ) {
		$node = array(
			'key'   => $f['key'],
			'name'  => $f['name'],
			'label' => $f['label'],
			'type'  => $f['type'],
		);
		if ( ! empty( $f['required'] ) ) {
			$node['required'] = true;
		}
		if ( ! empty( $f['sub_fields'] ) ) {
			$node['sub_fields'] = pcf_op_field_tree( $f['sub_fields'] );
		}
		if ( ! empty( $f['layouts'] ) ) {
			foreach ( $f['layouts'] as $l ) {
				$node['layouts'][] = array(
					'key'        => $l['key'],
					'name'       => $l['name'],
					'label'      => $l['label'],
					'sub_fields' => pcf_op_field_tree( $l['sub_fields'] ),
				);
			}
		}
		if ( 'clone' === $f['type'] ) {
			$node['clone'] = $f['clone'];
		}
		$out[] = $node;
	}
	return $out;
}

function pcf_op_group_summary( $g, $full = false ) {
	$source = ! empty( $g['ID'] ) ? 'database' : pcf_maybe_get( $g, 'local', 'unknown' );
	$base   = array(
		'key'       => $g['key'],
		'title'     => $g['title'],
		'active'    => (bool) $g['active'],
		'source'    => $source,
		'id'        => (int) pcf_maybe_get( $g, 'ID', 0 ),
		'location'  => $g['location'],
		'position'  => $g['position'],
		'json_file' => pcf_maybe_get( $g, 'local_file', pcf_maybe_get( PCF_Local_JSON::$files, $g['key'], array() ) ? PCF_Local_JSON::$files[ $g['key'] ]['file'] : '' ),
	);
	if ( $full ) {
		unset( $g['local_file'], $g['local_source'], $g['local'], $g['local_modified'] );
		return array_merge( $base, array( 'group' => PCF_Exporter::clean( $g ) ) );
	}
	$base['fields'] = pcf_op_field_tree( $g['fields'] );
	return $base;
}

function pcf_op_require_group( $selector ) {
	$g = pcf_get_field_group( $selector );
	if ( ! $g ) {
		return new WP_Error( 'pcf_group_not_found', sprintf( 'Grupo "%s" no encontrado. Usa list-field-groups.', $selector ), array( 'status' => 404 ) );
	}
	return $g;
}

/**
 * Localiza el grupo que contiene un campo.
 */
function pcf_op_group_for_field( $field_key ) {
	$index = pcf_get_field_index();
	if ( ! isset( $index[ $field_key ] ) ) {
		return new WP_Error( 'pcf_field_not_found', sprintf( 'Campo "%s" no encontrado.', $field_key ), array( 'status' => 404 ) );
	}
	return pcf_get_field_group( $index[ $field_key ]['_group'] );
}

function pcf_op_save_group( $group ) {
	$saved = pcf_update_field_group( $group );
	if ( is_wp_error( $saved ) ) {
		$saved->add_data( array( 'status' => 400 ) );
	}
	return $saved;
}

/**
 * Resuelve keys de campos a partir de nombres en la lógica condicional
 * (permite que la IA escriba {"field":"mostrar_boton"}).
 */
function pcf_op_resolve_condition_names( $group ) {
	$group = pcf_validate_field_group( $group );
	$names = array();
	pcf_map_fields(
		$group['fields'],
		function ( $f ) use ( &$names ) {
			if ( $f['name'] && ! isset( $names[ $f['name'] ] ) ) {
				$names[ $f['name'] ] = $f['key'];
			}
			return $f;
		}
	);
	$group['fields'] = pcf_map_fields(
		$group['fields'],
		function ( $f ) use ( $names ) {
			if ( is_array( $f['conditional_logic'] ) ) {
				foreach ( $f['conditional_logic'] as $gi => $and ) {
					foreach ( $and as $ri => $rule ) {
						if ( 0 !== strpos( $rule['field'], 'field_' ) && isset( $names[ $rule['field'] ] ) ) {
							$f['conditional_logic'][ $gi ][ $ri ]['field'] = $names[ $rule['field'] ];
						}
					}
				}
			}
			return $f;
		}
	);
	return $group;
}

/* --------------------------------------------------------------------------
 * Contexto y documentación
 * ----------------------------------------------------------------------- */

function pcf_op_get_site_context( $input ) {
	$json_path = PCF_Local_JSON::save_path();
	$theme     = wp_get_theme();
	return array(
		'site'           => array(
			'name'        => get_bloginfo( 'name' ),
			'url'         => home_url(),
			'wp_version'  => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
			'environment' => wp_get_environment_type(),
			'front_page'  => (int) get_option( 'page_on_front' ),
		),
		'theme'          => array(
			'name'       => $theme->get( 'Name' ),
			'stylesheet' => get_stylesheet(),
			'path'       => get_stylesheet_directory(),
			'block_theme' => wp_is_block_theme(),
		),
		'pcf'            => array(
			'version'        => PCF_VERSION,
			'acf_active'     => class_exists( 'ACF' ),
			'acf_compat_api' => function_exists( 'get_field' ) && ! class_exists( 'ACF' ),
			'json_path'      => $json_path,
			'json_writable'  => wp_is_writable( is_dir( $json_path ) ? $json_path : dirname( $json_path ) ),
			'file_writes'    => pcf_allow_file_writes(),
			'field_types'    => array_keys( pcf_get_field_types() ),
			'transports'     => array(
				'abilities_api' => function_exists( 'wp_register_ability' ),
				'mcp_adapter'   => class_exists( '\WP\MCP\Core\McpAdapter' ),
				'rest'          => rest_url( 'pcf/v1/operations' ),
				'wp_cli'        => 'wp pcf run <operacion> --input=\'{...}\' --user=admin',
			),
		),
		'counts'         => array(
			'field_groups'  => count( pcf_get_field_groups() ),
			'post_types'    => count( pcf_get_internal_items( 'post_type' ) ),
			'taxonomies'    => count( pcf_get_internal_items( 'taxonomy' ) ),
			'options_pages' => count( pcf_get_options_pages() ),
			'blocks'        => count( PCF_Blocks::get_blocks() ),
		),
		'post_types'     => pcf_get_post_type_choices(),
		'taxonomies'     => pcf_get_taxonomy_choices(),
		'page_templates' => wp_get_theme()->get_page_templates(),
		'current_user'   => array(
			'id'    => get_current_user_id(),
			'login' => wp_get_current_user()->user_login,
			'admin' => current_user_can( pcf_get_setting( 'capability' ) ),
		),
		'next_steps'     => 'Lee get-usage-guide si es tu primera vez. Luego list-field-groups / list-field-types.',
	);
}

function pcf_allow_file_writes() {
	$env = wp_get_environment_type();
	return (bool) apply_filters( 'pcf/allow_file_writes', in_array( $env, array( 'local', 'development' ), true ) && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) );
}

function pcf_op_get_usage_guide( $input ) {
	$guide = <<<'MD'
# Prompt Custom Fields — guía para agentes

## Flujo recomendado
1. `get-site-context` → entorno, tema, post types y transportes.
2. `list-field-types` (ajustes de cada tipo) y `list-location-rules` (valores válidos).
3. Crear estructura: `create-field-group` con todos los campos a la vez (más rápido que `add-field` uno a uno).
4. Rellenar contenido: `get-object-fields` → `update-values`.
5. Generar código del tema: `generate-template-code`.
6. Los grupos se guardan en BD y en `{tema}/pcf-json/{key}.json` (versionable).

## Formato de campo (igual que ACF)
```json
{"label":"Título","name":"titulo","type":"text","required":1,"instructions":"…",
 "wrapper":{"width":"50"},
 "conditional_logic":[[{"field":"mostrar","operator":"==","value":"1"}]]}
```
- `key` es opcional: se genera `field_xxxxxxxxxxxxx`. Si pasas `"key":"hero_titulo"` se normaliza a `field_hero_titulo` (útil para referencias estables).
- `name` es opcional: se deriva del label (`"Título Hero"` → `titulo_hero`).
- En `conditional_logic` puedes usar el **name** del campo; se convierte a key al guardar.
- `choices` admite `{"valor":"Etiqueta"}`, `["a","b"]` o texto `valor : Etiqueta` por línea.
- Contenedores: `group` y `repeater` usan `sub_fields`; `flexible_content` usa `layouts: [{"name","label","sub_fields"}]`; `clone` usa `clone: ["field_…" | "group_…"]`.

## Ubicación
`[[{"param":"post_type","operator":"==","value":"page"}]]` → OR entre grupos exteriores, AND dentro.
Atajos: `{"param":"page_template","value":"templates/landing.php"}` o `"options_page:theme-settings"`.
Bloques: `{"param":"block","value":"pcf/hero"}`.

## Objetos (parámetro `object`)
`123` (post) · `"options"` (options pages) · `"term_5"` · `"user_1"` · `"comment_3"`.

## Formato de valores para update-values (por name)
- text/textarea/wysiwyg/email/url: string · number/range: número · true_false: boolean
- select/radio/button_group: key de choice · checkbox o select múltiple: array de keys
- image/file: ID de adjunto o URL (las URLs externas se descargan a la biblioteca) · gallery: array de IDs/URLs
- post_object/page_link/relationship: ID, slug o título (array si es múltiple)
- taxonomy: ID, slug o nombre (se crea si add_term=1) · user: ID, login o email
- link: {"url","title","target"} · google_map: {"address","lat","lng"}
- date_picker: "2026-12-31" (se guarda Ymd) · date_time_picker: "2026-12-31 18:30:00" · time_picker: "18:30"
- group: {"sub":valor} · repeater: [{"sub":valor}, …] · flexible_content: [{"acf_fc_layout":"hero","sub":valor}, …]
`update-values` reemplaza el valor completo del campo (un repeater queda con exactamente las filas enviadas). Para cambios parciales usa add-row / update-row / delete-row.

## Plantillas del tema
API compatible con ACF: `get_field()`, `the_field()`, `have_rows()`, `the_row()`, `get_sub_field()`, `get_row_layout()`… (activa si ACF no está instalado). Siempre disponible con prefijo: `pcf_value()`, `pcf_have_rows()`, `pcf_the_row()`, `pcf_sub_value()`.
Opciones: `get_field('logo', 'option')`. Términos: `get_field('color', $term)` o `'term_5'`.

## Bloques
`create-block` crea `{tema}/blocks/{slug}/block.json` + `render.php` y (si pasas `fields`) su grupo de campos. En `render.php` usa `get_field()` normalmente; `$block['className']`, `$is_preview` y `$content` (InnerBlocks) están disponibles.

## Importar ACF
`detect-acf` → `import-acf` con `dry_run: true` → `import-acf`. Se conservan las keys, así el contenido existente sigue funcionando.
MD;

	// Las convenciones de la casa (cómo nombrar pestañas, qué poner en cada
	// ayuda) viven en un markdown aparte para poder editarlas sin tocar PHP.
	$convenciones = PCF_PATH . 'docs/convenciones.md';
	if ( is_readable( $convenciones ) ) {
		$guide .= "\n\n---\n\n" . file_get_contents( $convenciones );
	}

	return array( 'guide' => $guide );
}

/* --------------------------------------------------------------------------
 * Tipos y ubicaciones
 * ----------------------------------------------------------------------- */

function pcf_op_list_field_types( $input ) {
	$out = array();
	foreach ( pcf_get_field_types() as $name => $type ) {
		if ( ! empty( $input['type'] ) && $input['type'] !== $name ) {
			continue;
		}
		if ( ! empty( $input['category'] ) && $input['category'] !== $type->category ) {
			continue;
		}
		$d = $type->describe();
		if ( ! empty( $input['compact'] ) ) {
			$d = array_intersect_key( $d, array_flip( array( 'type', 'label', 'category', 'description', 'value_format' ) ) );
		}
		$out[] = $d;
	}
	return array(
		'common_settings' => array(
			'key'               => 'string opcional (field_…)',
			'label'             => 'string',
			'name'              => 'string (slug del meta)',
			'type'              => 'string',
			'instructions'      => 'string',
			'required'          => '0|1',
			'default_value'     => 'según tipo',
			'conditional_logic' => '[[{"field":"key|name","operator":"==|!=|==empty|!=empty|==contains|>|<","value":"…"}]]',
			'wrapper'           => '{"width":"50","class":"","id":""}',
		),
		'field_types'     => $out,
	);
}

function pcf_op_list_location_rules( $input ) {
	$out = array();
	foreach ( pcf()->locations as $name => $loc ) {
		if ( ! empty( $input['param'] ) && $input['param'] !== $name ) {
			continue;
		}
		$d = $loc->describe();
		if ( empty( $input['param'] ) && empty( $input['with_values'] ) ) {
			unset( $d['values'] );
		}
		$out[] = $d;
	}
	return array( 'rules' => $out );
}

/* --------------------------------------------------------------------------
 * Grupos
 * ----------------------------------------------------------------------- */

function pcf_op_list_field_groups( $input ) {
	$filter = array();
	if ( ! empty( $input['post_type'] ) ) {
		$filter['post_type'] = $input['post_type'];
	}
	if ( ! empty( $input['object'] ) ) {
		$filter = pcf_screen_for_post_id( pcf_get_valid_post_id( $input['object'] ) );
	}
	$groups = $filter ? pcf_get_field_groups( $filter ) : pcf_get_field_groups();
	$out    = array();
	foreach ( $groups as $g ) {
		if ( ! empty( $input['search'] ) && false === stripos( $g['title'] . ' ' . $g['key'], $input['search'] ) ) {
			continue;
		}
		$s = pcf_op_group_summary( $g );
		if ( isset( $input['include_fields'] ) && ! $input['include_fields'] ) {
			$s['field_count'] = count( $s['fields'] );
			unset( $s['fields'] );
		}
		$out[] = $s;
	}
	return array( 'count' => count( $out ), 'field_groups' => $out );
}

function pcf_op_get_field_group( $input ) {
	$g = pcf_op_require_group( $input['key'] );
	if ( is_wp_error( $g ) ) {
		return $g;
	}
	return pcf_op_group_summary( $g, true );
}

function pcf_op_create_field_group( $input ) {
	if ( ! empty( $input['key'] ) ) {
		$k = 0 === strpos( $input['key'], 'group_' ) ? $input['key'] : 'group_' . pcf_slugify( $input['key'] );
		$e = pcf_get_field_group( $k );
		if ( $e && ! empty( $e['ID'] ) ) {
			return new WP_Error( 'pcf_exists', sprintf( 'Ya existe el grupo %s. Usa update-field-group.', $k ), array( 'status' => 409 ) );
		}
	}
	$group = pcf_op_resolve_condition_names( $input );
	$saved = pcf_op_save_group( $group );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	return array_merge( array( 'created' => true ), pcf_op_group_summary( $saved ) );
}

function pcf_op_update_field_group( $input ) {
	$g = pcf_op_require_group( $input['key'] );
	if ( is_wp_error( $g ) ) {
		return $g;
	}
	$changes = (array) pcf_maybe_get( $input, 'changes', array() );
	unset( $changes['key'], $changes['ID'] );
	$group = array_merge( $g, $changes );
	$group = pcf_op_resolve_condition_names( $group );
	$saved = pcf_op_save_group( $group );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	return array_merge( array( 'updated' => true ), pcf_op_group_summary( $saved ) );
}

function pcf_op_delete_field_group( $input ) {
	$g = pcf_op_require_group( $input['key'] );
	if ( is_wp_error( $g ) ) {
		return $g;
	}
	$res = pcf_delete_field_group( $g['key'] );
	return is_wp_error( $res ) ? $res : array( 'deleted' => $g['key'], 'note' => 'Los valores guardados en el contenido NO se borran.' );
}

function pcf_op_duplicate_field_group( $input ) {
	$res = pcf_duplicate_field_group( $input['key'], pcf_maybe_get( $input, 'title', '' ) );
	return is_wp_error( $res ) ? $res : pcf_op_group_summary( $res );
}

/* --------------------------------------------------------------------------
 * Campos
 * ----------------------------------------------------------------------- */

function pcf_op_add_field( $input ) {
	$g = pcf_op_require_group( $input['group'] );
	if ( is_wp_error( $g ) ) {
		return $g;
	}
	$fields = isset( $input['fields'] ) ? (array) $input['fields'] : array( $input['field'] );
	$added  = array();
	foreach ( $fields as $i => $field ) {
		$field = pcf_validate_field( $field );
		if ( pcf_get_field( $field['key'] ) ) {
			return new WP_Error( 'pcf_duplicate_key', sprintf( 'La key %s ya existe.', $field['key'] ), array( 'status' => 409 ) );
		}
		$found       = false;
		$position    = isset( $input['position'] ) ? (int) $input['position'] + $i : null;
		$g['fields'] = pcf_insert_field( $g['fields'], $field, pcf_maybe_get( $input, 'parent' ), pcf_maybe_get( $input, 'layout' ), $position, $found );
		if ( ! $found ) {
			return new WP_Error( 'pcf_parent_not_found', sprintf( 'No se encontró el contenedor %s en el grupo.', $input['parent'] ), array( 'status' => 404 ) );
		}
		$added[] = array( 'key' => $field['key'], 'name' => $field['name'], 'type' => $field['type'] );
	}
	$saved = pcf_op_save_group( pcf_op_resolve_condition_names( $g ) );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	return array( 'added' => $added, 'group' => pcf_op_group_summary( $saved ) );
}

function pcf_op_update_field( $input ) {
	$g = pcf_op_group_for_field( $input['key'] );
	if ( is_wp_error( $g ) || ! $g ) {
		return $g ? $g : new WP_Error( 'pcf_field_not_found', 'Campo no encontrado.' );
	}
	$changes = (array) pcf_maybe_get( $input, 'changes', array() );
	$replace = ! empty( $input['replace'] );
	$g['fields'] = pcf_map_fields(
		$g['fields'],
		function ( $f ) use ( $input, $changes, $replace ) {
			if ( $f['key'] !== $input['key'] ) {
				return $f;
			}
			$new        = $replace ? $changes : array_merge( $f, $changes );
			$new['key'] = $f['key'];
			// Si cambia el tipo, descartar ajustes que no aplican.
			if ( ! $replace && isset( $changes['type'] ) && $changes['type'] !== $f['type'] ) {
				$old_type = pcf_get_field_type( $f['type'] );
				if ( $old_type ) {
					foreach ( array_keys( $old_type->defaults ) as $k ) {
						if ( ! array_key_exists( $k, $changes ) && ! in_array( $k, array( 'default_value', 'sub_fields' ), true ) ) {
							unset( $new[ $k ] );
						}
					}
				}
			}
			return pcf_validate_field( $new );
		}
	);
	$saved = pcf_op_save_group( pcf_op_resolve_condition_names( $g ) );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	return array(
		'updated' => $input['key'],
		'field'   => pcf_find_field_in( $saved['fields'], $input['key'] ),
		'note'    => isset( $changes['name'] ) ? 'Cambiar el name no migra valores ya guardados con el nombre anterior.' : '',
	);
}

function pcf_op_delete_field( $input ) {
	$g = pcf_op_group_for_field( $input['key'] );
	if ( is_wp_error( $g ) || ! $g ) {
		return $g ? $g : new WP_Error( 'pcf_field_not_found', 'Campo no encontrado.' );
	}
	$g['fields'] = pcf_map_fields(
		$g['fields'],
		function ( $f ) use ( $input ) {
			return $f['key'] === $input['key'] ? null : $f;
		}
	);
	$saved = pcf_op_save_group( $g );
	return is_wp_error( $saved ) ? $saved : array( 'deleted' => $input['key'], 'group' => $g['key'] );
}

function pcf_op_move_field( $input ) {
	$from = pcf_op_group_for_field( $input['key'] );
	if ( is_wp_error( $from ) || ! $from ) {
		return $from ? $from : new WP_Error( 'pcf_field_not_found', 'Campo no encontrado.' );
	}
	$field = pcf_find_field_in( $from['fields'], $input['key'] );
	unset( $field['parent'], $field['_group'] );

	$from['fields'] = pcf_map_fields(
		$from['fields'],
		function ( $f ) use ( $input ) {
			return $f['key'] === $input['key'] ? null : $f;
		}
	);

	$to_key = pcf_maybe_get( $input, 'to_group', $from['key'] );
	$to     = $to_key === $from['key'] ? $from : pcf_op_require_group( $to_key );
	if ( is_wp_error( $to ) ) {
		return $to;
	}
	$found        = false;
	$to['fields'] = pcf_insert_field( $to['fields'], $field, pcf_maybe_get( $input, 'parent' ), pcf_maybe_get( $input, 'layout' ), isset( $input['position'] ) ? (int) $input['position'] : null, $found );
	if ( ! $found ) {
		return new WP_Error( 'pcf_parent_not_found', 'Contenedor destino no encontrado.', array( 'status' => 404 ) );
	}
	if ( $to['key'] !== $from['key'] ) {
		$r = pcf_op_save_group( $from );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
	}
	$saved = pcf_op_save_group( $to );
	return is_wp_error( $saved ) ? $saved : array( 'moved' => $input['key'], 'group' => pcf_op_group_summary( $saved ) );
}

function pcf_op_add_layout( $input ) {
	$g = pcf_op_group_for_field( $input['field'] );
	if ( is_wp_error( $g ) || ! $g ) {
		return $g ? $g : new WP_Error( 'pcf_field_not_found', 'Campo no encontrado.' );
	}
	$layout      = $input['layout'];
	$g['fields'] = pcf_map_fields(
		$g['fields'],
		function ( $f ) use ( $input, $layout ) {
			if ( $f['key'] !== $input['field'] ) {
				return $f;
			}
			$layouts = (array) $f['layouts'];
			$pos     = isset( $input['position'] ) ? (int) $input['position'] : count( $layouts );
			array_splice( $layouts, $pos, 0, array( $layout ) );
			$f['layouts'] = $layouts;
			return pcf_validate_field( $f );
		}
	);
	$saved = pcf_op_save_group( pcf_op_resolve_condition_names( $g ) );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	return array( 'field' => pcf_op_field_tree( array( pcf_find_field_in( $saved['fields'], $input['field'] ) ) )[0] );
}

/* --------------------------------------------------------------------------
 * Post types, taxonomías, options pages
 * ----------------------------------------------------------------------- */

function pcf_op_list_internal( $kind ) {
	return function ( $input ) use ( $kind ) {
		$items = array();
		foreach ( pcf_get_internal_items( $kind ) as $item ) {
			$items[] = PCF_Exporter::clean( $item ) + array( 'id' => $item['ID'] );
		}
		$out = array( 'managed_by_pcf' => $items );
		if ( 'post_type' === $kind ) {
			$out['registered'] = pcf_get_post_type_choices();
		} elseif ( 'taxonomy' === $kind ) {
			$out['registered'] = pcf_get_taxonomy_choices();
		} else {
			$out['registered'] = array_values(
				array_map(
					function ( $p ) {
						return array_intersect_key( $p, array_flip( array( 'menu_slug', 'page_title', 'parent_slug', 'post_id', 'capability' ) ) ) + array( 'source' => pcf_maybe_get( $p, 'source', 'php' ) );
					},
					pcf_get_options_pages()
				)
			);
		}
		return $out;
	};
}

function pcf_op_save_internal( $kind ) {
	return function ( $input ) use ( $kind ) {
		$res = pcf_update_internal_item( $kind, $input );
		if ( is_wp_error( $res ) ) {
			$res->add_data( array( 'status' => 400 ) );
			return $res;
		}
		$note = 'options_page' === $kind
			? sprintf( 'Crea un grupo con ubicación options_page == %s y lee sus valores con get_field("campo", "%s").', $res['menu_slug'], $res['post_id'] )
			: 'Registrado en init; los enlaces permanentes se regeneran en la próxima carga.';
		return array( 'saved' => PCF_Exporter::clean( $res ), 'note' => $note );
	};
}

function pcf_op_delete_internal( $kind ) {
	return function ( $input ) use ( $kind ) {
		$res = pcf_delete_internal_item( $kind, $input['key'] );
		return is_wp_error( $res ) ? $res : array( 'deleted' => $input['key'], 'note' => 'El contenido existente no se borra.' );
	};
}

/* --------------------------------------------------------------------------
 * Bloques
 * ----------------------------------------------------------------------- */

function pcf_op_list_blocks( $input ) {
	$out = array();
	foreach ( PCF_Blocks::get_blocks() as $name => $b ) {
		$out[] = array(
			'name'     => $name,
			'title'    => $b['title'],
			'source'   => $b['source'],
			'dir'      => pcf_maybe_get( $b, 'dir', '' ),
			'template' => $b['render_template'],
			'groups'   => wp_list_pluck( pcf_get_field_groups( array( 'block' => $name ) ), 'key' ),
		);
	}
	return array( 'blocks' => $out, 'blocks_dirs' => pcf_get_setting( 'blocks_dirs' ) );
}

function pcf_op_create_block( $input ) {
	if ( ! pcf_allow_file_writes() ) {
		return new WP_Error( 'pcf_file_writes_disabled', 'La escritura de archivos sólo está permitida con WP_ENVIRONMENT_TYPE local/development (o el filtro pcf/allow_file_writes).', array( 'status' => 403 ) );
	}
	$res = PCF_Blocks::scaffold( $input );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	if ( ! empty( $input['fields'] ) ) {
		$group = array(
			'key'      => pcf_maybe_get( $input, 'group_key', 'group_block_' . pcf_slugify( $res['name'] ) ),
			'title'    => 'Bloque: ' . pcf_maybe_get( $input, 'title', $res['name'] ),
			'fields'   => $input['fields'],
			'location' => array( array( array( 'param' => 'block', 'operator' => '==', 'value' => $res['name'] ) ) ),
		);
		$existing = pcf_get_field_group( $group['key'] );
		if ( $existing ) {
			$group = array_merge( $existing, $group );
		}
		$saved = pcf_op_save_group( pcf_op_resolve_condition_names( $group ) );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$res['field_group'] = pcf_op_group_summary( $saved );
	}
	$res['note'] = 'El bloque se registra en la próxima carga. Edita render.php con write-block-file o usa generate-template-code.';
	return $res;
}

function pcf_op_write_block_file( $input ) {
	if ( ! pcf_allow_file_writes() ) {
		return new WP_Error( 'pcf_file_writes_disabled', 'Escritura de archivos deshabilitada en este entorno.', array( 'status' => 403 ) );
	}
	$allowed = array( 'render.php', 'style.css', 'editor.css', 'script.js', 'view.js', 'block.json' );
	if ( ! in_array( $input['file'], $allowed, true ) ) {
		return new WP_Error( 'pcf_invalid_file', 'Archivo no permitido. Permitidos: ' . implode( ', ', $allowed ) );
	}
	$dir = '';
	$blocks = PCF_Blocks::get_blocks();
	if ( isset( $blocks[ $input['block'] ]['dir'] ) ) {
		$dir = $blocks[ $input['block'] ]['dir'];
	} else {
		foreach ( PCF_Blocks::scan_dirs() as $name => $info ) {
			if ( $name === $input['block'] ) {
				$dir = $info['dir'];
			}
		}
	}
	if ( ! $dir ) {
		return new WP_Error( 'pcf_block_not_found', 'Bloque no encontrado (sólo bloques basados en block.json).', array( 'status' => 404 ) );
	}
	if ( 'block.json' === $input['file'] && null === pcf_json_decode( $input['content'] ) ) {
		return new WP_Error( 'pcf_invalid_json', 'block.json no es JSON válido.' );
	}
	$path = trailingslashit( $dir ) . $input['file'];
	file_put_contents( $path, $input['content'] ); // phpcs:ignore
	return array( 'written' => $path, 'bytes' => strlen( $input['content'] ) );
}

/* --------------------------------------------------------------------------
 * JSON, export, import
 * ----------------------------------------------------------------------- */

function pcf_op_json_status( $input ) {
	return array( 'path' => PCF_Local_JSON::save_path(), 'items' => PCF_Local_JSON::status() );
}

function pcf_op_json_sync( $input ) {
	return PCF_Local_JSON::sync( (array) pcf_maybe_get( $input, 'keys', array() ) );
}

function pcf_op_export( $input ) {
	$keys   = (array) pcf_maybe_get( $input, 'keys', array() );
	$format = pcf_maybe_get( $input, 'format', 'json' );
	if ( 'php' === $format ) {
		return array( 'format' => 'php', 'code' => PCF_Exporter::to_php( $keys, ! empty( $input['acf_function_names'] ) ) );
	}
	return array( 'format' => 'json', 'items' => PCF_Exporter::collect( $keys ) );
}

function pcf_op_import_json( $input ) {
	$importer = new PCF_ACF_Importer();
	return $importer->run(
		array(
			'source'    => 'json',
			'json'      => $input['json'],
			'overwrite' => ! empty( $input['overwrite'] ),
			'dry_run'   => ! empty( $input['dry_run'] ),
		)
	);
}

function pcf_op_detect_acf( $input ) {
	return PCF_ACF_Importer::detect();
}

function pcf_op_import_acf( $input ) {
	$importer = new PCF_ACF_Importer();
	return $importer->run( $input );
}
