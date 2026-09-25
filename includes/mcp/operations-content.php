<?php
/**
 * Implementación de operaciones de contenido (valores, filas, posts) y
 * generación de código de plantilla.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Convierte un valor crudo (subcampos por key) al formato que acepta update-values
 * (subcampos por name). Así get-values → update-values es un viaje de ida y vuelta.
 */
function pcf_value_to_input( $value, $field ) {
	switch ( $field['type'] ) {
		case 'repeater':
			$rows = array();
			foreach ( (array) $value as $row ) {
				$rows[] = pcf_row_to_input( $row, $field['sub_fields'] );
			}
			return $rows;
		case 'flexible_content':
			$rows = array();
			foreach ( (array) $value as $row ) {
				$name = pcf_maybe_get( $row, 'acf_fc_layout' );
				foreach ( (array) $field['layouts'] as $layout ) {
					if ( $layout['name'] === $name ) {
						$rows[] = array_merge( array( 'acf_fc_layout' => $name ), pcf_row_to_input( $row, $layout['sub_fields'] ) );
					}
				}
			}
			return $rows;
		case 'group':
			return pcf_row_to_input( (array) $value, $field['sub_fields'] );
		case 'clone':
			return pcf_row_to_input( (array) $value, pcf_get_field_type( 'clone' )->get_cloned_fields( $field ) );
		case 'true_false':
			return (bool) $value;
		case 'number':
		case 'range':
			return is_numeric( $value ) ? $value + 0 : $value;
		case 'image':
		case 'file':
			return $value ? (int) $value : null;
		case 'gallery':
			return array_map( 'intval', pcf_get_array( $value ) );
		default:
			return $value;
	}
}

function pcf_row_to_input( $row, $sub_fields ) {
	$out = array();
	foreach ( $sub_fields as $sub ) {
		$type = pcf_get_field_type( $sub['type'] );
		if ( ! $type || ! $type->has_value ) {
			continue;
		}
		$value = pcf_maybe_get( (array) $row, $sub['key'] );
		if ( 'clone' === $sub['type'] && 'seamless' === pcf_maybe_get( $sub, 'display' ) && empty( $sub['prefix_name'] ) ) {
			$out = array_merge( $out, (array) pcf_value_to_input( $value, $sub ) );
			continue;
		}
		$out[ $sub['name'] ] = pcf_value_to_input( $value, $sub );
	}
	return $out;
}

/**
 * Esquema de campos legible para la IA, con el formato de valor esperado.
 */
function pcf_op_field_schema_tree( $fields ) {
	$out = array();
	foreach ( (array) $fields as $f ) {
		$type = pcf_get_field_type( $f['type'] );
		if ( ! $type ) {
			continue;
		}
		$node = array(
			'name'   => $f['name'],
			'key'    => $f['key'],
			'label'  => $f['label'],
			'type'   => $f['type'],
			'format' => $type->value_format(),
		);
		if ( ! $type->has_value ) {
			$node['format'] = 'sin valor (' . $f['type'] . ')';
		}
		if ( ! empty( $f['required'] ) ) {
			$node['required'] = true;
		}
		if ( ! empty( $f['instructions'] ) ) {
			$node['instructions'] = $f['instructions'];
		}
		if ( ! empty( $f['choices'] ) ) {
			$node['choices'] = $f['choices'];
		}
		if ( ! empty( $f['multiple'] ) ) {
			$node['multiple'] = true;
		}
		foreach ( array( 'min', 'max', 'maxlength', 'post_type', 'taxonomy', 'return_format' ) as $k ) {
			if ( isset( $f[ $k ] ) && '' !== $f[ $k ] && array() !== $f[ $k ] && 0 !== $f[ $k ] ) {
				$node[ $k ] = $f[ $k ];
			}
		}
		if ( ! empty( $f['sub_fields'] ) ) {
			$node['sub_fields'] = pcf_op_field_schema_tree( $f['sub_fields'] );
		}
		if ( ! empty( $f['layouts'] ) ) {
			foreach ( $f['layouts'] as $l ) {
				$node['layouts'][] = array(
					'name'       => $l['name'],
					'label'      => $l['label'],
					'sub_fields' => pcf_op_field_schema_tree( $l['sub_fields'] ),
				);
			}
		}
		if ( 'clone' === $f['type'] ) {
			$node['sub_fields'] = pcf_op_field_schema_tree( pcf_get_field_type( 'clone' )->get_cloned_fields( $f ) );
			$node['seamless']   = 'seamless' === $f['display'] && empty( $f['prefix_name'] );
		}
		$out[] = $node;
	}
	return $out;
}

