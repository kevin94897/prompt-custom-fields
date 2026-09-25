<?php
/**
 * Campos avanzados.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_Field_Google_Map extends PCF_Field {
	protected function setup() {
		$this->name        = 'google_map';
		$this->label       = 'Google Map';
		$this->category    = 'advanced';
		$this->description = 'Dirección con coordenadas.';
		$this->settings    = array(
			'center_lat' => self::s( array( 'number', 'string' ), '', 'Latitud inicial.' ),
			'center_lng' => self::s( array( 'number', 'string' ), '', 'Longitud inicial.' ),
			'zoom'       => self::s( array( 'integer', 'string' ), '', 'Zoom.' ),
			'height'     => self::s( array( 'integer', 'string' ), '', 'Alto del mapa.' ),
		);
	}

	public function value_format() {
		return 'object {"address":"…","lat":-12.09,"lng":-77.03,"zoom":14} o string dirección';
	}

	public function render_field( $field ) {
		$v = wp_parse_args( is_array( $field['value'] ) ? $field['value'] : array(), array( 'address' => '', 'lat' => '', 'lng' => '', 'zoom' => '' ) );
		$n = $field['input_name'];
		echo '<div class="pcf-map-inputs">';
		printf( '<input type="text" class="widefat" name="%s[address]" value="%s" placeholder="%s" />', esc_attr( $n ), esc_attr( $v['address'] ), esc_attr__( 'Dirección', 'pcf' ) );
		printf( ' <input type="text" name="%s[lat]" value="%s" placeholder="lat" size="10" />', esc_attr( $n ), esc_attr( $v['lat'] ) );
		printf( ' <input type="text" name="%s[lng]" value="%s" placeholder="lng" size="10" />', esc_attr( $n ), esc_attr( $v['lng'] ) );
		printf( ' <input type="number" name="%s[zoom]" value="%s" placeholder="zoom" style="width:5em" />', esc_attr( $n ), esc_attr( $v['zoom'] ) );
		if ( $v['lat'] && $v['lng'] ) {
			printf( ' <a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( 'https://www.google.com/maps?q=' . rawurlencode( $v['lat'] . ',' . $v['lng'] ) ), esc_html__( 'Ver mapa', 'pcf' ) );
		}
		echo '</div>';
	}

	public function update_value( $value, $post_id, $field ) {
		if ( is_string( $value ) ) {
			$value = pcf_json_decode( $value ) ? pcf_json_decode( $value ) : array( 'address' => $value );
		}
		$value = (array) $value;
		if ( empty( $value['address'] ) && empty( $value['lat'] ) ) {
			return '';
		}
		foreach ( array( 'lat', 'lng' ) as $k ) {
			if ( isset( $value[ $k ] ) && '' !== $value[ $k ] ) {
				$value[ $k ] = (float) $value[ $k ];
			}
		}
		if ( isset( $value['zoom'] ) && '' !== $value['zoom'] ) {
			$value['zoom'] = (int) $value['zoom'];
		}
		return array_map( function ( $v ) { return is_string( $v ) ? sanitize_text_field( $v ) : $v; }, $value );
	}
}

abstract class PCF_Field_Date_Base extends PCF_Field {
	protected $store = 'Ymd';
	protected $html  = 'date';
	protected $html_format = 'Y-m-d';

	public function value_format() {
		return 'string fecha (cualquier formato que entienda strtotime; se guarda como ' . $this->store . ')';
	}

	protected function parse( $value ) {
		if ( '' === $value || null === $value ) {
			return null;
		}
		$value = (string) $value;
		if ( 'Ymd' === $this->store && preg_match( '/^\d{8}$/', $value ) ) {
			$dt = DateTime::createFromFormat( '!Ymd', $value, wp_timezone() );
		} elseif ( 'H:i:s' === $this->store ) {
			$dt = DateTime::createFromFormat( 'H:i:s', strlen( $value ) === 5 ? $value . ':00' : $value, wp_timezone() );
		} else {
			try {
				$dt = new DateTime( $value, wp_timezone() );
			} catch ( Exception $e ) {
				$dt = false;
			}
		}
		return $dt ? $dt : null;
	}

	public function render_field( $field ) {
		$dt = $this->parse( $field['value'] );
		printf(
			'<input type="%s" id="%s" name="%s" value="%s"%s />',
			esc_attr( $this->html ),
			esc_attr( $field['id'] ),
			esc_attr( $field['input_name'] ),
			esc_attr( $dt ? $dt->format( $this->html_format ) : '' ),
			'date' === $this->html ? '' : ' step="1"'
		);
	}

	public function validate_value( $valid, $value, $field ) {
		return $this->parse( $value ) ? $valid : sprintf( '%s: fecha/hora no válida.', $field['label'] );
	}

	public function update_value( $value, $post_id, $field ) {
		$dt = $this->parse( $value );
		return $dt ? $dt->format( $this->store ) : '';
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		$dt = $this->parse( $value );
		if ( ! $dt ) {
			return $value;
		}
		return wp_date( $field['return_format'], $dt->getTimestamp(), wp_timezone() );
	}
}

class PCF_Field_Date_Picker extends PCF_Field_Date_Base {
	protected function setup() {
		$this->name        = 'date_picker';
		$this->label       = 'Selector de fecha';
		$this->category    = 'advanced';
		$this->description = 'Fecha (se guarda Ymd como ACF).';
		$this->settings    = array(
			'display_format' => self::s( 'string', 'd/m/Y', 'Formato de visualización.' ),
			'return_format'  => self::s( 'string', 'd/m/Y', 'Formato PHP devuelto por get_field.' ),
			'first_day'      => self::s( 'integer', 1, 'Primer día de la semana.' ),
		);
	}
}

class PCF_Field_Date_Time_Picker extends PCF_Field_Date_Base {
	protected $store       = 'Y-m-d H:i:s';
	protected $html        = 'datetime-local';
	protected $html_format = 'Y-m-d\TH:i:s';

	protected function setup() {
		$this->name        = 'date_time_picker';
		$this->label       = 'Selector de fecha y hora';
		$this->category    = 'advanced';
		$this->description = 'Fecha y hora (se guarda Y-m-d H:i:s).';
		$this->settings    = array(
			'display_format' => self::s( 'string', 'd/m/Y g:i a', 'Formato de visualización.' ),
			'return_format'  => self::s( 'string', 'd/m/Y g:i a', 'Formato PHP devuelto.' ),
			'first_day'      => self::s( 'integer', 1, 'Primer día de la semana.' ),
		);
	}
}

class PCF_Field_Time_Picker extends PCF_Field_Date_Base {
	protected $store       = 'H:i:s';
	protected $html        = 'time';
	protected $html_format = 'H:i:s';

	protected function setup() {
		$this->name        = 'time_picker';
		$this->label       = 'Selector de hora';
		$this->category    = 'advanced';
		$this->description = 'Hora (se guarda H:i:s).';
		$this->settings    = array(
			'display_format' => self::s( 'string', 'g:i a', 'Formato de visualización.' ),
			'return_format'  => self::s( 'string', 'g:i a', 'Formato PHP devuelto.' ),
		);
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		$dt = $this->parse( $value );
		return $dt ? $dt->format( $field['return_format'] ) : $value;
	}
}

class PCF_Field_Color_Picker extends PCF_Field {
	protected function setup() {
		$this->name        = 'color_picker';
		$this->label       = 'Selector de color';
		$this->category    = 'advanced';
		$this->description = 'Color hex o rgba.';
		$this->settings    = array(
			'default_value'  => self::s( 'string', '', 'Color por defecto.' ),
			'enable_opacity' => self::s( array( 'integer', 'boolean' ), 0, 'Permitir transparencia (rgba).' ),
			'return_format'  => self::s( 'string', 'string', 'Formato devuelto.', array( 'string', 'array' ) ),
		);
	}

	public function value_format() {
		return 'string "#rrggbb" o "rgba(r,g,b,a)"';
	}

	public function render_field( $field ) {
		printf(
			'<input type="text" class="pcf-color" id="%s" name="%s" value="%s"%s />',
			esc_attr( $field['id'] ),
			esc_attr( $field['input_name'] ),
			esc_attr( is_scalar( $field['value'] ) ? $field['value'] : '' ),
			$field['enable_opacity'] ? ' data-alpha-enabled="true"' : ''
		);
	}

	public function validate_value( $valid, $value, $field ) {
		if ( ! preg_match( '/^(#[0-9a-f]{3,8}|rgba?\([\d\s.,%]+\))$/i', trim( (string) $value ) ) ) {
			return sprintf( '%s: color no válido.', $field['label'] );
		}
		return $valid;
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		if ( 'array' !== $field['return_format'] || ! $value ) {
			return $value;
		}
		$value = trim( $value );
		if ( preg_match( '/^rgba?\(([^)]+)\)$/', $value, $m ) ) {
			$p = array_map( 'trim', explode( ',', $m[1] ) );
			return array( 'red' => (int) $p[0], 'green' => (int) $p[1], 'blue' => (int) $p[2], 'alpha' => isset( $p[3] ) ? (float) $p[3] : 1 );
		}
		$hex = ltrim( $value, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return array( 'red' => hexdec( substr( $hex, 0, 2 ) ), 'green' => hexdec( substr( $hex, 2, 2 ) ), 'blue' => hexdec( substr( $hex, 4, 2 ) ), 'alpha' => 1 );
	}
}

class PCF_Field_Icon_Picker extends PCF_Field {
	protected function setup() {
		$this->name        = 'icon_picker';
		$this->label       = 'Selector de icono';
		$this->category    = 'advanced';
		$this->description = 'Dashicon, URL o imagen de la biblioteca.';
		$this->settings    = array(
			'tabs'          => self::s( 'array', array( 'dashicons', 'media_library', 'url' ), 'Orígenes permitidos.' ),
			'return_format' => self::s( 'string', 'string', 'Formato devuelto.', array( 'string', 'array' ) ),
			'default_value' => self::s( array( 'object', 'string' ), '', 'Valor por defecto.' ),
		);
	}

	public function value_format() {
		return 'object {"type":"dashicons|url|media_library","value":"dashicons-star-filled | https://… | 123"} o string "dashicons-…"';
	}

	public function render_field( $field ) {
		$v = is_array( $field['value'] ) ? $field['value'] : array( 'type' => 'dashicons', 'value' => (string) $field['value'] );
		$v = wp_parse_args( $v, array( 'type' => 'dashicons', 'value' => '' ) );
		$n = $field['input_name'];
		echo '<div class="pcf-icon-picker">';
		printf( '<select name="%s[type]">', esc_attr( $n ) );
		foreach ( (array) $field['tabs'] as $tab ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $tab ), selected( $v['type'], $tab, false ), esc_html( $tab ) );
		}
		echo '</select> ';
		printf( '<input type="text" name="%s[value]" value="%s" placeholder="dashicons-star-filled" /> ', esc_attr( $n ), esc_attr( $v['value'] ) );
		if ( 'dashicons' === $v['type'] && $v['value'] ) {
			printf( '<span class="dashicons %s"></span>', esc_attr( $v['value'] ) );
		}
		echo ' <a href="https://developer.wordpress.org/resource/dashicons/" target="_blank" rel="noopener">Dashicons</a></div>';
	}

	public function update_value( $value, $post_id, $field ) {
		if ( is_string( $value ) ) {
			$value = array( 'type' => preg_match( '#^https?://#', $value ) ? 'url' : ( is_numeric( $value ) ? 'media_library' : 'dashicons' ), 'value' => $value );
		}
		$value = wp_parse_args( (array) $value, array( 'type' => 'dashicons', 'value' => '' ) );
		if ( '' === $value['value'] ) {
			return '';
		}
		return array( 'type' => sanitize_key( $value['type'] ), 'value' => sanitize_text_field( $value['value'] ) );
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		if ( empty( $value ) || 'array' === $field['return_format'] ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			if ( 'media_library' === $value['type'] ) {
				return wp_get_attachment_url( (int) $value['value'] );
			}
			return $value['value'];
		}
		return $value;
	}
}
