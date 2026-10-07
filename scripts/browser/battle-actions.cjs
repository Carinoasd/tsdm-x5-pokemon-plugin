// Browser regression runner: real game WASM, isolated in-memory HTTP API fixtures.
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const http = require('node:http');
const path = require('node:path');
const {chromium, expect: baseExpect} = require('@playwright/test');
const expect = baseExpect.configure({timeout: 12000});
const wasmDirectory = process.env.GAME_WASM_DIRECTORY || path.resolve(__dirname, '../../plugin/wasm');
const artifacts = path.join(__dirname, 'artifacts/battle-actions');
const prefix = '/source/plugin/pokemon/wasm/';
const pixel = Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64');
const html = `<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Battle fixture</title>
<link rel="stylesheet" href="${prefix}game.css"><div id="main"></div><script type="module">
import init, {WebHandle} from '${prefix}_game.js';
await init({module_or_path:'${prefix}_game_bg.wasm'}); await new WebHandle().start();
</script></html>`;

function pet(id, species, name, hp, site) {
  return {id, name, nickname:null, type_id:species, level:5, exp:20,
    exp_to_next_level:80, exp_for_current_level:0, exp_for_next_level:100,
    hp, max_hp:50, gender:0, is_shiny:false, site, state:1, state_text:'正常', state_class:'normal',
    skills:[{type_id:12, pp:2, name:'Tackle', max_pp:20, power:40, skill_type:'normal',category:'物攻'}],
    base_info:{id:species,name,type_1:'normal',type_2:null}};
}

