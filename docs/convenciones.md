# Convenciones de NERD para los campos

Cómo se arman los campos de cada página para que el cliente los entienda y
para que su **manual en PDF se escriba solo**. Aplícalas en todo proyecto.
Salen de lo que se hizo en ESE Latam (`themes/ese-latam/inc/pcf-*.php`).

El manual lo arma el generador de NERD (MCP `manual-wp`): fotografía cada
pestaña del escritorio y redacta las láminas con la **etiqueta** y la **ayuda**
de cada campo. Si la ayuda dice «WebP, 1920×1080 px, máx. 400 KB», el manual
lo dice. Si está vacía, el manual solo nombra el campo.

---

## 1. Qué hace el manual con cada campo

| En el campo | En el manual |
|---|---|
| Título de la pestaña (`tab`) | Subtítulo de la lámina: «Hero:», «Productos:» |
| Primeras 4 etiquetas editables | «Podremos editar **titular**, **bajada**, **botón** y **video de fondo**…» |
| `label` + `instructions` | Una viñeta: «**Etiqueta**: instrucciones» |
| Campo `message` | Una viñeta: «**Etiqueta**: mensaje» |
| `repeater` | «**Etiqueta**: instrucciones. Cada fila permite modificar:» y debajo las etiquetas de sus subcampos |
| `placeholder`, `default_value`, `wrapper` | **No aparecen** |

Consecuencias:

- **Lo que el cliente tiene que saber va en `instructions`**, nunca solo en el
  `placeholder`.
- **Máximo 5 campos editables por pestaña.** Una lámina lleva 7 viñetas. Una
  es la introducción y otra el cierre, así que quedan 5 para campos. Con más,
  la sección se parte en dos láminas con la misma captura. Si una sección
  tiene muchos campos, parte la pestaña («Hero» y «Hero — tarjeta»).
- El HTML de la ayuda (`<strong>`, `<code>`) se pierde en el manual y queda
  solo el texto. El sentido no puede depender del formato.
- Deja `instruction_placement` en `label`, que es el valor por defecto. El
  manual lee la ayuda pegada a la etiqueta.

---

## 2. Estructura

### Grupos

- **Un grupo por pantalla.** Título «Contenido de la página» (o «Contenido de
  la portada»). En los tipos de contenido, «Ficha del producto», «Ficha del
  caso»…
- **Key estable:** `group_pagina_<slug>`, `group_home`, `group_ficha_<cpt>`.
- **Ubicación:**
  - Portada: `[[{"param":"page_type","operator":"==","value":"front_page"}]]`
  - Página concreta: `[[{"param":"page","operator":"==","value":"<ID>"}]]`.
    Saca el ID con `get-site-context` o buscando la página.
  - Tipo de contenido: `[[{"param":"post_type","operator":"==","value":"producto"}]]`
  - Lo compartido por todo el sitio: una página de opciones (sección 2,
    «Contenido compartido»).

### Pestañas

- **Una pestaña por sección visible**, en el orden en que aparece al bajar por
  la página. El nombre es el que el cliente reconoce al mirar la web: «Hero»,
  «Misión y visión», «Aliados», «Contactemos». Nada de nombres internos
  («Bloque 3», «Section CTA»).
- Key de pestaña: `tab_<pagina>_<seccion>`, que se normaliza a
  `field_tab_<pagina>_<seccion>`.
- **Numera las pestañas** solo cuando son pasos de un proceso que depende del
  anterior (ficha de producto: «1. Modelos», «2. Litraje», «3. Color», «4.
  Fotos»). Si un mensaje cita una pestaña numerada, el número tiene que
  coincidir.
- Si una sección **no se edita aquí** (sale de un tipo de contenido o de
  opciones), igual lleva su pestaña, con un `message` que diga dónde se
  edita. El manual lo convierte en viñeta y el cliente no se pierde.

### Keys de campo

Pásalas siempre, para que las plantillas y el `conditional_logic` tengan
referencias estables: `"key": "hero_titulo"` se normaliza a
`field_hero_titulo`. Dentro de un repetidor, `<repetidor>_<sub>`
(`marquee_texto`) para que no choquen entre sí.

### Contenido compartido

Contacto, certificaciones o distribuidores, que son iguales en todo el sitio,
van en una **página de opciones** y se leen con `get_field('x', 'option')`.
Si una página necesita otra versión, lleva un grupo «Secciones compartidas»
con un `true_false` `<seccion>_override` y los campos `<seccion>_pag_<campo>`
bajo `conditional_logic`. La precedencia es: override de la página, luego
global, y si no hay nada la sección no se pinta.

### Plantillas

- **Los campos son la única fuente del contenido.** Si un campo está vacío, la
  plantilla no pinta el elemento o se salta la sección. El tema no trae textos
  de respaldo.
- Usa la API compatible con ACF (`get_field`, `have_rows`…), no las
  funciones `pcf_*`, para que el tema siga funcionando si algún día se activa
  ACF.
- Los grupos creados por MCP quedan en `{tema}/pcf-json/`. Versiona esa
  carpeta con el tema.

