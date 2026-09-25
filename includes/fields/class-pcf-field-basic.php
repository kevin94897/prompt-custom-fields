<?php
/**
 * Campos básicos.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_Field_Text extends PCF_Field {
	protected function setup() {
		$this->name        = 'text';
		$this->label       = 'Texto';
		$this->description = 'Una línea de texto.';
		$this->settings    = array(
			'default_value' => self::s( 'string', '', 'Valor por defecto.' ),
			'placeholder'   => self::s( 'string', '', 'Placeholder.' ),
			'prepend'       => self::s( 'string', '', 'Texto antes del input.' ),
			'append'        => self::s( 'string', '', 'Texto después del input.' ),
			'maxlength'     => self::s( array( 'integer', 'string' ), '', 'Máximo de caracteres.' ),
		);
	}

	protected $input_type = 'text';

	public function render_field( $field ) {
		$attrs = array(
			'type'        => $this->input_type,
			'id'          => $field['id'],
			'name'        => $field['input_name'],
			'value'       => is_scalar( $field['value'] ) ? $field['value'] : '',
			'placeholder' => pcf_maybe_get( $field, 'placeholder' ) ? $field['placeholder'] : null,
			'maxlength'   => pcf_maybe_get( $field, 'maxlength' ) ? $field['maxlength'] : null,
		);
		foreach ( array( 'min', 'max', 'step' ) as $k ) {
			if ( isset( $field[ $k ] ) && '' !== $field[ $k ] ) {
				$attrs[ $k ] = $field[ $k ];
			}
		}
		echo '<div class="pcf-input-wrap">';
		if ( ! empty( $field['prepend'] ) ) {
			echo '<span class="pcf-prepend">' . esc_html( $field['prepend'] ) . '</span>';
		}
		echo '<input' . pcf_esc_attrs( $attrs ) . ' />'; // phpcs:ignore
		if ( ! empty( $field['append'] ) ) {
			echo '<span class="pcf-append">' . esc_html( $field['append'] ) . '</span>';
		}
		echo '</div>';
	}

	public function validate_value( $valid, $value, $field ) {
		$max = (int) pcf_maybe_get( $field, 'maxlength', 0 );
		if ( $max && is_string( $value ) && mb_strlen( $value ) > $max ) {
			return sprintf( '%s: máximo %d caracteres.', $field['label'], $max );
		}
		return $valid;
	}

	public function update_value( $value, $post_id, $field ) {
		return is_scalar( $value ) ? (string) $value : '';
	}
}

class PCF_Field_Textarea extends PCF_Field_Text {
	protected function setup() {
		$this->name        = 'textarea';
		$this->label       = 'Área de texto';
		$this->description = 'Texto multilínea.';
		$this->settings    = array(
			'default_value' => self::s( 'string', '', 'Valor por defecto.' ),
			'placeholder'   => self::s( 'string', '', 'Placeholder.' ),
			'maxlength'     => self::s( array( 'integer', 'string' ), '', 'Máximo de caracteres.' ),
			'rows'          => self::s( array( 'integer', 'string' ), '', 'Filas visibles.' ),
			'new_lines'     => self::s( 'string', '', 'Formato de saltos de línea.', array( '', 'wpautop', 'br' ) ),
		);
	}

	public function render_field( $field ) {
		printf(
			'<textarea%s>%s</textarea>',
			pcf_esc_attrs(
				array(
					'id'          => $field['id'],
					'name'        => $field['input_name'],
					'rows'        => $field['rows'] ? $field['rows'] : 6,
					'placeholder' => $field['placeholder'] ? $field['placeholder'] : null,
					'maxlength'   => $field['maxlength'] ? $field['maxlength'] : null,
				)
			),
			esc_textarea( is_scalar( $field['value'] ) ? $field['value'] : '' )
		);
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		if ( $escape_html ) {
			$value = esc_html( $value );
		}
		if ( 'wpautop' === $field['new_lines'] ) {
			return wpautop( $value );
		}
		if ( 'br' === $field['new_lines'] ) {
			return nl2br( $value );
		}
		return $value;
	}
}

class PCF_Field_Number extends PCF_Field_Text {
	protected $input_type = 'number';

	protected function setup() {
		$this->name        = 'number';
		$this->label       = 'Número';
		$this->description = 'Número con min/max/step.';
		$this->settings    = array(
			'default_value' => self::s( array( 'number', 'string' ), '', 'Valor por defecto.' ),
			'placeholder'   => self::s( 'string', '', 'Placeholder.' ),
			'prepend'       => self::s( 'string', '', 'Texto antes.' ),
			'append'        => self::s( 'string', '', 'Texto después (ej. unidad).' ),
			'min'           => self::s( array( 'number', 'string' ), '', 'Mínimo.' ),
			'max'           => self::s( array( 'number', 'string' ), '', 'Máximo.' ),
			'step'          => self::s( array( 'number', 'string' ), '', 'Incremento.' ),
		);
	}

	public function value_format() {
		return 'number';
	}

	public function validate_value( $valid, $value, $field ) {
		if ( ! is_numeric( $value ) ) {
			return sprintf( '%s: debe ser numérico.', $field['label'] );
		}
		if ( '' !== $field['min'] && $value < $field['min'] ) {
			return sprintf( '%s: mínimo %s.', $field['label'], $field['min'] );
		}
		if ( '' !== $field['max'] && $value > $field['max'] ) {
			return sprintf( '%s: máximo %s.', $field['label'], $field['max'] );
		}
		return $valid;
	}

	public function update_value( $value, $post_id, $field ) {
		return is_numeric( $value ) ? (string) $value : '';
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		if ( '' === $value || null === $value ) {
			return $value;
		}
		return is_numeric( $value ) ? $value + 0 : $value;
	}
}

class PCF_Field_Range extends PCF_Field_Number {
	protected $input_type = 'range';

	protected function setup() {
		parent::setup();
		$this->name        = 'range';
		$this->label       = 'Rango';
		$this->description = 'Deslizador numérico.';
		unset( $this->settings['placeholder'] );
	}

	public function render_field( $field ) {
		$field['min']  = '' === $field['min'] ? 0 : $field['min'];
		$field['max']  = '' === $field['max'] ? 100 : $field['max'];
		$field['step'] = '' === $field['step'] ? 1 : $field['step'];
		echo '<div class="pcf-range">';
		parent::render_field( $field );
		echo '<output>' . esc_html( is_scalar( $field['value'] ) ? $field['value'] : '' ) . '</output></div>';
	}
}

class PCF_Field_Email extends PCF_Field_Text {
	protected $input_type = 'email';

	protected function setup() {
		parent::setup();
		$this->name        = 'email';
		$this->label       = 'Email';
		$this->description = 'Dirección de email validada.';
		unset( $this->settings['maxlength'] );
	}

	public function validate_value( $valid, $value, $field ) {
		return is_email( $value ) ? $valid : sprintf( '%s: email no válido.', $field['label'] );
	}
}

class PCF_Field_Url extends PCF_Field_Text {
	protected $input_type = 'url';

	protected function setup() {
		$this->name        = 'url';
		$this->label       = 'URL';
		$this->description = 'URL validada.';
		$this->settings    = array(
			'default_value' => self::s( 'string', '', 'Valor por defecto.' ),
			'placeholder'   => self::s( 'string', '', 'Placeholder.' ),
		);
	}

	public function validate_value( $valid, $value, $field ) {
		if ( ! preg_match( '#^([a-z][a-z0-9+.-]*:|/|\#)#i', (string) $value ) ) {
			return sprintf( '%s: URL no válida.', $field['label'] );
		}
		return $valid;
	}

	public function update_value( $value, $post_id, $field ) {
		return esc_url_raw( (string) $value );
	}
}

class PCF_Field_Password extends PCF_Field_Text {
	protected $input_type = 'password';

	protected function setup() {
		$this->name        = 'password';
		$this->label       = 'Contraseña';
		$this->description = 'Input tipo password (se guarda en texto plano, como ACF).';
		$this->settings    = array(
			'placeholder' => self::s( 'string', '', 'Placeholder.' ),
			'prepend'     => self::s( 'string', '', 'Texto antes.' ),
			'append'      => self::s( 'string', '', 'Texto después.' ),
		);
	}
}
