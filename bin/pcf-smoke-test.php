<?php
/**
 * Prueba de humo de Prompt Custom Fields.
 *
 * En el "Open site shell" de Local:
 *   wp eval-file wp-content/plugins/prompt-custom-fields/bin/pcf-smoke-test.php --user=admin
 *
 * Crea un grupo y un post temporales, guarda y lee valores anidados por las
 * operaciones MCP y por la API de plantillas, y lo borra todo al terminar.
 *
 * @package PCF
 */

if ( ! defined( 'ABSPATH' ) || ! function_exists( 'pcf' ) ) {
	echo "Ejecuta con: wp eval-file .../bin/pcf-smoke-test.php --user=admin\n";
	return;
}

$pcf_ok   = 0;
$pcf_fail = 0;
$pcf_check = function ( $label, $cond, $detail = '' ) use ( &$pcf_ok, &$pcf_fail ) {
	if ( $cond ) {
		$pcf_ok++;
		echo "  ✔ {$label}\n";
	} else {
		$pcf_fail++;
		echo "  ✘ {$label}" . ( $detail ? " → {$detail}" : '' ) . "\n";
	}
};
$pcf_run = function ( $op, $input ) {
	$r = PCF_Operations::execute( $op, $input );
	if ( is_wp_error( $r ) ) {
		echo '    (' . $op . ': ' . $r->get_error_message() . ")\n";
	}
	return $r;
};

echo "Prompt Custom Fields " . PCF_VERSION . " — prueba de humo\n";
echo '  Usuario: ' . ( wp_get_current_user()->user_login ? wp_get_current_user()->user_login : '(ninguno: usa --user=admin)' ) . "\n";
echo '  Tipos de campo: ' . count( pcf()->field_types ) . ' · Operaciones: ' . count( PCF_Operations::all() ) . "\n";
echo '  Compatibilidad get_field(): ' . ( class_exists( 'ACF' ) ? 'ACF está activo (usa pcf_value)' : ( function_exists( 'get_field' ) ? 'activa' : 'inactiva' ) ) . "\n\n";

$key   = 'group_pcf_smoke_' . wp_rand( 1000, 9999 );
$group = $pcf_run(
	'create-field-group',
	array(
		'key'      => $key,
		'title'    => 'PCF smoke test',
		'location' => 'post_type:post',
		'fields'   => array(
			array( 'label' => 'Titular', 'type' => 'text', 'required' => 1 ),
			array( 'label' => 'Activo', 'name' => 'activo', 'type' => 'true_false' ),
			array( 'label' => 'Fecha', 'name' => 'fecha', 'type' => 'date_picker', 'return_format' => 'Y-m-d' ),
			array(
				'label'      => 'Filas',
				'name'       => 'filas',
				'type'       => 'repeater',
				'sub_fields' => array(
					array( 'label' => 'Texto', 'type' => 'text' ),
					array( 'label' => 'Tags', 'name' => 'tags', 'type' => 'repeater', 'sub_fields' => array( array( 'label' => 'Tag', 'type' => 'text' ) ) ),
				),
			),
			array(
				'label'   => 'Secciones',
				'name'    => 'secciones',
				'type'    => 'flexible_content',
				'layouts' => array(
					array( 'name' => 'cita', 'label' => 'Cita', 'sub_fields' => array( array( 'label' => 'Frase', 'type' => 'text' ) ) ),
				),
			),
			array( 'label' => 'SEO', 'name' => 'seo', 'type' => 'group', 'sub_fields' => array( array( 'label' => 'Meta', 'name' => 'meta', 'type' => 'text' ) ) ),
		),
	)
);
$pcf_check( 'create-field-group', ! is_wp_error( $group ) );

