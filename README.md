# Prompt Custom Fields (PCF)

Campos personalizados para WordPress con la misma arquitectura y el mismo formato de datos que **ACF PRO**, pensados para administrarse **desde un agente de IA por MCP** mientras desarrollas en **Local**.

- 37 tipos de campo (todos los de ACF PRO 6.8): repeater, flexible content, gallery, clone, group, relationship, taxonomy, link, icon picker, fechas…
- Post types, taxonomías, páginas de opciones y bloques con campos.
- API de plantillas compatible (`get_field()`, `have_rows()`, `the_row()`, `get_sub_field()`…) cuando ACF no está activo.
- Importa grupos, post types, taxonomías y options pages de ACF **conservando las keys**: el contenido que ya guardó ACF sigue funcionando sin migrar nada.
- Local JSON en `tu-tema/pcf-json/` para versionar la estructura con git.
- 43 operaciones MCP expuestas por tres canales: Abilities API + MCP Adapter, REST y WP-CLI.

---

## 1. Instalación en Local

1. En Local, abre la carpeta del sitio (`app/public/wp-content/plugins/`) y descomprime ahí `prompt-custom-fields.zip`.
2. Activa **Prompt Custom Fields** en *Plugins*.
3. Comprueba que todo funciona desde **Open site shell** (clic derecho sobre el sitio en Local):

   ```bash
   wp eval-file wp-content/plugins/prompt-custom-fields/bin/pcf-smoke-test.php --user=admin
   ```

   Debe terminar con `✔ Todo correcto`.

4. Abre **Campos PCF → Conexión MCP**. Esa pantalla muestra el estado del entorno y la configuración exacta de tu sitio (rutas, URL y usuario ya rellenos) para cada modo.

**Requisitos:** WordPress 6.2+ (6.9+ para la Abilities API), PHP 7.4+, Node 18+ en tu equipo para los modos A, B y D.

> Las operaciones que escriben archivos del tema (`create-block`, `write-block-file`) sólo están permitidas cuando `WP_ENVIRONMENT_TYPE` es `local` o `development`. Local suele definirlo; si la pantalla de conexión indica "bloqueada", añade en `wp-config.php`:
> ```php
> define( 'WP_ENVIRONMENT_TYPE', 'local' );
> ```

---

## 2. Conectar tu agente (elige un modo por proyecto)

