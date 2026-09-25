<?php
/**
 * Campos de layout y contenedores (incluye los "PRO": repeater, flexible, clone).
 * Los valores se guardan con el formato de ACF:
 *   repeater:  nombre = nº filas, nombre_0_sub = valor
 *   flexible:  nombre = ["layout_a","layout_b"], nombre_0_sub = valor
 *   group:     nombre = "", nombre_sub = valor
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

class PCF_Field_Message extends PCF_Field {
	public $has_value = false;

	protected function setup() {
		$this->name        = 'message';
		$this->label       = 'Mensaje';
		$this->category    = 'layout';
		$this->description = 'Texto informativo en el formulario (no guarda valor).';
		$this->settings    = array(
			'message'   => self::s( 'string', '', 'Contenido.' ),
			'new_lines' => self::s( 'string', 'wpautop', 'Saltos de línea.', array( '', 'wpautop', 'br' ) ),
			'esc_html'  => self::s( array( 'integer', 'boolean' ), 0, 'Escapar HTML.' ),
		);
	}

	public function value_format() {
		return 'sin valor';
	}

	public function render_field( $field ) {
		$m = $field['esc_html'] ? esc_html( $field['message'] ) : wp_kses_post( $field['message'] );
		if ( 'wpautop' === $field['new_lines'] ) {
			$m = wpautop( $m );
		} elseif ( 'br' === $field['new_lines'] ) {
			$m = nl2br( $m );
		}
		echo $m; // phpcs:ignore
	}

	public function update_value( $value, $post_id, $field ) {
		return null;
	}
}

class PCF_Field_Tab extends PCF_Field_Message {
	protected function setup() {
		$this->name        = 'tab';
		$this->label       = 'Pestaña';
		$this->category    = 'layout';
		$this->description = 'Agrupa los campos siguientes en una pestaña.';
		$this->settings    = array(
			'placement' => self::s( 'string', 'top', 'Posición.', array( 'top', 'left' ) ),
			'endpoint'  => self::s( array( 'integer', 'boolean' ), 0, 'Inicia un nuevo grupo de pestañas.' ),
			'selected'  => self::s( array( 'integer', 'boolean' ), 0, 'Abierta por defecto.' ),
		);
	}

	public function render_field( $field ) {}
}

class PCF_Field_Accordion extends PCF_Field_Message {
	protected function setup() {
		$this->name        = 'accordion';
		$this->label       = 'Acordeón';
		$this->category    = 'layout';
		$this->description = 'Agrupa los campos siguientes en un acordeón.';
		$this->settings    = array(
			'open'         => self::s( array( 'integer', 'boolean' ), 0, 'Abierto por defecto.' ),
			'multi_expand' => self::s( array( 'integer', 'boolean' ), 0, 'Permitir varios abiertos.' ),
			'endpoint'     => self::s( array( 'integer', 'boolean' ), 0, 'Cierra el acordeón anterior sin abrir otro.' ),
		);
	}

	public function render_field( $field ) {}
}

class PCF_Field_Separator extends PCF_Field_Message {
	protected function setup() {
		$this->name        = 'separator';
		$this->label       = 'Separador';
		$this->category    = 'layout';
		$this->description = 'Línea divisoria.';
		$this->settings    = array();
	}

	public function render_field( $field ) {
		echo '<hr />';
	}
}

/**
 * Base de contenedores.
 */
abstract class PCF_Field_Container extends PCF_Field {

	public function value_format() {
		return 'object';
	}

	/**
	 * Carga una fila: [sub_key => valor crudo].
	 */
	protected function load_row( $sub_fields, $post_id, $prefix ) {
		$row = array();
		foreach ( $sub_fields as $sub ) {
			$type = pcf_get_field_type( $sub['type'] );
			if ( ! $type || ! $type->has_value ) {
				continue;
			}
			$row[ $sub['key'] ] = pcf_get_value( $post_id, $this->sub( $sub, $prefix ) );
		}
		return $row;
	}

