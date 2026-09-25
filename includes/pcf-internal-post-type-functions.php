<?php
/**
 * Almacenamiento genérico de "elementos internos" (como ACF 6.1+):
 * post types, taxonomías y páginas de opciones creadas desde la UI/MCP.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

function pcf_internal_kinds() {
	return array(
		'post_type'    => array( 'cpt' => 'pcf-post-type', 'prefix' => 'post_type', 'id_field' => 'post_type', 'label' => 'Post types' ),
		'taxonomy'     => array( 'cpt' => 'pcf-taxonomy', 'prefix' => 'taxonomy', 'id_field' => 'taxonomy', 'label' => 'Taxonomías' ),
		'options_page' => array( 'cpt' => 'pcf-options-page', 'prefix' => 'ui_options_page', 'id_field' => 'menu_slug', 'label' => 'Páginas de opciones' ),
	);
}

function pcf_internal_item_from_post( WP_Post $post ) {
	$data             = (array) pcf_json_decode( $post->post_content );
	$data['ID']       = $post->ID;
	$data['key']      = $post->post_name;
	$data['title']    = $post->post_title;
	$data['active']   = 'publish' === $post->post_status;
	$data['modified'] = strtotime( $post->post_modified_gmt . ' UTC' );
	return $data;
}

/**
 * @return array Elementos indexados por key.
 */
function pcf_get_internal_items( $kind ) {
	static $cache = array();
	if ( 'flush' === $kind ) {
		$cache = array();
		return array();
	}
	if ( isset( $cache[ $kind ] ) ) {
		return $cache[ $kind ];
	}
	$kinds = pcf_internal_kinds();
	if ( ! isset( $kinds[ $kind ] ) ) {
		return array();
	}
	$items = array();
	$posts = get_posts(
		array(
			'post_type'        => $kinds[ $kind ]['cpt'],
			'post_status'      => array( 'publish', 'pcf-disabled' ),
			'posts_per_page'   => -1,
			'orderby'          => 'menu_order title',
			'order'            => 'ASC',
			'suppress_filters' => true,
		)
	);
	foreach ( $posts as $post ) {
		$items[ $post->post_name ] = pcf_internal_item_from_post( $post );
	}
	$cache[ $kind ] = $items;
	return $items;
}

/**
 * Busca por key, ID o por el identificador natural (slug del post type, etc.).
 */
function pcf_get_internal_item( $kind, $selector ) {
	$items = pcf_get_internal_items( $kind );
	if ( isset( $items[ $selector ] ) ) {
		return $items[ $selector ];
	}
	$id_field = pcf_internal_kinds()[ $kind ]['id_field'];
	foreach ( $items as $item ) {
		if ( ( is_numeric( $selector ) && (int) $item['ID'] === (int) $selector ) || pcf_maybe_get( $item, $id_field ) === $selector ) {
			return $item;
		}
	}
	return null;
}

function pcf_update_internal_item( $kind, $item ) {
	$kinds = pcf_internal_kinds();
	if ( ! isset( $kinds[ $kind ] ) ) {
		return new WP_Error( 'invalid_kind', 'Tipo interno no válido.' );
	}
	$conf     = $kinds[ $kind ];
	$id_field = $conf['id_field'];

	// Si existe por identificador natural, fusionar.
	$existing = null;
	if ( ! empty( $item['key'] ) ) {
		$existing = pcf_get_internal_item( $kind, $item['key'] );
	} elseif ( ! empty( $item[ $id_field ] ) ) {
		$existing = pcf_get_internal_item( $kind, $item[ $id_field ] );
	}
	if ( $existing ) {
		$item = array_replace_recursive( $existing, $item );
	}

	$item = apply_filters( "pcf/validate_{$kind}", $item );
	if ( is_wp_error( $item ) ) {
		return $item;
	}
	if ( empty( $item[ $id_field ] ) ) {
		return new WP_Error( 'missing_id', sprintf( 'Falta "%s".', $id_field ) );
	}
	if ( empty( $item['key'] ) ) {
		$item['key'] = pcf_uniqid( $conf['prefix'] );
	}
	$item['active'] = isset( $item['active'] ) ? (bool) $item['active'] : true;

	$content = $item;
	unset( $content['ID'], $content['key'], $content['title'], $content['active'], $content['modified'] );

	$postarr = array(
		'post_type'    => $conf['cpt'],
		'post_status'  => $item['active'] ? 'publish' : 'pcf-disabled',
		'post_title'   => pcf_maybe_get( $item, 'title', $item[ $id_field ] ),
		'post_name'    => $item['key'],
		'post_content' => wp_slash( pcf_json_encode( $content ) ),
	);

	kses_remove_filters();
	if ( $existing ) {
		$postarr['ID'] = $existing['ID'];
		$id            = wp_update_post( $postarr, true );
	} else {
		$id = wp_insert_post( $postarr, true );
	}
	kses_init_filters();

	if ( is_wp_error( $id ) ) {
		return $id;
	}
	pcf_get_internal_items( 'flush' );
	$saved = pcf_get_internal_item( $kind, $item['key'] );
	do_action( "pcf/update_{$kind}", $saved );
	return $saved;
}

function pcf_delete_internal_item( $kind, $selector ) {
	$item = pcf_get_internal_item( $kind, $selector );
	if ( ! $item ) {
		return new WP_Error( 'not_found', 'Elemento no encontrado.' );
	}
	wp_delete_post( $item['ID'], true );
	pcf_get_internal_items( 'flush' );
	do_action( "pcf/delete_{$kind}", $item );
	return true;
}
