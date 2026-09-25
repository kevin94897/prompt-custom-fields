<?php
/**
 * Pantallas de administración.
 *
 * La UI es deliberadamente sencilla: el flujo principal es por MCP. Aquí se puede
 * revisar lo creado, editarlo como JSON, sincronizar, importar/exportar y copiar
 * la configuración de conexión.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_Admin {

	const SLUG = 'pcf';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PCF_FILE ), array( __CLASS__, 'plugin_links' ) );
	}

	public static function cap() {
		return pcf_get_setting( 'capability' );
	}

	public static function url( $page = self::SLUG, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	public static function plugin_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Grupos', 'pcf' ) . '</a>',
			'<a href="' . esc_url( self::url( 'pcf-mcp' ) ) . '">' . esc_html__( 'Conexión MCP', 'pcf' ) . '</a>'
		);
		return $links;
	}

	public static function menu() {
		$cap = self::cap();
		add_menu_page( 'Prompt Custom Fields', 'Campos PCF', $cap, self::SLUG, array( __CLASS__, 'page_groups' ), 'dashicons-feedback', 81 );
		add_submenu_page( self::SLUG, __( 'Grupos de campos', 'pcf' ), __( 'Grupos de campos', 'pcf' ), $cap, self::SLUG, array( __CLASS__, 'page_groups' ) );
		add_submenu_page( self::SLUG, __( 'Post types', 'pcf' ), __( 'Post types', 'pcf' ), $cap, 'pcf-post_type', array( __CLASS__, 'page_internal' ) );
		add_submenu_page( self::SLUG, __( 'Taxonomías', 'pcf' ), __( 'Taxonomías', 'pcf' ), $cap, 'pcf-taxonomy', array( __CLASS__, 'page_internal' ) );
		add_submenu_page( self::SLUG, __( 'Páginas de opciones', 'pcf' ), __( 'Páginas de opciones', 'pcf' ), $cap, 'pcf-options_page', array( __CLASS__, 'page_internal' ) );
		add_submenu_page( self::SLUG, __( 'Herramientas', 'pcf' ), __( 'Herramientas', 'pcf' ), $cap, 'pcf-tools', array( __CLASS__, 'page_tools' ) );
		add_submenu_page( self::SLUG, __( 'Conexión MCP', 'pcf' ), __( 'Conexión MCP', 'pcf' ), $cap, 'pcf-mcp', array( __CLASS__, 'page_mcp' ) );
		// Editor (oculto en el menú).
		add_submenu_page( self::SLUG, __( 'Editar', 'pcf' ), __( 'Editar', 'pcf' ), $cap, 'pcf-edit', array( __CLASS__, 'page_edit' ) );
		// Se oculta del menú en admin_head (después de la comprobación de acceso).
		add_action( 'admin_head', array( __CLASS__, 'hide_edit_item' ) );
	}

	public static function hide_edit_item() {
		remove_submenu_page( self::SLUG, 'pcf-edit' );
	}

	/**
	 * Resalta el submenú correcto cuando se está en el editor.
	 */
	public static function submenu_file( $submenu_file ) {
		if ( isset( $_GET['page'] ) && 'pcf-edit' === $_GET['page'] ) { // phpcs:ignore
			$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : 'field_group'; // phpcs:ignore
			return 'field_group' === $kind ? self::SLUG : 'pcf-' . $kind;
		}
		return $submenu_file;
	}

	public static function enqueue( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore
		if ( 0 !== strpos( $page, 'pcf' ) || in_array( $page, array_keys( pcf_get_options_pages() ), true ) ) {
			return;
		}
		wp_enqueue_style( 'pcf-admin', PCF_URL . 'assets/css/pcf-admin.css', array(), PCF_VERSION );
		wp_enqueue_script( 'pcf-admin', PCF_URL . 'assets/js/pcf-admin.js', array( 'jquery' ), PCF_VERSION, true );
		if ( 'pcf-edit' === $page ) {
			$settings = wp_enqueue_code_editor( array( 'type' => 'application/json', 'codemirror' => array( 'lineWrapping' => false ) ) );
			wp_localize_script( 'pcf-admin', 'pcfAdmin', array( 'codeEditor' => $settings ? $settings : false ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Acciones
	 * --------------------------------------------------------------- */

	protected static function redirect( $url, $notice, $type = 'success' ) {
		set_transient( 'pcf_admin_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $notice ), 60 );
		wp_safe_redirect( $url );
		exit;
	}

	protected static function notice() {
		$n = get_transient( 'pcf_admin_notice_' . get_current_user_id() );
		if ( ! $n ) {
			return;
		}
		delete_transient( 'pcf_admin_notice_' . get_current_user_id() );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n['type'] ), wp_kses_post( $n['message'] ) );
	}

	public static function handle_actions() {
		if ( empty( $_REQUEST['pcf_action'] ) || ! current_user_can( self::cap() ) ) { // phpcs:ignore
			return;
		}
		$action = sanitize_key( wp_unslash( $_REQUEST['pcf_action'] ) ); // phpcs:ignore
		check_admin_referer( 'pcf_' . $action );
		$r = wp_unslash( $_REQUEST ); // phpcs:ignore

		switch ( $action ) {
			case 'save_group':
				$data = pcf_json_decode( $r['pcf_json'] );
				if ( ! is_array( $data ) ) {
					set_transient( 'pcf_draft_' . get_current_user_id(), $r['pcf_json'], 300 );
					self::redirect( self::url( 'pcf-edit', array( 'kind' => 'field_group', 'key' => $r['key'], 'draft' => 1 ) ), __( 'El JSON no es válido. Revisa comas y comillas.', 'pcf' ), 'error' );
				}
				$res = pcf_op_create_or_update_from_admin( $data, $r['key'] );
				if ( is_wp_error( $res ) ) {
					set_transient( 'pcf_draft_' . get_current_user_id(), $r['pcf_json'], 300 );
					self::redirect( self::url( 'pcf-edit', array( 'kind' => 'field_group', 'key' => $r['key'], 'draft' => 1 ) ), esc_html( implode( ' ', $res->get_error_messages() ) ), 'error' );
				}
				self::redirect( self::url( 'pcf-edit', array( 'kind' => 'field_group', 'key' => $res['key'] ) ), __( 'Grupo guardado.', 'pcf' ) );
				break;

			case 'save_item':
				$kind = sanitize_key( $r['kind'] );
				$data = pcf_json_decode( $r['pcf_json'] );
				if ( ! is_array( $data ) || ! isset( pcf_internal_kinds()[ $kind ] ) ) {
					self::redirect( self::url( 'pcf-edit', array( 'kind' => $kind, 'key' => $r['key'] ) ), __( 'El JSON no es válido.', 'pcf' ), 'error' );
				}
				if ( ! empty( $r['key'] ) ) {
					$data['key'] = $r['key'];
				}
				$res = pcf_update_internal_item( $kind, $data );
				if ( is_wp_error( $res ) ) {
					self::redirect( self::url( 'pcf-edit', array( 'kind' => $kind, 'key' => $r['key'] ) ), esc_html( $res->get_error_message() ), 'error' );
				}
				self::redirect( self::url( 'pcf-edit', array( 'kind' => $kind, 'key' => $res['key'] ) ), __( 'Guardado. Los cambios se aplican en la próxima carga.', 'pcf' ) );
				break;

			case 'delete':
				$kind = sanitize_key( $r['kind'] );
				$res  = 'field_group' === $kind ? pcf_delete_field_group( $r['key'] ) : pcf_delete_internal_item( $kind, $r['key'] );
				$back = 'field_group' === $kind ? self::url() : self::url( 'pcf-' . $kind );
				self::redirect( $back, is_wp_error( $res ) ? $res->get_error_message() : __( 'Eliminado. El contenido guardado se conserva.', 'pcf' ), is_wp_error( $res ) ? 'error' : 'success' );
				break;

			case 'duplicate':
				$res = pcf_duplicate_field_group( $r['key'] );
				self::redirect( self::url(), is_wp_error( $res ) ? $res->get_error_message() : sprintf( __( 'Duplicado como %s.', 'pcf' ), '<code>' . esc_html( $res['key'] ) . '</code>' ), is_wp_error( $res ) ? 'error' : 'success' );
				break;

			case 'toggle':
				$kind = sanitize_key( $r['kind'] );
				if ( 'field_group' === $kind ) {
					$g = pcf_get_field_group( $r['key'] );
					if ( $g ) {
						$g['active'] = ! $g['active'];
						pcf_update_field_group( $g );
					}
					self::redirect( self::url(), __( 'Estado actualizado.', 'pcf' ) );
				}
				$item = pcf_get_internal_item( $kind, $r['key'] );
				if ( $item ) {
					pcf_update_internal_item( $kind, array( 'key' => $item['key'], 'active' => empty( $item['active'] ) ) );
				}
				self::redirect( self::url( 'pcf-' . $kind ), __( 'Estado actualizado.', 'pcf' ) );
				break;

			case 'sync':
				$keys = isset( $r['keys'] ) ? array_map( 'sanitize_text_field', (array) $r['keys'] ) : array();
				$res  = PCF_Local_JSON::sync( $keys );
				$msg  = sprintf( _n( '%d elemento sincronizado.', '%d elementos sincronizados.', count( $res['synced'] ), 'pcf' ), count( $res['synced'] ) );
				if ( $res['errors'] ) {
					$msg .= ' ' . esc_html( implode( ' · ', $res['errors'] ) );
				}
				self::redirect( wp_get_referer() ? wp_get_referer() : self::url(), $msg, $res['errors'] ? 'warning' : 'success' );
				break;

			case 'import_acf':
				$importer = new PCF_ACF_Importer();
				$res      = $importer->run(
					array(
						'source'         => sanitize_key( $r['source'] ),
						'overwrite'      => ! empty( $r['overwrite'] ),
						'dry_run'        => ! empty( $r['dry_run'] ),
						'deactivate_acf' => ! empty( $r['deactivate_acf'] ),
						'include'        => isset( $r['include'] ) ? array_map( 'sanitize_key', (array) $r['include'] ) : array(),
					)
				);
				if ( is_wp_error( $res ) ) {
					self::redirect( self::url( 'pcf-tools' ), esc_html( $res->get_error_message() ), 'error' );
				}
				self::redirect( self::url( 'pcf-tools' ), self::import_report_html( $res ), $res['errors'] ? 'warning' : 'success' );
				break;

			case 'import_json':
				$json = isset( $r['json'] ) ? $r['json'] : '';
				if ( ! empty( $_FILES['json_file']['tmp_name'] ) ) { // phpcs:ignore
					$json = file_get_contents( $_FILES['json_file']['tmp_name'] ); // phpcs:ignore
				}
				$importer = new PCF_ACF_Importer();
				$res      = $importer->run( array( 'source' => 'json', 'json' => $json, 'overwrite' => ! empty( $r['overwrite'] ) ) );
				if ( is_wp_error( $res ) ) {
					self::redirect( self::url( 'pcf-tools' ), esc_html( $res->get_error_message() ), 'error' );
				}
				self::redirect( self::url( 'pcf-tools' ), self::import_report_html( $res ) );
				break;

			case 'export':
				$keys   = isset( $r['keys'] ) ? array_map( 'sanitize_text_field', (array) $r['keys'] ) : array();
				$format = 'php' === $r['format'] ? 'php' : 'json';
				$body   = 'php' === $format ? PCF_Exporter::to_php( $keys, ! empty( $r['acf_names'] ) ) : PCF_Exporter::to_json( $keys );
				nocache_headers();
				header( 'Content-Type: ' . ( 'php' === $format ? 'text/plain' : 'application/json' ) . '; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename=pcf-export-' . gmdate( 'Y-m-d' ) . '.' . $format );
				echo $body; // phpcs:ignore
				exit;
		}
	}

	protected static function import_report_html( $res ) {
		$verb = $res['dry_run'] ? __( 'Se importarían', 'pcf' ) : __( 'Importados', 'pcf' );
		$html = sprintf( '<strong>%s: %d</strong>', $verb, count( $res['imported'] ) );
		if ( $res['imported'] ) {
			$html .= '<br>' . esc_html( implode( ', ', wp_list_pluck( $res['imported'], 'title' ) ) );
		}
		if ( $res['skipped'] ) {
			$html .= '<br>' . sprintf( esc_html__( 'Omitidos (ya existen): %s', 'pcf' ), esc_html( implode( ', ', wp_list_pluck( $res['skipped'], 'key' ) ) ) );
		}
		foreach ( array_merge( $res['warnings'], wp_list_pluck( $res['errors'], 'error' ) ) as $w ) {
			$html .= '<br>⚠ ' . esc_html( $w );
		}
		return $html;
	}

	protected static function action_url( $action, $args = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'pcf_action' => $action ), $args ), admin_url( 'admin.php?page=pcf' ) ), 'pcf_' . $action );
	}

	protected static function header( $title, $new_url = '' ) {
		echo '<div class="wrap pcf-admin">';
		echo '<h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';
		if ( $new_url ) {
			echo ' <a href="' . esc_url( $new_url ) . '" class="page-title-action">' . esc_html__( 'Añadir nuevo', 'pcf' ) . '</a>';
		}
		echo '<hr class="wp-header-end">';
		self::notice();
	}

	protected static function location_label( $location ) {
		$parts = array();
		foreach ( (array) $location as $and ) {
			$rules = array();
			foreach ( $and as $rule ) {
				$rules[] = $rule['param'] . ' ' . $rule['operator'] . ' ' . $rule['value'];
			}
			$parts[] = implode( ' y ', $rules );
		}
		return implode( ' · o · ', $parts );
	}

	protected static function count_fields( $fields ) {
		$n = 0;
		pcf_map_fields(
			$fields,
			function ( $f ) use ( &$n ) {
				$n++;
				return $f;
			}
		);
		return $n;
	}

	/* ------------------------------------------------------------------
	 * Páginas
	 * --------------------------------------------------------------- */

	public static function page_groups() {
		self::header( __( 'Grupos de campos', 'pcf' ), self::url( 'pcf-edit', array( 'kind' => 'field_group' ) ) );
		$status  = array();
		$pending = 0;
		foreach ( PCF_Local_JSON::status() as $row ) {
			$status[ $row['key'] ] = $row['status'];
			if ( in_array( $row['status'], array( 'new', 'sync' ), true ) ) {
				$pending++;
			}
		}
		if ( $pending ) {
			printf(
				'<div class="notice notice-info"><p>%s <a class="button button-small" href="%s">%s</a></p></div>',
				esc_html( sprintf( _n( 'Hay %d cambio en Local JSON pendiente de sincronizar.', 'Hay %d cambios en Local JSON pendientes de sincronizar.', $pending, 'pcf' ), $pending ) ),
				esc_url( self::action_url( 'sync' ) ),
				esc_html__( 'Sincronizar todo', 'pcf' )
			);
		}
		$groups = pcf_get_field_groups();
		if ( ! $groups ) {
			echo '<div class="pcf-empty"><h2>' . esc_html__( 'Todavía no hay grupos de campos', 'pcf' ) . '</h2>';
			echo '<p>' . esc_html__( 'Pídele a tu agente de IA que cree uno (por ejemplo: "crea un grupo Hero para la portada con título, imagen y botón"), impórtalos desde ACF o créalos aquí.', 'pcf' ) . '</p>';
			printf( '<p><a class="button button-primary" href="%s">%s</a> <a class="button" href="%s">%s</a> <a class="button" href="%s">%s</a></p></div>', esc_url( self::url( 'pcf-mcp' ) ), esc_html__( 'Conectar un agente', 'pcf' ), esc_url( self::url( 'pcf-tools' ) ), esc_html__( 'Importar desde ACF', 'pcf' ), esc_url( self::url( 'pcf-edit', array( 'kind' => 'field_group' ) ) ), esc_html__( 'Crear grupo', 'pcf' ) );
			echo '</div>';
			return;
		}
		echo '<table class="widefat striped pcf-table"><thead><tr><th>' . esc_html__( 'Título', 'pcf' ) . '</th><th>Key</th><th>' . esc_html__( 'Campos', 'pcf' ) . '</th><th>' . esc_html__( 'Ubicación', 'pcf' ) . '</th><th>' . esc_html__( 'Origen', 'pcf' ) . '</th><th>JSON</th></tr></thead><tbody>';
		foreach ( $groups as $g ) {
			$edit    = self::url( 'pcf-edit', array( 'kind' => 'field_group', 'key' => $g['key'] ) );
			$source  = ! empty( $g['ID'] ) ? __( 'Base de datos', 'pcf' ) : ( 'php' === pcf_maybe_get( $g, 'local' ) ? 'PHP' : __( 'Sólo JSON', 'pcf' ) );
			$js      = pcf_maybe_get( $status, $g['key'], ! empty( $g['ID'] ) ? 'db_only' : '' );
			$labels  = array(
				'synced'  => __( 'Sincronizado', 'pcf' ),
				'sync'    => __( 'JSON más reciente', 'pcf' ),
				'new'     => __( 'Nuevo en JSON', 'pcf' ),
				'db_only' => __( 'Sin archivo', 'pcf' ),
			);
			$actions = array();
			if ( ! empty( $g['ID'] ) ) {
				$actions[] = '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Editar', 'pcf' ) . '</a>';
				$actions[] = '<a href="' . esc_url( self::action_url( 'toggle', array( 'kind' => 'field_group', 'key' => $g['key'] ) ) ) . '">' . ( $g['active'] ? esc_html__( 'Desactivar', 'pcf' ) : esc_html__( 'Activar', 'pcf' ) ) . '</a>';
				$actions[] = '<a href="' . esc_url( self::action_url( 'duplicate', array( 'key' => $g['key'] ) ) ) . '">' . esc_html__( 'Duplicar', 'pcf' ) . '</a>';
				$actions[] = '<a class="pcf-confirm submitdelete" data-confirm="' . esc_attr__( '¿Eliminar este grupo? Los valores guardados en el contenido no se borran.', 'pcf' ) . '" href="' . esc_url( self::action_url( 'delete', array( 'kind' => 'field_group', 'key' => $g['key'] ) ) ) . '">' . esc_html__( 'Eliminar', 'pcf' ) . '</a>';
			} else {
				$actions[] = '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Ver', 'pcf' ) . '</a>';
			}
			if ( in_array( $js, array( 'new', 'sync' ), true ) ) {
				$actions[] = '<a href="' . esc_url( self::action_url( 'sync', array( 'keys' => array( $g['key'] ) ) ) ) . '">' . esc_html__( 'Sincronizar', 'pcf' ) . '</a>';
			}
			printf(
				'<tr class="%s"><td><strong><a class="row-title" href="%s">%s</a></strong>%s<div class="row-actions">%s</div></td><td><code>%s</code></td><td>%d</td><td class="pcf-loc">%s</td><td>%s</td><td><span class="pcf-status pcf-status-%s">%s</span></td></tr>',
				$g['active'] ? '' : 'pcf-inactive',
				esc_url( $edit ),
				esc_html( $g['title'] ),
				$g['active'] ? '' : ' — <span class="post-state">' . esc_html__( 'Inactivo', 'pcf' ) . '</span>',
				implode( ' | ', $actions ), // phpcs:ignore
				esc_html( $g['key'] ),
				self::count_fields( $g['fields'] ),
				esc_html( self::location_label( $g['location'] ) ),
				esc_html( $source ),
				esc_attr( $js ),
				esc_html( pcf_maybe_get( $labels, $js, '—' ) )
			);
		}
		echo '</tbody></table></div>';
	}

	public static function page_internal() {
		$page  = sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore
		$kind  = substr( $page, 4 );
		$kinds = pcf_internal_kinds();
		if ( ! isset( $kinds[ $kind ] ) ) {
			return;
		}
		$conf = $kinds[ $kind ];
		self::header( $conf['label'], self::url( 'pcf-edit', array( 'kind' => $kind ) ) );
		$items = pcf_get_internal_items( $kind );
		if ( ! $items ) {
			echo '<div class="pcf-empty"><p>' . esc_html__( 'No hay elementos. Créalos desde tu agente (save-post-type, save-taxonomy, save-options-page), impórtalos desde ACF o añade uno aquí.', 'pcf' ) . '</p></div></div>';
			return;
		}
		echo '<table class="widefat striped pcf-table"><thead><tr><th>' . esc_html__( 'Título', 'pcf' ) . '</th><th>' . esc_html( $conf['id_field'] ) . '</th><th>Key</th><th>' . esc_html__( 'Estado', 'pcf' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$edit    = self::url( 'pcf-edit', array( 'kind' => $kind, 'key' => $item['key'] ) );
			$actions = array(
				'<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Editar', 'pcf' ) . '</a>',
				'<a href="' . esc_url( self::action_url( 'toggle', array( 'kind' => $kind, 'key' => $item['key'] ) ) ) . '">' . ( $item['active'] ? esc_html__( 'Desactivar', 'pcf' ) : esc_html__( 'Activar', 'pcf' ) ) . '</a>',
				'<a class="pcf-confirm submitdelete" data-confirm="' . esc_attr__( '¿Eliminar? El contenido existente se conserva.', 'pcf' ) . '" href="' . esc_url( self::action_url( 'delete', array( 'kind' => $kind, 'key' => $item['key'] ) ) ) . '">' . esc_html__( 'Eliminar', 'pcf' ) . '</a>',
			);
			printf(
				'<tr><td><strong><a class="row-title" href="%s">%s</a></strong><div class="row-actions">%s</div></td><td><code>%s</code></td><td><code>%s</code></td><td>%s</td></tr>',
				esc_url( $edit ),
				esc_html( $item['title'] ),
				implode( ' | ', $actions ), // phpcs:ignore
				esc_html( pcf_maybe_get( $item, $conf['id_field'], '' ) ),
				esc_html( $item['key'] ),
				$item['active'] ? esc_html__( 'Activo', 'pcf' ) : esc_html__( 'Inactivo', 'pcf' )
			);
		}
		echo '</tbody></table></div>';
	}

	protected static function templates( $kind ) {
		switch ( $kind ) {
			case 'post_type':
				return array( 'post_type' => 'libro', 'singular' => 'Libro', 'plural' => 'Libros', 'taxonomies' => array(), 'args' => array( 'public' => true, 'has_archive' => true, 'menu_icon' => 'dashicons-book', 'supports' => array( 'title', 'editor', 'thumbnail' ) ) );
			case 'taxonomy':
				return array( 'taxonomy' => 'genero', 'singular' => 'Género', 'plural' => 'Géneros', 'object_type' => array( 'post' ), 'args' => array( 'hierarchical' => true ) );
			case 'options_page':
				return array( 'page_title' => 'Ajustes del tema', 'menu_slug' => 'theme-settings', 'icon_url' => 'dashicons-admin-generic', 'post_id' => 'options' );
			default:
				return array(
					'title'    => 'Nuevo grupo',
					'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
					'fields'   => array(
						array( 'label' => 'Título', 'name' => 'titulo', 'type' => 'text' ),
					),
				);
		}
	}

	public static function page_edit() {
		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : 'field_group'; // phpcs:ignore
		$key  = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore
		$is_g = 'field_group' === $kind;

		$item = null;
		if ( $key ) {
			$item = $is_g ? pcf_get_field_group( $key ) : pcf_get_internal_item( $kind, $key );
			if ( ! $item ) {
				wp_die( esc_html__( 'Elemento no encontrado.', 'pcf' ) );
			}
		}
		$data  = $item ? PCF_Exporter::clean( $item ) : self::templates( $kind );
		$json  = pcf_json_encode( $data );
		$draft = ! empty( $_GET['draft'] ) ? get_transient( 'pcf_draft_' . get_current_user_id() ) : false; // phpcs:ignore
		if ( $draft ) {
			$json = $draft;
		}
		$readonly = $is_g && $item && empty( $item['ID'] ) && 'php' === pcf_maybe_get( $item, 'local' );
		$back     = $is_g ? self::url() : self::url( 'pcf-' . $kind );
		$title    = $item ? sprintf( __( 'Editar: %s', 'pcf' ), $item['title'] ) : __( 'Nuevo', 'pcf' );

		self::header( $title );
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Volver al listado', 'pcf' ) . '</a></p>';
		if ( $readonly ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Este grupo está registrado por PHP en el tema. Guardarlo creará una copia en la base de datos que tendrá prioridad.', 'pcf' ) . '</p></div>';
		}

		echo '<div class="pcf-editor-layout"><div class="pcf-editor-main">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=pcf' ) ) . '">';
		$action = $is_g ? 'save_group' : 'save_item';
		wp_nonce_field( 'pcf_' . $action );
		printf( '<input type="hidden" name="pcf_action" value="%s"><input type="hidden" name="kind" value="%s"><input type="hidden" name="key" value="%s">', esc_attr( $action ), esc_attr( $kind ), esc_attr( $key ) );
		printf( '<label class="screen-reader-text" for="pcf-json">JSON</label><textarea id="pcf-json" name="pcf_json" class="pcf-json" rows="30" spellcheck="false">%s</textarea>', esc_textarea( $json ) );
		echo '<p class="pcf-editor-actions">';
		submit_button( $item ? __( 'Guardar cambios', 'pcf' ) : __( 'Crear', 'pcf' ), 'primary', 'submit', false );
		if ( $item && ( ! $is_g || ! empty( $item['ID'] ) ) ) {
			printf( ' <a class="button-link-delete pcf-confirm" data-confirm="%s" href="%s">%s</a>', esc_attr__( '¿Eliminar definitivamente?', 'pcf' ), esc_url( self::action_url( 'delete', array( 'kind' => $kind, 'key' => $key ) ) ), esc_html__( 'Eliminar', 'pcf' ) );
		}
		echo '</p></form></div><aside class="pcf-editor-side">';

		if ( $is_g && $item ) {
			echo '<div class="pcf-card"><h2>' . esc_html__( 'Estructura', 'pcf' ) . '</h2>';
			self::render_tree( $item['fields'] );
			echo '</div>';
			echo '<div class="pcf-card"><h2>' . esc_html__( 'Código de plantilla', 'pcf' ) . '</h2>';
			$code = pcf_op_generate_template_code( array( 'group' => $item['key'] ) );
			if ( ! is_wp_error( $code ) ) {
				echo '<pre class="pcf-code">' . esc_html( $code['code'] ) . '</pre><button type="button" class="button pcf-copy" data-copy-prev>' . esc_html__( 'Copiar', 'pcf' ) . '</button>';
			}
			echo '</div>';
		}
		echo '<div class="pcf-card"><h2>' . esc_html__( 'Referencia rápida', 'pcf' ) . '</h2>';
		if ( $is_g ) {
			echo '<p>' . esc_html__( 'Mismo formato que un export de ACF. key y name son opcionales.', 'pcf' ) . '</p><p class="pcf-types">';
			foreach ( pcf_get_field_types() as $name => $t ) {
				printf( '<code title="%s">%s</code> ', esc_attr( $t->description ), esc_html( $name ) );
			}
			echo '</p><p>' . esc_html__( 'Ubicación: [[{"param":"post_type","operator":"==","value":"page"}]] o el atajo "post_type:page".', 'pcf' ) . '</p>';
		} elseif ( 'post_type' === $kind ) {
			echo '<p>' . esc_html__( '"args" acepta cualquier argumento de register_post_type(). Las etiquetas se generan a partir de singular y plural.', 'pcf' ) . '</p>';
		} elseif ( 'taxonomy' === $kind ) {
			echo '<p>' . esc_html__( '"args" acepta cualquier argumento de register_taxonomy(). "object_type" es la lista de post types.', 'pcf' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Crea un grupo con la ubicación "options_page:<menu_slug>" y lee los valores con get_field("campo", "option").', 'pcf' ) . '</p>';
		}
		echo '</div></aside></div></div>';
	}

	protected static function render_tree( $fields ) {
		if ( ! $fields ) {
			echo '<p>' . esc_html__( 'Sin campos.', 'pcf' ) . '</p>';
			return;
		}
		echo '<ul class="pcf-tree">';
		foreach ( $fields as $f ) {
			printf( '<li><span class="pcf-tree-type">%s</span> <strong>%s</strong> <code>%s</code>', esc_html( $f['type'] ), esc_html( $f['label'] ), esc_html( $f['name'] ) );
			if ( ! empty( $f['sub_fields'] ) ) {
				self::render_tree( $f['sub_fields'] );
			}
			if ( ! empty( $f['layouts'] ) ) {
				echo '<ul class="pcf-tree">';
				foreach ( $f['layouts'] as $l ) {
					printf( '<li><span class="pcf-tree-type">layout</span> <strong>%s</strong> <code>%s</code>', esc_html( $l['label'] ), esc_html( $l['name'] ) );
					self::render_tree( $l['sub_fields'] );
					echo '</li>';
				}
				echo '</ul>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	public static function page_tools() {
		self::header( __( 'Herramientas', 'pcf' ) );
		$detect = PCF_ACF_Importer::detect();
		$db     = $detect['database'];

		echo '<div class="pcf-grid">';

		// Importar desde ACF.
		echo '<div class="pcf-card"><h2>' . esc_html__( 'Importar desde ACF', 'pcf' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: grupos 2: campos 3: post types 4: taxonomías 5: options pages */
					__( 'En la base de datos hay %1$d grupos (%2$d campos), %3$d post types, %4$d taxonomías y %5$d páginas de opciones de ACF.', 'pcf' ),
					$db['acf-field-group'],
					$db['acf-field'],
					$db['acf-post-type'],
					$db['acf-taxonomy'],
					$db['acf-ui-options-page']
				)
			)
		);
		if ( $detect['acf_json_dir'] ) {
			echo '<p>' . esc_html( sprintf( __( 'Carpeta acf-json del tema: %d archivos.', 'pcf' ), $detect['acf_json_files'] ) ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'Las keys se conservan, así que el contenido ya guardado por ACF sigue funcionando sin migrar datos.', 'pcf' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=pcf' ) ) . '">';
		wp_nonce_field( 'pcf_import_acf' );
		echo '<input type="hidden" name="pcf_action" value="import_acf">';
		echo '<p><label for="pcf-source">' . esc_html__( 'Origen', 'pcf' ) . '</label><br><select id="pcf-source" name="source">';
		printf( '<option value="database">%s</option>', esc_html__( 'Base de datos (funciona con ACF desactivado)', 'pcf' ) );
		if ( $detect['acf_active'] ) {
			printf( '<option value="acf_api" selected>%s</option>', esc_html__( 'ACF activo (incluye grupos PHP y JSON)', 'pcf' ) );
		}
		if ( $detect['acf_json_dir'] ) {
			printf( '<option value="acf_json">%s</option>', esc_html__( 'Carpeta acf-json del tema', 'pcf' ) );
		}
		echo '</select></p><fieldset><legend>' . esc_html__( 'Qué importar', 'pcf' ) . '</legend>';
		foreach ( array( 'field_groups' => __( 'Grupos de campos', 'pcf' ), 'post_types' => __( 'Post types', 'pcf' ), 'taxonomies' => __( 'Taxonomías', 'pcf' ), 'options_pages' => __( 'Páginas de opciones', 'pcf' ) ) as $v => $l ) {
			printf( '<label><input type="checkbox" name="include[]" value="%s" checked> %s</label><br>', esc_attr( $v ), esc_html( $l ) );
		}
		echo '</fieldset><p>';
		echo '<label><input type="checkbox" name="dry_run" value="1" checked> ' . esc_html__( 'Simular primero (no guarda nada)', 'pcf' ) . '</label><br>';
		echo '<label><input type="checkbox" name="overwrite" value="1"> ' . esc_html__( 'Sobrescribir los que ya existen en PCF', 'pcf' ) . '</label><br>';
		echo '<label><input type="checkbox" name="deactivate_acf" value="1"> ' . esc_html__( 'Desactivar los grupos originales en ACF', 'pcf' ) . '</label></p>';
		submit_button( __( 'Importar', 'pcf' ), 'primary', 'submit', false );
		echo '</form></div>';

		// Sincronización JSON.
		$status = PCF_Local_JSON::status();
		echo '<div class="pcf-card"><h2>Local JSON</h2>';
		echo '<p>' . esc_html__( 'Carpeta:', 'pcf' ) . ' <code>' . esc_html( PCF_Local_JSON::save_path() ) . '</code></p>';
		$pending = array_filter(
			$status,
			function ( $r ) {
				return in_array( $r['status'], array( 'new', 'sync' ), true );
			}
		);
		if ( $pending ) {
			echo '<ul class="ul-disc">';
			foreach ( $pending as $p ) {
				printf( '<li>%s <code>%s</code> — %s</li>', esc_html( $p['title'] ), esc_html( $p['key'] ), 'new' === $p['status'] ? esc_html__( 'nuevo', 'pcf' ) : esc_html__( 'JSON más reciente', 'pcf' ) );
			}
			echo '</ul>';
			printf( '<p><a class="button button-primary" href="%s">%s</a></p>', esc_url( self::action_url( 'sync' ) ), esc_html__( 'Sincronizar todo', 'pcf' ) );
		} else {
			echo '<p>' . esc_html__( 'Todo está sincronizado.', 'pcf' ) . '</p>';
		}
		echo '</div>';

		// Exportar.
		echo '<div class="pcf-card"><h2>' . esc_html__( 'Exportar', 'pcf' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=pcf' ) ) . '">';
		wp_nonce_field( 'pcf_export' );
		echo '<input type="hidden" name="pcf_action" value="export"><div class="pcf-checklist">';
		foreach ( PCF_Exporter::collect() as $i ) {
			printf( '<label><input type="checkbox" name="keys[]" value="%s"> %s <code>%s</code></label><br>', esc_attr( $i['key'] ), esc_html( pcf_maybe_get( $i, 'title', $i['key'] ) ), esc_html( $i['key'] ) );
		}
		echo '</div><p class="description">' . esc_html__( 'Sin selección se exporta todo.', 'pcf' ) . '</p>';
		echo '<p><label><input type="radio" name="format" value="json" checked> JSON</label> <label><input type="radio" name="format" value="php"> PHP</label> <label><input type="checkbox" name="acf_names" value="1"> ' . esc_html__( 'PHP con nombres de ACF', 'pcf' ) . '</label></p>';
		submit_button( __( 'Descargar', 'pcf' ), 'secondary', 'submit', false );
		echo '</form></div>';

		// Importar JSON.
		echo '<div class="pcf-card"><h2>' . esc_html__( 'Importar JSON', 'pcf' ) . '</h2>';
		echo '<p>' . esc_html__( 'Acepta exports de PCF y de ACF.', 'pcf' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin.php?page=pcf' ) ) . '">';
		wp_nonce_field( 'pcf_import_json' );
		echo '<input type="hidden" name="pcf_action" value="import_json">';
		echo '<p><label for="pcf-json-file">' . esc_html__( 'Archivo', 'pcf' ) . '</label><br><input id="pcf-json-file" type="file" name="json_file" accept=".json,application/json"></p>';
		echo '<p><label for="pcf-json-paste">' . esc_html__( 'O pega el JSON', 'pcf' ) . '</label><br><textarea id="pcf-json-paste" name="json" rows="5" class="large-text code"></textarea></p>';
		echo '<p><label><input type="checkbox" name="overwrite" value="1"> ' . esc_html__( 'Sobrescribir existentes', 'pcf' ) . '</label></p>';
		submit_button( __( 'Importar', 'pcf' ), 'secondary', 'submit', false );
		echo '</form></div>';

		echo '</div></div>';
	}

	public static function page_mcp() {
		self::header( __( 'Conexión MCP', 'pcf' ) );
		$ep      = PCF_Abilities::endpoints();
		$user    = wp_get_current_user();
		$configs = PCF_MCP_Config::all( $user->user_login );
		$node_ok = file_exists( PCF_PATH . 'mcp-server/dist/pcf-mcp-server.mjs' );
		$envfile = file_exists( PCF_PATH . 'mcp-server/.local-env.json' );
		$app_ok  = function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();

		$rows = array(
			array( __( 'Entorno', 'pcf' ), wp_get_environment_type(), 'local' === wp_get_environment_type() || 'development' === wp_get_environment_type() ),
			array( __( 'Abilities API', 'pcf' ), $ep['abilities_api'] ? __( 'disponible', 'pcf' ) : __( 'no disponible (WordPress 6.9+)', 'pcf' ), $ep['abilities_api'] ),
			array( 'MCP Adapter', $ep['mcp_adapter'] ? $ep['pcf_server'] : __( 'plugin no activo (modos C y D)', 'pcf' ), $ep['mcp_adapter'] ),
			array( __( 'Contraseñas de aplicación', 'pcf' ), $app_ok ? __( 'disponibles', 'pcf' ) : __( 'no disponibles: usa HTTPS o WP_ENVIRONMENT_TYPE local', 'pcf' ), $app_ok ),
			array( __( 'Servidor Node', 'pcf' ), $node_ok ? __( 'incluido (mcp-server/dist, requiere Node 18+ en tu equipo)', 'pcf' ) : __( 'falta mcp-server/dist: ejecuta npm install && npm run build', 'pcf' ), $node_ok ),
			array( __( 'Entorno de Local capturado', 'pcf' ), $envfile ? __( 'sí (.local-env.json)', 'pcf' ) : __( 'no (necesario para modos B y D)', 'pcf' ), $envfile ),
			array( __( 'Escritura de archivos del tema', 'pcf' ), pcf_allow_file_writes() ? __( 'permitida', 'pcf' ) : __( 'bloqueada (sólo local/development)', 'pcf' ), pcf_allow_file_writes() ),
		);
		echo '<table class="widefat pcf-status-table"><tbody>';
		foreach ( $rows as $r ) {
			printf( '<tr><th scope="row">%s</th><td><span class="pcf-dot %s" aria-hidden="true"></span> %s</td></tr>', esc_html( $r[0] ), $r[2] ? 'is-ok' : 'is-warn', esc_html( $r[1] ) );
		}
		echo '</tbody></table>';

		printf(
			'<p>%s <a href="%s">%s</a></p>',
			esc_html__( 'Los modos A y C necesitan una contraseña de aplicación:', 'pcf' ),
			esc_url( admin_url( 'profile.php#application-passwords-section' ) ),
			esc_html__( 'crear una en tu perfil', 'pcf' )
		);

		echo '<div class="pcf-modes">';
		foreach ( $configs as $id => $c ) {
			echo '<section class="pcf-card pcf-mode" id="mode-' . esc_attr( $id ) . '">';
			echo '<h2>' . esc_html( $c['title'] ) . '</h2>';
			echo '<p>' . esc_html( $c['help'] ) . '</p>';
			echo '<h3>' . esc_html__( 'Claude Desktop / Cursor (JSON)', 'pcf' ) . '</h3>';
			echo '<pre class="pcf-code">' . esc_html( pcf_json_encode( $c['config'] ) ) . '</pre><button type="button" class="button pcf-copy" data-copy-prev>' . esc_html__( 'Copiar JSON', 'pcf' ) . '</button>';
			echo '<h3>Claude Code</h3>';
			echo '<pre class="pcf-code">' . esc_html( $c['claude_code'] ) . '</pre><button type="button" class="button pcf-copy" data-copy-prev>' . esc_html__( 'Copiar comando', 'pcf' ) . '</button>';
			echo '</section>';
		}
		echo '</div>';

		echo '<h2>' . esc_html__( 'Operaciones disponibles', 'pcf' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Tool', 'pcf' ) . '</th><th>' . esc_html__( 'Qué hace', 'pcf' ) . '</th></tr></thead><tbody>';
		foreach ( PCF_Operations::all() as $op ) {
			$flags = array();
			if ( $op['readonly'] ) {
				$flags[] = __( 'lectura', 'pcf' );
			}
			if ( $op['destructive'] ) {
				$flags[] = __( 'destructiva', 'pcf' );
			}
			printf( '<tr><td><code>pcf-%s</code>%s</td><td>%s</td></tr>', esc_html( $op['name'] ), $flags ? ' <span class="pcf-flag">' . esc_html( implode( ', ', $flags ) ) . '</span>' : '', esc_html( $op['description'] ) );
		}
		echo '</tbody></table></div>';
	}
}

/**
 * Guardado desde el editor JSON (crea o actualiza).
 */
function pcf_op_create_or_update_from_admin( $data, $original_key ) {
	if ( $original_key ) {
		$data['key'] = $original_key;
	}
	$group = pcf_op_resolve_condition_names( $data );
	return pcf_update_field_group( $group );
}

PCF_Admin::init();