function pcf_op_resolve_object( $object ) {
	$post_id = pcf_get_valid_post_id( $object );
	$d       = pcf_decode_post_id( $post_id );
	$exists  = true;
	switch ( $d['type'] ) {
		case 'post':
			$exists = $d['id'] && get_post( $d['id'] );
			break;
		case 'term':
			$exists = (bool) get_term( $d['id'] );
			break;
		case 'user':
			$exists = (bool) get_userdata( $d['id'] );
			break;
		case 'comment':
			$exists = (bool) get_comment( $d['id'] );
			break;
		case 'block':
			return new WP_Error( 'pcf_invalid_object', 'Los valores de bloque viven en el contenido del post; edítalos desde el editor.' );
	}
	if ( ! $exists ) {
		return new WP_Error( 'pcf_object_not_found', sprintf( 'Objeto "%s" no encontrado.', $object ), array( 'status' => 404 ) );
	}
	return $post_id;
}

/**
 * Busca un campo por name/key dentro de los grupos visibles del objeto.
 */
function pcf_op_field_for_object( $selector, $post_id ) {
	$groups = pcf_get_field_groups( pcf_screen_for_post_id( $post_id ) );
	foreach ( $groups as $g ) {
		$by = 0 === strpos( $selector, 'field_' ) ? 'key' : 'name';
		$f  = 'key' === $by ? pcf_find_field_in( $g['fields'], $selector ) : pcf_find_top_level_by_name( $g['fields'], $selector );
		if ( $f ) {
			return $f;
		}
	}
	return pcf_get_field( $selector, $post_id );
}

/* --------------------------------------------------------------------------
 * Operaciones
 * ----------------------------------------------------------------------- */

function pcf_op_get_object_fields( $input ) {
	$post_id = pcf_op_resolve_object( $input['object'] );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}
	$groups = array();
	foreach ( pcf_get_field_groups( pcf_screen_for_post_id( $post_id ) ) as $g ) {
		$groups[] = array(
			'key'    => $g['key'],
			'title'  => $g['title'],
			'fields' => pcf_op_field_schema_tree( $g['fields'] ),
		);
	}
	return array(
		'object'       => $post_id,
		'field_groups' => $groups,
		'note'         => $groups ? 'Usa update-values con {"name": valor}.' : 'Ningún grupo aplica a este objeto. Revisa las reglas de ubicación.',
	);
}

function pcf_op_get_values( $input ) {
	$post_id = pcf_op_resolve_object( $input['object'] );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}
	$format = pcf_maybe_get( $input, 'format', 'input' );
	$names  = (array) pcf_maybe_get( $input, 'fields', array() );
	$out    = array();

	if ( $names ) {
		foreach ( $names as $name ) {
			$field = pcf_op_field_for_object( $name, $post_id );
			if ( ! $field ) {
				$out[ $name ] = null;
				continue;
			}
			$out[ $name ] = pcf_op_value_in_format( $post_id, $field, $format );
		}
	} else {
		foreach ( pcf_get_object_values( $post_id, false, true ) as $name => $field ) {
			$out[ $name ] = pcf_op_value_in_format( $post_id, $field, $format );
		}
	}
	return array( 'object' => $post_id, 'format' => $format, 'values' => $out );
}

function pcf_op_value_in_format( $post_id, $field, $format ) {
	$raw = pcf_get_value( $post_id, $field );
	if ( 'raw' === $format ) {
		return $raw;
	}
	if ( 'formatted' === $format ) {
		return pcf_format_value( $raw, $post_id, $field );
	}
	return pcf_value_to_input( $raw, $field );
}

function pcf_op_update_values( $input ) {
	$post_id = pcf_op_resolve_object( $input['object'] );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}
	$validate = ! isset( $input['validate'] ) || $input['validate'];
	$report   = array( 'object' => $post_id, 'updated' => array(), 'errors' => array() );

	foreach ( (array) $input['values'] as $selector => $value ) {
		$field = pcf_op_field_for_object( (string) $selector, $post_id );
		if ( ! $field ) {
			$report['errors'][ $selector ] = 'Campo no encontrado para este objeto. Usa get-object-fields.';
			continue;
		}
		if ( $validate ) {
			$valid = pcf_validate_value( $value, $field );
			if ( true !== $valid ) {
				$report['errors'][ $selector ] = $valid;
				continue;
			}
		}
		pcf_update_value( $value, $post_id, $field );
		$report['updated'][] = $field['name'];
	}

	do_action( 'pcf/save_post', $post_id );
	if ( pcf_acf_bridge_enabled() ) {
		do_action( 'acf/save_post', $post_id );
	}
	if ( is_numeric( $post_id ) ) {
		clean_post_cache( (int) $post_id );
	}
	return $report;
}

