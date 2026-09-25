<?php
/**
 * Páginas de opciones (equivalente a acf_add_options_page y a las
 * "UI Options Pages" de ACF 6.2).
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_Options_Pages {

	/** @var array Registradas por PHP. */
	public static $pages = array();

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 99 );
		add_filter( 'pcf/validate_options_page', array( __CLASS__, 'validate_item' ) );
	}

	public static function defaults() {
		return array(
			'page_title'      => '',
			'menu_title'      => '',
			'menu_slug'       => '',
			'capability'      => 'edit_posts',
			'position'        => null,
			'parent_slug'     => '',
			'icon_url'        => '',
			'redirect'        => true,
			'post_id'         => 'options',
			'autoload'        => false,
			'update_button'   => __( 'Actualizar', 'pcf' ),
			'updated_message' => __( 'Opciones actualizadas', 'pcf' ),
			'description'     => '',
		);
	}

	public static function validate( $page ) {
		if ( is_string( $page ) ) {
			$page = array( 'page_title' => $page );
		}
		$page = wp_parse_args( (array) $page, self::defaults() );
		if ( '' === $page['page_title'] ) {
			$page['page_title'] = __( 'Opciones', 'pcf' );
		}
		if ( '' === $page['menu_title'] ) {
			$page['menu_title'] = $page['page_title'];
		}
		if ( '' === $page['menu_slug'] ) {
			$page['menu_slug'] = 'acf-options-' . sanitize_title( $page['menu_title'] );
		}
		$page['menu_slug'] = sanitize_key( $page['menu_slug'] );
		if ( '' === (string) $page['post_id'] ) {
			$page['post_id'] = 'options';
		}
		return $page;
	}

	public static function validate_item( $item ) {
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		$item          = self::validate( $item );
		$item['title'] = $item['page_title'];
		return $item;
	}

	public static function add( $page ) {
		$page                                 = self::validate( $page );
		self::$pages[ $page['menu_slug'] ] = $page;
		return $page;
	}

	public static function all() {
		$pages = self::$pages;
		foreach ( pcf_get_internal_items( 'options_page' ) as $item ) {
			if ( ! empty( $item['active'] ) && ! isset( $pages[ $item['menu_slug'] ] ) ) {
				$page                        = self::validate( $item );
				$page['key']                 = $item['key'];
				$page['source']              = 'db';
				$pages[ $page['menu_slug'] ] = $page;
			}
		}
		return $pages;
	}

	public static function admin_menu() {
		$pages = self::all();

		// Primero los de primer nivel.
		uasort(
			$pages,
			function ( $a, $b ) {
				return (int) (bool) $a['parent_slug'] <=> (int) (bool) $b['parent_slug'];
			}
		);

		foreach ( $pages as $slug => $page ) {
			$callback = function () use ( $slug ) {
				PCF_Options_Pages::render( $slug );
			};
			if ( $page['parent_slug'] ) {
				$hook = add_submenu_page( $page['parent_slug'], $page['page_title'], $page['menu_title'], $page['capability'], $slug, $callback, $page['position'] );
			} else {
				$hook = add_menu_page( $page['page_title'], $page['menu_title'], $page['capability'], $slug, $callback, $page['icon_url'], $page['position'] );
				// Redirección al primer hijo (como ACF).
				if ( $page['redirect'] ) {
					foreach ( $pages as $child_slug => $child ) {
						if ( $child['parent_slug'] === $slug ) {
							add_submenu_page( $slug, $page['page_title'], $page['menu_title'], $page['capability'], $slug, $callback );
							break;
						}
					}
				}
			}
			if ( $hook ) {
				add_action(
					"load-{$hook}",
					function () use ( $slug ) {
						PCF_Options_Pages::load( $slug );
					}
				);
			}
		}
	}

	public static function groups_for( $slug ) {
		return pcf_get_field_groups( array( 'options_page' => $slug ) );
	}

	public static function load( $slug ) {
		$page = pcf_maybe_get( self::all(), $slug );
		if ( ! $page ) {
			return;
		}
		PCF_Renderer::enqueue();
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['pcf_options_nonce'] ) ) { // phpcs:ignore
			check_admin_referer( 'pcf_options_' . $slug, 'pcf_options_nonce' );
			$result = PCF_Renderer::save_submission( $page['post_id'], self::groups_for( $slug ) );
			$arg    = empty( $result['errors'] ) ? array( 'message' => 1 ) : array( 'pcf_errors' => rawurlencode( wp_json_encode( $result['errors'] ) ) );
			wp_safe_redirect( add_query_arg( $arg, wp_get_referer() ) );
			exit;
		}
	}

	public static function render( $slug ) {
		$page   = pcf_maybe_get( self::all(), $slug );
		$groups = self::groups_for( $slug );
		echo '<div class="wrap pcf-options-page"><h1>' . esc_html( $page['page_title'] ) . '</h1>';
		if ( ! empty( $_GET['message'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $page['updated_message'] ) . '</p></div>';
		}
		PCF_Renderer::render_error_notice();
		if ( $page['description'] ) {
			echo '<p>' . esc_html( $page['description'] ) . '</p>';
		}
		echo '<form method="post" class="pcf-form">';
		wp_nonce_field( 'pcf_options_' . $slug, 'pcf_options_nonce' );
		if ( ! $groups ) {
			printf( '<p>%s</p>', esc_html( sprintf( __( 'Aún no hay campos. Crea un grupo con la ubicación options_page == %s.', 'pcf' ), $slug ) ) );
		}
		foreach ( $groups as $group ) {
			echo '<div class="postbox pcf-postbox"><h2 class="hndle">' . esc_html( $group['title'] ) . '</h2><div class="inside">';
			PCF_Renderer::render_group( $group, $page['post_id'] );
			echo '</div></div>';
		}
		submit_button( $page['update_button'] );
		echo '</form></div>';
	}
}

PCF_Options_Pages::init();

function pcf_add_options_page( $page = '' ) {
	return PCF_Options_Pages::add( $page );
}

function pcf_add_options_sub_page( $page = '' ) {
	if ( is_string( $page ) ) {
		$page = array( 'page_title' => $page );
	}
	if ( empty( $page['parent_slug'] ) ) {
		$parents = array_filter(
			PCF_Options_Pages::$pages,
			function ( $p ) {
				return ! $p['parent_slug'];
			}
		);
		$page['parent_slug'] = $parents ? array_key_first( $parents ) : pcf_add_options_page()['menu_slug'];
	}
	return PCF_Options_Pages::add( $page );
}

function pcf_get_options_pages() {
	return PCF_Options_Pages::all();
}

function pcf_get_options_page_by_post_id( $post_id ) {
	foreach ( PCF_Options_Pages::all() as $page ) {
		if ( (string) $page['post_id'] === (string) $post_id ) {
			return $page;
		}
	}
	return null;
}
