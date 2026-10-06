// Only the API is simulated: Chromium runs the shipped Dioxus/WASM application.
// PHP validation and dispatch behavior are covered by scripts/test/run.py.
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const http = require('node:http');
const path = require('node:path');

const wasmDirectory = path.resolve(__dirname, '../../plugin/wasm');
const assetPrefix = '/source/plugin/pokemon/wasm/';
const html = `<!doctype html><html lang="zh-CN"><meta charset="utf-8">
<title>Pokemon admin browser fixture</title>
<link rel="stylesheet" href="${assetPrefix}admin.css">
<script src="${assetPrefix}lucide.min.js"></script>
<div id="main"></div><script type="module">
import init, { WebHandle } from '${assetPrefix}_admin.js';
await init({module_or_path: '${assetPrefix}_admin_bg.wasm'});
await new WebHandle().start();
</script></html>`;

async function startFixture() {
  const state = {
    effects: [],
    skills: [{
      _TYPE: 'skill_type', id: 1, name: '浏览器测试技能', description: '',
      available_pokemons: [], min_level_limit: 1, use_times_limit: 20,
      effect: {physical_damage: ['normal', 40]}, effect_id: 0,
    }],
    requests: [],
    unexpected: [],
    failNext: null,
    nextEffectId: 1,
  };

  function dispatch(request) {
    state.requests.push(structuredClone(request));
    const {action} = request;
    if (state.failNext === action) {
      state.failNext = null;
      return {success: false, reason: '浏览器测试：模拟保存失败'};
    }
    if (action === 'list::global_config') {
      return {success: true, data: [{_TYPE: 'global_config', version: 'browser-test'}]};
    }
    const [operation, entity] = action.split('::');
    const rows = entity === 'effect_data' ? state.effects
      : entity === 'skill_type' ? state.skills : null;
    if (!rows) throw new Error(`Unexpected API action: ${action}`);
    const id = Number(request.id);
    if (operation === 'count') return {success: true, data: [{count: rows.length}]};
    if (operation === 'list') {
      const from = Number(request.from || 0);
      return {success: true, data: rows.slice(from, from + Number(request.count || 100))};
    }
    if (operation === 'get') return {success: true, data: rows.filter(row => row.id === id)};
    if (operation === 'delete') {
      if (entity === 'effect_data' && state.skills.some(skill => skill.effect_id === id)) {
        return {success: false, reason: '该效果仍被技能引用，不能删除'};
      }
      const index = rows.findIndex(row => row.id === id);
      assert.notEqual(index, -1, 'Delete must reference an existing record');
      rows.splice(index, 1);
      return {success: true, data: null};
    }
    const data = JSON.parse(request.data);
    if (operation === 'insert' && entity === 'effect_data') {
      const item = {...data, id: state.nextEffectId++, _TYPE: entity};
      rows.push(item);
      return {success: true, data: [item]};
    }
    if (operation === 'set') {
      const index = rows.findIndex(row => row.id === data.id);
      assert.notEqual(index, -1, 'Save must reference an existing record');
      // Reject missing effect_id: a permissive fixture would conceal the serde bug.
      if (entity === 'skill_type') assert.equal(typeof data.effect_id, 'number');
      rows[index] = {...data, _TYPE: entity};
      return {success: true, data: [rows[index]]};
    }
    throw new Error(`Unexpected API action: ${action}`);
  }

  const server = http.createServer(async (request, response) => {
    try {
      const url = new URL(request.url, 'http://localhost');
      if (request.method === 'POST' && url.pathname === '/plugin.php') {
        let body = '';
        for await (const chunk of request) body += chunk;
        const result = dispatch(JSON.parse(body));
        response.writeHead(200, {'Content-Type': 'application/json; charset=utf-8'});
        response.end(JSON.stringify(result));
      } else if (url.pathname === '/plugin.php') {
        response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
        response.end(html);
      } else if (url.pathname.startsWith(assetPrefix)) {
        const relative = decodeURIComponent(url.pathname.slice(assetPrefix.length));
        const filename = path.resolve(wasmDirectory, relative);
        assert.ok(filename.startsWith(wasmDirectory + path.sep));
        const contentType = {'.wasm': 'application/wasm', '.js': 'text/javascript', '.css': 'text/css'};
        const bytes = await fs.readFile(filename);
        response.writeHead(200, {'Content-Type': contentType[path.extname(filename)] || 'application/octet-stream'});
        response.end(bytes);
      } else {
        response.writeHead(404);
        response.end();
      }
    } catch (error) {
      state.unexpected.push(String(error));
      if (!response.headersSent) response.writeHead(500, {'Content-Type': 'application/json'});
      response.end(JSON.stringify({success: false, reason: String(error)}));
    }
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  return {
    state,
    url: `http://127.0.0.1:${server.address().port}/plugin.php?id=pokemon:pokemon&index=admin`,
    close: () => new Promise(resolve => server.close(resolve)),
  };
}

module.exports = {startFixture};
