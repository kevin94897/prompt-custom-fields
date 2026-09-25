<?php
/**
 * Definición de operaciones. Añadir una aquí la expone por Abilities/MCP, REST y WP-CLI.
 *
 * @package PCF
 */

defined( 'ABSPATH' ) || exit;

function pcf_register_core_operations() {
	$R = array( 'PCF_Operations', 'register' );

	/* ---------------- Contexto ---------------- */

	call_user_func(
		$R,
		'get-site-context',
		array(
			'label'       => 'Contexto del sitio',
			'description' => 'Primera llamada recomendada. Devuelve WordPress, tema activo, post types, taxonomías, plantillas, número de grupos/bloques, rutas de Local JSON y transportes disponibles.',
			'callback'    => 'pcf_op_get_site_context',
			'readonly'    => true,
			'idempotent'  => true,
			'group'       => 'context',
			'permission'  => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);

	call_user_func(
		$R,
		'get-usage-guide',
		array(
			'label'       => 'Guía de uso para agentes',
			'description' => 'Guía en markdown: formato de campos (igual que ACF), ubicaciones, formato de valores por tipo, API de plantillas, bloques e importación de ACF. Incluye las convenciones de NERD (una pestaña por sección, qué poner en cada ayuda, medidas y peso de las imágenes): léela y síguela antes de crear o editar campos.',
			'callback'    => 'pcf_op_get_usage_guide',
			'readonly'    => true,
			'idempotent'  => true,
			'group'       => 'context',
			'permission'  => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);

	call_user_func(
		$R,
		'list-field-types',
		array(
			'label'       => 'Listar tipos de campo',
			'description' => 'Tipos de campo disponibles con sus ajustes (tipo, default, enum) y el formato de valor que acepta update-values.',
			'properties'  => array(
				'type'     => pcf_schema_str( 'Filtrar a un tipo (ej. repeater).' ),
				'category' => pcf_schema_str( 'Filtrar por categoría.', array( 'enum' => array( 'basic', 'content', 'choice', 'relational', 'advanced', 'layout' ) ) ),
				'compact'  => pcf_schema_bool( 'Sólo nombre, descripción y formato de valor (sin ajustes).' ),
			),
			'callback'    => 'pcf_op_list_field_types',
			'readonly'    => true,
			'idempotent'  => true,
			'group'       => 'context',
		)
	);

	call_user_func(
		$R,
		'list-location-rules',
		array(
			'label'       => 'Listar reglas de ubicación',
			'description' => 'Parámetros de ubicación (post_type, page_template, taxonomy, options_page, block…) con operadores. Pasa param para ver los valores válidos de uno.',
			'properties'  => array(
				'param'       => pcf_schema_str( 'Regla concreta (devuelve sus valores posibles).' ),
				'with_values' => pcf_schema_bool( 'Incluir valores de todas las reglas.' ),
			),
			'callback'    => 'pcf_op_list_location_rules',
			'readonly'    => true,
			'idempotent'  => true,
			'group'       => 'context',
		)
	);

	/* ---------------- Grupos ---------------- */

	$group_props = array(
		'title'                 => pcf_schema_str( 'Título del grupo.' ),
		'key'                   => pcf_schema_str( 'Key opcional (group_…). Útil para referencias estables.' ),
		'fields'                => pcf_schema_fields(),
		'location'              => pcf_schema_location(),
		'position'              => pcf_schema_str( 'Posición del meta box.', array( 'enum' => array( 'normal', 'side', 'acf_after_title' ) ) ),
		'style'                 => pcf_schema_str( 'Estilo.', array( 'enum' => array( 'default', 'seamless' ) ) ),
		'label_placement'       => pcf_schema_str( 'Etiquetas.', array( 'enum' => array( 'top', 'left' ) ) ),
		'instruction_placement' => pcf_schema_str( 'Instrucciones.', array( 'enum' => array( 'label', 'field' ) ) ),
		'hide_on_screen'        => pcf_schema_arr( 'Elementos a ocultar en el editor clásico (the_content, excerpt, featured_image…).', array( 'type' => 'string' ) ),
		'description'           => pcf_schema_str( 'Descripción interna.' ),
		'menu_order'            => pcf_schema_int( 'Orden.' ),
		'active'                => pcf_schema_bool( 'Activo.' ),
		'show_in_rest'          => array( 'type' => array( 'integer', 'boolean' ), 'description' => 'Exponer valores en la REST API de WordPress.' ),
	);

	call_user_func(
		$R,
		'list-field-groups',
		array(
			'label'       => 'Listar grupos de campos',
			'description' => 'Grupos con su árbol de campos (key, name, type), ubicación y origen (database/json/php).',
			'properties'  => array(
				'search'         => pcf_schema_str( 'Texto a buscar en título o key.' ),
				'post_type'      => pcf_schema_str( 'Sólo grupos visibles en este post type.' ),
				'object'         => pcf_schema_object_id( 'Sólo grupos visibles para este objeto.' ),
				'include_fields' => pcf_schema_bool( 'Incluir árbol de campos (por defecto true).' ),
			),
			'callback'    => 'pcf_op_list_field_groups',
			'readonly'    => true,
			'idempotent'  => true,
			'group'       => 'field_groups',
		)
	);

	call_user_func(
		$R,
		'get-field-group',
		array(
			'label'       => 'Obtener grupo de campos',
			'description' => 'Definición completa del grupo (todos los ajustes de cada campo).',
			'properties'  => array( 'key' => pcf_schema_str( 'Key, ID o título del grupo.' ) ),
			'required'    => array( 'key' ),
			'callback'    => 'pcf_op_get_field_group',
			'readonly'    => true,
			'idempotent'  => true,
			'group'       => 'field_groups',
		)
	);

	call_user_func(
		$R,
		'create-field-group',
		array(
			'label'       => 'Crear grupo de campos',
			'description' => 'Crea un grupo con todos sus campos en una sola llamada (incluye repeaters, flexible content, etc.). Se guarda en BD y en Local JSON.',
			'properties'  => $group_props,
			'required'    => array( 'title', 'fields', 'location' ),
			'callback'    => 'pcf_op_create_field_group',
			'group'       => 'field_groups',
		)
	);

	call_user_func(
		$R,
		'update-field-group',
		array(
			'label'       => 'Actualizar grupo de campos',
			'description' => 'Aplica cambios parciales a un grupo. Si "changes" incluye "fields", la lista de campos se REEMPLAZA completa (para cambios puntuales usa add-field / update-field).',
			'properties'  => array(
				'key'     => pcf_schema_str( 'Key del grupo.' ),
				'changes' => pcf_schema_obj( 'Propiedades a cambiar (title, location, position, active, fields…).' ),
			),
			'required'    => array( 'key', 'changes' ),
			'callback'    => 'pcf_op_update_field_group',
			'idempotent'  => true,
			'group'       => 'field_groups',
		)
	);

	call_user_func(
		$R,
		'delete-field-group',
		array(
			'label'       => 'Eliminar grupo de campos',
			'description' => 'Elimina el grupo de la BD y su JSON local. Los valores ya guardados en el contenido se conservan.',
			'properties'  => array( 'key' => pcf_schema_str( 'Key del grupo.' ) ),
			'required'    => array( 'key' ),
			'callback'    => 'pcf_op_delete_field_group',
			'destructive' => true,
			'group'       => 'field_groups',
		)
	);

	call_user_func(
		$R,
		'duplicate-field-group',
		array(
			'label'       => 'Duplicar grupo de campos',
			'description' => 'Copia un grupo con keys nuevas (la lógica condicional se reapunta).',
			'properties'  => array(
				'key'   => pcf_schema_str( 'Key del grupo origen.' ),
				'title' => pcf_schema_str( 'Título de la copia.' ),
			),
			'required'    => array( 'key' ),
			'callback'    => 'pcf_op_duplicate_field_group',
			'group'       => 'field_groups',
		)
	);

	/* ---------------- Campos ---------------- */

	call_user_func(
		$R,
		'add-field',
		array(
			'label'       => 'Añadir campo(s)',
			'description' => 'Añade uno ("field") o varios ("fields") campos a un grupo, en la raíz o dentro de un contenedor (parent = key de group/repeater/flexible; layout = name del layout).',
			'properties'  => array(
				'group'    => pcf_schema_str( 'Key del grupo.' ),
				'field'    => pcf_schema_obj( 'Campo a añadir (formato ACF).' ),
				'fields'   => pcf_schema_fields(),
				'parent'   => pcf_schema_str( 'Key del campo contenedor (opcional).' ),
				'layout'   => pcf_schema_str( 'Name o key del layout si parent es flexible_content.' ),
				'position' => pcf_schema_int( 'Posición (0 = primero). Por defecto al final.' ),
			),
			'required'    => array( 'group' ),
			'callback'    => 'pcf_op_add_field',
			'group'       => 'fields',
		)
	);

	call_user_func(
		$R,
		'update-field',
		array(
			'label'       => 'Actualizar campo',
			'description' => 'Modifica ajustes de un campo (por key). Por defecto fusiona; replace=true reemplaza la definición completa.',
			'properties'  => array(
				'key'     => pcf_schema_str( 'Key del campo (field_…).' ),
				'changes' => pcf_schema_obj( 'Ajustes a cambiar (label, choices, required, sub_fields…).' ),
				'replace' => pcf_schema_bool( 'Reemplazar en vez de fusionar.' ),
			),
			'required'    => array( 'key', 'changes' ),
			'callback'    => 'pcf_op_update_field',
			'idempotent'  => true,
			'group'       => 'fields',
		)
	);

	call_user_func(
		$R,
		'delete-field',
		array(
			'label'       => 'Eliminar campo',
			'description' => 'Elimina un campo (y sus subcampos) de su grupo.',
			'properties'  => array( 'key' => pcf_schema_str( 'Key del campo.' ) ),
			'required'    => array( 'key' ),
			'callback'    => 'pcf_op_delete_field',
			'destructive' => true,
			'group'       => 'fields',
		)
	);

	call_user_func(
		$R,
		'move-field',
		array(
			'label'       => 'Mover campo',
			'description' => 'Mueve un campo a otra posición, contenedor o grupo.',
			'properties'  => array(
				'key'      => pcf_schema_str( 'Key del campo.' ),
				'to_group' => pcf_schema_str( 'Grupo destino (por defecto el mismo).' ),
				'parent'   => pcf_schema_str( 'Contenedor destino (vacío = raíz).' ),
				'layout'   => pcf_schema_str( 'Layout destino (flexible).' ),
				'position' => pcf_schema_int( 'Posición destino.' ),
			),
			'required'    => array( 'key' ),
			'callback'    => 'pcf_op_move_field',
			'group'       => 'fields',
		)
	);

	call_user_func(
		$R,
		'add-layout',
		array(
			'label'       => 'Añadir layout a flexible content',
			'description' => 'Añade un layout {"name","label","display","sub_fields"} a un campo flexible_content.',
			'properties'  => array(
				'field'    => pcf_schema_str( 'Key del campo flexible_content.' ),
				'layout'   => pcf_schema_obj( 'Layout.' ),
				'position' => pcf_schema_int( 'Posición.' ),
			),
			'required'    => array( 'field', 'layout' ),
			'callback'    => 'pcf_op_add_layout',
			'group'       => 'fields',
		)
	);

	/* ---------------- Contenido ---------------- */

	$object = pcf_schema_object_id();

	call_user_func(
		$R,
		'get-object-fields',
		array(
			'label'       => 'Campos de un objeto',
			'description' => 'Qué grupos y campos aplican a un post/term/user/options, con el formato de valor que espera update-values.',
			'properties'  => array( 'object' => $object ),
			'required'    => array( 'object' ),
			'callback'    => 'pcf_op_get_object_fields',
			'permission'  => 'content',
			'readonly'    => true,
			'idempotent'  => true,
			'group'       => 'content',
		)
	);

	call_user_func(
		$R,
		'get-values',
		array(
			'label'       => 'Leer valores',
			'description' => 'Valores de campos de un objeto. format=input (por defecto, reutilizable en update-values), formatted (como get_field) o raw (como se guarda).',
			'properties'  => array(
				'object' => $object,
				'fields' => pcf_schema_arr( 'Names o keys (vacío = todos).', array( 'type' => 'string' ) ),
				'format' => pcf_schema_str( 'Formato.', array( 'enum' => array( 'input', 'formatted', 'raw' ) ) ),
			),
			'required'    => array( 'object' ),
			'callback'    => 'pcf_op_get_values',
			'permission'  => 'content',
			'readonly'    => true,
			'idempotent'  => true,
			'group'       => 'content',
		)
	);

	call_user_func(
		$R,
		'update-values',
		array(
			'label'       => 'Guardar valores',
			'description' => 'Guarda valores {"name": valor} en un objeto. Repeater/flexible/group reciben su estructura completa (ver get-usage-guide). Devuelve campos actualizados y errores de validación.',
			'properties'  => array(
				'object'   => $object,
				'values'   => pcf_schema_obj( 'Mapa name|key => valor.' ),
				'validate' => pcf_schema_bool( 'Validar antes de guardar (por defecto true).' ),
			),
			'required'    => array( 'object', 'values' ),
			'callback'    => 'pcf_op_update_values',
			'permission'  => 'content',
			'idempotent'  => true,
			'group'       => 'content',
		)
	);

	call_user_func(
		$R,
		'delete-values',
		array(
			'label'       => 'Borrar valores',
			'description' => 'Elimina los valores de los campos indicados en un objeto.',
			'properties'  => array(
				'object' => $object,
				'fields' => pcf_schema_arr( 'Names o keys.', array( 'type' => 'string' ) ),
			),
			'required'    => array( 'object', 'fields' ),
			'callback'    => 'pcf_op_delete_values',
			'permission'  => 'content',
			'destructive' => true,
			'group'       => 'content',
		)
	);

	$row_base = array(
		'object' => $object,
		'field'  => pcf_schema_str( 'Name o key del repeater / flexible_content (primer nivel).' ),
	);

	call_user_func(
		$R,
		'add-row',
		array(
			'label'       => 'Añadir fila',
			'description' => 'Añade fila(s) a un repeater o flexible content sin tocar las demás. En flexible incluye "acf_fc_layout".',
			'properties'  => array_merge(
				$row_base,
				array(
					'row'      => pcf_schema_obj( 'Fila {"sub": valor}.' ),
					'rows'     => pcf_schema_arr( 'Varias filas.', array( 'type' => 'object', 'additionalProperties' => true ) ),
					'position' => pcf_schema_int( 'Índice base 1 donde insertar (por defecto al final).' ),
				)
			),
			'required'    => array( 'object', 'field' ),
			'callback'    => 'pcf_op_add_row',
			'permission'  => 'content',
			'group'       => 'content',
		)
	);

	call_user_func(
		$R,
		'update-row',
		array(
			'label'       => 'Actualizar fila',
			'description' => 'Fusiona cambios en la fila indicada (índice base 1).',
			'properties'  => array_merge(
				$row_base,
				array(
					'index' => pcf_schema_int( 'Índice base 1.', array( 'minimum' => 1 ) ),
					'row'   => pcf_schema_obj( 'Subcampos a cambiar.' ),
				)
			),
			'required'    => array( 'object', 'field', 'index', 'row' ),
			'callback'    => 'pcf_op_update_row',
			'permission'  => 'content',
			'idempotent'  => true,
			'group'       => 'content',
		)
	);

	call_user_func(
		$R,
		'delete-row',
		array(
			'label'       => 'Eliminar fila',
			'description' => 'Elimina la fila indicada (índice base 1).',
			'properties'  => array_merge( $row_base, array( 'index' => pcf_schema_int( 'Índice base 1.', array( 'minimum' => 1 ) ) ) ),
			'required'    => array( 'object', 'field', 'index' ),
			'callback'    => 'pcf_op_delete_row',
			'permission'  => 'content',
			'destructive' => true,
			'group'       => 'content',
		)
	);

	call_user_func(
		$R,
		'list-posts',
		array(
			'label'       => 'Buscar contenido',
			'description' => 'Busca posts/páginas para obtener IDs, con los grupos de campos que les aplican.',
			'properties'  => array(
				'post_type'      => array( 'type' => array( 'string', 'array' ), 'description' => 'Post type(s). Por defecto any.' ),
				'search'         => pcf_schema_str( 'Texto.' ),
				'status'         => array( 'type' => array( 'string', 'array' ), 'description' => 'Estado(s).' ),
				'per_page'       => pcf_schema_int( 'Máx. 100.', array( 'minimum' => 1, 'maximum' => 100 ) ),
				'page'           => pcf_schema_int( 'Página.', array( 'minimum' => 1 ) ),
				'include_values' => pcf_schema_bool( 'Incluir valores de campos.' ),
			),
			'callback'    => 'pcf_op_list_posts',
			'permission'  => 'content',
			'readonly'    => true,
			'idempotent'  => true,
			'group'       => 'content',
		)
	);

	call_user_func(
		$R,
		'save-post',
		array(
			'label'       => 'Crear o editar post',
			'description' => 'Crea (sin id) o actualiza (con id) un post con título, contenido, estado, plantilla, imagen destacada, términos y valores de campos en una sola llamada.',
			'properties'  => array(
				'id'             => pcf_schema_int( 'ID para actualizar (omitir para crear).' ),
				'post_type'      => pcf_schema_str( 'Post type (al crear). Por defecto post.' ),
				'title'          => pcf_schema_str( 'Título.' ),
				'content'        => pcf_schema_str( 'Contenido (HTML o bloques serializados).' ),
				'excerpt'        => pcf_schema_str( 'Extracto.' ),
				'status'         => pcf_schema_str( 'Estado.', array( 'enum' => array( 'draft', 'publish', 'pending', 'private', 'future' ) ) ),
				'slug'           => pcf_schema_str( 'Slug.' ),
				'parent'         => pcf_schema_int( 'Padre.' ),
				'order'          => pcf_schema_int( 'menu_order.' ),
				'template'       => pcf_schema_str( 'Plantilla de página (archivo).' ),
				'featured_image' => array( 'type' => array( 'integer', 'string' ), 'description' => 'ID o URL de la imagen destacada.' ),
				'terms'          => pcf_schema_obj( '{"category":["noticias"]}' ),
				'values'         => pcf_schema_obj( 'Valores de campos {"name": valor}.' ),
				'front_page'     => pcf_schema_bool( 'Establecer como portada (páginas).' ),
			),
			'callback'    => 'pcf_op_save_post',
			'permission'  => function () {
				return current_user_can( 'edit_posts' );
			},
			'group'       => 'content',
		)
	);

	/* ---------------- Post types / taxonomías / options pages ---------------- */

	$kinds = array(
		'post-type'    => array(
			'kind'   => 'post_type',
			'label'  => 'post type',
			'props'  => array(
				'post_type'  => pcf_schema_str( 'Slug (máx. 20, a-z0-9_-).' ),
				'singular'   => pcf_schema_str( 'Nombre singular (genera las etiquetas).' ),
				'plural'     => pcf_schema_str( 'Nombre plural.' ),
				'taxonomies' => pcf_schema_arr( 'Taxonomías asociadas.', array( 'type' => 'string' ) ),
				'args'       => pcf_schema_obj( 'Argumentos de register_post_type (public, has_archive, supports, menu_icon, rewrite, hierarchical, show_in_rest…).' ),
			),
			'id'     => 'post_type',
			'plural' => 'post-types',
		),
		'taxonomy'     => array(
			'kind'   => 'taxonomy',
			'label'  => 'taxonomía',
			'props'  => array(
				'taxonomy'    => pcf_schema_str( 'Slug (máx. 32).' ),
				'object_type' => pcf_schema_arr( 'Post types a los que se asocia.', array( 'type' => 'string' ) ),
				'singular'    => pcf_schema_str( 'Nombre singular.' ),
				'plural'      => pcf_schema_str( 'Nombre plural.' ),
				'args'        => pcf_schema_obj( 'Argumentos de register_taxonomy (hierarchical, rewrite, show_admin_column…).' ),
			),
			'id'     => 'taxonomy',
			'plural' => 'taxonomies',
		),
		'options-page' => array(
			'kind'   => 'options_page',
			'label'  => 'página de opciones',
			'props'  => array(
				'page_title'      => pcf_schema_str( 'Título.' ),
				'menu_title'      => pcf_schema_str( 'Título en el menú.' ),
				'menu_slug'       => pcf_schema_str( 'Slug (usado en la regla options_page).' ),
				'parent_slug'     => pcf_schema_str( 'Menú padre (ej. options-general.php o el slug de otra options page).' ),
				'capability'      => pcf_schema_str( 'Capacidad requerida (edit_posts).' ),
				'position'        => array( 'type' => array( 'integer', 'string', 'null' ), 'description' => 'Posición en el menú.' ),
				'icon_url'        => pcf_schema_str( 'Dashicon o URL.' ),
				'redirect'        => pcf_schema_bool( 'Redirigir al primer hijo.' ),
				'post_id'         => pcf_schema_str( 'post_id de almacenamiento (por defecto "options").' ),
				'update_button'   => pcf_schema_str( 'Texto del botón.' ),
				'updated_message' => pcf_schema_str( 'Mensaje al guardar.' ),
				'description'     => pcf_schema_str( 'Descripción.' ),
			),
			'id'     => 'menu_slug',
			'plural' => 'options-pages',
		),
	);

	foreach ( $kinds as $slug => $k ) {
		call_user_func(
			$R,
			'list-' . $k['plural'],
			array(
				'label'       => 'Listar ' . $k['label'],
				'description' => 'Elementos de tipo ' . $k['label'] . ' gestionados por PCF y los ya registrados en WordPress.',
				'callback'    => pcf_op_list_internal( $k['kind'] ),
				'readonly'    => true,
				'idempotent'  => true,
				'group'       => 'registrations',
			)
		);
		call_user_func(
			$R,
			'save-' . $slug,
			array(
				'label'       => 'Crear/actualizar ' . $k['label'],
				'description' => 'Crea o actualiza (por key o por ' . $k['id'] . ') un/a ' . $k['label'] . '. Los cambios se fusionan con lo existente.',
				'properties'  => array_merge(
					$k['props'],
					array(
						'key'    => pcf_schema_str( 'Key existente (opcional).' ),
						'active' => pcf_schema_bool( 'Activo.' ),
					)
				),
				'callback'    => pcf_op_save_internal( $k['kind'] ),
				'idempotent'  => true,
				'group'       => 'registrations',
			)
		);
		call_user_func(
			$R,
			'delete-' . $slug,
			array(
				'label'       => 'Eliminar ' . $k['label'],
				'description' => 'Elimina la definición (el contenido existente se conserva).',
				'properties'  => array( 'key' => pcf_schema_str( 'Key o ' . $k['id'] . '.' ) ),
				'required'    => array( 'key' ),
				'callback'    => pcf_op_delete_internal( $k['kind'] ),
				'destructive' => true,
				'group'       => 'registrations',
			)
		);
	}

	/* ---------------- Bloques ---------------- */

	call_user_func(
		$R,
		'list-blocks',
		array(
			'label'       => 'Listar bloques',
			'description' => 'Bloques con campos registrados (block.json del tema o PHP) y sus grupos.',
			'callback'    => 'pcf_op_list_blocks',
			'readonly'    => true,
			'idempotent'  => true,
			'group'       => 'blocks',
		)
	);

	call_user_func(
		$R,
		'create-block',
		array(
			'label'       => 'Crear bloque',
			'description' => 'Crea {tema}/blocks/{slug}/ con block.json y render.php (y style.css) y, si pasas fields, su grupo de campos. Sólo en entornos local/development.',
			'properties'  => array(
				'name'        => pcf_schema_str( 'Slug (hero) o nombre completo (pcf/hero).' ),
				'title'       => pcf_schema_str( 'Título.' ),
				'description' => pcf_schema_str( 'Descripción.' ),
				'category'    => pcf_schema_str( 'Categoría (text, media, design, widgets, theme, embed).' ),
				'icon'        => pcf_schema_str( 'Dashicon sin prefijo (star-filled).' ),
				'keywords'    => pcf_schema_arr( 'Palabras clave.', array( 'type' => 'string' ) ),
				'supports'    => pcf_schema_obj( 'supports de block.json (align, anchor, jsx para InnerBlocks…).' ),
				'mode'        => pcf_schema_str( 'Modo.', array( 'enum' => array( 'preview', 'edit', 'auto' ) ) ),
				'template'    => pcf_schema_str( 'Contenido PHP de render.php (si se omite se genera uno base; también puedes usar generate-template-code con write_to_block).' ),
				'style'       => pcf_schema_str( 'CSS del bloque.' ),
				'fields'      => pcf_schema_fields(),
				'group_key'   => pcf_schema_str( 'Key del grupo de campos (opcional).' ),
				'overwrite'   => pcf_schema_bool( 'Sobrescribir si existe.' ),
			),
			'required'    => array( 'name' ),
			'callback'    => 'pcf_op_create_block',
			'group'       => 'blocks',
		)
	);

	call_user_func(
		$R,
		'write-block-file',
		array(
			'label'       => 'Escribir archivo de bloque',
			'description' => 'Sobrescribe render.php, style.css, editor.css, script.js, view.js o block.json de un bloque del tema. Sólo en entornos local/development.',
			'properties'  => array(
				'block'   => pcf_schema_str( 'Nombre del bloque (pcf/hero).' ),
				'file'    => pcf_schema_str( 'Archivo.', array( 'enum' => array( 'render.php', 'style.css', 'editor.css', 'script.js', 'view.js', 'block.json' ) ) ),
				'content' => pcf_schema_str( 'Contenido completo.' ),
			),
			'required'    => array( 'block', 'file', 'content' ),
			'callback'    => 'pcf_op_write_block_file',
			'idempotent'  => true,
			'group'       => 'blocks',
		)
	);

	call_user_func(
		$R,
		'generate-template-code',
		array(
			'label'       => 'Generar código de plantilla',
			'description' => 'Genera PHP para mostrar los campos de un grupo o bloque (con loops para repeater/flexible, escapado correcto y return_format). Con write_to_block lo guarda como render.php del bloque.',
			'properties'  => array(
				'group'          => pcf_schema_str( 'Key del grupo.' ),
				'block'          => pcf_schema_str( 'Nombre del bloque.' ),
				'fields'         => pcf_schema_arr( 'Limitar a estos names.', array( 'type' => 'string' ) ),
				'api'            => pcf_schema_str( 'Funciones a usar.', array( 'enum' => array( 'acf', 'pcf' ) ) ),
				'write_to_block' => pcf_schema_bool( 'Escribir en render.php del bloque.' ),
			),
			'callback'    => 'pcf_op_generate_template_code',
			'readonly'    => false,
			'group'       => 'blocks',
		)
	);

	/* ---------------- JSON / importación ---------------- */

	call_user_func(
		$R,
		'json-sync-status',
		array(
			'label'       => 'Estado de Local JSON',
			'description' => 'Compara los JSON de pcf-json con la BD: new (sólo JSON), sync (JSON más reciente), synced, db_only.',
			'callback'    => 'pcf_op_json_status',
			'readonly'    => true,
			'idempotent'  => true,
			'group'       => 'sync',
		)
	);

	call_user_func(
		$R,
		'json-sync',
		array(
			'label'       => 'Sincronizar Local JSON',
			'description' => 'Importa a BD los JSON pendientes (o las keys indicadas). Útil tras un git pull.',
			'properties'  => array( 'keys' => pcf_schema_arr( 'Keys concretas (vacío = todos los pendientes).', array( 'type' => 'string' ) ) ),
			'callback'    => 'pcf_op_json_sync',
			'idempotent'  => true,
			'group'       => 'sync',
		)
	);

	call_user_func(
		$R,
		'export',
		array(
			'label'       => 'Exportar',
			'description' => 'Exporta grupos, post types, taxonomías y options pages a JSON (compatible con ACF) o a código PHP.',
			'properties'  => array(
				'keys'               => pcf_schema_arr( 'Keys (vacío = todo).', array( 'type' => 'string' ) ),
				'format'             => pcf_schema_str( 'Formato.', array( 'enum' => array( 'json', 'php' ) ) ),
				'acf_function_names' => pcf_schema_bool( 'En PHP, usar acf_add_local_field_group en lugar de pcf_….' ),
			),
			'callback'    => 'pcf_op_export',
			'readonly'    => true,
			'idempotent'  => true,
			'group'       => 'sync',
		)
	);

	call_user_func(
		$R,
		'import-json',
		array(
			'label'       => 'Importar JSON',
			'description' => 'Importa un export JSON de PCF o de ACF (un elemento o una lista).',
			'properties'  => array(
				'json'      => array( 'type' => array( 'string', 'array', 'object' ), 'description' => 'JSON (string) o estructura ya decodificada.' ),
				'overwrite' => pcf_schema_bool( 'Sobrescribir existentes.' ),
				'dry_run'   => pcf_schema_bool( 'Sólo simular.' ),
			),
			'required'    => array( 'json' ),
			'callback'    => 'pcf_op_import_json',
			'group'       => 'sync',
		)
	);

	call_user_func(
		$R,
		'detect-acf',
		array(
			'label'       => 'Detectar datos de ACF',
			'description' => 'Indica si ACF está activo y cuántos grupos, campos, post types, taxonomías y options pages de ACF hay para importar (BD y acf-json).',
			'callback'    => 'pcf_op_detect_acf',
			'readonly'    => true,
			'idempotent'  => true,
			'group'       => 'sync',
		)
	);

	call_user_func(
		$R,
		'import-acf',
		array(
			'label'       => 'Importar desde ACF',
			'description' => 'Importa definiciones de ACF conservando las keys (el contenido existente sigue funcionando). Ejecuta primero con dry_run=true.',
			'properties'  => array(
				'source'         => pcf_schema_str( 'Origen.', array( 'enum' => array( 'database', 'acf_api', 'json', 'acf_json' ) ) ),
				'json'           => array( 'type' => array( 'string', 'array', 'object' ), 'description' => 'Contenido si source=json.' ),
				'path'           => pcf_schema_str( 'Carpeta si source=acf_json (por defecto {tema}/acf-json).' ),
				'keys'           => pcf_schema_arr( 'Importar sólo estas keys.', array( 'type' => 'string' ) ),
				'include'        => pcf_schema_arr(
					'Qué importar.',
					array(
						'type' => 'string',
						'enum' => array( 'field_groups', 'post_types', 'taxonomies', 'options_pages' ),
					)
				),
				'overwrite'      => pcf_schema_bool( 'Sobrescribir existentes.' ),
				'dry_run'        => pcf_schema_bool( 'Sólo simular.' ),
				'deactivate_acf' => pcf_schema_bool( 'Desactivar los grupos originales en ACF tras importar (source=database).' ),
			),
			'callback'    => 'pcf_op_import_acf',
			'group'       => 'sync',
		)
	);

	do_action( 'pcf/register_operations' );
}

pcf_register_core_operations();