async function startFixture({fainted=false, holdList=false}={}) {
  const state = {
    requests:[], unexpected:[], gates:new Map(), turn:3, pp:2,
    engineId:37, revision:3, receipts:new Map(), loseNext:null, rejectNext:null, logFailure:false, finished:false, winNext:false, hp:fainted ? 0 : 50, activeId:501, activeName:'Pikachu', awaiting:fainted,
    itemCounts:{17:3,18:2}, balls:2, failReplace:false,
  };
  const hold = key => {
    assert(!state.gates.has(key), `Already holding ${key}`);
    let release;
    const promise = new Promise(resolve => {release=resolve;});
    state.gates.set(key, {promise,release});
    return () => {state.gates.get(key)?.release(); state.gates.delete(key);};
  };
  if (holdList) hold('pokemon/list');
  const scene = () => ({battle_id:'battle_1',engine_battle_id:state.engineId,revision:state.revision,phase:state.finished ? 'ended' : state.awaiting ? 'awaiting_switch' : 'active',events:[],map_id:1,map_name:'Fixture Meadow',turn:state.turn,
    my_pokemon:{id:state.activeId===501 ? 25 : 1,instance_id:state.activeId,name:state.activeName,
      level:5,hp:state.hp,max_hp:50,skills:[{id:12,name:'Tackle',power:40,pp:state.pp,max_pp:20,skill_type:'normal',category:'物攻'}]},
    wild_pokemon:{id:19,name:'Rattata',level:5,hp:20,max_hp:30,gender:0,is_shiny:false,is_boss:false,boss_multiplier:1},
    status:state.finished ? 'victory' : state.awaiting ? 'defeat' : 'active',battle_over:state.finished,can_continue_switch:state.awaiting,message:'Fixture action completed'});
  const list = () => [
    pet(501,25,'Pikachu',state.activeId===501 ? state.hp : 0,state.activeId===501 ? 1 : 2),
    pet(25,1,'Bulbasaur',state.activeId===25 ? state.hp : 40,state.activeId===25 ? 1 : 2),
  ];
  async function dispatch(url, body) {
    const key = `${url.searchParams.get('endpoint')}/${url.searchParams.get('action')}`;
    state.requests.push({key,body:structuredClone(body),query:Object.fromEntries(url.searchParams)});
    if (state.gates.has(key)) await state.gates.get(key).promise;
    let data;
    const original=structuredClone(body);
    const mutation = Object.hasOwn(body,'request_id');
    if (mutation) {
      assert.match(body.request_id,/^[A-Za-z0-9_-]{16,64}$/);
      if(state.rejectNext === key) {state.rejectNext=null;return {status:403,result:{success:false,error:'登录验证过期'}};}
      if(state.receipts.has(body.request_id)) {
        const receipt=state.receipts.get(body.request_id);
        assert.deepEqual(receipt.body,body,'Retry must keep the exact original payload');
        return receipt.result;
      }
      if(body.engine_battle_id!==state.engineId || body.expected_revision!==state.revision) {
        return {status:409,result:{success:false,error:'battle_state_conflict'}};
      }
      const {request_id,engine_battle_id,expected_revision,...fields}=body; body=fields;
    } else if (['battle/turn','battle/flee','battle/capture','battle/use_item','battle/use_item_on_skill','battle/replace_pokemon'].includes(key)) {
      throw Error('Mutation missing request metadata: '+key);
    }
    switch (key) {
      case 'user/profile': data={uid:1,username:'Fixture Trainer',group_id:10,is_admin:false,is_new_player:false,money:1000,adventure_strength:100,strength_level:1,wins:0,losses:0,total_pokemons:2,total_items:7,npcid:state.finished ? 0 : 19}; break;
      case 'user/inventory_stats': data={categories:[{type_id:1,count:5},{type_id:2,count:2}]}; break;
      case 'user/badge_status': data={hidden:false,initialized:true}; break;
      case 'user/online_players': data={total:0,players:[],max_display:20}; break;
      case 'topics/list': data={topics:[],news_announcements:[],total:0}; break;
      case 'config/global': data={is_open:true,is_enable_catch:true}; break;
      case 'pokemon/list': data={pokemons:list(),total:2}; break;
      case 'battle/recover':
        if(state.finished) return {status:404,result:{success:false,error:'没有进行中的战斗'}};
        data=scene(); break;
      case 'battle/battle_log':
        assert.equal(url.searchParams.get('battle_id'),'37');
        if(state.logFailure) {state.logFailure=false;return {status:500,result:{success:false,error:'模拟战报载入失败'}};}
        data={battle_id:37,turn:state.turn,phase:'active',result:'',schema_version:1,
          lines:['皮卡丘使用撞击，造成12点伤害。'],
          turns:[{turn:3,lines:['皮卡丘使用撞击，造成12点伤害。','野怪陷入烧伤。']}],
          events:[{turn:3,seq:1,type:'damage',payload:{side:'ally',amount:12}}],
          bbcode:'[quote]第3回合：皮卡丘造成12点伤害。[/quote]'}; break;
      case 'battle/maps': data={maps:[],total:0}; break;
      case 'battle/get_battle_items': data={items:[
        {id:17,name:'Potion',img:'hp20',nums:state.itemCounts[17],item_type:1,module:'hp20',addhp:20},
        {id:18,name:'Ether',img:'pp5',nums:state.itemCounts[18],item_type:1,module:'pp5'},
      ]}; break;
      case 'user/inventory': {
        assert.equal(url.searchParams.get('type'),'2','Recovered battle must use battle inventory for potions');
        data={items:[{id:403,type_id:5,item_type:2,name:'Poke Ball',description:'Fixture ball',image:'jlq',quantity:state.balls,type_name:'精灵球',can_use:true}],total:1,page:1,per_page:20,total_pages:1};
        break;
      }
      case 'battle/use_item': {
        if (body.item_id===18) {
          data={requires_skill_selection:true,engine_battle_id:37,revision:state.revision,item_id:18,item_name:'Ether',available_skills:[{id:701,skill_id:12,name:'Tackle',current_pp:state.pp,max_pp:20}],message:'请选择要恢复PP的技能'};
        } else {
          assert.deepEqual(body,{item_id:17});
          state.itemCounts[17]--; state.hp=45; state.turn++; data=scene();
        }
        break;
      }
      case 'battle/use_item_on_skill': {
        assert.deepEqual(body,{item_id:18,skill_record_id:701});
        state.pp+=5; state.hp=40; state.itemCounts[18]--; state.turn++; data=scene(); break;
      }
      case 'battle/capture': {
        assert.deepEqual(body,{ball_id:5});
        state.balls--; state.hp-=5; state.turn++; data=scene(); break;
      }
      case 'battle/turn': {
        if(state.winNext) {state.winNext=false; state.finished=true;}
        else {state.hp=0; state.awaiting=true;}
        state.turn++; data=scene(); break;
      }
      case 'battle/replace_pokemon': {
        assert.deepEqual(body,{pokemon_id:25});
        if (state.failReplace) {state.failReplace=false; return {status:400,result:{success:false,error:'模拟换宠失败，请重试'}};}
        state.activeId=25; state.activeName='Bulbasaur'; state.hp=40; state.awaiting=false; data=scene(); break;
      }
      default: throw new Error(`Unexpected API ${key}`);
    }
    if(mutation && !data.requires_skill_selection) { state.revision++; data.revision=state.revision; }
    const result={status:200,result:{success:true,data}};
    if(mutation) state.receipts.set(original.request_id,{body:original,result:structuredClone(result)});
    if(state.loseNext===key) {state.loseNext=null; return {lost:true};}
    return result;
  }
  const server = http.createServer(async (request,response) => {
    try {
      const url = new URL(request.url,'http://localhost');
      if (url.pathname==='/plugin.php' && url.searchParams.get('endpoint')==='avatar') {
        response.writeHead(200,{'Content-Type':'image/gif'}); response.end(pixel);
      } else if (url.pathname==='/plugin.php' && url.searchParams.has('endpoint')) {
        let body=''; for await(const chunk of request) body+=chunk;
        const {status,result,lost} = await dispatch(url,body ? JSON.parse(body) : {});
        if(lost) {
          // A truncated body models a response lost after commit without Chromium
          // transparently retrying a closed keep-alive socket before the app sees it.
          response.writeHead(200,{'Content-Type':'application/json'});
          response.end('{"success":'); return;
        }
        response.writeHead(status,{'Content-Type':'application/json; charset=utf-8'}); response.end(JSON.stringify(result));
      } else if (url.pathname==='/plugin.php' || url.pathname==='/') {
        response.writeHead(200,{'Content-Type':'text/html; charset=utf-8'}); response.end(html);
      } else if (url.pathname.startsWith(prefix)) {
        const filename=path.resolve(wasmDirectory,decodeURIComponent(url.pathname.slice(prefix.length)));
        assert(filename.startsWith(path.resolve(wasmDirectory)+path.sep));
        const bytes=await fs.readFile(filename);
        response.writeHead(200,{'Content-Type':{'.wasm':'application/wasm','.js':'text/javascript','.css':'text/css'}[path.extname(filename)]||'application/octet-stream'}); response.end(bytes);
      } else if (url.pathname.includes('/images/')) {
        response.writeHead(200,{'Content-Type':'image/gif'}); response.end(pixel);
      } else {response.writeHead(404); response.end();}
    } catch(error) {
      state.unexpected.push(String(error));
      if(!response.headersSent) response.writeHead(500,{'Content-Type':'application/json'});
      response.end(JSON.stringify({success:false,error:String(error)}));
    }
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  return {state,hold,scene,url:`http://127.0.0.1:${server.address().port}/plugin.php?id=pokemon:pokemon`,
    release:key=>{state.gates.get(key)?.release(); state.gates.delete(key);},
    close:async()=>{for(const gate of state.gates.values())gate.release(); server.closeAllConnections(); await new Promise(resolve=>server.close(resolve));}};
}

async function withPage(browser,name,options,test) {
  if(process.env.BATTLE_CASE_FILTER && !new RegExp(process.env.BATTLE_CASE_FILTER).test(name)) return;
  const fixture=await startFixture(options);
  const context=await browser.newContext(options.mobile ? {viewport:{width:390,height:844},isMobile:true,hasTouch:true} : {viewport:{width:1440,height:1100}});
  context.setDefaultTimeout(12000);
  context.setDefaultNavigationTimeout(20000);
  const errors=[];
  await context.route('https://**/*',route=>route.fulfill({status:200,contentType:'image/gif',body:pixel}));
  await context.tracing.start({screenshots:true,snapshots:true});
  const page=await context.newPage();
  page.on('pageerror',error=>errors.push(String(error)));
  try {
    await page.goto(fixture.url);
    await expect(page.locator('.page-battle')).toBeVisible();
    await test(page,fixture);
    assert.deepEqual(fixture.state.unexpected,[]);
    assert.deepEqual(errors,[]);
    await page.screenshot({path:path.join(artifacts,`${name}.png`),fullPage:true});
    console.log(`PASS ${name}`);
  } catch(error) {
    await page.screenshot({path:path.join(artifacts,`${name}-failure.png`),fullPage:true}).catch(()=>{});
    await fs.writeFile(path.join(artifacts,`${name}-failure.html`),await page.content());
    console.error('Fixture requests',JSON.stringify(fixture.state.requests));
    console.error('Browser errors',errors,'Fixture errors',fixture.state.unexpected);
    throw error;
  } finally {
    await context.tracing.stop({path:path.join(artifacts,`${name}-trace.zip`)});
    await context.close(); await fixture.close();
  }
}

async function assertSingleWhileBusy(page,fixture,key,target,initialCount=0) {
  await expect.poll(()=>fixture.state.requests.filter(r=>r.key===key).length).toBe(initialCount+1);
  await expect(target).toHaveAttribute('aria-disabled','true');
  // Force dispatch beyond CSS to exercise both the UI callback and Adventure's pending-request guard.
  await target.dispatchEvent('click'); await target.dispatchEvent('click');
  await page.waitForTimeout(150);
  assert.equal(fixture.state.requests.filter(r=>r.key===key).length,initialCount+1);
}


async function openItem(page,id,keyboard=false) {
  const card=page.getByTestId('battle-item-'+id);
  if(keyboard) {await card.focus();await card.press('Enter');} else {await card.tap();}
  await expect(page.getByTestId('battle-item-details')).toBeVisible();
}
async function closeItem(page) {await page.locator('.modal-container').filter({has:page.getByTestId('battle-item-details')}).getByRole('button',{name:'×',exact:true}).click();}
async function run() {
  await fs.mkdir(artifacts,{recursive:true});
  const browser=await chromium.launch({headless:true});
  try {
    await withPage(browser,'mobile-details-pp-and-log',{mobile:true},async(page,fixture)=>{
      await page.getByRole('button',{name:'道具',exact:true}).click();
      await openItem(page,17);
      await expect(page.getByTestId('battle-item-details')).toContainText('当前HP已满');
      await expect(page.getByTestId('battle-item-use')).toBeDisabled();
      assert.equal(fixture.state.requests.filter(r=>r.key==='battle/use_item').length,0);
      await closeItem(page);
      await page.screenshot({path:path.join(artifacts,'mobile-battle-items.png'),fullPage:true});
      await openItem(page,18);
      await expect(page.getByTestId('battle-item-details')).toContainText('5 点PP');
      await expect(page.getByTestId('battle-item-details')).toContainText('持有数量：2');
      let release=fixture.hold('battle/use_item');
      await page.getByTestId('battle-item-use').click();
      await assertSingleWhileBusy(page,fixture,'battle/use_item',page.getByTestId('battle-item-18'));
      release();
      const skill=page.getByTestId('pp-skill-701'); await expect(skill).toContainText('2/20');
      release=fixture.hold('battle/use_item_on_skill');
      await skill.focus(); await skill.press('Space');
      await assertSingleWhileBusy(page,fixture,'battle/use_item_on_skill',skill);
      release(); await expect(skill).toHaveCount(0);
      assert.equal(fixture.state.pp,7);
      await page.getByRole('button',{name:'捕捉',exact:true}).click();
      await page.getByTestId('battle-ball-5').tap();
      await expect(page.getByTestId('battle-item-details')).toContainText('持有数量：2');
      release=fixture.hold('battle/capture');
      await page.getByTestId('battle-item-use').click();
      await assertSingleWhileBusy(page,fixture,'battle/capture',page.getByTestId('battle-ball-5'));
      release(); await expect(page.locator('.turn-number')).toHaveText('5');
      assert.equal(fixture.state.balls,1);
      fixture.state.logFailure=true;
      await page.getByTestId('battle-log-open').click();
      await expect(page.getByTestId('battle-log-error')).toBeVisible();
      await page.getByTestId('battle-log-refresh').click();
      await expect(page.getByTestId('battle-log-events')).toContainText('第 3 回合');
      await expect(page.getByTestId('battle-log-events')).toContainText('烧伤');
      await page.context().grantPermissions(['clipboard-read','clipboard-write']);
      await page.getByTestId('battle-log-copy').click();
      await expect(page.getByTestId('battle-copy-status')).toContainText('已复制');
      assert.equal(await page.evaluate(()=>navigator.clipboard.readText()),'[quote]第3回合：皮卡丘造成12点伤害。[/quote]');
    });
    await withPage(browser,'lost-response-retry-same-request',{},async(page,fixture)=>{
      await page.getByRole('button',{name:'道具',exact:true}).click();
      await openItem(page,18,true);
      fixture.state.loseNext='battle/use_item';
      await page.getByTestId('battle-item-use').click();
      await expect(page.getByTestId('battle-action-retry')).toBeVisible();
      const first=fixture.state.requests.filter(r=>r.key==='battle/use_item')[0].body;
      await page.reload();
      await expect(page.getByTestId('battle-action-retry')).toBeEnabled();
      await page.getByTestId('battle-action-retry').click();
      await expect(page.getByTestId('pp-skill-701')).toBeVisible();
      const second=fixture.state.requests.filter(r=>r.key==='battle/use_item')[1].body;
      assert.deepEqual(second,first);
      fixture.state.loseNext='battle/use_item_on_skill';
      await page.getByTestId('pp-skill-701').click();
      await expect(page.getByTestId('battle-action-retry')).toBeVisible();
      assert.equal(fixture.state.itemCounts[18],1);
      await page.getByTestId('battle-action-retry').click();
      await expect(page.getByTestId('battle-connection')).toHaveCount(0);
      await expect(page.locator('.turn-number')).toHaveText('4');
      assert.equal(fixture.state.itemCounts[18],1);
      assert.equal(fixture.state.pp,7);
      assert.equal(fixture.state.requests.filter(r=>r.key==='battle/use_item_on_skill').length,2);
    });
    await withPage(browser,'committed-action-inventory-timeouts-release-busy',{},async(page,fixture)=>{
      await page.getByRole('button',{name:'道具',exact:true}).click();
      await openItem(page,18,true); await page.getByTestId('battle-item-use').click();
      await expect(page.getByTestId('pp-skill-701')).toBeVisible();
      const releaseItems=fixture.hold('battle/get_battle_items');
      const releaseBalls=fixture.hold('user/inventory');
      await page.getByTestId('pp-skill-701').click();
      await expect(page.locator('.turn-number')).toHaveText('4');
      await expect(page.getByTestId('battle-item-18')).toBeDisabled();
      // Both refresh reads hang after the mutation committed. Each must finish its 15-second deadline.
      await expect(page.getByTestId('battle-item-18')).toBeEnabled({timeout:35000});
      assert.equal(fixture.state.itemCounts[18],1); assert.equal(fixture.state.pp,7);
      assert.equal(fixture.state.requests.filter(r=>r.key==='battle/use_item_on_skill').length,1);
      assert.equal(await page.evaluate(()=>sessionStorage.getItem('pokemon_pending_battle_1')),null);
      releaseItems(); releaseBalls();
      await page.locator('.battle-tab').filter({hasText:'道具'}).click();
      await openItem(page,18,true);
      await expect(page.getByTestId('battle-item-details')).toContainText('持有数量：1');
    });
    await withPage(browser,'old-receipt-keeps-newer-recovered-state',{},async(page,fixture)=>{
      await page.getByRole('button',{name:'道具',exact:true}).click();
      await openItem(page,18,true); await page.getByTestId('battle-item-use').click();
      fixture.state.loseNext='battle/use_item_on_skill';
      await page.getByTestId('pp-skill-701').click();
      await expect(page.getByTestId('battle-action-retry')).toBeVisible();
      fixture.state.revision++; fixture.state.turn++; fixture.state.hp=25;
      await page.reload();
      await expect(page.getByTestId('battle-action-retry')).toBeEnabled();
      await page.getByTestId('battle-action-retry').click();
      await expect(page.getByTestId('battle-reconnect')).toBeVisible();
      await expect(page.locator('.turn-number')).toHaveText('5');
      await page.getByTestId('battle-reconnect').click();
      await expect(page.getByTestId('battle-connection')).toHaveCount(0);
      await expect(page.locator('.turn-number')).toHaveText('5');
      assert.equal(fixture.state.itemCounts[18],1); assert.equal(fixture.state.pp,7);
    });
    await withPage(browser,'old-start-receipt-keeps-newer-battle',{},async(page,fixture)=>{
      // Restore an unresolved start from this tab after another tab has finished it and started battle 38.
      const request={request_id:'fixture_start_receipt_37',engine_battle_id:0,expected_revision:0,map_id:1};
      fixture.state.receipts.set(request.request_id,{body:request,result:{status:200,result:{success:true,data:fixture.scene()}}});
      await page.evaluate(pending=>sessionStorage.setItem('pokemon_pending_battle_1',JSON.stringify(pending)),{action:'start',request});
      fixture.state.engineId=38; fixture.state.revision=1; fixture.state.turn=1;
      await page.reload();
      await expect(page.getByTestId('battle-action-retry')).toBeEnabled();
      await page.getByTestId('battle-action-retry').click();
      await expect(page.getByTestId('battle-reconnect')).toBeVisible();
      await expect(page.locator('.turn-number')).toHaveText('1');
      await page.getByTestId('battle-reconnect').click();
      await expect(page.getByTestId('battle-connection')).toHaveCount(0);
      await expect(page.locator('.turn-number')).toHaveText('1');
      assert.equal(fixture.state.engineId,38);
      assert.equal(fixture.state.requests.filter(r=>r.key==='battle/start').length,1);
    });
    await withPage(browser,'committed-victory-lost-response-reload',{},async(page,fixture)=>{
      fixture.state.winNext=true; fixture.state.loseNext='battle/turn';
      await page.getByRole('button',{name:/普通攻击/}).click();
      await expect(page.getByTestId('battle-action-retry')).toBeVisible();
      assert.equal(fixture.state.finished,true);
      await page.reload();
      await expect(page.getByTestId('battle-action-retry')).toBeEnabled();
      assert.equal(await page.evaluate(()=>sessionStorage.getItem('pokemon_pending_battle_0')),null);
      await page.getByTestId('battle-action-retry').click();
      await expect(page.getByTestId('battle-end-log')).toBeVisible();
      await page.getByTestId('battle-end-log').click();
      await expect(page.getByTestId('battle-log-events')).toContainText('第 3 回合');
      const turns=fixture.state.requests.filter(r=>r.key==='battle/turn');
      assert.equal(turns.length,2); assert.deepEqual(turns[0].body,turns[1].body);
      assert.equal(fixture.state.turn,4);
    });
    await withPage(browser,'retry-after-other-tab-ended-battle',{},async(page,fixture)=>{
      await page.getByRole('button',{name:'道具',exact:true}).click();
      await openItem(page,18,true); await page.getByTestId('battle-item-use').click();
      fixture.state.loseNext='battle/use_item_on_skill';
      await page.getByTestId('pp-skill-701').click();
      await expect(page.getByTestId('battle-action-retry')).toBeVisible();
      fixture.state.finished=true; fixture.state.revision++;
      await page.reload();
      await expect(page.getByTestId('battle-action-retry')).toBeEnabled();
      await page.getByTestId('battle-action-retry').click();
      await expect(page.getByTestId('battle-connection')).toHaveCount(0);
      await expect(page.locator('.page-battle')).toHaveCount(0);
      await expect(page.getByTestId('pp-skill-701')).toHaveCount(0);
      assert.equal(fixture.state.itemCounts[18],1);
      assert.equal(await page.evaluate(()=>sessionStorage.getItem('pokemon_pending_battle_1')),null);
    });
    await withPage(browser,'forbidden-retry-preserves-original-victory',{},async(page,fixture)=>{
      fixture.state.winNext=true; fixture.state.loseNext='battle/turn';
      await page.getByRole('button',{name:/普通攻击/}).click();
      await expect(page.getByTestId('battle-action-retry')).toBeEnabled();
      const original=fixture.state.requests.filter(r=>r.key==='battle/turn')[0].body;
      fixture.state.rejectNext='battle/turn';
      await page.getByTestId('battle-action-retry').click();
      await expect(page.getByTestId('battle-connection')).toContainText('重新登录');
      assert(await page.evaluate(()=>sessionStorage.getItem('pokemon_pending_battle_1')));
      await page.reload();
      await expect(page.getByTestId('battle-action-retry')).toBeEnabled();
      await page.getByTestId('battle-action-retry').click();
      await expect(page.getByTestId('battle-end-log')).toBeVisible();
      const turns=fixture.state.requests.filter(r=>r.key==='battle/turn');
      assert.equal(turns.length,3); turns.forEach(request=>assert.deepEqual(request.body,original));
      assert.equal(fixture.state.turn,4);
    });
    await withPage(browser,'stale-revision-reconnect',{},async(page,fixture)=>{
      fixture.state.revision++;
      await page.getByRole('button',{name:/普通攻击/}).click();
      await expect(page.getByTestId('battle-reconnect')).toBeVisible();
      await page.getByTestId('battle-reconnect').click();
      await expect(page.getByTestId('battle-connection')).toHaveCount(0);
      const release=fixture.hold('pokemon/list');
      await page.getByRole('button',{name:/普通攻击/}).click();
      await expect(page.getByTestId('replacement-pokemon-25')).toBeEnabled();
      const turns=fixture.state.requests.filter(r=>r.key==='battle/turn');
      assert.equal(turns.length,2);
      assert.notEqual(turns[0].body.request_id,turns[1].body.request_id);
      assert.equal(turns[1].body.expected_revision,4);
      release();
    });
    await withPage(browser,'recover-delayed-list',{fainted:true,holdList:true},async(page,fixture)=>{
      await expect(page.locator('.faint-replace-modal')).toBeVisible();
      await expect(page.getByText('战斗失败...', {exact:true})).toHaveCount(0);
      fixture.release('pokemon/list');
      const reserve=page.getByTestId('replacement-pokemon-25');
      await expect(reserve).toBeEnabled(); await expect(reserve).toContainText('HP: 40/50');
      await reserve.click(); await expect(page.locator('.faint-replace-modal')).toHaveCount(0);
    });
  } finally {await browser.close();}
}
run().catch(error=>{console.error(error);process.exitCode=1;});
