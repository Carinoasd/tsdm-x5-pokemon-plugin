const fs = require('node:fs/promises');
const http = require('node:http');
const path = require('node:path');
const assert = require('node:assert/strict');
const {chromium, expect} = require('@playwright/test');
const assetRoot = path.resolve(process.env.ADMIN_WASM_DIRECTORY || path.join(__dirname, '../../plugin/wasm'));
const artifacts = path.join(__dirname, 'artifacts/admin-actions');
const prefix = '/source/plugin/pokemon/wasm/';
const html = `<!doctype html><html><meta charset="utf-8"><link rel="stylesheet" href="${prefix}admin.css"><script src="${prefix}lucide.min.js"></script><div id="main"></div><script type="module">import init,{WebHandle} from '${prefix}_admin.js';await init({module_or_path:'${prefix}_admin_bg.wasm'});await new WebHandle().start();</script></html>`;
async function fixture(options = {}) {
  const state = {requests: [], rows: {}, unexpected: [], hold: null, afterHold: null, fail: null, releases: [], completed: [], itemReplies: {},
    config: {_TYPE:'global_config',version:'proof',ann_title:'server title',ann_url:'https://example.invalid/notice',medical_price:7777,egg_price:3210},
    ...options};
  const server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, 'http://localhost');
      if (req.method === 'POST' && url.pathname === '/plugin.php') {
        let body = ''; for await (const part of req) body += part;
        const payload = JSON.parse(body); state.requests.push(payload);
        const [operation, entity] = payload.action.split('::');
        if (state.hold === payload.action || (typeof state.hold === 'function' && state.hold(payload))) await new Promise(resolve => state.releases.push(resolve));
        let result;
        if (state.fail === payload.action) result = {success:false,reason:'proof rejected save'};
        else if (payload.action === 'list::global_config') {
          result = state.failInitialConfig && state.requests.filter(p=>p.action==='list::global_config').length===1
            ? {success:false,reason:'initial config unavailable'} : {success:true,data:[state.config]};
        } else if (payload.action === 'set::global_config') {
          state.config = {...JSON.parse(payload.data),_TYPE:'global_config'};
          state.config.ann_title = state.config.ann_title.trim();
          result = {success:true,data:[state.config]};
        }
        else {
          const rows = state.rows[entity] ||= [];
          if (operation === 'count') result = {success:true,data:[{count:rows.length}]};
          else if (operation === 'list' || operation === 'filter') result = {success:true,data:entity === 'item_info' && state.itemReplies[payload.uid] ? state.itemReplies[payload.uid] : payload.uid ? rows.filter(row=>row.owner===Number(payload.uid)) : rows};
          else if (operation === 'insert') {
            const row = {...JSON.parse(payload.data), id:rows.length+1, _TYPE:entity}; rows.push(row);
            result = {success:true,data:[row]};
          } else if (operation === 'set') {
            const row = {...JSON.parse(payload.data),_TYPE:entity};
            const index = rows.findIndex(item=>item.id===row.id);assert.notEqual(index,-1);rows[index]=row;
            result = {success:true,data:[row]};
          }
          else throw new Error(`Unexpected action ${payload.action}`);
        }
        if (state.afterHold?.(payload)) await new Promise(resolve => state.releases.push(resolve));
        state.completed.push(payload);
        res.writeHead(200, {'Content-Type':'application/json; charset=utf-8'}); res.end(JSON.stringify(result));
      } else if (url.pathname.startsWith(prefix)) {
        const filename = path.resolve(assetRoot, decodeURIComponent(url.pathname.slice(prefix.length)));
        assert.ok(filename.startsWith(assetRoot + path.sep));
        const bytes = await fs.readFile(filename);
        res.writeHead(200, {'Content-Type':{'.wasm':'application/wasm','.js':'text/javascript','.css':'text/css'}[path.extname(filename)] || 'application/octet-stream'});res.end(bytes);
      } else if (url.pathname === '/plugin.php') { res.writeHead(200,{'Content-Type':'text/html; charset=utf-8'});res.end(html); }
      else {res.writeHead(404);res.end();}
    } catch (error) {state.unexpected.push(String(error));res.writeHead(500);res.end(String(error));}
  });
  await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
  return {state,url:`http://127.0.0.1:${server.address().port}/plugin.php?id=pokemon:pokemon&index=admin`,close:async()=>{state.releases.splice(0).forEach(resolve=>resolve());server.closeAllConnections();await new Promise(resolve=>server.close(resolve));}};
}
async function run(name, action, options = {}) {
  if (process.env.ADMIN_CASE_FILTER && !new RegExp(process.env.ADMIN_CASE_FILTER).test(name)) return;
  await fs.mkdir(artifacts,{recursive:true});
  const app = await fixture(options); const browser = await chromium.launch({headless:true});
  const context = await browser.newContext({viewport:{width:1440,height:1000}});
  context.setDefaultTimeout(10000);context.setDefaultNavigationTimeout(20000);
  await context.tracing.start({screenshots:true,snapshots:true});
  const page = await context.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
  try {
    await page.goto(app.url);
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await action(page,app.state);
    assert.deepEqual(errors,[],'No unhandled browser errors');
    assert.deepEqual(app.state.unexpected,[],'All API requests match the fixture contract');
    console.log(`PASS ${name}`);
  } finally {
    await page.screenshot({path:path.join(artifacts,`${name}.png`),fullPage:true}).catch(()=>{});
    await fs.writeFile(path.join(artifacts,`${name}.json`),JSON.stringify({errors,unexpected:app.state.unexpected,requests:app.state.requests},null,2));
    await context.tracing.stop({path:path.join(artifacts,`${name}.zip`)}).catch(()=>{});
    await browser.close();await app.close();
  }
}
async function openCreate(page, nav, create) {
  await page.getByRole('button',{name:nav,exact:true}).click();
  await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
  await page.getByRole('button',{name:create,exact:true}).click();
  await expect(page.getByRole('button',{name:'创建记录',exact:true})).toBeVisible();
}
function seedUsers(state) {
  state.rows.user_info=[1,2].map(id=>({_TYPE:'user_info',id,name:`Trainer${id}`,win_count:0,lose_count:0,money:50,experience:0,pokemon_list:[],item_list:[id*100]}));
  state.rows.item_type=[{_TYPE:'item_type',id:1,name:'Potion',img_name:'',description:'',is_selling:false,price:0,tag:'drug',limits:{min_level:0,kind_require:null},effects:{}}];
  state.rows.item_info=[1,2].map(owner=>({_TYPE:'item_info',id:owner*100,owner,type_id:1,count:5}));
}
async function openUser(page, uid) {
  await page.locator('tbody tr').filter({hasText:`Trainer${uid}`}).click();
  await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
  await expect(page.locator('.admin-table--user-item-info tbody tr')).toHaveCount(1);
}
(async()=>{
  await run('config-initial-failure-blocks-default-save-and-can-retry',async(page,state)=>{
    const title=page.getByPlaceholder('输入公告标题',{exact:true});
    await expect(page.locator('.admin-toast--error')).toContainText('initial config unavailable');
    await expect(title).toBeDisabled();
    await expect(page.getByRole('button',{name:'保存配置',exact:true})).toBeDisabled();
    await page.getByRole('button',{name:'道具数据',exact:true}).click();
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await page.getByRole('button',{name:'全局配置',exact:true}).click();
    assert.equal(state.requests.filter(p=>p.action==='set::global_config').length,0);
    await page.getByRole('button',{name:'重新读取配置',exact:true}).click();
    await expect(title).toHaveValue('server title');
    await expect(title).toBeEnabled();
    await title.fill('changed title');
    await page.getByRole('button',{name:'保存配置',exact:true}).click();
    await expect(page.locator('.admin-toast--success')).toContainText('全局配置已保存');
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    const saved=JSON.parse(state.requests.find(p=>p.action==='set::global_config').data);
    assert.equal(saved.medical_price,7777);assert.equal(saved.egg_price,3210);
    assert.equal(saved.ann_url,'https://example.invalid/notice');assert.equal(saved.ann_title,'changed title');
    assert.equal(state.requests.filter(p=>p.action==='list::global_config').length,2);
  },{failInitialConfig:true});
  await run('config-rejected-save-retains-draft-for-retry',async(page,state)=>{
    const title=page.getByPlaceholder('输入公告标题',{exact:true});
    await expect(title).toHaveValue('server title');
    await title.fill('keep this draft');
    state.fail='set::global_config';
    await page.getByRole('button',{name:'保存配置',exact:true}).click();
    await expect(page.locator('.admin-toast--error')).toContainText('proof rejected save');
    await expect(title).toHaveValue('keep this draft');await expect(title).toBeEnabled();
    assert.equal(state.config.ann_title,'server title');
    state.fail=null;
    await page.getByRole('button',{name:'保存配置',exact:true}).click();
    await expect(page.locator('.admin-toast--success')).toContainText('全局配置已保存');
    assert.equal(state.config.ann_title,'keep this draft');
    assert.equal(state.requests.filter(p=>p.action==='set::global_config').length,2);
  });
  await run('config-pending-save-blocks-edits-and-repeat-submission',async(page,state)=>{
    const title=page.getByPlaceholder('输入公告标题',{exact:true});
    await expect(title).toHaveValue('server title');
    await title.fill('  canonical title  ');
    state.hold='set::global_config';
    await page.getByRole('button',{name:'保存配置',exact:true}).evaluate(button=>{button.click();button.click();button.click();});
    await expect.poll(()=>state.releases.length).toBe(1);
    await expect(title).toBeDisabled();
    await title.focus();await page.keyboard.type('late edit');
    await expect(title).toHaveValue('  canonical title  ');
    assert.equal(state.requests.filter(p=>p.action==='set::global_config').length,1);
    state.hold=null;state.releases.splice(0).forEach(resolve=>resolve());
    await expect(title).toHaveValue('canonical title');
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await expect(page.getByRole('button',{name:'保存配置',exact:true})).toBeDisabled();
    assert.equal(state.requests.filter(p=>p.action==='list::global_config').length,1);
  });
  await run('map-save-busy',async(page,state)=>{
    await openCreate(page,'地图设定','新增地图');
    await page.getByPlaceholder('地图名称',{exact:true}).fill('proof map');
    await page.getByRole('button',{name:'创建记录',exact:true}).click();
    await expect.poll(()=>state.rows.map_info?.length).toBe(1);
    await expect(page.locator('.admin-toast--success')).toContainText('已新增地图');
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await expect(page.getByRole('button',{name:'用户数据',exact:true})).toBeEnabled();
  });
  await run('evolution-save-busy',async(page,state)=>{
    state.rows.evolution_info=[{_TYPE:'evolution_info',id:1,source_id:25,target_id:26,source_name:'Pikachu',target_name:'Raichu',condition:{min_level:20},priority:1}];
    await page.getByRole('button',{name:'进化路线',exact:true}).click();
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await page.locator('tbody tr').filter({hasText:'Pikachu'}).click();
    await page.getByRole('button',{name:'保存修改',exact:true}).click();
    await expect(page.locator('.admin-toast--success')).toContainText('已更新');
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await expect(page.getByRole('button',{name:'用户数据',exact:true})).toBeEnabled();
  });
  await run('skill-save-busy',async(page,state)=>{
    await openCreate(page,'技能数据','新增技能');
    await page.getByPlaceholder('技能名称',{exact:true}).fill('proof skill');
    await page.getByRole('button',{name:'创建记录',exact:true}).click();
    await expect.poll(()=>state.rows.skill_type?.length).toBe(1);
    await expect(page.locator('.admin-toast--success')).toContainText('已创建');
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await expect(page.getByRole('button',{name:'用户数据',exact:true})).toBeEnabled();
  });
  await run('item-double-create',async(page,state)=>{
    await openCreate(page,'道具数据','新增道具');
    await page.getByPlaceholder('道具名称',{exact:true}).fill('proof item');
    state.hold='insert::item_type';
    await page.getByRole('button',{name:'创建记录',exact:true}).evaluate(button=>{button.click();button.click();button.click();});
    await expect.poll(()=>state.requests.filter(p=>p.action==='insert::item_type').length).toBeGreaterThan(0);
    await page.waitForTimeout(100);
    assert.equal(state.requests.filter(p=>p.action==='insert::item_type').length,1,'One click burst creates one request');
    await expect(page.getByRole('button',{name:'用户数据',exact:true})).toBeDisabled();
    state.releases.splice(0).forEach(resolve=>resolve());
    await expect.poll(()=>state.rows.item_type?.length).toBeGreaterThan(0);
    assert.equal(state.rows.item_type.length,1);
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await expect(page.locator('.admin-action-modal')).toHaveCount(0);
  });
  await run('item-rejected-save',async(page,state)=>{
    await openCreate(page,'道具数据','新增道具');
    await page.getByPlaceholder('道具名称',{exact:true}).fill('unsaved important draft');
    state.fail='insert::item_type';
    await page.getByRole('button',{name:'创建记录',exact:true}).click();
    await expect(page.locator('.admin-toast--error')).toContainText('proof rejected save');
    await expect(page.locator('.admin-action-modal')).toHaveCount(1);
    await expect(page.getByPlaceholder('道具名称',{exact:true})).toHaveValue('unsaved important draft');
    state.fail=null;
    await page.getByRole('button',{name:'创建记录',exact:true}).click();
    await expect.poll(()=>state.rows.item_type?.length).toBe(1);
    await expect(page.locator('.admin-action-modal')).toHaveCount(0);
  });
  await run('user-related-wrong-owner',async(page,state)=>{
    seedUsers(state);
    await page.getByRole('button',{name:'用户数据',exact:true}).click();
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await page.locator('tbody tr').filter({hasText:'Trainer1'}).click();
    await expect(page.locator('.admin-table--user-item-info tbody tr')).toContainText('100');
    await page.locator('.admin-table--user-item-info tbody tr').click();
    const itemModal=page.locator('.admin-action-modal').filter({has:page.getByRole('heading',{name:'编辑物品 #100 - Potion #1',exact:true})});
    await itemModal.locator('input[type=number]').fill('6');
    state.hold=p=>p.action==='list::pokemon_info' && p.uid==='1';
    await itemModal.getByRole('button',{name:'保存修改',exact:true}).click();
    await expect.poll(()=>state.releases.length).toBe(1);
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(1);
    await page.getByRole('button',{name:'关闭',exact:true}).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await page.locator('tbody tr').filter({hasText:'Trainer2'}).click();
    await expect(page.locator('.admin-table--user-item-info tbody tr')).toContainText('200');
    state.hold=null;state.releases.splice(0).forEach(resolve=>resolve());
    await expect.poll(()=>state.completed.filter(p=>p.action==='list::item_info' && p.uid==='1').length).toBe(2);
    await page.waitForTimeout(100);
    await expect(page.locator('.admin-action-modal__header')).toContainText('用户 #2');
    await expect(page.locator('.admin-table--user-item-info tbody tr')).toContainText('200');
    await page.locator('.admin-table--user-item-info tbody tr').click();
    await page.getByRole('button',{name:'保存修改',exact:true}).click();
    await expect.poll(()=>state.requests.filter(p=>p.action==='set::item_info').length).toBe(2);
    const mutation=JSON.parse(state.requests.filter(p=>p.action==='set::item_info').at(-1).data);
    assert.equal(mutation.id,200);assert.equal(mutation.owner,2);
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await expect(page.locator('.admin-table--user-item-info tbody tr')).toContainText('200');
  });
  await run('user-owner-mismatch-rejects-write-and-delete',async(page,state)=>{
    seedUsers(state);
    state.itemReplies[1]=[state.rows.item_info[1]];
    await page.getByRole('button',{name:'用户数据',exact:true}).click();
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await openUser(page,1);
    await page.locator('.admin-table--user-item-info tbody tr').click();
    await page.getByRole('button',{name:'保存修改',exact:true}).click();
    await expect(page.locator('.admin-toast--error')).toContainText('数据不属于当前用户');
    await expect(page.locator('.admin-action-modal')).toHaveCount(2);
    assert.equal(state.requests.filter(p=>p.action==='set::item_info').length,0);
    await page.getByRole('button',{name:'删除物品',exact:true}).click();
    await page.locator('.admin-confirm-dialog .admin-form-actions .admin-btn--danger').click();
    assert.equal(state.requests.filter(p=>p.action==='delete::item_info'||p.action==='set::item_info').length,0);
    await expect(page.locator('.admin-action-modal')).toHaveCount(2);
  });
  await run('user-close-clears-other-owner-confirmations',async(page,state)=>{
    seedUsers(state);
    await page.getByRole('button',{name:'用户数据',exact:true}).click();
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await openUser(page,1);
    await page.locator('.admin-table--user-item-info tbody tr').click();
    await page.getByRole('button',{name:'删除物品',exact:true}).click();
    await expect(page.locator('.admin-action-modal')).toHaveCount(2);
    await page.getByRole('button',{name:'关闭',exact:true}).first().focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('.admin-action-modal')).toHaveCount(0);
    await openUser(page,2);
    await expect(page.locator('.admin-action-modal')).toHaveCount(1);
    await expect(page.locator('.admin-table--user-item-info tbody tr')).toContainText('200');
    assert.equal(state.requests.filter(p=>p.action.startsWith('delete::')).length,0);
    await page.getByRole('button',{name:'关闭',exact:true}).click();
    await page.getByRole('button',{name:'地图设定',exact:true}).click();
    await expect(page.getByRole('button',{name:'新增地图',exact:true})).toBeEnabled();
  });
  await run('user-old-metadata-response-preserves-newer-save',async(page,state)=>{
    seedUsers(state);
    await page.getByRole('button',{name:'用户数据',exact:true}).click();
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await openUser(page,1);
    const money=page.locator('.admin-modal-section--user-meta .admin-field').filter({has:page.locator('label').filter({hasText:/^金钱$/})}).locator('input');
    await money.fill('100');
    state.afterHold=p=>p.action==='set::user_info' && JSON.parse(p.data).money===100;
    await page.getByRole('button',{name:'保存用户信息',exact:true}).click();
    await expect.poll(()=>state.releases.length).toBe(1);
    await page.getByRole('button',{name:'关闭',exact:true}).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await openUser(page,1);
    await money.fill('200');
    await page.getByRole('button',{name:'保存用户信息',exact:true}).click();
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await expect.poll(()=>state.completed.filter(p=>p.action==='set::user_info').length).toBe(1);
    state.afterHold=null;state.releases.splice(0).forEach(resolve=>resolve());
    await expect.poll(()=>state.completed.filter(p=>p.action==='set::user_info').length).toBe(2);
    await page.waitForTimeout(100);
    await page.getByRole('button',{name:'关闭',exact:true}).click();
    await openUser(page,1);
    await expect(money).toHaveValue('200');
    assert.equal(state.rows.user_info[0].money,200);
  });
  await run('user-grant-keeps-pending-draft-and-refresh-busy',async(page,state)=>{
    seedUsers(state);
    await page.getByRole('button',{name:'用户数据',exact:true}).click();
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await openUser(page,1);
    await page.getByRole('button',{name:'给予物品',exact:true}).click();
    await page.getByPlaceholder('输入物品名称搜索...',{exact:true}).fill('Potion');
    await page.locator('.admin-search-dropdown-portal__item').filter({hasText:'Potion'}).click();
    await page.getByRole('button',{name:'下一步',exact:true}).click();
    const grant=page.locator('.admin-action-modal').filter({has:page.getByRole('heading',{name:'给予物品给用户 #1',exact:true})});
    await grant.locator('input[type=number]').fill('7');
    state.hold='insert::item_info';state.fail='insert::item_info';
    await grant.getByRole('button',{name:'确认',exact:true}).evaluate(button=>{button.click();button.click();button.click();});
    await expect.poll(()=>state.releases.length).toBe(1);
    assert.equal(state.requests.filter(p=>p.action==='insert::item_info').length,1);
    await grant.getByRole('button',{name:'关闭',exact:true}).focus();
    await page.keyboard.press('Enter');
    await expect(grant.locator('input[type=number]')).toHaveValue('7');
    state.hold=null;state.releases.splice(0).forEach(resolve=>resolve());
    await expect(page.locator('.admin-toast--error')).toContainText('proof rejected save');
    await expect(grant.locator('input[type=number]')).toHaveValue('7');
    state.fail=null;state.hold=p=>p.action==='list::pokemon_info'&&p.uid==='1';
    await grant.getByRole('button',{name:'确认',exact:true}).click();
    await expect.poll(()=>state.releases.length).toBe(1);
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(1);
    await expect(grant).toHaveCount(0);
    state.hold=null;state.releases.splice(0).forEach(resolve=>resolve());
    await expect(page.locator('.admin-fullscreen-overlay')).toHaveCount(0);
    await expect(page.locator('.admin-table--user-item-info tbody tr')).toHaveCount(2);
    assert.equal(state.rows.item_info.find(row=>row.id===3).count,7);
  });
})().catch(error=>{console.error(error);process.exitCode=1;});