function pcf_op_delete_values( $input ) {
	$post_id = pcf_op_resolve_object( $input['object'] );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}
	$deleted = array();
	foreach ( (array) $input['fields'] as $name ) {
		$field = pcf_op_field_for_object( $name, $post_id );
		if ( $field ) {
			pcf_delete_value( $post_id, $field );
			$deleted[] = $name;
		}
	}
	return array( 'object' => $post_id, 'deleted' => $deleted );
}

function pcf_op_rows_context( $input ) {
	$post_id = pcf_op_resolve_object( $input['object'] );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}
	$field = pcf_op_field_for_object( $input['field'], $post_id );
	if ( ! $field || ! in_array( $field['type'], array( 'repeater', 'flexible_content' ), true ) ) {
		return new WP_Error( 'pcf_not_rows', 'El campo no existe o no es repeater / flexible_content.', array( 'status' => 400 ) );
	}
	$rows = pcf_value_to_input( pcf_get_value( $post_id, $field ), $field );
	return array( $post_id, $field, $rows );
}

function pcf_op_save_rows( $post_id, $field, $rows ) {
	$valid = pcf_validate_value( $rows, $field );
	if ( true !== $valid && array() !== $rows ) {
		return new WP_Error( 'pcf_invalid', $valid, array( 'status' => 400 ) );
	}
	pcf_update_value( $rows, $post_id, $field );
	return array( 'object' => $post_id, 'field' => $field['name'], 'row_count' => count( $rows ), 'rows' => $rows );
}

function pcf_op_add_row( $input ) {
	$ctx = pcf_op_rows_context( $input );
	if ( is_wp_error( $ctx ) ) {
		return $ctx;
	}
	list( $post_id, $field, $rows ) = $ctx;
	$new = isset( $input['rows'] ) ? (array) $input['rows'] : array( (array) $input['row'] );
	$pos = isset( $input['position'] ) ? max( 0, (int) $input['position'] - 1 ) : count( $rows );
	array_splice( $rows, $pos, 0, $new );
	return pcf_op_save_rows( $post_id, $field, $rows );
}

function pcf_op_update_row( $input ) {
	$ctx = pcf_op_rows_context( $input );
	if ( is_wp_error( $ctx ) ) {
		return $ctx;
	}
	list( $post_id, $field, $rows ) = $ctx;
	$i = (int) $input['index'] - 1;
	if ( ! isset( $rows[ $i ] ) ) {
		return new WP_Error( 'pcf_row_not_found', sprintf( 'La fila %d no existe (hay %d).', $input['index'], count( $rows ) ), array( 'status' => 404 ) );
	}
	$rows[ $i ] = array_merge( $rows[ $i ], (array) $input['row'] );
	return pcf_op_save_rows( $post_id, $field, $rows );
}

function pcf_op_delete_row( $input ) {
	$ctx = pcf_op_rows_context( $input );
	if ( is_wp_error( $ctx ) ) {
		return $ctx;
	}
	list( $post_id, $field, $rows ) = $ctx;
	$i = (int) $input['index'] - 1;
	if ( ! isset( $rows[ $i ] ) ) {
		return new WP_Error( 'pcf_row_not_found', sprintf( 'La fila %d no existe.', $input['index'] ), array( 'status' => 404 ) );
	}
	array_splice( $rows, $i, 1 );
	return pcf_op_save_rows( $post_id, $field, $rows );
}

function pcf_op_list_posts( $input ) {
	$args = array(
		'post_type'      => pcf_maybe_get( $input, 'post_type', 'any' ),
		'post_status'    => pcf_maybe_get( $input, 'status', array( 'publish', 'draft', 'pending', 'private', 'future' ) ),
		's'              => pcf_maybe_get( $input, 'search', '' ),
		'posts_per_page' => min( 100, (int) pcf_maybe_get( $input, 'per_page', 20 ) ),
		'paged'          => max( 1, (int) pcf_maybe_get( $input, 'page', 1 ) ),
		'orderby'        => 'modified',
		'order'          => 'DESC',
	);
	$q    = new WP_Query( $args );
	$out  = array();
	foreach ( $q->posts as $p ) {
		$item = array(
			'id'       => $p->ID,
			'title'    => get_the_title( $p ),
			'slug'     => $p->post_name,
			'type'     => $p->post_type,
			'status'   => $p->post_status,
			'template' => get_page_template_slug( $p ),
			'link'     => get_permalink( $p ),
			'groups'   => wp_list_pluck( pcf_get_field_groups( pcf_screen_for_post_id( $p->ID ) ), 'key' ),
		);
		if ( ! empty( $input['include_values'] ) ) {
			$item['values'] = pcf_op_get_values( array( 'object' => $p->ID ) )['values'];
		}
		$out[] = $item;
	}
	return array( 'total' => (int) $q->found_posts, 'pages' => (int) $q->max_num_pages, 'posts' => $out );
}

