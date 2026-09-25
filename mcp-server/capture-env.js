#!/usr/bin/env node
/**
 * Ejecuta este script DENTRO del "Open site shell" de Local:
 *
 *   node /ruta/al/plugin/mcp-server/capture-env.js
 *
 * Guarda en .local-env.json las variables que Local define para que PHP, MySQL y
 * WP-CLI funcionen (PATH, PHPRC, MYSQL_HOME, WP_CLI_*…). El servidor MCP en modo
 * "cli" y wp-env.js las cargan, así el cliente MCP puede lanzar WP-CLI sin abrir ese shell.
 */
import { writeFileSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const out = resolve(process.argv[2] || join(HERE, '.local-env.json'));
const KEEP = /^(PATH|Path|PHPRC|PHP_INI_SCAN_DIR|MYSQL_HOME|MYSQL_UNIX_PORT|WP_CLI_[A-Z_]+|MAGICK_[A-Z_]+|LD_LIBRARY_PATH|DYLD_[A-Z_]+|SystemRoot|TEMP|TMP)$/;

const envOut = {};
for (const [k, v] of Object.entries(process.env)) {
  if (KEEP.test(k)) envOut[k] = v;
}

const which = (bin) => {
  try {
    const cmd = process.platform === 'win32' ? `where ${bin}` : `command -v ${bin}`;
    return execSync(cmd, { encoding: 'utf8', shell: process.platform === 'win32' ? undefined : '/bin/sh' }).split(/\r?\n/)[0].trim();
  } catch {
    return '';
  }
};

const wp = which('wp');
const php = which('php');
if (wp) envOut.PCF_WP_CLI_BIN = wp;
if (php) envOut.PCF_PHP_BIN = php;

let check = 'no comprobado';
if (wp) {
  try {
    check = execSync('wp option get siteurl', { encoding: 'utf8', cwd: process.cwd(), stdio: ['ignore', 'pipe', 'pipe'] }).trim();
  } catch (e) {
    check = 'wp no pudo conectar desde esta carpeta (normal si no estás en app/public)';
  }
}

writeFileSync(out, JSON.stringify({ created: new Date().toISOString(), cwd: process.cwd(), env: envOut }, null, 2));
console.log(`Entorno guardado en ${out}`);
console.log(`  wp : ${wp || 'NO ENCONTRADO — ¿estás en el "Open site shell" de Local?'}`);
console.log(`  php: ${php || 'NO ENCONTRADO'}`);
console.log(`  siteurl: ${check}`);
console.log(`  WP_PATH sugerido: ${process.cwd()}`);
