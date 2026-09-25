<?php
/**
 * Formulario de usuarios (perfil, alta y registro).
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_Form_User {

	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'edit_form' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'edit_form' ) );
		add_action( 'user_new_form', array( __CLASS__, 'add_form' ) );
		add_action( 'register_form', array( __CLASS__, 'register_form' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save' ) );
		add_action( 'user_register', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue( $hook ) {
		if ( in_array( $hook, array( 'profile.php', 'user-edit.php', 'user-new.php' ), true ) ) {
			PCF_Renderer::enqueue();
		}
	}

	protected static function render( $screen, $post_id ) {
		foreach ( pcf_get_field_groups( $screen ) as $group ) {
			if ( 'seamless' !== $group['style'] ) {
				echo '<h2>' . esc_html( $group['title'] ) . '</h2>';
			}
			echo '<div class="pcf-user-group">';
			PCF_Renderer::render_group( $group, $post_id );
			echo '</div>';
		}
	}

	public static function edit_form( $user ) {
		self::render( array( 'user_form' => 'edit', 'user_id' => $user->ID ), 'user_' . $user->ID );
	}

	public static function add_form() {
		self::render( array( 'user_form' => 'add' ), 0 );
	}

	public static function register_form() {
		self::render( array( 'user_form' => 'register' ), 0 );
	}

	public static function save( $user_id ) {
		if ( ! PCF_Renderer::verify_nonce() ) {
			return;
		}
		// En el registro público el usuario aún no tiene permisos: sólo se guardan grupos de registro.
		$is_register = 'user_register' === current_action() && ! is_user_logged_in();
		if ( ! $is_register && ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$groups = PCF_Renderer::submitted_groups();
		if ( $is_register ) {
			$groups = array_filter(
				$groups,
				function ( $g ) {
					return pcf_get_field_group_visibility( $g, array( 'user_form' => 'register' ) );
				}
			);
		}
		PCF_Renderer::save_submission( 'user_' . $user_id, $groups );
	}
}

PCF_Form_User::init();
