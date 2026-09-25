#!/usr/bin/env node
/**
 * Servidor MCP (STDIO) para Prompt Custom Fields.
 *
 * Expone cada operación del plugin como una tool MCP. La lista de operaciones y sus
 * JSON Schema se leen del propio WordPress, así que añadir una operación en PHP la
 * hace aparecer aquí sin tocar este archivo.
 *
 * Backends:
 *   PCF_BACKEND=rest  → WP_URL, WP_USER, WP_APP_PASSWORD  [PCF_INSECURE_TLS=1]
 *   PCF_BACKEND=cli   → WP_PATH, WP_USER  [WP_CLI_BIN=wp] [PHP_BIN=php] [PCF_ENV_FILE]
 *
 * Diagnóstico: node index.js --check
 */

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { CallToolRequestSchema, ListToolsRequestSchema } from '@modelcontextprotocol/sdk/types.js';
import { spawn } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const VERSION = '1.0.0';
const env = process.env;

const BACKEND = (env.PCF_BACKEND || (env.WP_URL ? 'rest' : 'cli')).toLowerCase();
const TIMEOUT = Number(env.PCF_TIMEOUT || 120000);
const PREFIX = env.PCF_TOOL_PREFIX ?? 'pcf-';

if (env.PCF_INSECURE_TLS === '1') {
  env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
}

// Los logs van a stderr: stdout está reservado para el protocolo MCP.
const log = (...a) => { if (env.PCF_DEBUG === '1' || process.argv.includes('--check')) console.error('[pcf-mcp]', ...a); };

/* ------------------------------------------------------------------ */
/* Entorno de Local (capturado con capture-env.js)                      */
/* ------------------------------------------------------------------ */

export function loadLocalEnv(file = env.PCF_ENV_FILE) {
  // El bundle vive en dist/: buscar junto al script y en la carpeta superior.
  if (!file) file = [join(HERE, '.local-env.json'), join(HERE, '..', '.local-env.json')].find((f) => existsSync(f));
  if (!file || !existsSync(file)) return {};
  try {
    const data = JSON.parse(readFileSync(file, 'utf8'));
    return data.env || {};
  } catch (e) {
    console.error(`[pcf-mcp] No se pudo leer ${file}: ${e.message}`);
    return {};
  }
}

/* ------------------------------------------------------------------ */
/* Backend REST                                                         */
/* ------------------------------------------------------------------ */

class RestBackend {
  constructor() {
    const url = (env.WP_URL || '').replace(/\/+$/, '');
    if (!url) throw new Error('Falta WP_URL (ej. http://misitio.local).');
    if (!env.WP_USER || !env.WP_APP_PASSWORD) throw new Error('Faltan WP_USER y/o WP_APP_PASSWORD (Application Password).');
    this.base = env.WP_REST_BASE ? env.WP_REST_BASE.replace(/\/+$/, '') : `${url}/wp-json`;
    this.auth = 'Basic ' + Buffer.from(`${env.WP_USER}:${env.WP_APP_PASSWORD}`).toString('base64');
    this.name = `REST ${this.base}`;
  }

  async request(path, init = {}) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), TIMEOUT);
    try {
      let res = await fetch(this.base + path, {
        ...init,
        signal: ctrl.signal,
        headers: { Authorization: this.auth, Accept: 'application/json', 'Content-Type': 'application/json', ...(init.headers || {}) },
      });
      // Sitios sin enlaces permanentes: /wp-json no existe → usar ?rest_route=
      if (res.status === 404 && !env.WP_REST_BASE && this.base.endsWith('/wp-json')) {
        const alt = this.base.replace(/\/wp-json$/, '') + '/?rest_route=' + encodeURIComponent(path);
        const retry = await fetch(alt, { ...init, signal: ctrl.signal, headers: { Authorization: this.auth, 'Content-Type': 'application/json' } });
        if (retry.ok || retry.status !== 404) {
          this.base = this.base.replace(/\/wp-json$/, '') + '/?rest_route=';
          res = retry;
        }
      }
      const text = await res.text();
      let body;
      try { body = JSON.parse(text); } catch { body = { code: 'invalid_json', message: text.slice(0, 500) }; }
      if (!res.ok) {
        const hint = res.status === 401 ? ' Revisa WP_USER / WP_APP_PASSWORD (las Application Passwords requieren HTTPS o WP_ENVIRONMENT_TYPE=local).' : '';
        const err = new Error(`${body.message || res.statusText}${hint}`);
        err.code = body.code || `http_${res.status}`;
        err.data = body.data;
        throw err;
      }
      return body;
    } finally {
      clearTimeout(timer);
    }
  }

  async listOperations() {
    return this.request('/pcf/v1/operations');
  }

  async run(name, input) {
    const body = await this.request(`/pcf/v1/operations/${encodeURIComponent(name)}`, {
      method: 'POST',
      body: JSON.stringify(input ?? {}),
    });
    return body.result;
  }
}

