# Servidor MCP de Prompt Custom Fields

`dist/pcf-mcp-server.mjs` es un servidor MCP (STDIO) autocontenido: sólo necesita Node 18+. Lee la lista de operaciones del propio WordPress, así que cualquier operación nueva registrada en PHP aparece sin tocar este código.

## Variables de entorno

| Variable | Modo | Descripción |
|---|---|---|
| `PCF_BACKEND` | ambos | `rest` o `cli` (por defecto `rest` si hay `WP_URL`) |
| `WP_URL` | rest | URL del sitio, p. ej. `http://misitio.local` |
| `WP_USER` | ambos | Usuario de WordPress (administrador) |
| `WP_APP_PASSWORD` | rest | Contraseña de aplicación |
| `WP_REST_BASE` | rest | Base REST alternativa (por defecto `{WP_URL}/wp-json`; si da 404 se prueba `?rest_route=`) |
| `PCF_INSECURE_TLS` | rest | `1` para aceptar el certificado autofirmado de Local |
| `WP_PATH` | cli | Carpeta de WordPress (`.../app/public`) |
| `WP_CLI_BIN` | cli | Binario de WP-CLI o ruta a `wp-cli.phar` |
| `PHP_BIN` | cli | PHP para ejecutar un `.phar` |
| `PCF_ENV_FILE` | cli | Entorno capturado (por defecto `mcp-server/.local-env.json`) |
| `PCF_TIMEOUT` | ambos | Milisegundos por llamada (120000) |
| `PCF_TOOL_PREFIX` | ambos | Prefijo de las tools (`pcf-`) |
| `PCF_DEBUG` | ambos | `1` para registrar en stderr |

## Archivos

- `dist/pcf-mcp-server.mjs` — servidor empaquetado (el que usan los clientes).
- `index.js` — código fuente.
- `capture-env.js` — ejecútalo en "Open site shell" de Local para guardar PATH/PHPRC/MYSQL_HOME.
- `wp-env.js` — lanza un comando (p. ej. `wp mcp-adapter serve`) con ese entorno.
- `test-client.mjs` — cliente MCP de prueba (`npm install` y `npm test`).

## Comprobación rápida

```bash
PCF_BACKEND=rest WP_URL=http://misitio.local WP_USER=admin WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx" \
  node dist/pcf-mcp-server.mjs --check
```