$post = $pcf_run(
	'save-post',
	array(
		'title'  => 'PCF smoke test',
		'status' => 'draft',
		'values' => array(
			'titular'   => 'Hola',
			'activo'    => true,
			'fecha'     => '2030-01-02',
			'filas'     => array(
				array( 'texto' => 'A', 'tags' => array( array( 'tag' => 'x' ), array( 'tag' => 'y' ) ) ),
				array( 'texto' => 'B' ),
			),
			'secciones' => array( array( 'acf_fc_layout' => 'cita', 'frase' => 'Carpe diem' ) ),
			'seo'       => array( 'meta' => 'Meta SEO' ),
		),
	)
);
$pid = is_wp_error( $post ) ? 0 : $post['id'];
$pcf_check( 'save-post con valores', $pid && empty( $post['values']['errors'] ), is_wp_error( $post ) ? '' : wp_json_encode( $post['values']['errors'] ) );

if ( $pid ) {
	$pcf_check( 'pcf_value (texto)', 'Hola' === pcf_value( 'titular', $pid ) );
	$pcf_check( 'pcf_value (bool)', true === pcf_value( 'activo', $pid ) );
	$pcf_check( 'fecha guardada como Ymd', '20300102' === get_post_meta( $pid, 'fecha', true ) );
	$pcf_check( 'formato ACF en meta', '2' === get_post_meta( $pid, 'filas', true ) && 'y' === get_post_meta( $pid, 'filas_0_tags_1_tag', true ) && 0 === strpos( get_post_meta( $pid, '_filas', true ), 'field_' ) );

	$out = array();
	while ( pcf_have_rows( 'filas', $pid ) ) {
		pcf_the_row();
		$tags = array();
		while ( pcf_have_rows( 'tags' ) ) {
			pcf_the_row();
			$tags[] = pcf_sub_value( 'tag' );
		}
		$out[] = pcf_sub_value( 'texto' ) . ':' . implode( ',', $tags );
	}
	$pcf_check( 'loops anidados', 'A:x,y|B:' === implode( '|', $out ), implode( '|', $out ) );

	$layout = '';
	while ( pcf_have_rows( 'secciones', $pid ) ) {
		pcf_the_row();
		$layout = pcf_row_layout() . '=' . pcf_sub_value( 'frase' );
	}
	$pcf_check( 'flexible content', 'cita=Carpe diem' === $layout, $layout );
	$seo = pcf_value( 'seo', $pid );
	$pcf_check( 'group', 'Meta SEO' === pcf_maybe_get( $seo, 'meta' ) );

	$r = $pcf_run( 'delete-row', array( 'object' => $pid, 'field' => 'filas', 'index' => 1 ) );
	$pcf_check( 'delete-row limpia datos anidados', ! is_wp_error( $r ) && 1 === $r['row_count'] && '' === get_post_meta( $pid, 'filas_0_tags_1_tag', true ) );

	$bad = $pcf_run( 'update-values', array( 'object' => $pid, 'values' => array( 'titular' => '' ) ) );
	$pcf_check( 'validación de obligatorios', ! is_wp_error( $bad ) && isset( $bad['errors']['titular'] ) );

	if ( function_exists( 'get_field' ) && ! class_exists( 'ACF' ) ) {
		$pcf_check( 'get_field() compatible', 'Hola' === get_field( 'titular', $pid ) );
	}

	$code = $pcf_run( 'generate-template-code', array( 'group' => $key ) );
	$pcf_check( 'generate-template-code', ! is_wp_error( $code ) && false !== strpos( $code['code'], "have_rows( 'filas' )" ) );

	wp_delete_post( $pid, true );
}

$pcf_check( 'Local JSON escrito', file_exists( trailingslashit( PCF_Local_JSON::save_path() ) . $key . '.json' ), PCF_Local_JSON::save_path() );
$del = $pcf_run( 'delete-field-group', array( 'key' => $key ) );
$pcf_check( 'limpieza', ! is_wp_error( $del ) && ! file_exists( trailingslashit( PCF_Local_JSON::save_path() ) . $key . '.json' ) );

echo "\n" . ( $pcf_fail ? "✘ {$pcf_fail} fallo(s), {$pcf_ok} correctas\n" : "✔ Todo correcto ({$pcf_ok} comprobaciones)\n" );