function pcf_op_save_post( $input ) {
	$is_update = ! empty( $input['id'] );
	if ( $is_update && ! current_user_can( 'edit_post', (int) $input['id'] ) ) {
		return new WP_Error( 'pcf_forbidden', 'No puedes editar este post.', array( 'status' => 403 ) );
	}
	$postarr = array();
	$map     = array(
		'title'   => 'post_title',
		'content' => 'post_content',
		'excerpt' => 'post_excerpt',
		'status'  => 'post_status',
		'slug'    => 'post_name',
		'parent'  => 'post_parent',
		'order'   => 'menu_order',
	);
	foreach ( $map as $in => $wp ) {
		if ( isset( $input[ $in ] ) ) {
			$postarr[ $wp ] = $input[ $in ];
		}
	}
	if ( $is_update ) {
		$postarr['ID'] = (int) $input['id'];
		$id            = wp_update_post( wp_slash( $postarr ), true );
	} else {
		$postarr['post_type'] = pcf_maybe_get( $input, 'post_type', 'post' );
		if ( ! post_type_exists( $postarr['post_type'] ) ) {
			return new WP_Error( 'pcf_invalid_post_type', 'post_type no registrado.', array( 'status' => 400 ) );
		}
		$pto = get_post_type_object( $postarr['post_type'] );
		if ( ! current_user_can( $pto->cap->create_posts ) ) {
			return new WP_Error( 'pcf_forbidden', 'No puedes crear este tipo de contenido.', array( 'status' => 403 ) );
		}
		$postarr += array( 'post_status' => 'draft' );
		$id       = wp_insert_post( wp_slash( $postarr ), true );
	}
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	if ( isset( $input['template'] ) ) {
		update_post_meta( $id, '_wp_page_template', $input['template'] );
	}
	if ( ! empty( $input['featured_image'] ) ) {
		$att = pcf_resolve_attachment_id( $input['featured_image'], $id );
		if ( $att ) {
			set_post_thumbnail( $id, $att );
		}
	}
	foreach ( (array) pcf_maybe_get( $input, 'terms', array() ) as $tax => $terms ) {
		wp_set_object_terms( $id, (array) $terms, $tax, false );
	}
	if ( isset( $input['front_page'] ) && $input['front_page'] && current_user_can( 'manage_options' ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $id );
	}
	$result = array(
		'id'     => $id,
		'status' => get_post_status( $id ),
		'link'   => get_permalink( $id ),
		'edit'   => get_edit_post_link( $id, 'raw' ),
	);
	if ( ! empty( $input['values'] ) ) {
		pcf_flush_field_cache();
		$result['values'] = pcf_op_update_values( array( 'object' => $id, 'values' => $input['values'] ) );
	}
	return $result;
}

/* --------------------------------------------------------------------------
 * Generador de código de plantilla
 * ----------------------------------------------------------------------- */

class PCF_Template_Generator {

	protected $acf;
	protected $lines = array();

	public function __construct( $acf_names = true ) {
		$this->acf = $acf_names;
	}

	protected function fn( $name ) {
		if ( $this->acf ) {
			return $name;
		}
		$map = array(
			'get_field'      => 'pcf_value',
			'get_sub_field'  => 'pcf_sub_value',
			'have_rows'      => 'pcf_have_rows',
			'the_row'        => 'pcf_the_row',
			'get_row_layout' => 'pcf_row_layout',
			'get_row_index'  => 'pcf_row_index',
		);
		return $map[ $name ];
	}

	protected function add( $depth, $line ) {
		$this->lines[] = str_repeat( "\t", $depth ) . $line;
	}

	public function generate( $fields, $post_arg = '' ) {
		$this->lines = array();
		$this->fields( $fields, 0, false, $post_arg );
		return implode( "\n", $this->lines );
	}

	protected function getter( $name, $sub, $post_arg ) {
		if ( $sub ) {
			return $this->fn( 'get_sub_field' ) . "( '{$name}' )";
		}
		return $this->fn( 'get_field' ) . "( '{$name}'" . ( $post_arg ? ", {$post_arg}" : '' ) . ' )';
	}