| Modo | Cómo se conecta | Necesita | Cuándo usarlo |
|---|---|---|---|
| **A · Node + REST** | `mcp-server/dist/pcf-mcp-server.mjs` → REST del sitio | Node 18+, contraseña de aplicación | Opción por defecto. Funciona con cualquier sitio de Local encendido. |
| **B · Node + WP-CLI** | `mcp-server/dist/pcf-mcp-server.mjs` → `wp pcf run` | Node 18+, capturar el entorno de Local una vez | Sin contraseñas; también funciona con el sitio sin servidor web. |
| **C · MCP Adapter por HTTP** | Plugin oficial [MCP Adapter](https://github.com/WordPress/mcp-adapter) → `/wp-json/mcp/pcf` | Plugin MCP Adapter, contraseña de aplicación | Si ya usas el ecosistema oficial de WordPress/MCP. |
| **D · MCP Adapter por STDIO** | `wp mcp-adapter serve --server=pcf-server` | Plugin MCP Adapter, entorno de Local capturado | Igual que C pero sin HTTP. |

Todos exponen las mismas 43 tools con el prefijo `pcf-` (por ejemplo `pcf-create-field-group`).

### Contraseña de aplicación (modos A y C)

*Usuarios → Tu perfil → Contraseñas de aplicación* → nombre `mcp` → **Añadir**. Copia la contraseña (con espacios, tal cual).

WordPress sólo las permite con HTTPS o con `WP_ENVIRONMENT_TYPE` = `local`. Si tu sitio de Local usa HTTPS con su certificado propio, añade `"PCF_INSECURE_TLS": "1"` al bloque `env`.

### Modo A — Claude Desktop / Cursor

Añade a `claude_desktop_config.json` (o `~/.cursor/mcp.json`):

```json
{
  "mcpServers": {
    "pcf-misitio": {
      "command": "node",
      "args": ["/Users/tu/Local Sites/misitio/app/public/wp-content/plugins/prompt-custom-fields/mcp-server/dist/pcf-mcp-server.mjs"],
      "env": {
        "PCF_BACKEND": "rest",
        "WP_URL": "http://misitio.local",
        "WP_USER": "admin",
        "WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

Claude Code:

```bash
claude mcp add pcf-misitio -e PCF_BACKEND=rest -e WP_URL=http://misitio.local -e WP_USER=admin \
  -e "WP_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx" \
  -- node "/Users/tu/Local Sites/misitio/app/public/wp-content/plugins/prompt-custom-fields/mcp-server/dist/pcf-mcp-server.mjs"
```

En Windows usa rutas con `/` o `\\` escapadas en el JSON (`C:/Users/tu/Local Sites/...`).

### Modo B — WP-CLI con el entorno de Local

WP-CLI necesita el PHP y el MySQL de Local, que sólo están en el PATH dentro de **Open site shell**. Guárdalos una vez:

```bash
# Dentro de "Open site shell" (ya estás en app/public)
node wp-content/plugins/prompt-custom-fields/mcp-server/capture-env.js
```

Esto crea `mcp-server/.local-env.json` y te muestra el `WP_PATH` sugerido. Configuración:

```json
{
  "mcpServers": {
    "pcf-misitio": {
      "command": "node",
      "args": [".../prompt-custom-fields/mcp-server/dist/pcf-mcp-server.mjs"],
      "env": { "PCF_BACKEND": "cli", "WP_PATH": "/Users/tu/Local Sites/misitio/app/public", "WP_USER": "admin" }
    }
  }
}
```

Repite la captura si cambias la versión de PHP del sitio en Local. El sitio debe estar **iniciado** en Local (MySQL encendido).

### Modos C y D — MCP Adapter oficial

1. Descarga `mcp-adapter.zip` de [github.com/WordPress/mcp-adapter/releases](https://github.com/WordPress/mcp-adapter/releases) e instálalo como plugin.
2. PCF registra automáticamente un servidor propio `pcf-server` (endpoint `/wp-json/mcp/pcf`) donde cada operación es una tool directa. Las abilities también aparecen en el servidor por defecto del adapter (`mcp-adapter-discover-abilities` / `execute-ability`).

Modo C:

```json
{
  "mcpServers": {
    "pcf-misitio": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "http://misitio.local/wp-json/mcp/pcf",
        "WP_API_USERNAME": "admin",
        "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

Modo D (requiere haber ejecutado `capture-env.js`):

```json
{
  "mcpServers": {
    "pcf-misitio": {
      "command": "node",
      "args": [".../mcp-server/wp-env.js", "wp", "--path=/Users/tu/Local Sites/misitio/app/public",
               "mcp-adapter", "serve", "--server=pcf-server", "--user=admin"]
    }
  }
}
```

### Diagnóstico

```bash
# Comprueba la conexión con las mismas variables que usarás en el cliente
PCF_BACKEND=rest WP_URL=http://misitio.local WP_USER=admin WP_APP_PASSWORD="…" \
  node mcp-server/dist/pcf-mcp-server.mjs --check

wp pcf operations                 # lista de operaciones
wp pcf mcp-config --user=admin    # imprime las 4 configuraciones
```

Si el cliente no logra conectar, el servidor Node expone una única tool `pcf-connection-status` con el error concreto; al llamarla reintenta.

---

## 3. Prompts de ejemplo

- "Mira el contexto del sitio y la guía de PCF antes de empezar."
- "Crea un post type **Proyecto** con archivo en `/proyectos` y una taxonomía **Sector** jerárquica."
- "Para los Proyectos crea un grupo con cliente, año, galería, un repeater de resultados (métrica + valor) y un relationship a otros proyectos."
- "Crea una página de opciones **Ajustes del tema** con logo, teléfono, redes sociales (repeater) y texto del footer, y rellénala con datos de ejemplo."
- "Crea un bloque **Hero** con titular, subtítulo, imagen de fondo y botón; genera su `render.php` con `generate-template-code` y añade estilos."
- "Construye la portada con un flexible content (hero, servicios, testimonios, CTA), crea la página *Inicio*, rellénala y ponla como portada."
- "Importa todo lo que hay en ACF, primero en modo simulación."
- "Genera la plantilla `single-proyecto.php` usando los campos del grupo de proyectos."

---

## 4. Operaciones MCP

| Grupo | Tools |
|---|---|
| Contexto | `get-site-context`, `get-usage-guide`, `list-field-types`, `list-location-rules` |
| Grupos | `list-field-groups`, `get-field-group`, `create-field-group`, `update-field-group`, `delete-field-group`, `duplicate-field-group` |
| Campos | `add-field`, `update-field`, `delete-field`, `move-field`, `add-layout` |
| Contenido | `get-object-fields`, `get-values`, `update-values`, `delete-values`, `add-row`, `update-row`, `delete-row`, `list-posts`, `save-post` |
| Registros | `list/save/delete-post-type(s)`, `list/save/delete-taxonom(y/ies)`, `list/save/delete-options-page(s)` |
| Bloques | `list-blocks`, `create-block`, `write-block-file`, `generate-template-code` |
| Sincronización | `json-sync-status`, `json-sync`, `export`, `import-json`, `detect-acf`, `import-acf` |

Cada tool lleva su JSON Schema y las anotaciones `readOnlyHint` / `destructiveHint` / `idempotentHint`, así el cliente puede pedir confirmación en las destructivas.

También desde la terminal:

```bash
wp pcf run create-field-group --user=admin --input='{"title":"Hero","location":"post_type:page","fields":[{"label":"Titular","type":"text"}]}'
echo '{"object":2,"values":{"titular":"Hola"}}' | wp pcf run update-values --input=- --user=admin
```

### Formato de campos

Idéntico al export JSON de ACF. `key` y `name` son opcionales (se generan). En la lógica condicional puedes referenciar campos por su `name`. Ubicación: `[[{"param":"post_type","operator":"==","value":"page"}]]` o el atajo `"post_type:page"`.

### Formato de valores (`update-values`)

| Tipo | Valor |
|---|---|
| text, textarea, wysiwyg, email, url | string |
| number, range | número |
| true_false | boolean |
| select / radio / button_group | key de la opción (array si es múltiple) |
| checkbox | array de keys |
| image, file | ID de adjunto o URL (las externas se descargan a la biblioteca) |
| gallery | array de IDs o URLs |
| post_object, page_link, relationship | ID, slug o título |
| taxonomy | ID, slug o nombre (se crea si `add_term`) |
| user | ID, login o email |
| link | `{"url","title","target"}` |
| google_map | `{"address","lat","lng","zoom"}` |
| date_picker / date_time_picker / time_picker | `"2026-12-31"` / `"2026-12-31 18:30:00"` / `"18:30"` |
| group | `{"sub": valor}` |
| repeater | `[{"sub": valor}, …]` |
| flexible_content | `[{"acf_fc_layout": "hero", "sub": valor}, …]` |

`update-values` reemplaza el valor completo del campo; los subcampos que no envíes en una fila quedan vacíos. Para cambios puntuales usa `add-row`, `update-row` y `delete-row`.

---

## 5. Uso en el tema

Si ACF **no** está activo, la API de ACF funciona tal cual:

```php
<h1><?php the_field( 'titular' ); ?></h1>

<?php if ( have_rows( 'servicios' ) ) : ?>
	<?php while ( have_rows( 'servicios' ) ) : the_row(); ?>
		<h3><?php echo esc_html( get_sub_field( 'nombre' ) ); ?></h3>
	<?php endwhile; ?>
<?php endif; ?>

<?php echo esc_html( get_field( 'telefono', 'option' ) ); ?>
<?php echo esc_html( get_field( 'color', get_queried_object() ) ); ?>
```

Equivalentes siempre disponibles (también con ACF activo): `pcf_value()`, `pcf_the_value()`, `pcf_values()`, `pcf_field_object()`, `pcf_have_rows()`, `pcf_the_row()`, `pcf_sub_value()`, `pcf_row_layout()`, `pcf_row_index()`, `pcf_set_value()`, `pcf_add_row()`, `pcf_update_row()`, `pcf_delete_row()`, `pcf_update_sub_value()`.

Registro por PHP (igual que ACF): `acf_add_local_field_group()` / `pcf_add_local_field_group()`, `acf_add_options_page()` / `pcf_add_options_page()`, `acf_register_block_type()` / `pcf_register_block_type()`. Los hooks `acf/init`, `acf/save_post`, `acf/load_value`, `acf/format_value`, `acf/update_value`, `acf/validate_value`, `acf/settings/save_json` y `acf/settings/load_json` se siguen disparando.

### Bloques

Cualquier carpeta en `tu-tema/blocks/<nombre>/` con un `block.json` que tenga la clave `"pcf"` (o `"acf"`, para bloques heredados) se registra automáticamente:

```json
{ "name": "pcf/testimonio", "title": "Testimonio", "pcf": { "mode": "preview", "renderTemplate": "render.php" } }
```

En `render.php` están disponibles `$block`, `$content` (InnerBlocks), `$is_preview`, `$post_id` y `get_field()`. En el editor, los campos aparecen en la barra lateral y el botón de la barra de herramientas alterna entre vista previa y formulario. Los datos se guardan en el mismo formato que ACF Blocks, así que el contenido existente de ACF se renderiza igual.

`blocks-example/` incluye un bloque **Testimonio** completo: copia `blocks-example/blocks/testimonio` a `tu-tema/blocks/` y `blocks-example/pcf-json/*.json` a `tu-tema/pcf-json/`, y sincroniza en *Campos PCF → Grupos*.

---

## 6. Local JSON y git

Cada grupo, post type, taxonomía y página de opciones se guarda también en `tu-tema/pcf-json/{key}.json`. Súbelo al repositorio. Tras un `git pull`:

```bash
wp pcf status   # qué cambió
wp pcf sync     # importar a la base de datos
```

o el aviso **Sincronizar** en *Campos PCF → Grupos*. Si tu tema filtra `acf/settings/save_json`, PCF usará esa misma carpeta.

---

## 7. Importar desde ACF y convivencia

Tres caminos (todos con simulación previa):

- **Admin:** *Campos PCF → Herramientas → Importar desde ACF*.
- **Agente:** `detect-acf` → `import-acf` con `dry_run: true` → `import-acf`.
- **Terminal:** `wp pcf import-acf --dry-run` y luego `wp pcf import-acf`.

Orígenes: base de datos (funciona con ACF desactivado), ACF activo (incluye grupos registrados por PHP o JSON), carpeta `acf-json` o un export JSON (`--file=export.json`).

**Recomendación:** importa con ACF activo o desde la base de datos y después **desactiva ACF**. A partir de ahí `get_field()` lo sirve PCF con los mismos datos.

Si ACF sigue activo, ambos conviven sin errores: ACF mantiene `get_field()` y sus propias pantallas, y PCF funciona con sus funciones `pcf_*`. Para no ver campos duplicados en el editor, marca "Desactivar los grupos originales en ACF" al importar.

---

## 8. Ajustes y filtros

```php
add_filter( 'pcf/settings/save_json', fn() => get_stylesheet_directory() . '/fields' );
add_filter( 'pcf/settings/load_json', fn( $paths ) => array_merge( $paths, [ WP_CONTENT_DIR . '/shared-fields' ] ) );
add_filter( 'pcf/settings/blocks_dirs', fn( $dirs ) => [ get_stylesheet_directory() . '/src/blocks' ] );
add_filter( 'pcf/settings/capability', fn() => 'edit_theme_options' );   // quién puede cambiar la estructura
add_filter( 'pcf/settings/acf_compat', '__return_false' );               // no declarar get_field() (funciona desde functions.php)
add_filter( 'pcf/settings/json', '__return_false' );                     // desactivar Local JSON
add_filter( 'pcf/allow_file_writes', '__return_true' );                  // permitir create-block fuera de local
add_filter( 'pcf/sideload_remote_media', '__return_false' );             // no descargar URLs externas
add_filter( 'pcf/mcp/public', fn( $public, $op ) => $op !== 'delete-field-group', 10, 2 );
add_filter( 'pcf/mcp/create_server', '__return_false' );                 // no crear /wp-json/mcp/pcf
```

Añadir operaciones propias (aparecen solas en los tres canales):

```php
add_action( 'plugins_loaded', function () {
	PCF_Operations::register( 'mi-operacion', [
		'label'       => 'Mi operación',
		'description' => 'Qué hace, explicado para el agente.',
		'properties'  => [ 'id' => [ 'type' => 'integer', 'description' => 'ID del post' ] ],
		'required'    => [ 'id' ],
		'callback'    => fn( $input ) => [ 'ok' => true ],
		'readonly'    => true,
	] );
} );
```

---

## 8.1 Duplicar entradas con sus campos

WordPress no trae "duplicar". PCF lo añade en el listado de cualquier tipo de
contenido con interfaz —páginas, entradas y los CPT del sitio—, en tres sitios:

- **Duplicar** en la fila del listado, junto a Editar y Papelera.
- **Duplicar** en el desplegable de acciones masivas.
- **Duplicar con sus campos** en la caja "Publicar" del editor clásico.

La copia se lleva el contenido, el extracto, la imagen destacada, la plantilla
de página, el orden, el padre, las taxonomías y **todo el meta**, que es donde
viven los valores de los campos: repetidores, grupos anidados, enlaces y
relaciones incluidos. Sale siempre como **borrador** y con el autor de quien
la hizo, y se abre en el editor.

Hace falta poder editar la entrada original y crear entradas de ese tipo, así
que un suscriptor no ve el enlace. Se excluyen los medios y los tipos internos
de WordPress (`wp_block`, `wp_template`…).

```php
// Solo páginas y productos.
add_filter( 'pcf/duplicate/post_types', fn() => [ 'page', 'producto' ] );

// No copiar un meta propio.
add_filter( 'pcf/duplicate/skip_meta', fn( $claves ) => array_merge( $claves, [ '_mi_contador' ] ) );

// Publicar la copia en vez de dejarla en borrador.
add_filter( 'pcf/duplicate/postarr', function ( $postarr, $post ) {
	$postarr['post_status'] = 'publish';
	return $postarr;
}, 10, 2 );

// Algo más después de copiar.
add_action( 'pcf/duplicate/after', fn( $nuevo, $original ) => error_log( "copiado $original -> $nuevo" ), 10, 2 );
```

> **Ojo con los grupos ubicados por ID de página.** Si un grupo usa la regla
> `page == 12`, la copia se lleva los valores pero el grupo no se muestra en su
> editor, porque la regla apunta a la página original. Para que la copia sea
> editable, ubica el grupo por `post_type`, por plantilla de página o añade la
> nueva página a la regla.

---

## 9. Seguridad

- Las operaciones de estructura requieren `manage_options` (configurable). Las de contenido comprueban `edit_post`, `edit_term`, `edit_user` o la capacidad de la página de opciones sobre el objeto concreto.
- Toda entrada se valida contra el JSON Schema de la operación antes de ejecutarse.
- La escritura de archivos del tema está limitada a entornos `local`/`development` y a nombres de archivo concretos dentro de la carpeta del bloque.
- El HTML de los campos WYSIWYG se filtra con `wp_kses_post` para usuarios sin `unfiltered_html`.
- PCF está pensado para desarrollo. En producción puedes dejar el plugin (el tema sigue funcionando) sin exponer los transportes MCP: no instales el MCP Adapter, revoca las contraseñas de aplicación y usa `pcf/mcp/public` si lo necesitas.

---

## 10. Limitaciones conocidas

- La estructura se edita por MCP o con el editor JSON del admin; no hay constructor visual de arrastrar y soltar.
- No hay `acf_form()` (formularios en el front).
- Reglas de ubicación de ACF no soportadas: `widget`, `nav_menu`, `nav_menu_item`. Al importar se omiten con aviso. Los tipos de campo de add-ons de terceros se importan como `text` (se guarda el tipo original en `pcf_original_type`).
- La regla `comment` existe pero aún no se pintan campos en el formulario de comentarios.
- Al duplicar, los grupos ubicados por ID de página no se muestran en la copia (ver 8.1); los valores sí se copian.
- Los selectores de post, término y usuario del admin cargan hasta 300 elementos (200 en la barra lateral de bloques), sin búsqueda remota.
- `google_map` guarda dirección y coordenadas con un enlace a Google Maps, sin mapa interactivo.
- Los grupos que dependen de la plantilla de página aparecen tras guardar y recargar (no se actualizan en vivo al cambiar la plantilla).
- En la barra lateral de bloques, WYSIWYG se edita como HTML en un área de texto.

---

## 11. Desarrollo

```
prompt-custom-fields.php         Bootstrap (clase PCF)
includes/
  pcf-*-functions.php            Núcleo: campos, grupos, valores, metadatos (formato ACF)
  fields/                        37 tipos de campo
  locations/                     20 reglas de ubicación
  api/api-template.php           API pcf_* (loops, valores)
  compat/acf-compat.php          get_field() y compañía
  forms/                         Meta boxes, términos, usuarios, renderizado
  options/ post-types/ blocks/   Registros
  import/                        Importador ACF y exportador JSON/PHP
  mcp/                           Registro de operaciones + Abilities/MCP Adapter + REST + configuraciones
  cli/                           wp pcf
  admin/                         Pantallas de administración
assets/                          JS/CSS de formularios, admin y bloques (sin compilación)
mcp-server/                      Servidor MCP en Node (dist/ ya empaquetado)
bin/pcf-smoke-test.php           Prueba de humo
blocks-example/                  Bloque de ejemplo
```

Servidor Node: `cd mcp-server && npm install && npm run build` regenera `dist/`. `npm test` lanza un cliente MCP de prueba contra el bundle (usa las mismas variables de entorno).
