<?php
/**
 * CPTs internos + registro de post types y taxonomías definidos vía UI/MCP.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_Post_Types {

	const RESERVED = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'action', 'author', 'order', 'theme', 'category', 'tag', 'post_tag', 'nav_menu', 'link_category', 'post_format', 'type', 'name', 'term', 'taxonomy', 'year', 'day', 'month', 'feed', 's', 'p', 'm' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_internal' ), 0 );
		add_action( 'init', array( __CLASS__, 'register_user_defined' ), 6 );
		add_filter( 'pcf/validate_post_type', array( __CLASS__, 'validate_post_type' ) );
		add_filter( 'pcf/validate_taxonomy', array( __CLASS__, 'validate_taxonomy' ) );
		add_action( 'pcf/update_post_type', array( __CLASS__, 'schedule_flush' ) );
		add_action( 'pcf/update_taxonomy', array( __CLASS__, 'schedule_flush' ) );
		add_action( 'pcf/delete_post_type', array( __CLASS__, 'schedule_flush' ) );
		add_action( 'pcf/delete_taxonomy', array( __CLASS__, 'schedule_flush' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
	}

	public static function register_internal() {
		$args = array(
			'public'          => false,
			'show_ui'         => false,
			'hierarchical'    => false,
			'rewrite'         => false,
			'query_var'       => false,
			'supports'        => array( 'title' ),
			'can_export'      => false,
			'capability_type' => 'page',
			'map_meta_cap'    => true,
		);
		register_post_type( PCF_GROUP_POST_TYPE, array_merge( $args, array( 'label' => 'Grupos de campos PCF' ) ) );
		foreach ( pcf_internal_kinds() as $kind ) {
			register_post_type( $kind['cpt'], array_merge( $args, array( 'label' => $kind['label'] ) ) );
		}
		register_post_status(
			'pcf-disabled',
			array(
				'label'                     => __( 'Inactivo', 'pcf' ),
				'public'                    => false,
				'internal'                  => true,
				'show_in_admin_status_list' => false,
				'show_in_admin_all_list'    => false,
			)
		);
	}

	public static function labels( $singular, $plural, $is_tax = false ) {
		$labels = array(
			'name'               => $plural,
			'singular_name'      => $singular,
			'menu_name'          => $plural,
			'all_items'          => sprintf( __( 'Todos: %s', 'pcf' ), $plural ),
			'add_new'            => __( 'Añadir nuevo', 'pcf' ),
			'add_new_item'       => sprintf( __( 'Añadir %s', 'pcf' ), $singular ),
			'edit_item'          => sprintf( __( 'Editar %s', 'pcf' ), $singular ),
			'new_item'           => sprintf( __( 'Nuevo %s', 'pcf' ), $singular ),
			'view_item'          => sprintf( __( 'Ver %s', 'pcf' ), $singular ),
			'search_items'       => sprintf( __( 'Buscar %s', 'pcf' ), $plural ),
			'not_found'          => sprintf( __( 'No se encontraron %s', 'pcf' ), $plural ),
			'not_found_in_trash' => sprintf( __( 'No hay %s en la papelera', 'pcf' ), $plural ),
			'parent_item_colon'  => sprintf( __( '%s superior:', 'pcf' ), $singular ),
			'update_item'        => sprintf( __( 'Actualizar %s', 'pcf' ), $singular ),
			'new_item_name'      => sprintf( __( 'Nombre de %s', 'pcf' ), $singular ),
		);
		return $labels;
	}

	public static function validate_post_type( $item ) {
		$slug = sanitize_key( pcf_maybe_get( $item, 'post_type', '' ) );
		if ( ! $slug || strlen( $slug ) > 20 ) {
			return new WP_Error( 'invalid_post_type', 'post_type debe tener entre 1 y 20 caracteres (a-z, 0-9, _ y -).' );
		}
		if ( in_array( $slug, self::RESERVED, true ) ) {
			return new WP_Error( 'reserved_post_type', sprintf( '"%s" es un nombre reservado de WordPress.', $slug ) );
		}
		$item['post_type'] = $slug;
		$item['singular']  = pcf_maybe_get( $item, 'singular', ucfirst( $slug ) );
		$item['plural']    = pcf_maybe_get( $item, 'plural', $item['singular'] . 's' );
		$item['title']     = $item['plural'];
		$item['args']      = (array) pcf_maybe_get( $item, 'args', array() );
		return $item;
	}

	public static function validate_taxonomy( $item ) {
		$slug = sanitize_key( pcf_maybe_get( $item, 'taxonomy', '' ) );
		if ( ! $slug || strlen( $slug ) > 32 ) {
			return new WP_Error( 'invalid_taxonomy', 'taxonomy debe tener entre 1 y 32 caracteres.' );
		}
		if ( in_array( $slug, self::RESERVED, true ) ) {
			return new WP_Error( 'reserved_taxonomy', sprintf( '"%s" es un nombre reservado.', $slug ) );
		}
		$item['taxonomy']    = $slug;
		$item['singular']    = pcf_maybe_get( $item, 'singular', ucfirst( $slug ) );
		$item['plural']      = pcf_maybe_get( $item, 'plural', $item['singular'] . 's' );
		$item['title']       = $item['plural'];
		$item['object_type'] = array_values( array_filter( (array) pcf_maybe_get( $item, 'object_type', array() ) ) );
		$item['args']        = (array) pcf_maybe_get( $item, 'args', array() );
		return $item;
	}

	public static function register_user_defined() {
		foreach ( pcf_get_internal_items( 'post_type' ) as $item ) {
			if ( empty( $item['active'] ) || post_type_exists( $item['post_type'] ) ) {
				continue;
			}
			$args = wp_parse_args(
				$item['args'],
				array(
					'labels'       => self::labels( $item['singular'], $item['plural'] ),
					'public'       => true,
					'show_in_rest' => true,
					'has_archive'  => false,
					'hierarchical' => false,
					'menu_icon'    => 'dashicons-admin-post',
					'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ),
					'taxonomies'   => (array) pcf_maybe_get( $item, 'taxonomies', array() ),
				)
			);
			if ( isset( $item['args']['labels'] ) ) {
				$args['labels'] = array_merge( self::labels( $item['singular'], $item['plural'] ), (array) $item['args']['labels'] );
			}
			register_post_type( $item['post_type'], apply_filters( 'pcf/post_type/registration_args', $args, $item ) );
		}

		foreach ( pcf_get_internal_items( 'taxonomy' ) as $item ) {
			if ( empty( $item['active'] ) || taxonomy_exists( $item['taxonomy'] ) ) {
				continue;
			}
			$args = wp_parse_args(
				$item['args'],
				array(
					'labels'            => self::labels( $item['singular'], $item['plural'], true ),
					'public'            => true,
					'show_in_rest'      => true,
					'hierarchical'      => true,
					'show_admin_column' => true,
				)
			);
			register_taxonomy( $item['taxonomy'], $item['object_type'], apply_filters( 'pcf/taxonomy/registration_args', $args, $item ) );
		}
	}

	public static function schedule_flush() {
		update_option( 'pcf_flush_rewrite', 1 );
	}

	public static function maybe_flush() {
		if ( get_option( 'pcf_flush_rewrite' ) ) {
			delete_option( 'pcf_flush_rewrite' );
			flush_rewrite_rules( false );
		}
	}
}

PCF_Post_Types::init();