---

## 3. Patrones de campo

| Elemento | Tipo | Etiqueta | Notas |
|---|---|---|---|
| Antetítulo | `text` | «Antetítulo sobre el titular» | `wrapper.width` 40–50 |
| Titular | `textarea`, `rows` 2 | «Titular principal de la sección» | Ayuda fija del resaltado, ver abajo. `wrapper.width` 50–60 |
| Bajada | `wysiwyg` (`toolbar` basic, `media_upload` 0, `tabs` visual) o `textarea` | «Bajada bajo el titular» | Dile qué pasa con la negrita si la negrita cambia de color |
| Botón / enlace | `link` | «Botón principal (texto y enlace)», «Enlace al catálogo completo» | Ayuda: qué pasa si queda vacío |
| Imagen | `image` | Qué foto es y dónde: «Foto de fondo del hero», «Logo del aliado» | **Siempre** formato, medidas y peso (sección 4) |
| Video | `file`, `mime_types` "mp4,webm" | «Video de fondo del hero» | Formato, medidas, duración y peso |
| Documento | `file`, `mime_types` "pdf" | «Ficha técnica descargable (PDF)» | Peso máximo |
| Lista de elementos | `repeater`, `layout` table | En plural y con su sección: «Pilares de la sección», «Pistas del marquee» | `min`, `max`, `button_label` «Añadir <cosa>» |
| Elegir entre opciones | `select` o `true_false` con `ui` 1 | La pregunta: «¿Cerrar el titular con signo de pregunta?» | |
| Aviso o puntero | `message`, `esc_html` 0 | «Dónde se edita esta lista» | Queda en el manual como viñeta |

Ayuda fija de los titulares, la misma en todo el sitio:

> Encierra entre barras el tramo que va en color de marca: `sectores que |transformamos|`. Cada salto de línea es un renglón del titular.

En la plantilla, las barras se convierten en `<strong>` o `<span class="hl">`
y los saltos en `<br>`: se procesa el texto escapado y el tramo entre barras
se envuelve con la etiqueta.

Ejemplo de la pestaña «Hero» de una página, lista para `create-field-group`:

```json
[
  {"key":"tab_nos_hero","label":"Hero","name":"","type":"tab","placement":"top"},
  {"key":"nos_hero_kicker","label":"Antetítulo sobre el titular","name":"nos_hero_kicker","type":"text",
   "instructions":"Palabra o frase corta en mayúsculas encima del titular. Hasta 30 caracteres. Vacío: no se muestra.",
   "wrapper":{"width":"40"}},
  {"key":"nos_hero_titulo","label":"Titular principal del hero","name":"nos_hero_titulo","type":"textarea","rows":2,
   "instructions":"Frase grande sobre la foto de fondo. Encierra entre barras el tramo que va en color de marca: <code>sectores que |transformamos|</code>. Cada salto de línea es un renglón del titular.",
   "wrapper":{"width":"60"}},
  {"key":"nos_hero_bajada","label":"Bajada bajo el titular","name":"nos_hero_bajada","type":"wysiwyg",
   "toolbar":"basic","media_upload":0,"tabs":"visual",
   "instructions":"Párrafo corto bajo el titular, una o dos frases. El tramo en <strong>negrita</strong> se muestra en verde. Vacía: no se muestra."},
  {"key":"nos_hero_fondo","label":"Foto de fondo del hero","name":"nos_hero_fondo","type":"image",
   "return_format":"url","preview_size":"medium",
   "instructions":"Ocupa toda la pantalla detrás del titular. WebP o JPG · 1920×1080 px (16:9) · máx. 400 KB. Se recorta al centro. Vacía: el hero queda en negro liso."},
  {"key":"nos_hero_cta","label":"Botón principal (texto y enlace)","name":"nos_hero_cta","type":"link",
   "instructions":"Botón verde bajo la bajada. Escribe el texto del botón y la página a la que lleva. Vacío: no se muestra."}
]
```

---

## 4. Cómo redactar etiquetas y ayudas

### Etiquetas: completas y detalladas

**Regla de NERD: cada etiqueta dice qué es el campo y a qué elemento
pertenece, sin necesitar la captura ni el nombre de la pestaña.** El manual
las copia en negrita y las encadena en la frase de apertura («Podremos editar
el titular principal, la bajada bajo el titular…»). Una etiqueta genérica
como «Titular», «Texto» o «Imagen» deja al cliente adivinando.

- En español, con el nombre de lo que el cliente **ve** en la web. Nunca el
  `name` técnico.
- Fórmula: **elemento + papel o ubicación** (+ variante si hay más de una).

  | Genérica (no) | Detallada (sí) |
  |---|---|
  | Titular | Titular principal del hero |
  | Bajada | Bajada bajo el titular |
  | Botón | Botón principal (texto y enlace) |
  | Imagen | Foto de fondo del hero (escritorio) |
  | Texto | Texto de la pista en movimiento |
  | Número | Isla flotante — ancho máximo en píxeles |