	protected function format_row( $row, $sub_fields, $post_id, $prefix, $escape_html ) {
		$out = array();
		foreach ( $sub_fields as $sub ) {
			$type = pcf_get_field_type( $sub['type'] );
			if ( ! $type || ! $type->has_value ) {
				continue;
			}
			$s = $this->sub( $sub, $prefix );
			// Clones "seamless" sin prefijo se aplanan en la fila.
			if ( 'clone' === $sub['type'] && 'seamless' === pcf_maybe_get( $sub, 'display' ) && empty( $sub['prefix_name'] ) ) {
				$out = array_merge( $out, (array) pcf_format_value( pcf_maybe_get( $row, $sub['key'] ), $post_id, $s, $escape_html ) );
				continue;
			}
			$out[ $sub['name'] ] = pcf_format_value( pcf_maybe_get( $row, $sub['key'] ), $post_id, $s, $escape_html );
		}
		return $out;
	}

	protected function update_row( $row, $sub_fields, $post_id, $prefix ) {
		foreach ( $sub_fields as $sub ) {
			$s      = $this->sub( $sub, $prefix );
			$exists = false;
			$value  = pcf_row_value( $row, $s, $exists );
			if ( ! $exists && 'clone' === $sub['type'] ) {
				// Clon seamless: sus valores vienen directamente en la fila.
				$value  = $row;
				$exists = true;
			}
			if ( $exists ) {
				pcf_update_value( $value, $post_id, $s );
			} else {
				// Un subcampo ausente se vacía: evita que datos de otra fila (tras reordenar) persistan.
				pcf_delete_value( $post_id, $s );
			}
		}
	}

	protected function delete_row( $sub_fields, $post_id, $prefix ) {
		foreach ( $sub_fields as $sub ) {
			pcf_delete_value( $post_id, $this->sub( $sub, $prefix ) );
		}
	}

	protected function validate_row( $row, $sub_fields, $label ) {
		foreach ( $sub_fields as $sub ) {
			$exists = false;
			$value  = pcf_row_value( $row, $sub, $exists );
			$valid  = pcf_validate_value( $value, $sub );
			if ( true !== $valid ) {
				return $label . ' › ' . $valid;
			}
		}
		return true;
	}

	protected function sub( $sub, $prefix ) {
		return pcf_sub_field_for( $sub, $prefix );
	}

	/**
	 * Normaliza la lista de filas recibida (form o MCP).
	 */
	protected function rows_from_input( $value ) {
		if ( is_string( $value ) ) {
			$decoded = pcf_json_decode( $value );
			$value   = null === $decoded ? array() : $decoded;
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$value,
				function ( $row ) {
					return is_array( $row );
				}
			)
		);
	}
}

class PCF_Field_Group extends PCF_Field_Container {
	protected function setup() {
		$this->name        = 'group';
		$this->label       = 'Grupo';
		$this->category    = 'layout';
		$this->description = 'Agrupa subcampos bajo un nombre. get_field devuelve array asociativo.';
		$this->settings    = array(
			'sub_fields' => self::s( 'array', array(), 'Subcampos (mismo formato que los campos).' ),
			'layout'     => self::s( 'string', 'block', 'Disposición.', array( 'block', 'table', 'row' ) ),
		);
	}

	public function value_format() {
		return 'object {"subcampo": valor, ...}';
	}

	public function load_value( $value, $post_id, $field ) {
		return $this->load_row( $field['sub_fields'], $post_id, $field['name'] );
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		return $this->format_row( (array) $value, $field['sub_fields'], $post_id, $field['name'], $escape_html );
	}

	public function update_value( $value, $post_id, $field ) {
		$value = is_string( $value ) ? (array) pcf_json_decode( $value ) : (array) $value;
		$this->update_row( $value, $field['sub_fields'], $post_id, $field['name'] );
		return '';
	}

