<?php
/**
 * Bloque: Testimonio (ejemplo de Prompt Custom Fields).
 *
 * @var array  $block      Ajustes y atributos del bloque (className, anchor, align…).
 * @var string $content    Contenido interno (InnerBlocks) si supports.jsx está activo.
 * @var bool   $is_preview true dentro del editor.
 */
$cita   = get_field( 'cita' );
$autor  = get_field( 'autor' );
$cargo  = get_field( 'cargo' );
$foto   = get_field( 'foto' );
$nota   = (int) get_field( 'valoracion' );
$anchor = ! empty( $block['anchor'] ) ? ' id="' . esc_attr( $block['anchor'] ) . '"' : '';

if ( ! $cita && $is_preview ) {
	echo '<p class="pcf-testimonio-vacio">Escribe la cita en la barra lateral.</p>';
	return;
}
?>
<figure class="<?php echo esc_attr( $block['className'] ); ?> pcf-testimonio"<?php echo $anchor; ?>>
	<?php if ( $nota ) : ?>
		<p class="pcf-testimonio__nota" aria-label="<?php echo esc_attr( sprintf( '%d de 5', $nota ) ); ?>"><?php echo esc_html( str_repeat( '★', $nota ) . str_repeat( '☆', 5 - $nota ) ); ?></p>
	<?php endif; ?>
	<blockquote class="pcf-testimonio__cita"><?php echo wp_kses_post( wpautop( $cita ) ); ?></blockquote>
	<figcaption class="pcf-testimonio__autor">
		<?php if ( $foto ) : ?>
			<?php echo wp_get_attachment_image( $foto, 'thumbnail', false, array( 'class' => 'pcf-testimonio__foto' ) ); ?>
		<?php endif; ?>
		<span>
			<strong><?php echo esc_html( $autor ); ?></strong>
			<?php if ( $cargo ) : ?>
				<small><?php echo esc_html( $cargo ); ?></small>
			<?php endif; ?>
		</span>
	</figcaption>
</figure>