- Para las partes de un mismo elemento usa raya y nombra la parte con
  precisión: «Tarjeta flotante — cifra destacada», «Tarjeta flotante — texto
  bajo la cifra».
- Si en la pestaña hay dos campos del mismo tipo, la etiqueta dice en qué se
  diferencian: «Foto — versión escritorio» y «Foto — versión móvil».
- En los repetidores, cada subcampo es una columna que el manual lista bajo
  «Cada fila permite modificar:». Tiene que entenderse sola, con el elemento
  al que pertenece: «Nombre del pilar», «Ícono del pilar», «Enlace de la
  tarjeta». La etiqueta del repetidor va en plural y dice qué lista es:
  «Pilares de la sección», «Logos de aliados».
- Un campo `message` lleva de etiqueta la pregunta que responde: «Dónde se
  editan las tarjetas de sectores».
- Largo razonable: entre 2 y 8 palabras. Si necesita más, el resto va en la
  ayuda.

### Ayudas (`instructions`): obligatorias en todo campo editable

**Ningún campo editable queda sin ayuda**, tampoco los textos. Sin ayuda, el
manual solo nombra el campo. Cada ayuda responde, en este orden y solo lo que
aplique:

1. **Qué es y dónde aparece** en la página: «Frase grande sobre el video, a
   la izquierda.»
2. **Formato y medidas** (imágenes, videos y archivos, siempre).
3. **Límites y recomendaciones**: largo en caracteres o palabras, cantidad
   de filas, tono («Una o dos frases.»).
4. **Qué pasa si queda vacío**, comprobado en la plantilla: «Vacía: no se
   muestra la tarjeta.» Nunca prometas un texto o una imagen de respaldo que
   la plantilla no tiene.

Tutea («Encierra…», «Súbela…»), usa frases cortas y termina con punto.

### Imágenes: formato fijo

```
<Formato> · <ancho>×<alto> px (<proporción>) · máx. <peso>. <Nota>. Vacía: <qué pasa>.
```

- `WebP o JPG · 1920×1080 px (16:9) · máx. 400 KB. Vacía: el hero queda en negro liso.`
- `PNG o WebP con fondo transparente · 1200×1200 px · máx. 300 KB.`
- `SVG, o PNG monocromo de 400 px de ancho. Se muestra en blanco sobre el fondo oscuro.`

**De dónde salen las medidas:** del diseño (Figma) o del CSS. Toma el tamaño
más grande al que se pinta el contenedor en escritorio, multiplícalo por 2
para pantallas retina y redondea (tope 2560 px de ancho). La proporción es la
del contenedor (`aspect-ratio` o el alto fijo con `object-fit: cover`). Si la
imagen se recorta, dilo: «se recorta al centro».

Valores de partida, a confirmar contra el diseño:

| Uso | Medidas | Formato | Peso |
|---|---|---|---|
| Fondo de hero a pantalla completa | 1920×1080 (16:9) | WebP o JPG | 400 KB |
| Recorte flotante con transparencia | 2400 px de ancho | PNG o WebP transparente | 500 KB |
| Foto de tarjeta | 800×600 (4:3) | WebP o JPG | 200 KB |
| Foto de producto sobre fondo neutro | 1200×1200 (1:1) | PNG o WebP transparente | 300 KB |
| Logo de aliado o cliente | 400 px de ancho | SVG o PNG monocromo | 50 KB |
| Imagen para compartir (OG) | 1200×630 | JPG | 300 KB |

### Videos y archivos

- Video: `MP4 (H.264) · 1920×1080 px · hasta 20 s · máx. 8 MB, sin audio. Vacío: <qué pasa>.`
- Documento: `Solo PDF, máx. 5 MB. Sin archivo, el botón de descarga no se muestra.`

### Textos

- Si el diseño se rompe con textos largos, pon el límite en la ayuda **y** en
  el campo (`maxlength`): «Hasta 60 caracteres.»
- Repetidor: «Entre 3 y 6 pilares. Con menos de 3 el bloque se ve vacío.»,
  con `min` y `max` iguales en la definición.

---

## 5. Antes de dar por terminada una página

- [ ] Cada pestaña es una sección visible, en orden, con el nombre que el
      cliente reconoce.
- [ ] Ninguna pestaña tiene más de 5 campos editables. Si tiene más, pártela.
- [ ] Cada etiqueta es completa y detallada: elemento + papel o ubicación. No
      queda ninguna etiqueta genérica suelta («Titular», «Texto», «Imagen»).
- [ ] Todo campo editable tiene ayuda, incluidos los textos, y dice dónde
      aparece.
- [ ] Todas las imágenes, videos y archivos tienen formato, medidas y peso en
      la ayuda.
- [ ] Todo campo opcional dice qué pasa si queda vacío.
- [ ] Las secciones que se editan en otro lado tienen su `message`.
- [ ] Los números de pestaña citados en mensajes coinciden con los reales.
- [ ] Revisar con el MCP `manual-wp`: `listar_pantallas` con la URL de edición
      de la página muestra lo que va a leer el manual, y `generar_manual` con
      `vistas: true` deja las láminas para revisarlas.