	protected function fields( $fields, $d, $sub, $post_arg ) {
		foreach ( $fields as $f ) {
			if ( 'clone' === $f['type'] ) {
				$cloned = pcf_get_field_type( 'clone' )->get_cloned_fields( $f );
				if ( 'seamless' === $f['display'] && empty( $f['prefix_name'] ) ) {
					$this->fields( $cloned, $d, $sub, $post_arg );
					continue;
				}
				$f['type']       = 'group';
				$f['sub_fields'] = $cloned;
			}
			$this->field( $f, $d, $sub, $post_arg );
		}
	}

	protected function field( $f, $d, $sub, $post_arg ) {
		$name = $f['name'];
		$var  = '$' . preg_replace( '/[^a-z0-9_]/', '_', $name );
		$get  = $this->getter( $name, $sub, $post_arg );
		$rf   = pcf_maybe_get( $f, 'return_format', '' );

		switch ( $f['type'] ) {
			case 'tab':
			case 'accordion':
			case 'message':
			case 'separator':
				return;

			case 'text':
			case 'email':
			case 'number':
			case 'range':
			case 'password':
			case 'date_picker':
			case 'date_time_picker':
			case 'time_picker':
			case 'radio':
			case 'button_group':
				$this->add( $d, "<?php if ( {$var} = {$get} ) : ?>" );
				$tag = 'text' === $f['type'] && preg_match( '/titul|title|heading/', $name ) ? 'h2' : 'p';
				$this->add( $d + 1, "<{$tag} class=\"{$name}\"><?php echo esc_html( " . ( 'array' === $rf ? "{$var}['label']" : $var ) . " ); ?></{$tag}>" );
				$this->add( $d, '<?php endif; ?>' );
				return;

			case 'select':
			case 'checkbox':
				$multi = 'checkbox' === $f['type'] || ! empty( $f['multiple'] );
				$this->add( $d, "<?php if ( {$var} = {$get} ) : ?>" );
				if ( $multi ) {
					$item = 'array' === $rf ? "\$item['label']" : '$item';
					$this->add( $d + 1, "<ul class=\"{$name}\">" );
					$this->add( $d + 2, "<?php foreach ( {$var} as \$item ) : ?><li><?php echo esc_html( {$item} ); ?></li><?php endforeach; ?>" );
					$this->add( $d + 1, '</ul>' );
				} else {
					$this->add( $d + 1, "<p class=\"{$name}\"><?php echo esc_html( " . ( 'array' === $rf ? "{$var}['label']" : $var ) . ' ); ?></p>' );
				}
				$this->add( $d, '<?php endif; ?>' );
				return;

			case 'textarea':
				$this->add( $d, "<?php if ( {$var} = {$get} ) : ?>" );
				$this->add( $d + 1, "<div class=\"{$name}\"><?php echo wp_kses_post( " . ( '' === pcf_maybe_get( $f, 'new_lines', '' ) ? "nl2br( {$var} )" : $var ) . ' ); ?></div>' );
				$this->add( $d, '<?php endif; ?>' );
				return;

			case 'wysiwyg':
				$this->add( $d, "<?php if ( {$var} = {$get} ) : ?>" );
				$this->add( $d + 1, "<div class=\"{$name}\"><?php echo wp_kses_post( {$var} ); ?></div>" );
				$this->add( $d, '<?php endif; ?>' );
				return;

			case 'url':
				$this->add( $d, "<?php if ( {$var} = {$get} ) : ?>" );
				$this->add( $d + 1, "<a class=\"{$name}\" href=\"<?php echo esc_url( {$var} ); ?>\"><?php echo esc_html( {$var} ); ?></a>" );
				$this->add( $d, '<?php endif; ?>' );
				return;

			case 'oembed':
				$this->add( $d, "<?php if ( {$var} = {$get} ) : ?>" );
				$this->add( $d + 1, "<div class=\"{$name} embed\"><?php echo {$var}; // HTML de oEmbed de WordPress. ?></div>" );
				$this->add( $d, '<?php endif; ?>' );
				return;

			case 'true_false':
				$this->add( $d, "<?php if ( {$get} ) : ?>" );
				$this->add( $d + 1, "<!-- {$f['label']}: activado -->" );
				$this->add( $d, '<?php endif; ?>' );
				return;

			case 'color_picker':
				$this->add( $d, "<?php {$var} = {$get}; ?>" );
				$this->add( $d, "<div class=\"{$name}\" style=\"--color: <?php echo esc_attr( " . ( 'array' === $rf ? "sprintf( 'rgba(%d,%d,%d,%s)', {$var}['red'], {$var}['green'], {$var}['blue'], {$var}['alpha'] )" : $var ) . " ); ?>\"></div>" );
				return;

			case 'image':
				$this->add( $d, "<?php if ( {$var} = {$get} ) : ?>" );
				if ( 'id' === $rf ) {
					$this->add( $d + 1, "<?php echo wp_get_attachment_image( {$var}, 'large', false, array( 'class' => '{$name}' ) ); ?>" );
				} elseif ( 'url' === $rf ) {
					$this->add( $d + 1, "<img class=\"{$name}\" src=\"<?php echo esc_url( {$var} ); ?>\" alt=\"\" loading=\"lazy\" />" );
				} else {
					$this->add( $d + 1, "<?php echo wp_get_attachment_image( {$var}['ID'], 'large', false, array( 'class' => '{$name}' ) ); ?>" );
				}
				$this->add( $d, '<?php endif; ?>' );
				return;

			case 'file':
				$this->add( $d, "<?php if ( {$var} = {$get} ) : ?>" );
				if ( 'array' === $rf || '' === $rf ) {
					$this->add( $d + 1, "<a class=\"{$name}\" href=\"<?php echo esc_url( {$var}['url'] ); ?>\" download><?php echo esc_html( {$var}['title'] ); ?></a>" );
				} else {
					$url = 'id' === $rf ? "wp_get_attachment_url( {$var} )" : $var;
					$this->add( $d + 1, "<a class=\"{$name}\" href=\"<?php echo esc_url( {$url} ); ?>\" download><?php esc_html_e( 'Descargar' ); ?></a>" );
				}
				$this->add( $d, '<?php endif; ?>' );
				return;

			case 'gallery':
				$id = 'id' === $rf ? '$image' : ( 'url' === $rf ? 'attachment_url_to_postid( $image )' : "\$image['ID']" );
				$this->add( $d, "<?php if ( {$var} = {$get} ) : ?>" );
				$this->add( $d + 1, "<div class=\"{$name} gallery\">" );
				$this->add( $d + 2, "<?php foreach ( {$var} as \$image ) : ?>" );
				$this->add( $d + 3, "<figure><?php echo wp_get_attachment_image( {$id}, 'medium_large' ); ?></figure>" );
				$this->add( $d + 2, '<?php endforeach; ?>' );
				$this->add( $d + 1, '</div>' );
				$this->add( $d, '<?php endif; ?>' );
				return;

			case 'link':
				$this->add( $d, "<?php if ( {$var} = {$get} ) : ?>" );
				if ( 'url' === $rf ) {
					$this->add( $d + 1, "<a class=\"{$name}\" href=\"<?php echo esc_url( {$var} ); ?>\"><?php echo esc_html( {$var} ); ?></a>" );
				} else {
					$this->add( $d + 1, "<a class=\"{$name}\" href=\"<?php echo esc_url( {$var}['url'] ); ?>\" target=\"<?php echo esc_attr( {$var}['target'] ?: '_self' ); ?>\"><?php echo esc_html( {$var}['title'] ?: {$var}['url'] ); ?></a>" );
				}
				$this->add( $d, '<?php endif; ?>' );
				return;

			case 'post_object':
			case 'relationship':
				$multi = 'relationship' === $f['type'] || ! empty( $f['multiple'] );
				$pid   = 'id' === $rf ? '$item' : '$item->ID';
				$this->add( $d, "<?php if ( {$var} = {$get} ) : ?>" );
				if ( $multi ) {
					$this->add( $d + 1, "<ul class=\"{$name}\">" );
					$this->add( $d + 2, "<?php foreach ( {$var} as \$item ) : ?>" );
					$this->add( $d + 3, "<li><a href=\"<?php echo esc_url( get_permalink( {$pid} ) ); ?>\"><?php echo esc_html( get_the_title( {$pid} ) ); ?></a></li>" );
					$this->add( $d + 2, '<?php endforeach; ?>' );
					$this->add( $d + 1, '</ul>' );
				} else {
					$one = 'id' === $rf ? $var : "{$var}->ID";
					$this->add( $d + 1, "<a class=\"{$name}\" href=\"<?php echo esc_url( get_permalink( {$one} ) ); ?>\"><?php echo esc_html( get_the_title( {$one} ) ); ?></a>" );
				}
				$this->add( $d, '<?php endif; ?>' );
				return;

			case 'page_link':
				$this->add( $d, "<?php if ( {$var} = {$get} ) : ?>" );
				if ( ! empty( $f['multiple'] ) ) {
					$this->add( $d + 1, "<?php foreach ( {$var} as \$url ) : ?><a href=\"<?php echo esc_url( \$url ); ?>\"><?php echo esc_html( \$url ); ?></a><?php endforeach; ?>" );
				} else {
					$this->add( $d + 1, "<a class=\"{$name}\" href=\"<?php echo esc_url( {$var} ); ?>\"><?php esc_html_e( 'Ver más' ); ?></a>" );
				}
				$this->add( $d, '<?php endif; ?>' );
				return;

			case 'taxonomy':
				$term = 'object' === $rf ? '$term' : "get_term( \$term, '{$f['taxonomy']}' )";
				$this->add( $d, "<?php if ( {$var} = {$get} ) : ?>" );
				$this->add( $d + 1, "<ul class=\"{$name}\">" );
				$this->add( $d + 2, "<?php foreach ( (array) {$var} as \$term ) : \$t = {$term}; ?>" );
				$this->add( $d + 3, '<li><a href="<?php echo esc_url( get_term_link( $t ) ); ?>"><?php echo esc_html( $t->name ); ?></a></li>' );
				$this->add( $d + 2, '<?php endforeach; ?>' );
				$this->add( $d + 1, '</ul>' );
				$this->add( $d, '<?php endif; ?>' );
				return;

			case 'user':
				$uid = 'id' === $rf ? '$u' : ( 'object' === $rf ? '$u->ID' : "\$u['ID']" );
				$this->add( $d, "<?php if ( {$var} = {$get} ) : ?>" );
				$this->add( $d + 1, "<?php foreach ( " . ( empty( $f['multiple'] ) ? "array( {$var} )" : $var ) . ' as $u ) : ?>' );
				$this->add( $d + 2, "<span class=\"{$name}\"><?php echo esc_html( get_the_author_meta( 'display_name', {$uid} ) ); ?></span>" );
				$this->add( $d + 1, '<?php endforeach; ?>' );
				$this->add( $d, '<?php endif; ?>' );
				return;

			case 'google_map':
				$this->add( $d, "<?php if ( ( {$var} = {$get} ) && ! empty( {$var}['lat'] ) ) : ?>" );
				$this->add( $d + 1, "<div class=\"{$name} map\" data-lat=\"<?php echo esc_attr( {$var}['lat'] ); ?>\" data-lng=\"<?php echo esc_attr( {$var}['lng'] ); ?>\"><?php echo esc_html( {$var}['address'] ); ?></div>" );
				$this->add( $d, '<?php endif; ?>' );
				return;

			case 'icon_picker':
				$this->add( $d, "<?php if ( {$var} = {$get} ) : ?>" );
				$this->add( $d + 1, "<?php if ( 0 === strpos( {$var}, 'dashicons' ) ) : ?><span class=\"dashicons <?php echo esc_attr( {$var} ); ?>\"></span><?php else : ?><img src=\"<?php echo esc_url( {$var} ); ?>\" alt=\"\" /><?php endif; ?>" );
				$this->add( $d, '<?php endif; ?>' );
				return;

			case 'group':
				$have = $this->fn( 'have_rows' ) . "( '{$name}'" . ( ! $sub && $post_arg ? ", {$post_arg}" : '' ) . ' )';
				$this->add( $d, "<?php if ( {$have} ) : while ( {$have} ) : " . $this->fn( 'the_row' ) . '(); ?>' );
				$this->add( $d + 1, "<div class=\"{$name}\">" );
				$this->fields( $f['sub_fields'], $d + 2, true, $post_arg );
				$this->add( $d + 1, '</div>' );
				$this->add( $d, '<?php endwhile; endif; ?>' );
				return;

			case 'repeater':
				$have = $this->fn( 'have_rows' ) . "( '{$name}'" . ( ! $sub && $post_arg ? ", {$post_arg}" : '' ) . ' )';
				$this->add( $d, "<?php if ( {$have} ) : ?>" );
				$this->add( $d + 1, "<div class=\"{$name}\">" );
				$this->add( $d + 2, "<?php while ( {$have} ) : " . $this->fn( 'the_row' ) . '(); ?>' );
				$this->add( $d + 3, '<div class="' . $name . '__item">' );
				$this->fields( $f['sub_fields'], $d + 4, true, $post_arg );
				$this->add( $d + 3, '</div>' );
				$this->add( $d + 2, '<?php endwhile; ?>' );
				$this->add( $d + 1, '</div>' );
				$this->add( $d, '<?php endif; ?>' );
				return;

			case 'flexible_content':
				$have = $this->fn( 'have_rows' ) . "( '{$name}'" . ( ! $sub && $post_arg ? ", {$post_arg}" : '' ) . ' )';
				$this->add( $d, "<?php if ( {$have} ) : ?>" );
				$this->add( $d + 1, "<?php while ( {$have} ) : " . $this->fn( 'the_row' ) . '(); ?>' );
				$this->add( $d + 2, '<?php switch ( ' . $this->fn( 'get_row_layout' ) . '() ) : ?>' );
				foreach ( (array) $f['layouts'] as $layout ) {
					$this->add( $d + 3, "<?php case '{$layout['name']}': ?>" );
					$this->add( $d + 4, '<section class="' . $name . ' ' . $name . '--' . $layout['name'] . '">' );
					$this->fields( $layout['sub_fields'], $d + 5, true, $post_arg );
					$this->add( $d + 4, '</section>' );
					$this->add( $d + 4, '<?php break; ?>' );
				}
				$this->add( $d + 2, '<?php endswitch; ?>' );
				$this->add( $d + 1, '<?php endwhile; ?>' );
				$this->add( $d, '<?php endif; ?>' );
				return;

			default:
				$this->add( $d, "<?php echo esc_html( (string) {$get} ); ?>" );
		}
	}
}