/* ------------------------------------------------------------------ */
/* Backend WP-CLI                                                       */
/* ------------------------------------------------------------------ */

class CliBackend {
  constructor() {
    if (!env.WP_PATH) throw new Error('Falta WP_PATH (carpeta de WordPress, en Local: .../app/public).');
    this.path = env.WP_PATH;
    this.user = env.WP_USER || 'admin';
    this.extraEnv = loadLocalEnv();
    const bin = env.WP_CLI_BIN || this.extraEnv.PCF_WP_CLI_BIN || 'wp';
    // Si WP_CLI_BIN es un .phar se ejecuta con PHP.
    if (bin.endsWith('.phar')) {
      this.cmd = env.PHP_BIN || this.extraEnv.PCF_PHP_BIN || 'php';
      this.pre = [bin];
    } else {
      this.cmd = bin;
      this.pre = [];
    }
    this.name = `WP-CLI ${this.cmd} ${this.pre.join(' ')} --path=${this.path}`;
  }

  exec(args, stdin) {
    return new Promise((resolve, reject) => {
      const child = spawn(this.cmd, [...this.pre, ...args, `--path=${this.path}`, `--user=${this.user}`], {
        env: { ...process.env, ...this.extraEnv },
        shell: process.platform === 'win32',
        windowsHide: true,
      });
      let out = '';
      let err = '';
      const timer = setTimeout(() => { child.kill(); reject(new Error(`WP-CLI superó ${TIMEOUT} ms`)); }, TIMEOUT);
      child.stdout.on('data', (d) => (out += d));
      child.stderr.on('data', (d) => (err += d));
      child.on('error', (e) => {
        clearTimeout(timer);
        reject(new Error(`No se pudo ejecutar "${this.cmd}": ${e.message}. En Local usa capture-env.js o define WP_CLI_BIN.`));
      });
      child.on('close', (code) => {
        clearTimeout(timer);
        resolve({ code, out, err });
      });
      if (stdin !== undefined) child.stdin.end(stdin);
      else child.stdin.end();
    });
  }

  /** Extrae el último JSON de la salida (PHP puede imprimir avisos antes). */
  static parse(out) {
    const lines = out.trim().split(/\r?\n/).reverse();
    for (const line of lines) {
      const t = line.trim();
      if (t.startsWith('{')) {
        try { return JSON.parse(t); } catch { /* sigue */ }
      }
    }
    return null;
  }

  async listOperations() {
    const { code, out, err } = await this.exec(['pcf', 'operations', '--format=json']);
    const data = CliBackend.parse(out);
    if (!data) {
      throw new Error(`WP-CLI no devolvió operaciones (código ${code}). ${(err || out).trim().slice(0, 600)}`);
    }
    return data;
  }

  async run(name, input) {
    const { code, out, err } = await this.exec(['pcf', 'run', name, '--input=-'], JSON.stringify(input ?? {}));
    const data = CliBackend.parse(out);
    if (!data) {
      throw new Error(`WP-CLI falló (código ${code}): ${(err || out).trim().slice(0, 800)}`);
    }
    if (!data.ok) {
      const e = new Error(data.error?.message || 'Error');
      e.code = data.error?.code;
      e.data = data.error?.data;
      throw e;
    }
    return data.result;
  }
}