	public function delete_value( $post_id, $field ) {
		$this->delete_row( $field['sub_fields'], $post_id, $field['name'] );
	}

	public function validate_value( $valid, $value, $field ) {
		return $this->validate_row( (array) $value, $field['sub_fields'], $field['label'] );
	}

	public function render_field( $field ) {
		printf( '<div class="pcf-group pcf-layout-%s">', esc_attr( $field['layout'] ) );
		PCF_Renderer::render_fields( $field['sub_fields'], $field['post_id'], $field['input_name'], (array) $field['value'] );
		echo '</div>';
	}
}

class PCF_Field_Repeater extends PCF_Field_Container {
	protected function setup() {
		$this->name        = 'repeater';
		$this->label       = 'Repetidor';
		$this->category    = 'layout';
		$this->description = 'Filas repetibles de subcampos. get_field devuelve array de filas; en plantillas usar have_rows()/the_row().';
		$this->settings    = array(
			'sub_fields'    => self::s( 'array', array(), 'Subcampos de cada fila.' ),
			'min'           => self::s( array( 'integer', 'string' ), 0, 'Mínimo de filas.' ),
			'max'           => self::s( array( 'integer', 'string' ), 0, 'Máximo de filas.' ),
			'layout'        => self::s( 'string', 'table', 'Disposición.', array( 'table', 'block', 'row' ) ),
			'button_label'  => self::s( 'string', '', 'Texto del botón añadir.' ),
			'pagination'    => self::s( array( 'integer', 'boolean' ), 0, 'Paginación (compatibilidad).' ),
			'rows_per_page' => self::s( array( 'integer', 'string' ), 20, 'Filas por página.' ),
		);
	}

	public function value_format() {
		return 'array de filas: [{"subcampo": valor}, ...]';
	}

