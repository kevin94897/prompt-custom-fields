=== Prompt Custom Fields ===
Contributors: pcf
Tags: custom fields, acf, mcp, ai, blocks
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Campos personalizados con la arquitectura y el formato de datos de ACF PRO, administrables por MCP.

== Description ==

* 37 tipos de campo, incluidos repeater, flexible content, gallery, clone y group.
* El campo "Enlace" usa el modal nativo de WordPress: buscador de contenido publicado, texto del botón y "abrir en una pestaña nueva".
* Post types, taxonomías, páginas de opciones y bloques con campos.
* API compatible con ACF (get_field, have_rows, the_row…) cuando ACF no está activo.
* Importador de ACF que conserva las keys: el contenido existente sigue funcionando.
* Local JSON en el tema para versionar con git.
* 43 operaciones MCP por Abilities API + MCP Adapter, REST (servidor Node incluido) y WP-CLI.

Consulta README.md para la instalación en Local y la configuración de cada modo de conexión.

== Installation ==

1. Descomprime en wp-content/plugins/ y activa el plugin.
2. Ejecuta `wp eval-file wp-content/plugins/prompt-custom-fields/bin/pcf-smoke-test.php --user=admin`.
3. Abre Campos PCF → Conexión MCP y copia la configuración para tu cliente.

== Changelog ==

= 1.2.3 =
* Corregido: al cargar una pantalla con pestañas se veían todas a la vez. La pestaña activa se marcaba dentro del bucle que las construye, cuando las siguientes todavía no existían, así que nunca se ocultaban; ahora se activa al terminar de montarlas.
* El CSS deja a la vista solo los campos de la primera pestaña mientras el script del pie no ha corrido, para que no se vean apiladas durante la carga.

= 1.2.2 =
* Corregido: el campo "Enlace" usaba input type="url", que rechaza anclas (#contacto) y rutas relativas. Al estar oculto tras el modal, el navegador no podía enfocarlo para avisar y bloqueaba el guardado de toda la pantalla ("An invalid form control ... is not focusable"). Ahora es type="text" con inputmode="url"; el saneado sigue haciéndolo esc_url_raw() al guardar.

= 1.2.0 =
* El repetidor ya no trae el botón de contraer filas ni el ajuste "collapsed": una fila se lee de un vistazo y plegarla solo escondía campos. El contenido flexible sí lo conserva, porque ahí cada fila puede ser una sección entera.

= 1.1.0 =
* El campo "Enlace" abre el modal nativo de WordPress ("Insertar/editar enlace") en vez de tres inputs sueltos: trae el buscador de contenido publicado, el texto del enlace y la casilla de pestaña nueva. El formato guardado no cambia ({url, title, target}).

= 1.0.0 =
* Primera versión.
