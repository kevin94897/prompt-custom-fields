// Cliente MCP de prueba: node test-client.mjs  (usa las mismas variables de entorno que index.js)
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const transport = new StdioClientTransport({ command: process.execPath, args: [join(here, process.env.PCF_TEST_SOURCE ? 'index.js' : 'dist/pcf-mcp-server.mjs')], env: process.env, stderr: 'inherit' });
const client = new Client({ name: 'pcf-test', version: '1.0.0' });
await client.connect(transport);
const t0 = Date.now();
const { tools } = await client.listTools();
console.log(`tools: ${tools.length} (${Date.now() - t0} ms) → ${tools.slice(0, 4).map((t) => t.name).join(', ')}…`);
const call = async (name, args) => {
  const r = await client.callTool({ name, arguments: args });
  const text = r.content?.[0]?.text || '';
  console.log(`${name}: ${r.isError ? 'ERROR ' : ''}${text.replace(/\s+/g, ' ').slice(0, 160)}`);
  return r;
};
await call('pcf-get-site-context', {});
await call('pcf-create-field-group', { title: `Test ${process.env.PCF_BACKEND}`, key: `test_${process.env.PCF_BACKEND}`, location: 'post_type:post', fields: [{ label: 'Resumen', type: 'textarea' }, { label: 'Puntos', type: 'repeater', sub_fields: [{ label: 'Punto', type: 'text' }] }] });
await call('pcf-save-post', { title: `Post ${process.env.PCF_BACKEND}`, status: 'publish', values: { resumen: 'Creado por MCP', puntos: [{ punto: 'uno' }, { punto: 'dos' }] } });
await call('pcf-get-values', { object: 999999 });
await call('pcf-create-field-group', { title: 'Mal' });
await call('pcf-delete-field-group', { key: `group_test_${process.env.PCF_BACKEND}` });
await client.close();