	public function load_value( $value, $post_id, $field ) {
		$count = is_numeric( $value ) ? (int) $value : ( is_array( $value ) ? count( $value ) : 0 );
		$rows  = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$rows[] = $this->load_row( $field['sub_fields'], $post_id, $field['name'] . '_' . $i );
		}
		return $rows;
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		if ( empty( $value ) || ! is_array( $value ) ) {
			return false;
		}
		$out = array();
		foreach ( array_values( $value ) as $i => $row ) {
			$out[] = $this->format_row( $row, $field['sub_fields'], $post_id, $field['name'] . '_' . $i, $escape_html );
		}
		return $out;
	}

	public function update_value( $value, $post_id, $field ) {
		$rows = $this->rows_from_input( $value );
		$old  = (int) pcf_get_metadata( $post_id, $field['name'] );
		foreach ( $rows as $i => $row ) {
			$this->update_row( $row, $field['sub_fields'], $post_id, $field['name'] . '_' . $i );
		}
		for ( $i = count( $rows ); $i < $old; $i++ ) {
			$this->delete_row( $field['sub_fields'], $post_id, $field['name'] . '_' . $i );
		}
		return count( $rows );
	}

	public function delete_value( $post_id, $field ) {
		$old = (int) pcf_get_metadata( $post_id, $field['name'] );
		for ( $i = 0; $i < $old; $i++ ) {
			$this->delete_row( $field['sub_fields'], $post_id, $field['name'] . '_' . $i );
		}
	}

	public function validate_value( $valid, $value, $field ) {
		$rows  = $this->rows_from_input( $value );
		$count = count( $rows );
		if ( $field['min'] && $count < (int) $field['min'] ) {
			return sprintf( '%s: mínimo %d filas.', $field['label'], $field['min'] );
		}
		if ( $field['max'] && $count > (int) $field['max'] ) {
			return sprintf( '%s: máximo %d filas.', $field['label'], $field['max'] );
		}
		foreach ( $rows as $i => $row ) {
			$r = $this->validate_row( $row, $field['sub_fields'], sprintf( '%s (fila %d)', $field['label'], $i + 1 ) );
			if ( true !== $r ) {
				return $r;
			}
		}
		return $valid;
	}

	public function render_field( $field ) {
		$rows        = is_array( $field['value'] ) ? array_values( $field['value'] ) : array();
		$placeholder = '__row_' . $field['key'] . '__';
		printf(
			'<div class="pcf-repeater pcf-layout-%s" data-min="%d" data-max="%d" data-placeholder="%s">',
			esc_attr( $field['layout'] ),
			(int) $field['min'],
			(int) $field['max'],
			esc_attr( $placeholder )
		);
		printf( '<input type="hidden" name="%s" value="" />', esc_attr( $field['input_name'] ) );
		echo '<div class="pcf-rows">';
		foreach ( $rows as $i => $row ) {
			$this->render_row( $field, $row, $field['input_name'] . '[row-' . $i . ']', $i + 1 );
		}
		echo '</div><template class="pcf-row-template">';
		$this->render_row( $field, array(), $field['input_name'] . '[' . $placeholder . ']', '#' );
		echo '</template>';
		printf( '<p class="pcf-actions"><button type="button" class="button pcf-add-row">%s</button></p>', esc_html( $field['button_label'] ? $field['button_label'] : __( 'Añadir fila', 'pcf' ) ) );
		echo '</div>';
	}

	protected function render_row( $field, $row, $prefix, $num ) {
		echo '<div class="pcf-row">';
		printf( '<div class="pcf-row-handle" title="%s"><span class="pcf-row-num">%s</span></div>', esc_attr__( 'Arrastrar para ordenar', 'pcf' ), esc_html( $num ) );
		echo '<div class="pcf-row-fields">';
		PCF_Renderer::render_fields( $field['sub_fields'], $field['post_id'], $prefix, $row );
		echo '</div><div class="pcf-row-tools">';
		// Sin botón de contraer: una fila de repetidor se lee de un vistazo y
		// plegarla solo escondía campos. El contenido flexible sí lo conserva,
		// porque ahí cada fila puede ser una sección entera.
		printf( '<button type="button" class="pcf-row-duplicate" aria-label="%s"><span class="dashicons dashicons-admin-page"></span></button>', esc_attr__( 'Duplicar', 'pcf' ) );
		printf( '<button type="button" class="pcf-row-remove" aria-label="%s"><span class="dashicons dashicons-trash"></span></button>', esc_attr__( 'Eliminar fila', 'pcf' ) );
		echo '</div></div>';
	}
}

class PCF_Field_Flexible_Content extends PCF_Field_Container {
	protected function setup() {
		$this->name        = 'flexible_content';
		$this->label       = 'Contenido flexible';
		$this->category    = 'layout';
		$this->description = 'Secciones con distintos layouts. Cada fila lleva "acf_fc_layout" con el name del layout.';
		$this->settings    = array(
			'layouts'      => self::s( 'array', array(), 'Layouts: [{"name":"hero","label":"Hero","display":"block","sub_fields":[...],"min":"","max":""}].' ),
			'min'          => self::s( array( 'integer', 'string' ), '', 'Mínimo de filas.' ),
			'max'          => self::s( array( 'integer', 'string' ), '', 'Máximo de filas.' ),
			'button_label' => self::s( 'string', '', 'Texto del botón añadir.' ),
		);
	}

	public function value_format() {
		return 'array de filas: [{"acf_fc_layout":"hero","titulo":"…"}, ...] (también acepta "layout")';
	}

	protected function get_layout( $field, $name ) {
		foreach ( (array) $field['layouts'] as $layout ) {
			if ( $layout['name'] === $name || $layout['key'] === $name ) {
				return $layout;
			}
		}
		return null;
	}

	protected function row_layout( $row ) {
		foreach ( array( 'acf_fc_layout', 'pcf_fc_layout', 'layout' ) as $k ) {
			if ( ! empty( $row[ $k ] ) && is_string( $row[ $k ] ) ) {
				return $row[ $k ];
			}
		}
		return '';
	}

