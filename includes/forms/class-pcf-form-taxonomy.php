<?php
/**
 * Formulario de términos.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_Form_Taxonomy {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'hook_taxonomies' ), 99 );
		add_action( 'created_term', array( __CLASS__, 'save' ), 10, 3 );
		add_action( 'edited_term', array( __CLASS__, 'save' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue( $hook ) {
		if ( in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) ) {
			PCF_Renderer::enqueue();
		}
	}

	public static function hook_taxonomies() {
		foreach ( get_taxonomies( array( 'show_ui' => true ) ) as $tax ) {
			add_action( "{$tax}_add_form_fields", array( __CLASS__, 'add_form' ) );
			add_action( "{$tax}_edit_form", array( __CLASS__, 'edit_form' ), 10, 2 );
		}
	}

	public static function add_form( $taxonomy ) {
		foreach ( pcf_get_field_groups( array( 'taxonomy' => $taxonomy ) ) as $group ) {
			echo '<div class="form-field pcf-term-group">';
			if ( 'seamless' !== $group['style'] ) {
				echo '<h3>' . esc_html( $group['title'] ) . '</h3>';
			}
			PCF_Renderer::render_group( $group, 0 );
			echo '</div>';
		}
		// El formulario de alta se envía por AJAX: limpiar tras crear.
		echo '<script>jQuery(function($){$(document).ajaxComplete(function(e,x,s){if(s.data&&s.data.indexOf("action=add-tag")!==-1&&x.responseXML&&!$(x.responseXML).find("wp_error").length){$(".pcf-term-group .pcf-rows").empty();}});});</script>';
	}

	public static function edit_form( $term, $taxonomy ) {
		$post_id = 'term_' . $term->term_id;
		foreach ( pcf_get_field_groups( array( 'taxonomy' => $taxonomy, 'term_id' => $term->term_id ) ) as $group ) {
			if ( 'seamless' !== $group['style'] ) {
				echo '<h2>' . esc_html( $group['title'] ) . '</h2>';
			}
			echo '<div class="pcf-term-group">';
			PCF_Renderer::render_group( $group, $post_id );
			echo '</div>';
		}
	}

	public static function save( $term_id, $tt_id, $taxonomy ) {
		if ( ! PCF_Renderer::verify_nonce() || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		PCF_Renderer::save_submission( 'term_' . $term_id, PCF_Renderer::submitted_groups() );
	}
}

PCF_Form_Taxonomy::init();
