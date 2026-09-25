<?php
/**
 * Campos de elección.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

abstract class PCF_Field_Choice_Base extends PCF_Field {

	protected $multiple = false;

	protected function choice_settings() {
		return array(
			'choices'       => self::s( array( 'object', 'array', 'string' ), array(), 'Opciones: {"valor":"Etiqueta"}, ["a","b"] o texto "valor : Etiqueta" por línea.' ),
			'default_value' => self::s( array( 'string', 'array' ), '', 'Valor(es) por defecto.' ),
			'return_format' => self::s( 'string', 'value', 'Formato devuelto.', array( 'value', 'label', 'array' ) ),
		);
	}

	public function value_format() {
		return $this->multiple ? 'array de valores (keys de choices)' : 'string (key de choices)';
	}

	protected function is_multiple( $field ) {
		return $this->multiple || ! empty( $field['multiple'] );
	}

	public function validate_value( $valid, $value, $field ) {
		if ( ! empty( $field['allow_custom'] ) || ! empty( $field['other_choice'] ) || empty( $field['choices'] ) ) {
			return $valid;
		}
		foreach ( pcf_get_array( $value ) as $v ) {
			if ( '' !== $v && ! array_key_exists( (string) $v, $field['choices'] ) ) {
				return sprintf( '%s: "%s" no es una opción válida (%s).', $field['label'], $v, implode( ', ', array_keys( $field['choices'] ) ) );
			}
		}
		return $valid;
	}

	public function update_value( $value, $post_id, $field ) {
		if ( $this->is_multiple( $field ) ) {
			$vals = array_values( array_filter( array_map( 'strval', pcf_get_array( $value ) ), 'strlen' ) );
			return $vals ? $vals : '';
		}
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	public function load_value( $value, $post_id, $field ) {
		if ( $this->is_multiple( $field ) && ! is_array( $value ) && null !== $value && '' !== $value ) {
			$value = pcf_get_array( $value );
		}
		return $value;
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $v ) {
				$out[] = $this->format_single( $v, $field );
			}
			return $out;
		}
		if ( '' === $value || null === $value ) {
			return $value;
		}
		return $this->format_single( $value, $field );
	}

	protected function format_single( $value, $field ) {
		$label = pcf_maybe_get( $field['choices'], (string) $value, $value );
		if ( 'label' === $field['return_format'] ) {
			return $label;
		}
		if ( 'array' === $field['return_format'] ) {
			return array( 'value' => $value, 'label' => $label );
		}
		return $value;
	}

	protected function render_options_list( $field, $input_type ) {
		$values = array_map( 'strval', pcf_get_array( $field['value'] ) );
		$name   = $field['input_name'] . ( 'checkbox' === $input_type ? '[]' : '' );
		$layout = pcf_maybe_get( $field, 'layout', 'vertical' );
		printf( '<input type="hidden" name="%s" value="" />', esc_attr( $field['input_name'] ) );
		printf( '<ul class="pcf-choice-list pcf-%s">', esc_attr( $layout ) );
		if ( 'checkbox' === $input_type && ! empty( $field['toggle'] ) ) {
			echo '<li><label><input type="checkbox" class="pcf-toggle-all" /> ' . esc_html__( 'Todos', 'pcf' ) . '</label></li>';
		}
		$i = 0;
		foreach ( $field['choices'] as $val => $label ) {
			printf(
				'<li><label><input type="%s" id="%s" name="%s" value="%s"%s /> %s</label></li>',
				esc_attr( $input_type ),
				esc_attr( $field['id'] . '-' . $i++ ),
				esc_attr( $name ),
				esc_attr( $val ),
				checked( in_array( (string) $val, $values, true ), true, false ),
				esc_html( $label )
			);
		}
		// Valores personalizados guardados.
		foreach ( array_diff( $values, array_map( 'strval', array_keys( $field['choices'] ) ) ) as $custom ) {
			if ( '' === $custom ) {
				continue;
			}
			printf( '<li><label><input type="%s" name="%s" value="%s" checked /> <input type="text" class="pcf-custom-value" value="%3$s" /></label></li>', esc_attr( $input_type ), esc_attr( $name ), esc_attr( $custom ) );
		}
		echo '</ul>';
		if ( ! empty( $field['allow_custom'] ) || ! empty( $field['other_choice'] ) ) {
			printf( '<p><button type="button" class="button-link pcf-add-custom" data-name="%s" data-type="%s">+ %s</button></p>', esc_attr( $name ), esc_attr( $input_type ), esc_html__( 'Otro valor', 'pcf' ) );
		}
	}
}

class PCF_Field_Select extends PCF_Field_Choice_Base {
	protected function setup() {
		$this->name        = 'select';
		$this->label       = 'Selección';
		$this->category    = 'choice';
		$this->description = 'Desplegable (simple o múltiple).';
		$this->settings    = array_merge(
			$this->choice_settings(),
			array(
				'allow_null'  => self::s( array( 'integer', 'boolean' ), 0, 'Permitir vacío.' ),
				'multiple'    => self::s( array( 'integer', 'boolean' ), 0, 'Selección múltiple.' ),
				'ui'          => self::s( array( 'integer', 'boolean' ), 0, 'UI mejorada.' ),
				'ajax'        => self::s( array( 'integer', 'boolean' ), 0, 'Carga AJAX (compatibilidad).' ),
				'placeholder' => self::s( 'string', '', 'Texto de opción vacía.' ),
			)
		);
	}

	public function value_format() {
		return 'string, o array si multiple=1';
	}

	public function render_field( $field ) {
		$values   = array_map( 'strval', pcf_get_array( $field['value'] ) );
		$multiple = ! empty( $field['multiple'] );
		if ( $multiple ) {
			printf( '<input type="hidden" name="%s" value="" />', esc_attr( $field['input_name'] ) );
		}
		printf(
			'<select id="%s" name="%s"%s>',
			esc_attr( $field['id'] ),
			esc_attr( $field['input_name'] . ( $multiple ? '[]' : '' ) ),
			$multiple ? ' multiple size="6"' : ''
		);
		if ( ! $multiple && ( $field['allow_null'] || '' !== $field['placeholder'] || ! $values ) ) {
			printf( '<option value="">%s</option>', esc_html( $field['placeholder'] ? $field['placeholder'] : '— ' . __( 'Seleccionar', 'pcf' ) . ' —' ) );
		}
		foreach ( $field['choices'] as $val => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $val ), selected( in_array( (string) $val, $values, true ), true, false ), esc_html( $label ) );
		}
		echo '</select>';
	}
}

class PCF_Field_Checkbox extends PCF_Field_Choice_Base {
	protected $multiple = true;

	protected function setup() {
		$this->name        = 'checkbox';
		$this->label       = 'Casillas';
		$this->category    = 'choice';
		$this->description = 'Varias casillas.';
		$this->settings    = array_merge(
			$this->choice_settings(),
			array(
				'layout'       => self::s( 'string', 'vertical', 'Disposición.', array( 'vertical', 'horizontal' ) ),
				'toggle'       => self::s( array( 'integer', 'boolean' ), 0, 'Casilla "todos".' ),
				'allow_custom' => self::s( array( 'integer', 'boolean' ), 0, 'Permitir valores personalizados.' ),
				'save_custom'  => self::s( array( 'integer', 'boolean' ), 0, 'Guardar valores personalizados en choices.' ),
			)
		);
	}

	public function render_field( $field ) {
		$this->render_options_list( $field, 'checkbox' );
	}
}

class PCF_Field_Radio extends PCF_Field_Choice_Base {
	protected function setup() {
		$this->name        = 'radio';
		$this->label       = 'Botones de radio';
		$this->category    = 'choice';
		$this->description = 'Una opción entre varias.';
		$this->settings    = array_merge(
			$this->choice_settings(),
			array(
				'layout'            => self::s( 'string', 'vertical', 'Disposición.', array( 'vertical', 'horizontal' ) ),
				'allow_null'        => self::s( array( 'integer', 'boolean' ), 0, 'Permitir vacío.' ),
				'other_choice'      => self::s( array( 'integer', 'boolean' ), 0, 'Opción "otro".' ),
				'save_other_choice' => self::s( array( 'integer', 'boolean' ), 0, 'Guardar "otro" en choices.' ),
			)
		);
	}

	public function render_field( $field ) {
		$this->render_options_list( $field, 'radio' );
	}
}

class PCF_Field_Button_Group extends PCF_Field_Choice_Base {
	protected function setup() {
		$this->name        = 'button_group';
		$this->label       = 'Grupo de botones';
		$this->category    = 'choice';
		$this->description = 'Una opción, mostrada como botones.';
		$this->settings    = array_merge(
			$this->choice_settings(),
			array(
				'allow_null' => self::s( array( 'integer', 'boolean' ), 0, 'Permitir vacío.' ),
				'layout'     => self::s( 'string', 'horizontal', 'Disposición.', array( 'vertical', 'horizontal' ) ),
			)
		);
	}

	public function render_field( $field ) {
		$field['layout'] = 'buttons ' . $field['layout'];
		$this->render_options_list( $field, 'radio' );
	}
}

class PCF_Field_True_False extends PCF_Field {
	protected function setup() {
		$this->name        = 'true_false';
		$this->label       = 'Verdadero / Falso';
		$this->category    = 'choice';
		$this->description = 'Interruptor. get_field devuelve bool.';
		$this->settings    = array(
			'message'       => self::s( 'string', '', 'Texto junto a la casilla.' ),
			'default_value' => self::s( array( 'integer', 'boolean' ), 0, 'Valor por defecto.' ),
			'ui'            => self::s( array( 'integer', 'boolean' ), 0, 'Mostrar como interruptor.' ),
			'ui_on_text'    => self::s( 'string', '', 'Texto activo.' ),
			'ui_off_text'   => self::s( 'string', '', 'Texto inactivo.' ),
		);
	}

	public function value_format() {
		return 'boolean';
	}

	public function render_field( $field ) {
		printf( '<input type="hidden" name="%s" value="0" />', esc_attr( $field['input_name'] ) );
		printf(
			'<label class="pcf-switch%s"><input type="checkbox" id="%s" name="%s" value="1"%s /> <span>%s</span></label>',
			$field['ui'] ? ' is-ui' : '',
			esc_attr( $field['id'] ),
			esc_attr( $field['input_name'] ),
			checked( (bool) $field['value'], true, false ),
			esc_html( $field['message'] )
		);
	}

	public function update_value( $value, $post_id, $field ) {
		if ( is_string( $value ) ) {
			$value = ! in_array( strtolower( $value ), array( '', '0', 'false', 'no', 'off' ), true );
		}
		return $value ? '1' : '0';
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		return (bool) $value;
	}
}