	public function load_value( $value, $post_id, $field ) {
		$rows = array();
		foreach ( array_values( pcf_get_array( $value ) ) as $i => $name ) {
			$layout = $this->get_layout( $field, $name );
			if ( ! $layout ) {
				continue;
			}
			$rows[] = array_merge( array( 'acf_fc_layout' => $layout['name'] ), $this->load_row( $layout['sub_fields'], $post_id, $field['name'] . '_' . $i ) );
		}
		return $rows;
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		if ( empty( $value ) || ! is_array( $value ) ) {
			return false;
		}
		$out = array();
		foreach ( array_values( $value ) as $i => $row ) {
			$layout = $this->get_layout( $field, $this->row_layout( $row ) );
			if ( ! $layout ) {
				continue;
			}
			$out[] = array_merge( array( 'acf_fc_layout' => $layout['name'] ), $this->format_row( $row, $layout['sub_fields'], $post_id, $field['name'] . '_' . $i, $escape_html ) );
		}
		return $out;
	}

	public function update_value( $value, $post_id, $field ) {
		$rows  = $this->rows_from_input( $value );
		$old   = array_values( pcf_get_array( pcf_get_metadata( $post_id, $field['name'] ) ) );
		$names = array();
		$i     = 0;
		foreach ( $rows as $row ) {
			$layout = $this->get_layout( $field, $this->row_layout( $row ) );
			if ( ! $layout ) {
				continue;
			}
			// Si el layout de ese índice cambió, limpiar los valores anteriores.
			if ( isset( $old[ $i ] ) && $old[ $i ] !== $layout['name'] && ( $prev = $this->get_layout( $field, $old[ $i ] ) ) ) {
				$this->delete_row( $prev['sub_fields'], $post_id, $field['name'] . '_' . $i );
			}
			$this->update_row( $row, $layout['sub_fields'], $post_id, $field['name'] . '_' . $i );
			$names[] = $layout['name'];
			$i++;
		}
		for ( $j = $i; $j < count( $old ); $j++ ) {
			$prev = $this->get_layout( $field, $old[ $j ] );
			if ( $prev ) {
				$this->delete_row( $prev['sub_fields'], $post_id, $field['name'] . '_' . $j );
			}
		}
		return $names ? $names : '';
	}

	public function delete_value( $post_id, $field ) {
		foreach ( array_values( pcf_get_array( pcf_get_metadata( $post_id, $field['name'] ) ) ) as $i => $name ) {
			$layout = $this->get_layout( $field, $name );
			if ( $layout ) {
				$this->delete_row( $layout['sub_fields'], $post_id, $field['name'] . '_' . $i );
			}
		}
	}

	public function validate_value( $valid, $value, $field ) {
		$rows  = $this->rows_from_input( $value );
		$count = count( $rows );
		if ( '' !== $field['min'] && $count < (int) $field['min'] ) {
			return sprintf( '%s: mínimo %d filas.', $field['label'], $field['min'] );
		}
		if ( '' !== $field['max'] && $field['max'] && $count > (int) $field['max'] ) {
			return sprintf( '%s: máximo %d filas.', $field['label'], $field['max'] );
		}
		$counts = array();
		foreach ( $rows as $i => $row ) {
			$name   = $this->row_layout( $row );
			$layout = $this->get_layout( $field, $name );
			if ( ! $layout ) {
				$names = wp_list_pluck( (array) $field['layouts'], 'name' );
				return sprintf( '%s (fila %d): layout "%s" no existe. Disponibles: %s', $field['label'], $i + 1, $name, implode( ', ', $names ) );
			}
			$counts[ $layout['name'] ] = pcf_maybe_get( $counts, $layout['name'], 0 ) + 1;
			$r                         = $this->validate_row( $row, $layout['sub_fields'], sprintf( '%s (fila %d, %s)', $field['label'], $i + 1, $layout['label'] ) );
			if ( true !== $r ) {
				return $r;
			}
		}
		foreach ( (array) $field['layouts'] as $layout ) {
			$c = pcf_maybe_get( $counts, $layout['name'], 0 );
			if ( '' !== $layout['max'] && $layout['max'] && $c > (int) $layout['max'] ) {
				return sprintf( '%s: máximo %d "%s".', $field['label'], $layout['max'], $layout['label'] );
			}
			if ( '' !== $layout['min'] && $layout['min'] && $c < (int) $layout['min'] ) {
				return sprintf( '%s: mínimo %d "%s".', $field['label'], $layout['min'], $layout['label'] );
			}
		}
		return $valid;
	}

