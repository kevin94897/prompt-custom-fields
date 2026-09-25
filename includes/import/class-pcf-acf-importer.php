<?php
/**
 * Importa grupos de campos, post types, taxonomías y options pages de ACF.
 *
 * Fuentes:
 *  - database: posts acf-field-group / acf-field / acf-post-type / acf-taxonomy /
 *              acf-ui-options-page (funciona aunque ACF esté desactivado).
 *  - acf_api:  si ACF está activo, usa acf_get_field_groups() (incluye grupos PHP/JSON).
 *  - json:     contenido de un export de ACF (string o array).
 *  - acf_json: carpeta acf-json del tema (o ruta indicada).
 *
 * Las keys se conservan, así los valores ya guardados por ACF (meta _nombre => field_key)
 * funcionan sin migrar datos.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_ACF_Importer {

	const SUPPORTED_LOCATIONS = array( 'post_type', 'post_template', 'post_status', 'post_format', 'post_category', 'post_taxonomy', 'post', 'page_template', 'page_type', 'page_parent', 'page', 'current_user', 'current_user_role', 'user_form', 'user_role', 'taxonomy', 'attachment', 'comment', 'block', 'options_page' );

	/** @var array */
	protected $warnings = array();

	/**
	 * Detecta qué hay disponible para importar.
	 */
	public static function detect() {
		global $wpdb;
		$counts = array();
		foreach ( array( 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page' ) as $pt ) {
			$counts[ $pt ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','acf-disabled')", $pt ) ); // phpcs:ignore
		}
		$json_dir = get_stylesheet_directory() . '/acf-json';
		return array(
			'acf_active'     => class_exists( 'ACF' ),
			'acf_version'    => defined( 'ACF_VERSION' ) ? ACF_VERSION : null,
			'database'       => $counts,
			'acf_json_dir'   => is_dir( $json_dir ) ? $json_dir : null,
			'acf_json_files' => is_dir( $json_dir ) ? count( glob( $json_dir . '/*.json' ) ) : 0,
		);
	}

	/**
	 * @param array $args source, json, path, keys, include, overwrite, dry_run, deactivate_acf.
	 */
	public function run( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'source'         => 'database',
				'json'           => null,
				'path'           => '',
				'keys'           => array(),
				'include'        => array( 'field_groups', 'post_types', 'taxonomies', 'options_pages' ),
				'overwrite'      => false,
				'dry_run'        => false,
				'deactivate_acf' => false,
			)
		);

		$this->warnings = array();
		switch ( $args['source'] ) {
			case 'acf_api':
				$items = $this->from_acf_api();
				break;
			case 'json':
				$data = pcf_json_decode( $args['json'] );
				if ( null === $data ) {
					return new WP_Error( 'invalid_json', 'El JSON no es válido.' );
				}
				$items = $this->from_export( isset( $data['key'] ) ? array( $data ) : $data );
				break;
			case 'acf_json':
				$items = $this->from_json_dir( $args['path'] ? $args['path'] : get_stylesheet_directory() . '/acf-json' );
				break;
			case 'database':
			default:
				$items = $this->from_database();
		}
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$report = array(
			'source'   => $args['source'],
			'dry_run'  => (bool) $args['dry_run'],
			'imported' => array(),
			'skipped'  => array(),
			'errors'   => array(),
		);

		$kinds = array(
			'field_groups'  => 'field_group',
			'post_types'    => 'post_type',
			'taxonomies'    => 'taxonomy',
			'options_pages' => 'options_page',
		);

		foreach ( $items as $item ) {
			$kind   = $item['_kind'];
			$acf_id = (int) pcf_maybe_get( $item, '_acf_post_id', 0 );
			unset( $item['_kind'], $item['_acf_post_id'] );
			if ( ! in_array( array_search( $kind, $kinds, true ), (array) $args['include'], true ) ) {
				continue;
			}
			if ( $args['keys'] && ! in_array( $item['key'], (array) $args['keys'], true ) ) {
				continue;
			}
			$exists = 'field_group' === $kind ? pcf_get_field_group( $item['key'] ) : pcf_get_internal_item( $kind, $item['key'] );
			if ( $exists && ! empty( $exists['ID'] ) && ! $args['overwrite'] ) {
				$report['skipped'][] = array( 'key' => $item['key'], 'kind' => $kind, 'reason' => 'ya existe (usa overwrite)' );
				continue;
			}
			$summary = array(
				'key'   => $item['key'],
				'kind'  => $kind,
				'title' => pcf_maybe_get( $item, 'title', '' ),
			);
			if ( 'field_group' === $kind ) {
				$count = 0;
				pcf_map_fields(
					$item['fields'],
					function ( $f ) use ( &$count ) {
						$count++;
						return $f;
					}
				);
				$summary['fields'] = $count;
			}
			if ( $args['dry_run'] ) {
				$report['imported'][] = $summary;
				continue;
			}
			$res = 'field_group' === $kind ? pcf_update_field_group( $item ) : pcf_update_internal_item( $kind, $item );
			if ( is_wp_error( $res ) ) {
				$report['errors'][] = array( 'key' => $item['key'], 'error' => $res->get_error_message() );
				continue;
			}
			$report['imported'][] = $summary;
			if ( $args['deactivate_acf'] && $acf_id ) {
				wp_update_post( array( 'ID' => $acf_id, 'post_status' => 'acf-disabled' ) );
			}
		}

		$report['warnings'] = array_values( array_unique( $this->warnings ) );
		if ( ! $args['dry_run'] ) {
			pcf_flush_field_cache();
		}
		return $report;
	}

	/* -------------------------------------------------------------------- */

	protected function from_database() {
		$items = array();
		$posts = get_posts(
			array(
				'post_type'        => array( 'acf-field-group', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page' ),
				'post_status'      => array( 'publish', 'acf-disabled' ),
				'posts_per_page'   => -1,
				'orderby'          => 'menu_order title',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		foreach ( $posts as $post ) {
			$data           = (array) maybe_unserialize( $post->post_content );
			$data['key']    = $post->post_name;
			$data['title']  = $post->post_title;
			$data['active'] = 'publish' === $post->post_status;
			switch ( $post->post_type ) {
				case 'acf-field-group':
					$data['menu_order'] = $post->menu_order;
					$data['fields']     = $this->db_children( $post->ID );
					$item               = $this->convert_group( $data );
					break;
				case 'acf-post-type':
					$item = $this->convert_post_type( $data );
					break;
				case 'acf-taxonomy':
					$item = $this->convert_taxonomy( $data );
					break;
				default:
					$item = $this->convert_options_page( $data );
			}
			$item['_acf_post_id'] = $post->ID;
			$items[]              = $item;
		}
		return $items;
	}

	protected function db_children( $parent_id ) {
		$fields = array();
		$posts  = get_posts(
			array(
				'post_type'        => 'acf-field',
				'post_parent'      => $parent_id,
				'post_status'      => array( 'publish', 'acf-disabled' ),
				'posts_per_page'   => -1,
				'orderby'          => 'menu_order',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		foreach ( $posts as $post ) {
			$field          = (array) maybe_unserialize( $post->post_content );
			$field['key']   = $post->post_name;
			$field['label'] = $post->post_title;
			$field['name']  = $post->post_excerpt;
			$children       = $this->db_children( $post->ID );
			if ( $children ) {
				if ( 'flexible_content' === pcf_maybe_get( $field, 'type' ) ) {
					$layouts = array();
					foreach ( (array) pcf_maybe_get( $field, 'layouts', array() ) as $lk => $layout ) {
						$layout['sub_fields'] = array();
						$layouts[ $layout['key'] ] = $layout;
					}
					foreach ( $children as $child ) {
						$lk = pcf_maybe_get( $child, 'parent_layout' );
						if ( $lk && isset( $layouts[ $lk ] ) ) {
							$layouts[ $lk ]['sub_fields'][] = $child;
						}
					}
					$field['layouts'] = array_values( $layouts );
				} else {
					$field['sub_fields'] = $children;
				}
			}
			$fields[] = $field;
		}
		return $fields;
	}

	protected function from_acf_api() {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return new WP_Error( 'acf_inactive', 'ACF no está activo: usa source=database o source=json.' );
		}
		$items = array();
		foreach ( acf_get_field_groups() as $group ) {
			$group['fields'] = acf_get_fields( $group );
			$items[]         = $this->convert_group( $group );
		}
		foreach ( array( 'acf_get_acf_post_types' => 'convert_post_type', 'acf_get_acf_taxonomies' => 'convert_taxonomy', 'acf_get_ui_options_pages' => 'convert_options_page' ) as $fn => $conv ) {
			if ( function_exists( $fn ) ) {
				foreach ( (array) call_user_func( $fn ) as $raw ) {
					$items[] = $this->$conv( $raw );
				}
			}
		}
		return $items;
	}

	protected function from_json_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return new WP_Error( 'no_dir', 'No existe la carpeta ' . $dir );
		}
		$list = array();
		foreach ( glob( trailingslashit( $dir ) . '*.json' ) as $file ) {
			$data = pcf_json_decode( file_get_contents( $file ) ); // phpcs:ignore
			if ( $data ) {
				$list[] = $data;
			}
		}
		return $this->from_export( $list );
	}

	protected function from_export( $list ) {
		$items = array();
		foreach ( (array) $list as $data ) {
			if ( ! is_array( $data ) || empty( $data['key'] ) ) {
				continue;
			}
			$key = $data['key'];
			if ( 0 === strpos( $key, 'group_' ) ) {
				$items[] = $this->convert_group( $data );
			} elseif ( 0 === strpos( $key, 'post_type_' ) ) {
				$items[] = $this->convert_post_type( $data );
			} elseif ( 0 === strpos( $key, 'taxonomy_' ) ) {
				$items[] = $this->convert_taxonomy( $data );
			} elseif ( 0 === strpos( $key, 'ui_options_page_' ) ) {
				$items[] = $this->convert_options_page( $data );
			}
		}
		return $items;
	}

	/* -------------------------------------------------------------------- */

	public function convert_group( $g ) {
		$keep  = array_keys( pcf_field_group_defaults() );
		$group = array_intersect_key( $g, array_flip( array_merge( $keep, array( 'allow_ai_access', 'ai_description' ) ) ) );

		$group['fields']   = array_map( array( $this, 'convert_field' ), (array) pcf_maybe_get( $g, 'fields', array() ) );
		$group['location'] = array();
		foreach ( (array) pcf_maybe_get( $g, 'location', array() ) as $and ) {
			$rules = array();
			foreach ( (array) $and as $rule ) {
				if ( ! in_array( pcf_maybe_get( $rule, 'param' ), self::SUPPORTED_LOCATIONS, true ) ) {
					$this->warnings[] = sprintf( 'Grupo "%s": la regla de ubicación "%s" no está soportada y se omitió.', pcf_maybe_get( $g, 'title' ), pcf_maybe_get( $rule, 'param' ) );
					continue;
				}
				$rules[] = $rule;
			}
			if ( $rules ) {
				$group['location'][] = $rules;
			}
		}
		if ( 'acf_after_title' === pcf_maybe_get( $group, 'position' ) ) {
			$group['position'] = 'acf_after_title';
		}
		$group['_kind'] = 'field_group';
		return $group;
	}

	public function convert_field( $f ) {
		$drop = array( 'ID', 'id', 'class', 'parent', 'menu_order', 'prefix', '_name', '_valid', '_prepare', 'value', 'parent_layout', 'field_group' );
		foreach ( $drop as $k ) {
			unset( $f[ $k ] );
		}
		$type = pcf_maybe_get( $f, 'type', 'text' );
		if ( did_action( 'init' ) && ! pcf_get_field_type( $type ) ) {
			$this->warnings[] = sprintf( 'Campo "%s": tipo "%s" no soportado; se importó como text.', pcf_maybe_get( $f, 'name' ), $type );
			$f['pcf_original_type'] = $type;
			$f['type']              = 'text';
		}
		if ( ! empty( $f['sub_fields'] ) ) {
			$f['sub_fields'] = array_map( array( $this, 'convert_field' ), $f['sub_fields'] );
		}
		if ( ! empty( $f['layouts'] ) ) {
			$layouts = array();
			foreach ( $f['layouts'] as $layout ) {
				$layout['sub_fields'] = array_map( array( $this, 'convert_field' ), (array) pcf_maybe_get( $layout, 'sub_fields', array() ) );
				$layouts[]            = $layout;
			}
			$f['layouts'] = $layouts;
		}
		// ACF usa "clone" con keys; se conservan tal cual.
		return $f;
	}

	protected function flag( $v ) {
		return (bool) $v && '0' !== $v;
	}

	public function convert_post_type( $d ) {
		$labels = (array) pcf_maybe_get( $d, 'labels', array() );
		$args   = array(
			'labels'              => array_filter( $labels ),
			'description'         => pcf_maybe_get( $d, 'description', '' ),
			'public'              => $this->flag( pcf_maybe_get( $d, 'public', true ) ),
			'hierarchical'        => $this->flag( pcf_maybe_get( $d, 'hierarchical', false ) ),
			'exclude_from_search' => $this->flag( pcf_maybe_get( $d, 'exclude_from_search', false ) ),
			'publicly_queryable'  => $this->flag( pcf_maybe_get( $d, 'publicly_queryable', true ) ),
			'show_ui'             => $this->flag( pcf_maybe_get( $d, 'show_ui', true ) ),
			'show_in_menu'        => pcf_maybe_get( $d, 'admin_menu_parent' ) ? $d['admin_menu_parent'] : $this->flag( pcf_maybe_get( $d, 'show_in_menu', true ) ),
			'show_in_admin_bar'   => $this->flag( pcf_maybe_get( $d, 'show_in_admin_bar', true ) ),
			'show_in_nav_menus'   => $this->flag( pcf_maybe_get( $d, 'show_in_nav_menus', true ) ),
			'show_in_rest'        => $this->flag( pcf_maybe_get( $d, 'show_in_rest', true ) ),
			'supports'            => array_values( (array) pcf_maybe_get( $d, 'supports', array( 'title', 'editor' ) ) ),
			'can_export'          => $this->flag( pcf_maybe_get( $d, 'can_export', true ) ),
			'delete_with_user'    => $this->flag( pcf_maybe_get( $d, 'delete_with_user', false ) ),
		);
		if ( ! empty( $d['rest_base'] ) ) {
			$args['rest_base'] = $d['rest_base'];
		}
		if ( '' !== (string) pcf_maybe_get( $d, 'menu_position', '' ) ) {
			$args['menu_position'] = (int) $d['menu_position'];
		}
		$icon = pcf_maybe_get( $d, 'menu_icon' );
		if ( is_array( $icon ) ) {
			$icon = pcf_maybe_get( $icon, 'value', '' );
		}
		if ( $icon ) {
			$args['menu_icon'] = $icon;
		}
		if ( $this->flag( pcf_maybe_get( $d, 'has_archive', false ) ) ) {
			$args['has_archive'] = pcf_maybe_get( $d, 'has_archive_slug' ) ? $d['has_archive_slug'] : true;
		}
		$rewrite = pcf_maybe_get( $d, 'rewrite' );
		if ( is_array( $rewrite ) ) {
			$mode = pcf_maybe_get( $rewrite, 'permalink_rewrite', 'post_type_key' );
			if ( 'no_permalink' === $mode ) {
				$args['rewrite'] = false;
			} else {
				$args['rewrite'] = array(
					'slug'       => 'custom_permalink' === $mode && ! empty( $rewrite['slug'] ) ? $rewrite['slug'] : $d['post_type'],
					'with_front' => $this->flag( pcf_maybe_get( $rewrite, 'with_front', true ) ),
					'feeds'      => $this->flag( pcf_maybe_get( $rewrite, 'feeds', false ) ),
					'pages'      => $this->flag( pcf_maybe_get( $rewrite, 'pages', true ) ),
				);
			}
		}
		return array(
			'_kind'      => 'post_type',
			'key'        => $d['key'],
			'title'      => pcf_maybe_get( $d, 'title', pcf_maybe_get( $labels, 'name', $d['post_type'] ) ),
			'active'     => pcf_maybe_get( $d, 'active', true ),
			'post_type'  => $d['post_type'],
			'singular'   => pcf_maybe_get( $labels, 'singular_name', $d['post_type'] ),
			'plural'     => pcf_maybe_get( $labels, 'name', $d['post_type'] ),
			'taxonomies' => array_values( array_filter( (array) pcf_maybe_get( $d, 'taxonomies', array() ) ) ),
			'args'       => $args,
		);
	}

	public function convert_taxonomy( $d ) {
		$labels = (array) pcf_maybe_get( $d, 'labels', array() );
		$args   = array(
			'labels'             => array_filter( $labels ),
			'description'        => pcf_maybe_get( $d, 'description', '' ),
			'public'             => $this->flag( pcf_maybe_get( $d, 'public', true ) ),
			'hierarchical'       => $this->flag( pcf_maybe_get( $d, 'hierarchical', false ) ),
			'publicly_queryable' => $this->flag( pcf_maybe_get( $d, 'publicly_queryable', true ) ),
			'show_ui'            => $this->flag( pcf_maybe_get( $d, 'show_ui', true ) ),
			'show_in_menu'       => $this->flag( pcf_maybe_get( $d, 'show_in_menu', true ) ),
			'show_in_nav_menus'  => $this->flag( pcf_maybe_get( $d, 'show_in_nav_menus', true ) ),
			'show_in_rest'       => $this->flag( pcf_maybe_get( $d, 'show_in_rest', true ) ),
			'show_tagcloud'      => $this->flag( pcf_maybe_get( $d, 'show_tagcloud', true ) ),
			'show_in_quick_edit' => $this->flag( pcf_maybe_get( $d, 'show_in_quick_edit', true ) ),
			'show_admin_column'  => $this->flag( pcf_maybe_get( $d, 'show_admin_column', false ) ),
		);
		$rewrite = pcf_maybe_get( $d, 'rewrite' );
		if ( is_array( $rewrite ) ) {
			$mode = pcf_maybe_get( $rewrite, 'permalink_rewrite', 'taxonomy_key' );
			if ( 'no_permalink' === $mode ) {
				$args['rewrite'] = false;
			} else {
				$args['rewrite'] = array(
					'slug'         => 'custom_permalink' === $mode && ! empty( $rewrite['slug'] ) ? $rewrite['slug'] : $d['taxonomy'],
					'with_front'   => $this->flag( pcf_maybe_get( $rewrite, 'with_front', true ) ),
					'hierarchical' => $this->flag( pcf_maybe_get( $rewrite, 'rewrite_hierarchical', false ) ),
				);
			}
		}
		return array(
			'_kind'       => 'taxonomy',
			'key'         => $d['key'],
			'title'       => pcf_maybe_get( $d, 'title', pcf_maybe_get( $labels, 'name', $d['taxonomy'] ) ),
			'active'      => pcf_maybe_get( $d, 'active', true ),
			'taxonomy'    => $d['taxonomy'],
			'object_type' => array_values( array_filter( (array) pcf_maybe_get( $d, 'object_type', array() ) ) ),
			'singular'    => pcf_maybe_get( $labels, 'singular_name', $d['taxonomy'] ),
			'plural'      => pcf_maybe_get( $labels, 'name', $d['taxonomy'] ),
			'args'        => $args,
		);
	}

	public function convert_options_page( $d ) {
		$icon = pcf_maybe_get( $d, 'icon_url', '' );
		if ( is_array( $icon ) ) {
			$icon = pcf_maybe_get( $icon, 'value', '' );
		}
		$page = array(
			'_kind'           => 'options_page',
			'key'             => $d['key'],
			'title'           => pcf_maybe_get( $d, 'title', pcf_maybe_get( $d, 'page_title', '' ) ),
			'active'          => pcf_maybe_get( $d, 'active', true ),
			'page_title'      => pcf_maybe_get( $d, 'page_title', '' ),
			'menu_title'      => pcf_maybe_get( $d, 'menu_title', '' ),
			'menu_slug'       => pcf_maybe_get( $d, 'menu_slug', '' ),
			'parent_slug'     => 'none' === pcf_maybe_get( $d, 'parent_slug', '' ) ? '' : pcf_maybe_get( $d, 'parent_slug', '' ),
			'capability'      => pcf_maybe_get( $d, 'capability', 'edit_posts' ),
			'position'        => '' === pcf_maybe_get( $d, 'position', '' ) ? null : pcf_maybe_get( $d, 'position' ),
			'icon_url'        => $icon,
			'redirect'        => $this->flag( pcf_maybe_get( $d, 'redirect', true ) ),
			'post_id'         => pcf_maybe_get( $d, 'post_id', 'options' ),
			'autoload'        => $this->flag( pcf_maybe_get( $d, 'autoload', false ) ),
			'update_button'   => pcf_maybe_get( $d, 'update_button', __( 'Actualizar', 'pcf' ) ),
			'updated_message' => pcf_maybe_get( $d, 'updated_message', __( 'Opciones actualizadas', 'pcf' ) ),
			'description'     => pcf_maybe_get( $d, 'description', '' ),
		);
		return $page;
	}
}