/* ------------------------------------------------------------------ */
/* Servidor MCP                                                         */
/* ------------------------------------------------------------------ */

export function createBackend() {
  if (BACKEND === 'rest') return new RestBackend();
  if (BACKEND === 'cli') return new CliBackend();
  throw new Error(`PCF_BACKEND desconocido: ${BACKEND} (usa rest o cli)`);
}

const toolName = (op) => `${PREFIX}${op}`;
const opName = (tool) => (tool.startsWith(PREFIX) ? tool.slice(PREFIX.length) : tool);

function toTool(op) {
  const schema = op.input_schema || { type: 'object', properties: {} };
  if (Array.isArray(schema.properties) || !schema.properties) schema.properties = {};
  return {
    name: toolName(op.name),
    title: op.label,
    description: op.description,
    inputSchema: schema,
    annotations: { title: op.label, ...(op.annotations || {}) },
  };
}

async function main() {
  const backend = createBackend();
  log('backend:', backend.name);

  if (process.argv.includes('--check')) {
    const data = await backend.listOperations();
    console.error(`[pcf-mcp] OK: ${data.site} · PCF ${data.version} · ${data.operations.length} operaciones`);
    const ctx = await backend.run('get-site-context', {});
    console.error(`[pcf-mcp] Usuario: ${ctx.current_user.login} (admin: ${ctx.current_user.admin}) · Tema: ${ctx.theme.name} · Entorno: ${ctx.site.environment}`);
    return;
  }

  let cache = null;
  const loadTools = async () => {
    if (!cache) {
      const data = await backend.listOperations();
      cache = data.operations.map(toTool);
      log(`${cache.length} tools cargadas de ${data.site}`);
    }
    return cache;
  };

  const server = new Server(
    { name: 'prompt-custom-fields', version: VERSION },
    {
      capabilities: { tools: {} },
      instructions:
        'Herramientas para gestionar campos personalizados de WordPress (formato ACF PRO). ' +
        `Empieza con ${toolName('get-site-context')} y ${toolName('get-usage-guide')}. ` +
        `Crea estructuras completas con ${toolName('create-field-group')} y rellena contenido con ${toolName('update-values')}.`,
    }
  );

  server.setRequestHandler(ListToolsRequestSchema, async () => {
    try {
      return { tools: await loadTools() };
    } catch (e) {
      // Sin conexión: se expone una tool de diagnóstico en lugar de fallar.
      return {
        tools: [
          {
            name: toolName('connection-status'),
            description: `No se pudo conectar con WordPress (${backend.name}): ${e.message}. Llama a esta tool para reintentar.`,
            inputSchema: { type: 'object', properties: {} },
          },
        ],
      };
    }
  });

  server.setRequestHandler(CallToolRequestSchema, async (req) => {
    const name = opName(req.params.name);
    const args = req.params.arguments || {};
    if (name === 'connection-status') {
      cache = null;
      try {
        const tools = await loadTools();
        return { content: [{ type: 'text', text: `Conectado. ${tools.length} tools disponibles; vuelve a listar las tools.` }] };
      } catch (e) {
        return { isError: true, content: [{ type: 'text', text: e.message }] };
      }
    }
    try {
      const result = await backend.run(name, args);
      const payload = result && typeof result === 'object' && !Array.isArray(result) ? result : { result };
      return {
        content: [{ type: 'text', text: JSON.stringify(result, null, 2) }],
        structuredContent: payload,
      };
    } catch (e) {
      const detail = { code: e.code || 'error', message: e.message, data: e.data };
      return { isError: true, content: [{ type: 'text', text: JSON.stringify(detail, null, 2) }] };
    }
  });

  await server.connect(new StdioServerTransport());
  log('servidor MCP listo (stdio)');
}

main().catch((e) => {
  console.error(`[pcf-mcp] ${e.message}`);
  process.exit(1);
});