	public function render_field( $field ) {
		$rows        = is_array( $field['value'] ) ? array_values( $field['value'] ) : array();
		$placeholder = '__row_' . $field['key'] . '__';
		printf( '<div class="pcf-flexible" data-min="%s" data-max="%s" data-placeholder="%s">', esc_attr( $field['min'] ), esc_attr( $field['max'] ), esc_attr( $placeholder ) );
		printf( '<input type="hidden" name="%s" value="" />', esc_attr( $field['input_name'] ) );
		echo '<div class="pcf-rows">';
		foreach ( $rows as $i => $row ) {
			$layout = $this->get_layout( $field, $this->row_layout( $row ) );
			if ( $layout ) {
				$this->render_layout( $field, $layout, $row, $field['input_name'] . '[row-' . $i . ']' );
			}
		}
		echo '</div>';
		foreach ( (array) $field['layouts'] as $layout ) {
			printf( '<template class="pcf-layout-template" data-layout="%s">', esc_attr( $layout['name'] ) );
			$this->render_layout( $field, $layout, array(), $field['input_name'] . '[' . $placeholder . ']' );
			echo '</template>';
		}
		echo '<div class="pcf-actions pcf-fc-add"><button type="button" class="button button-primary pcf-fc-open">' . esc_html( $field['button_label'] ? $field['button_label'] : __( 'Añadir sección', 'pcf' ) ) . '</button><ul class="pcf-fc-menu" hidden>';
		foreach ( (array) $field['layouts'] as $layout ) {
			printf( '<li><button type="button" class="button-link pcf-add-layout" data-layout="%s">%s</button></li>', esc_attr( $layout['name'] ), esc_html( $layout['label'] ) );
		}
		echo '</ul></div></div>';
	}

	protected function render_layout( $field, $layout, $row, $prefix ) {
		printf( '<div class="pcf-row pcf-fc-layout pcf-layout-%s" data-layout="%s">', esc_attr( $layout['display'] ), esc_attr( $layout['name'] ) );
		printf( '<input type="hidden" name="%s[acf_fc_layout]" value="%s" />', esc_attr( $prefix ), esc_attr( $layout['name'] ) );
		printf( '<div class="pcf-fc-title pcf-row-handle"><span class="pcf-row-num"></span> <strong>%s</strong></div>', esc_html( $layout['label'] ) );
		echo '<div class="pcf-row-fields">';
		PCF_Renderer::render_fields( $layout['sub_fields'], $field['post_id'], $prefix, $row );
		echo '</div><div class="pcf-row-tools">';
		echo '<button type="button" class="pcf-row-toggle"><span class="dashicons dashicons-arrow-up-alt2"></span></button>';
		echo '<button type="button" class="pcf-row-duplicate"><span class="dashicons dashicons-admin-page"></span></button>';
		echo '<button type="button" class="pcf-row-remove"><span class="dashicons dashicons-trash"></span></button>';
		echo '</div></div>';
	}
}

