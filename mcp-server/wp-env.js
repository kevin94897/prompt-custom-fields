#!/usr/bin/env node
/**
 * Lanza un comando con el entorno de Local guardado por capture-env.js.
 * Pensado para el MCP Adapter por STDIO:
 *
 *   node wp-env.js wp --path=/.../app/public mcp-adapter serve --server=pcf-server --user=admin
 *
 * Si el primer argumento es "wp" se usa el binario de WP-CLI capturado.
 */
import { spawn } from 'node:child_process';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const file = process.env.PCF_ENV_FILE || join(HERE, '.local-env.json');
let extra = {};
if (existsSync(file)) {
  try { extra = JSON.parse(readFileSync(file, 'utf8')).env || {}; } catch (e) { console.error(`[wp-env] ${e.message}`); }
} else {
  console.error(`[wp-env] Aviso: no existe ${file}; se usa el entorno actual.`);
}

let [cmd, ...args] = process.argv.slice(2);
if (!cmd) {
  console.error('Uso: node wp-env.js <comando> [args...]');
  process.exit(2);
}
if (cmd === 'wp' && (process.env.WP_CLI_BIN || extra.PCF_WP_CLI_BIN)) {
  cmd = process.env.WP_CLI_BIN || extra.PCF_WP_CLI_BIN;
}
if (cmd.endsWith('.phar')) {
  args = [cmd, ...args];
  cmd = process.env.PHP_BIN || extra.PCF_PHP_BIN || 'php';
}

const child = spawn(cmd, args, {
  stdio: 'inherit',
  env: { ...process.env, ...extra },
  shell: process.platform === 'win32',
});
child.on('error', (e) => { console.error(`[wp-env] ${e.message}`); process.exit(1); });
child.on('exit', (code) => process.exit(code ?? 0));
for (const sig of ['SIGINT', 'SIGTERM']) process.on(sig, () => child.kill(sig));
