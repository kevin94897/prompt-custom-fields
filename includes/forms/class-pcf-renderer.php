<?php
/**
 * Renderizado de campos en formularios del admin y guardado de envíos.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_Renderer {

	public static function enqueue() {
		if ( is_admin() && ! did_action( 'admin_enqueue_scripts' ) ) {
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
			return;
		}
		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_editor();
		wp_enqueue_style( 'pcf-input', PCF_URL . 'assets/css/pcf-input.css', array(), PCF_VERSION );
		wp_enqueue_script( 'pcf-input', PCF_URL . 'assets/js/pcf-input.js', array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker', 'wp-util' ), PCF_VERSION, true );
		wp_localize_script(
			'pcf-input',
			'pcfInput',
			array(
				'i18n' => array(
					'selectImage' => __( 'Seleccionar imagen', 'pcf' ),
					'selectFile'  => __( 'Seleccionar archivo', 'pcf' ),
					'use'         => __( 'Usar', 'pcf' ),
					'maxRows'     => __( 'Se alcanzó el máximo de filas.', 'pcf' ),
					'minRows'     => __( 'No se puede quitar: hay un mínimo de filas.', 'pcf' ),
					'confirm'     => __( '¿Eliminar esta fila?', 'pcf' ),
				),
			)
		);
	}

	/**
	 * Renderiza un grupo completo con su nonce.
	 */
	public static function render_group( $group, $post_id ) {
		static $nonce = false;
		if ( ! $nonce ) {
			wp_nonce_field( 'pcf_save', 'pcf_nonce' );
			$nonce = true;
		}
		printf(
			'<div class="pcf-fields pcf-root pcf-labels-%s pcf-instructions-%s" data-group="%s">',
			esc_attr( $group['label_placement'] ),
			esc_attr( $group['instruction_placement'] ),
			esc_attr( $group['key'] )
		);
		printf( '<input type="hidden" name="pcf_groups[]" value="%s" />', esc_attr( $group['key'] ) );
		self::render_fields( $group['fields'], $post_id, 'pcf' );
		echo '</div>';
		if ( ! empty( $group['description'] ) && current_user_can( pcf_get_setting( 'capability' ) ) ) {
			// La descripción sólo es informativa para administradores.
			printf( '<!-- %s -->', esc_html( $group['description'] ) );
		}
	}

	/**
	 * @param array      $fields  Campos.
	 * @param mixed      $post_id Objeto.
	 * @param string     $prefix  Prefijo del atributo name.
	 * @param array|null $values  Valores de la fila (subcampos); null = cargar de BD.
	 */
	public static function render_fields( $fields, $post_id, $prefix, $values = null ) {
		foreach ( $fields as $field ) {
			$type = pcf_get_field_type( $field['type'] );
			if ( ! $type ) {
				continue;
			}
			$field['post_id']    = $post_id;
			$field['input_name'] = $prefix . '[' . $field['key'] . ']';
			$field['id']         = 'pcf-' . trim( preg_replace( '/[^a-z0-9_-]+/i', '-', $field['input_name'] ), '-' );

			if ( ! $type->has_value ) {
				$field['value'] = null;
			} elseif ( null === $values ) {
				$field['value'] = pcf_get_value( $post_id, $field );
			} else {
				$exists         = false;
				$field['value'] = pcf_row_value( $values, $field, $exists );
				if ( ! $exists && 'clone' === $field['type'] ) {
					$field['value'] = $values;
				} elseif ( ! $exists && isset( $field['default_value'] ) ) {
					$field['value'] = $field['default_value'];
				}
			}
			self::render_field_wrap( $field, $type );
		}
	}

	public static function render_field_wrap( $field, $type ) {
		$field = apply_filters( 'pcf/prepare_field', $field );
		if ( ! $field ) {
			return;
		}
		$classes = array( 'pcf-field', 'pcf-field-' . str_replace( '_', '-', $field['type'] ) );
		if ( ! empty( $field['wrapper']['class'] ) ) {
			$classes[] = $field['wrapper']['class'];
		}
		if ( ! empty( $field['required'] ) ) {
			$classes[] = 'is-required';
		}
		$attrs = array(
			'class'           => implode( ' ', $classes ),
			'data-key'        => $field['key'],
			'data-name'       => $field['name'],
			'data-type'       => $field['type'],
			'data-conditions' => $field['conditional_logic'] ? $field['conditional_logic'] : null,
			'id'              => ! empty( $field['wrapper']['id'] ) ? $field['wrapper']['id'] : null,
			'style'           => ! empty( $field['wrapper']['width'] ) ? 'width:' . (float) $field['wrapper']['width'] . '%' : null,
		);
		if ( in_array( $field['type'], array( 'tab', 'accordion' ), true ) ) {
			$attrs['data-placement'] = pcf_maybe_get( $field, 'placement', '' );
			$attrs['data-endpoint']  = ! empty( $field['endpoint'] ) ? 1 : 0;
			$attrs['data-open']      = ( ! empty( $field['open'] ) || ! empty( $field['selected'] ) ) ? 1 : 0;
			$attrs['data-multi']     = ! empty( $field['multi_expand'] ) ? 1 : 0;
			printf( '<div%s><span class="pcf-marker-label">%s</span></div>', pcf_esc_attrs( $attrs ), esc_html( $field['label'] ) ); // phpcs:ignore
			return;
		}

		echo '<div' . pcf_esc_attrs( $attrs ) . '>'; // phpcs:ignore
		if ( '' !== $field['label'] ) {
			printf(
				'<div class="pcf-label"><label for="%s">%s%s</label>%s</div>',
				esc_attr( $field['id'] ),
				esc_html( $field['label'] ),
				! empty( $field['required'] ) ? ' <span class="pcf-required">*</span>' : '',
				$field['instructions'] ? '<p class="description">' . wp_kses_post( $field['instructions'] ) . '</p>' : ''
			);
		}
		echo '<div class="pcf-input">';
		do_action( 'pcf/render_field/before', $field );
		$type->render_field( $field );
		do_action( 'pcf/render_field', $field );
		echo '</div></div>';
	}

	/**
	 * Guarda los valores enviados para los grupos indicados.
	 */
	public static function save_submission( $post_id, $groups, $input = null ) {
		if ( null === $input ) {
			$input = isset( $_POST['pcf'] ) ? wp_unslash( $_POST['pcf'] ) : array(); // phpcs:ignore
		}
		$input  = (array) $input;
		$report = array( 'updated' => array(), 'errors' => array() );

		do_action( 'pcf/save_post/before', $post_id );
		if ( pcf_acf_bridge_enabled() ) {
			do_action( 'acf/save_post/before', $post_id ); // phpcs:ignore
		}

		foreach ( $groups as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( ! array_key_exists( $field['key'], $input ) ) {
					continue;
				}
				$value = $input[ $field['key'] ];
				$valid = pcf_validate_value( $value, $field );
				if ( true !== $valid ) {
					$report['errors'][ $field['name'] ] = $valid;
					continue;
				}
				pcf_update_value( $value, $post_id, $field );
				$report['updated'][] = $field['name'];
			}
		}

		do_action( 'pcf/save_post', $post_id );
		if ( pcf_acf_bridge_enabled() ) {
			do_action( 'acf/save_post', $post_id );
		}

		if ( $report['errors'] ) {
			set_transient( 'pcf_errors_' . get_current_user_id(), $report['errors'], 60 );
		}
		return $report;
	}

	public static function render_error_notice() {
		$errors = get_transient( 'pcf_errors_' . get_current_user_id() );
		if ( ! $errors ) {
			return;
		}
		delete_transient( 'pcf_errors_' . get_current_user_id() );
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Algunos campos no se guardaron:', 'pcf' ) . '</strong></p><ul>';
		foreach ( (array) $errors as $name => $msg ) {
			printf( '<li><code>%s</code>: %s</li>', esc_html( $name ), esc_html( $msg ) );
		}
		echo '</ul></div>';
	}

	public static function verify_nonce() {
		return isset( $_POST['pcf_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pcf_nonce'] ) ), 'pcf_save' );
	}

	/**
	 * Grupos enviados en el formulario (evita guardar grupos no mostrados).
	 */
	public static function submitted_groups() {
		$keys   = isset( $_POST['pcf_groups'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['pcf_groups'] ) ) : array(); // phpcs:ignore
		$groups = array();
		foreach ( array_unique( $keys ) as $key ) {
			$g = pcf_get_field_group( $key );
			if ( $g ) {
				$groups[] = $g;
			}
		}
		return $groups;
	}
}

add_action( 'admin_notices', array( 'PCF_Renderer', 'render_error_notice' ) );