class PCF_Field_Clone extends PCF_Field_Container {
	protected function setup() {
		$this->name        = 'clone';
		$this->label       = 'Clon';
		$this->category    = 'layout';
		$this->description = 'Reutiliza campos o grupos existentes (por key).';
		$this->settings    = array(
			'clone'        => self::s( 'array', array(), 'Keys de campos (field_…) o grupos (group_…) a clonar.' ),
			'display'      => self::s( 'string', 'seamless', 'Visualización.', array( 'seamless', 'group' ) ),
			'layout'       => self::s( 'string', 'block', 'Disposición si display=group.', array( 'block', 'table', 'row' ) ),
			'prefix_label' => self::s( array( 'integer', 'boolean' ), 0, 'Prefijar etiquetas.' ),
			'prefix_name'  => self::s( array( 'integer', 'boolean' ), 0, 'Prefijar nombres (nombre_sub).' ),
		);
	}

	public function value_format() {
		return 'object {"subcampo": valor} (en modo seamless sin prefijo, los subcampos se usan directamente por su nombre)';
	}

	/**
	 * Resuelve los campos clonados. Las keys resultantes son {clone_key}_{key}.
	 */
	public function get_cloned_fields( $field ) {
		static $depth = 0;
		if ( $depth > 5 ) {
			return array();
		}
		$depth++;
		$out = array();
		foreach ( (array) $field['clone'] as $selector ) {
			$list = array();
			if ( 0 === strpos( $selector, 'group_' ) ) {
				$list = pcf_get_fields( $selector );
			} elseif ( ( $f = pcf_get_field( $selector ) ) ) {
				$list = array( $f );
			}
			foreach ( $list as $sub ) {
				unset( $sub['parent'], $sub['_group'] );
				$sub['key'] = $field['key'] . '_' . $sub['key'];
				if ( ! empty( $field['prefix_label'] ) ) {
					$sub['label'] = $field['label'] . ' ' . $sub['label'];
				}
				$out[] = $sub;
			}
		}
		$depth--;
		return $out;
	}

	protected function uses_prefix( $field ) {
		return ! empty( $field['prefix_name'] ) || 'group' === $field['display'];
	}

	protected function sub( $sub, $prefix ) {
		if ( null === $prefix ) {
			$sub['_name'] = $sub['name'];
			return $sub;
		}
		return parent::sub( $sub, $prefix );
	}

	protected function prefix_for( $field ) {
		if ( $this->uses_prefix( $field ) ) {
			return $field['name'];
		}
		// Seamless sin prefijo dentro de un repeater: heredar el prefijo del padre.
		if ( ! empty( $field['_name'] ) && $field['_name'] !== $field['name'] ) {
			return substr( $field['name'], 0, -strlen( $field['_name'] ) - 1 );
		}
		return null;
	}

	public function load_value( $value, $post_id, $field ) {
		return $this->load_row( $this->get_cloned_fields( $field ), $post_id, $this->prefix_for( $field ) );
	}

	public function format_value( $value, $post_id, $field, $escape_html = false ) {
		return $this->format_row( (array) $value, $this->get_cloned_fields( $field ), $post_id, $this->prefix_for( $field ), $escape_html );
	}

	public function update_value( $value, $post_id, $field ) {
		$value = is_string( $value ) ? (array) pcf_json_decode( $value ) : (array) $value;
		$this->update_row( $value, $this->get_cloned_fields( $field ), $post_id, $this->prefix_for( $field ) );
		return $this->uses_prefix( $field ) ? '' : null;
	}

	public function delete_value( $post_id, $field ) {
		$this->delete_row( $this->get_cloned_fields( $field ), $post_id, $this->prefix_for( $field ) );
	}

	public function validate_value( $valid, $value, $field ) {
		return $this->validate_row( (array) $value, $this->get_cloned_fields( $field ), $field['label'] );
	}

	public function render_field( $field ) {
		printf( '<div class="pcf-clone pcf-group pcf-display-%s">', esc_attr( $field['display'] ) );
		PCF_Renderer::render_fields( $this->get_cloned_fields( $field ), $field['post_id'], $field['input_name'], (array) $field['value'] );
		echo '</div>';
	}
}