function pcf_op_generate_template_code( $input ) {
	$acf_names = 'pcf' !== pcf_maybe_get( $input, 'api', 'acf' );
	$fields    = array();
	$post_arg  = '';
	$title     = '';

	if ( ! empty( $input['block'] ) ) {
		$fields = pcf_get_block_fields( $input['block'] );
		$title  = 'Bloque ' . $input['block'];
		if ( ! $fields ) {
			return new WP_Error( 'pcf_no_fields', 'Ese bloque no tiene campos asignados.', array( 'status' => 404 ) );
		}
	} elseif ( ! empty( $input['group'] ) ) {
		$g = pcf_op_require_group( $input['group'] );
		if ( is_wp_error( $g ) ) {
			return $g;
		}
		$fields = $g['fields'];
		$title  = $g['title'];
		foreach ( $g['location'] as $and ) {
			foreach ( $and as $rule ) {
				if ( 'options_page' === $rule['param'] ) {
					$page     = pcf_maybe_get( pcf_get_options_pages(), $rule['value'] );
					$post_arg = "'" . ( $page ? $page['post_id'] : 'option' ) . "'";
				} elseif ( 'taxonomy' === $rule['param'] ) {
					$post_arg = 'get_queried_object()';
				} elseif ( in_array( $rule['param'], array( 'user_form', 'user_role' ), true ) ) {
					$post_arg = "'user_' . get_current_user_id()";
				}
			}
		}
	} else {
		return new WP_Error( 'pcf_missing', 'Indica group o block.', array( 'status' => 400 ) );
	}

	if ( ! empty( $input['fields'] ) ) {
		$only   = (array) $input['fields'];
		$fields = array_values(
			array_filter(
				$fields,
				function ( $f ) use ( $only ) {
					return in_array( $f['name'], $only, true ) || in_array( $f['key'], $only, true );
				}
			)
		);
	}

	$gen  = new PCF_Template_Generator( $acf_names );
	$body = $gen->generate( $fields, $post_arg );

	if ( ! empty( $input['block'] ) ) {
		$code = "<?php\n/**\n * {$title}\n *\n * @var array \$block\n * @var string \$content\n * @var bool \$is_preview\n */\n\$anchor = ! empty( \$block['anchor'] ) ? ' id=\"' . esc_attr( \$block['anchor'] ) . '\"' : '';\n?>\n<section class=\"<?php echo esc_attr( \$block['className'] ); ?>\"<?php echo \$anchor; ?>>\n" . preg_replace( '/^/m', "\t", $body ) . "\n</section>\n";
	} else {
		$code = "<?php // {$title} ?>\n" . $body . "\n";
	}

	$result = array(
		'api'  => $acf_names ? 'acf (get_field…)' : 'pcf (pcf_value…)',
		'code' => $code,
	);
	if ( ! $acf_names || ! function_exists( 'get_field' ) ) {
		$result['note'] = $acf_names ? 'get_field() no está disponible ahora mismo: activa la compatibilidad o usa api=pcf.' : '';
	}

	if ( ! empty( $input['write_to_block'] ) && ! empty( $input['block'] ) ) {
		$write = pcf_op_write_block_file( array( 'block' => $input['block'], 'file' => 'render.php', 'content' => $code ) );
		$result['written'] = is_wp_error( $write ) ? $write->get_error_message() : $write['written'];
	}
	return $result;
}
