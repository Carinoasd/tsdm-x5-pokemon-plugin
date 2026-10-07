// Real game WASM against isolated player/shop fixtures. Never connects to a real account.
const assert=require('node:assert/strict');
const fs=require('node:fs/promises');
const http=require('node:http');
const path=require('node:path');
const {chromium,expect:baseExpect}=require('@playwright/test');
const expect=baseExpect.configure({timeout:10000});
const wasmDirectory=process.env.GAME_WASM_DIRECTORY || path.resolve(__dirname,'../../plugin/wasm');
const artifacts=path.join(__dirname,'artifacts/player-actions');
const prefix='/source/plugin/pokemon/wasm/';
const pixel=Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7','base64');
const html=`<!doctype html><html><meta charset="utf-8"><link rel="stylesheet" href="${prefix}game.css"><div id="main"></div><script type="module">import init,{WebHandle} from '${prefix}_game.js';await init({module_or_path:'${prefix}_game_bg.wasm'});await new WebHandle().start();</script></html>`;
function pet(id,name,site,hp=50) {
  return {id,name,nickname:`昵称${id}`,type_id:id,level:5,exp:20,exp_to_next_level:80,exp_for_current_level:0,exp_for_next_level:100,hp,max_hp:50,gender:0,is_shiny:false,site,state:1,state_text:'正常',state_class:'normal',skills:[],base_info:{id,name,type_1:'水',type_2:null}};
}
async function fixture(options={}) {
  const state={requests:[],unexpected:[],gates:new Map(),money:1000,battle:!!options.battle,profileFailures:options.profileFailure?1:0,renameFailure:false,buys:0,initializations:0,newPlayer:!!options.newPlayer,pets:[pet(25,'皮卡丘',1,20),pet(1,'妙蛙种子',2,40)]};
  state.items=options.medicine ? [17,18].map(id=>({id:1000+id,type_id:id,item_type:1,name:`药水${id}`,description:'回复20点HP',image:'hp20',quantity:2,type_name:'回复药',can_use:true})) : [];
  const scene=()=>({battle_id:'battle_37',engine_battle_id:37,revision:2,phase:'active',events:[],map_id:1,map_name:'Test',turn:2,my_pokemon:{id:25,instance_id:25,name:'皮卡丘',level:5,hp:20,max_hp:50,skills:[]},wild_pokemon:{id:19,name:'小拉达',level:5,hp:30,max_hp:30},status:'active',battle_over:false,can_continue_switch:false,message:''});
  const pendingReleases=new Set();
  const hold=(key,once=false)=>{let release;const promise=new Promise(resolve=>{release=resolve});const done=()=>{state.gates.delete(key);pendingReleases.delete(done);release()};pendingReleases.add(done);state.gates.set(key,{promise,once});return done};
  async function dispatch(url,body) {
    const key=`${url.searchParams.get('endpoint')}/${url.searchParams.get('action')}`;
    state.requests.push({key,body,query:Object.fromEntries(url.searchParams)});
    const profileMoney=state.money;
    const gate=state.gates.get(key);if(gate){if(gate.once)state.gates.delete(key);await gate.promise;}
    let data;
    switch(key) {
      case 'user/profile':
        if(state.profileFailures-- > 0) return {success:false,error:'模拟账户载入失败',code:503};
        data={uid:1,username:'Fixture Trainer',group_id:10,is_admin:false,is_new_player:state.newPlayer,money:profileMoney,adventure_strength:100,strength_level:1,wins:0,losses:0,total_pokemons:state.newPlayer?0:state.pets.length,total_items:2,npcid:state.battle?19:0};break;
      case 'user/inventory_stats':data={categories:[]};break;
      case 'user/badge_status':data={hidden:false,initialized:true};break;
      case 'user/online_players':data={total:0,players:[],max_display:20};break;
      case 'topics/list':data={topics:[],news_announcements:[],total:0};break;
      case 'config/global':data={is_open:true,is_enable_catch:true};break;
      case 'pokemon/list':data={pokemons:state.newPlayer?[]:state.pets,total:state.newPlayer?0:state.pets.length};break;
      case 'pokemon/detail':{const p=state.pets.find(p=>p.id===Number(url.searchParams.get('pokemon_id')));assert(p);data={...p,stats:{hp:50,attack:p.id*10,defense:10,sp_attack:10,sp_defense:10,speed:10}};break;}
      case 'pokemon/rename':
        if(state.renameFailure) {state.renameFailure=false;return {success:false,error:'昵称暂时无法保存',code:400};}
        state.pets.find(p=>p.id===body.id).nickname=body.name;data=null;break;
      case 'battle/recover':if(!state.battle)return {success:false,error:'No battle',code:404};data=scene();break;
      case 'battle/maps':data={maps:[],total:0};break;
      case 'battle/get_battle_items':data={items:[]};break;
      case 'user/inventory':data={items:state.items.filter(item=>item.quantity>0),total:state.items.filter(item=>item.quantity>0).length,page:1,per_page:50,total_pages:1};break;
      case 'user/get_usable_pokemon':{const item=state.items.find(item=>item.type_id===Number(url.searchParams.get('item_id')));assert(item);data={item_id:item.type_id,item_name:item.name,item_type:1,usable_pokemon:[state.pets[0]],unusable_count:1};break;}
      case 'user/use_item':{const item=state.items.find(item=>item.type_id===body.item_id);const pokemon=state.pets.find(p=>p.id===body.pokemon_id);assert(item&&pokemon);item.quantity--;pokemon.hp=Math.min(pokemon.max_hp,pokemon.hp+20);data={success:true,message:'已使用药水',item_remaining:item.quantity,pokemon_updated:{id:pokemon.id,hp:pokemon.hp,max_hp:pokemon.max_hp,state:pokemon.state}};break;}
      case 'shop/list':data={items:[{id:17,name:'测试药水',description:'回复HP',type_id:1,type_name:'回复药',image:'hp20',price:100,stock:999,effect:{},can_buy:true}],total:1,page:1,per_page:50,total_pages:1};break;
      case 'shop/pets':data={pets:[{id:4,name:'小火龙',type_1:'火',hp:40,atk:50,def:40,spatk:50,spdef:50,speed:60,price:100,can_buy:true}],total:1,page:1,per_page:50,total_pages:1};break;
      case 'shop/buy':state.buys++;state.money-=100;data={message:'已购买',total_cost:100,remaining_money:state.money,items_purchased:1};break;
      case 'shop/buy_pet':state.buys++;state.money-=100;data={message:'已购买',total_cost:100,remaining_money:state.money,pokemon_name:'小火龙',pokemon_type_id:4,site:3};break;
      case 'user/heal':case 'user/heal_and_flee':{if(state.healFailure){state.healFailure=false;return {success:false,error:'模拟治疗失败',code:400};}const p=state.pets.find(p=>p.id===Number(url.searchParams.get('pokemon_id')));assert(p);const cost=p.hp<50?20:0;state.money-=cost;p.hp=50;if(key.endsWith('heal_and_flee'))state.battle=false;data={cost,message:'已治疗',pokemon_id:p.id,current_hp:50,max_hp:50};break;}
      case 'user/initialize':state.initializations++;state.newPlayer=false;data={success:true,message:'已初始化',money:1000,egg_received:true};break;
      default:throw Error(`Unexpected ${key}`);
    }
    return {success:true,data};
  }
  const server=http.createServer(async(req,res)=>{try{
    const url=new URL(req.url,'http://localhost');
    if(url.pathname==='/plugin.php' && url.searchParams.get('endpoint')==='avatar') {res.writeHead(200,{'Content-Type':'image/gif'});res.end(pixel);}
    else if(url.pathname==='/plugin.php' && url.searchParams.has('endpoint')) {let body='';for await(const chunk of req)body+=chunk;const result=await dispatch(url,body?JSON.parse(body):{});res.writeHead(result.code||200,{'Content-Type':'application/json'});res.end(JSON.stringify(result));}
    else if(url.pathname.startsWith(prefix)) {const filename=path.resolve(wasmDirectory,decodeURIComponent(url.pathname.slice(prefix.length)));assert(filename.startsWith(path.resolve(wasmDirectory)+path.sep));const bytes=await fs.readFile(filename);res.writeHead(200,{'Content-Type':{'.js':'text/javascript','.wasm':'application/wasm','.css':'text/css'}[path.extname(filename)]||'application/octet-stream'});res.end(bytes);}
    else if(url.pathname.includes('/images/')) {res.writeHead(200,{'Content-Type':'image/gif'});res.end(pixel);}
    else {res.writeHead(200,{'Content-Type':'text/html'});res.end(html);}
  } catch(error) {state.unexpected.push(String(error));res.writeHead(500,{'Content-Type':'application/json'});res.end(JSON.stringify({success:false,error:String(error)}));}});
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  return {state,hold,url:`http://127.0.0.1:${server.address().port}/plugin.php?id=pokemon:pokemon`,close:async()=>{for(const release of pendingReleases)release();server.closeAllConnections();await new Promise(resolve=>server.close(resolve))}};
}
async function runCase(browser,name,options,test) {
  if(process.env.PLAYER_CASE_FILTER && !new RegExp(process.env.PLAYER_CASE_FILTER).test(name))return;
  const app=await fixture(options);
  const context=await browser.newContext({viewport:{width:1440,height:1100}});
  context.setDefaultTimeout(12000);context.setDefaultNavigationTimeout(20000);
  await context.route('https://**/*',route=>route.fulfill({status:200,contentType:'image/gif',body:pixel}));
  const page=await context.newPage();const errors=[];page.on('pageerror',error=>errors.push(String(error)));
  try {await page.goto(app.url);await test(page,app);assert.deepEqual(errors,[]);assert.deepEqual(app.state.unexpected,[]);console.log(`PASS ${name}`);}
  catch(error) {await page.screenshot({path:path.join(artifacts,`${name}-failure.png`),fullPage:true});console.error('Requests:',JSON.stringify(app.state.requests),'page errors:',errors,'fixture errors:',app.state.unexpected);throw error;}
  finally {await context.close();await app.close();}
}
async function run() {
  await fs.mkdir(artifacts,{recursive:true});const browser=await chromium.launch({headless:true});
  try {
    await runCase(browser,'inventory-picker-can-change-during-loading',{medicine:true},async(page,app)=>{
      await page.getByText('商店',{exact:true}).click();
      await page.getByTitle('查看背包',{exact:true}).click();
      const release=app.hold('user/get_usable_pokemon',true);
      await page.locator('[data-item-id="17"] .buy-btn').click();
      await expect.poll(()=>app.state.requests.filter(r=>r.key==='user/get_usable_pokemon').length).toBe(1);
      await page.locator('.modal-close-btn').click();
      await page.locator('[data-item-id="18"] .buy-btn').click();
      await expect(page.locator('.modal-header')).toContainText('药水18');
      await expect(page.locator('.pokemon-select-item')).toHaveCount(1);
      release();
      await page.waitForTimeout(100);
      await expect(page.locator('.modal-header')).toContainText('药水18');
      assert.equal(app.state.requests.filter(r=>r.key==='user/use_item').length,0);
    });
    await runCase(browser,'inventory-target-click-submits-once',{medicine:true},async(page,app)=>{
      await page.getByText('商店',{exact:true}).click();
      await page.getByTitle('查看背包',{exact:true}).click();
      await page.locator('[data-item-id="17"] .buy-btn').click();
      const release=app.hold('user/use_item');
      await page.locator('.pokemon-select-item').evaluate(node=>{node.click();node.click();node.click()});
      await expect.poll(()=>app.state.requests.filter(r=>r.key==='user/use_item').length).toBeGreaterThan(0);
      await page.waitForTimeout(100);
      assert.equal(app.state.requests.filter(r=>r.key==='user/use_item').length,1,'Repeated target clicks send one mutation');
      release();
      await expect(page.locator('[data-item-id="17"] .item-meta')).toContainText('×1');
      assert.equal(app.state.pets[0].hp,40);
      await page.locator('.pm-mini-slot').first().hover();
      await expect(page.locator('.stat-hp-bar .stat-value-inside')).toHaveText('40/50');
    });
    await runCase(browser,'shop-purchases-block-repeat-clicks',{},async(page,app)=>{
      await page.getByText('商店',{exact:true}).click();
      for(const key of ['shop/buy','shop/buy_pet']) {
        if(key.endsWith('buy_pet')) await page.locator('.category-btn').filter({hasText:'宠物'}).click();
        const button=page.locator('.buy-btn');await expect(button).toBeVisible();
        const release=app.hold(key);await button.click();await button.dispatchEvent('click');await button.dispatchEvent('click');
        await page.waitForTimeout(150);
        assert.equal(app.state.requests.filter(r=>r.key===key).length,1,'A pending purchase must not be submitted again');
        await expect(page.locator('.category-btn').first()).toBeDisabled();
        await expect(button).toBeDisabled();release();await expect(button).toBeEnabled();
      }
      assert.equal(app.state.buys,2);assert.equal(app.state.money,800);
    });
    await runCase(browser,'late-profile-response-keeps-current-balance',{},async(page,app)=>{
      await page.getByText('商店',{exact:true}).click();
      const release=app.hold('user/profile',true);
      await page.locator('.buy-btn').click();
      await expect.poll(()=>app.state.requests.filter(r=>r.key==='user/profile').length).toBe(2);
      await expect(page.locator('.buy-btn')).toBeEnabled();
      await page.locator('.buy-btn').click();
      await expect(page.locator('.app-sidebar')).toContainText('800');
      const response=page.waitForResponse(r=>r.url().includes('endpoint=user&action=profile'));
      release();await response;await page.waitForTimeout(150);
      await expect(page.locator('.app-sidebar')).toContainText('800');
    });
    await runCase(browser,'profile-refresh-survives-navigation',{},async(page,app)=>{
      await page.getByText('商店',{exact:true}).click();
      const release=app.hold('user/profile',true);await page.locator('.buy-btn').click();
      await expect.poll(()=>app.state.requests.filter(r=>r.key==='user/profile').length).toBe(2);
      await page.getByText('个人中心',{exact:true}).click();
      const response=page.waitForResponse(r=>r.url().includes('endpoint=user&action=profile'));
      release();await response;
      await expect(page.locator('.app-sidebar')).toContainText('900');
    });
    await runCase(browser,'switching-pokemon-reloads-details',{},async(page,app)=>{
      await page.getByText('个人中心',{exact:true}).click();
      await expect(page.locator('.pokemon-name-row')).toContainText('昵称25');
      await page.getByRole('button',{name:/昵称1 Lv/}).click();
      await expect(page.locator('.info-tags')).toContainText('ID 1');
      await expect(page.locator('.pokemon-name-row')).toContainText('昵称1');
      assert(app.state.requests.some(r=>r.key==='pokemon/detail'&&r.query.pokemon_id==='1'));
      app.state.renameFailure=true;
      await page.locator('.edit-icon-btn').click();await page.locator('.pokemon-name-input').fill('新的昵称');
      await page.getByTitle('保存',{exact:true}).click();
      await expect(page.getByRole('alert')).toContainText('昵称暂时无法保存');
      await expect(page.locator('.pokemon-name-input')).toHaveValue('新的昵称');
      await page.getByTitle('保存',{exact:true}).click();
      await expect(page.locator('.pokemon-name-row')).toContainText('新的昵称');
      await page.locator('.edit-icon-btn').click();await expect(page.locator('.pokemon-name-input')).toBeEnabled();
    });
    await runCase(browser,'center-flee-clears-battle-and-refreshes-balance',{battle:true},async(page,app)=>{
      await expect(page.locator('.page-battle')).toBeVisible();
      await page.getByText('宠物中心',{exact:true}).click();
      app.state.healFailure=true;const release=app.hold('user/heal_and_flee');
      const heal=page.getByRole('button',{name:'脱战并治疗',exact:true});
      await heal.evaluate(node=>{node.click();node.click();node.click()});
      await expect.poll(()=>app.state.requests.filter(r=>r.key==='user/heal_and_flee').length).toBe(1);
      await expect(heal).toBeDisabled();release();await expect(heal).toBeEnabled();
      assert.equal(app.state.battle,true);assert.equal(app.state.money,1000);
      await page.getByText('商店',{exact:true}).click();await expect(page.locator('.battle-disabled-overlay')).toHaveCount(1);
      await page.getByText('宠物中心',{exact:true}).click();
      await page.getByRole('button',{name:'脱战并治疗',exact:true}).click();
      await expect.poll(()=>app.state.battle).toBe(false);
      await page.getByText('商店',{exact:true}).click();await expect(page.locator('.shop-item-card')).toBeVisible();
      await expect(page.locator('.battle-disabled-overlay')).toHaveCount(0);
      await expect(page.locator('.app-sidebar')).toContainText('980');
      await page.getByText('宠物中心',{exact:true}).click();await page.getByRole('button',{name:'治疗',exact:true}).click();
      await expect.poll(()=>app.state.money).toBe(960);await expect(page.locator('.app-sidebar')).toContainText('960');
    });
    await runCase(browser,'profile-error-can-retry',{profileFailure:true},async(page)=>{
      await expect(page.getByRole('alert')).toContainText('模拟账户载入失败');
      await page.getByRole('button',{name:'重新载入',exact:true}).click();
      await expect(page.getByText('商店',{exact:true})).toBeVisible();
    });
    await runCase(browser,'welcome-prevents-double-initialization',{newPlayer:true},async(page,app)=>{
      const button=page.getByRole('button',{name:'开始冒险',exact:true});await expect(button).toBeVisible();
      const release=app.hold('user/initialize');
      await button.evaluate(node=>{node.click();node.click();node.click()});await page.waitForTimeout(150);
      assert.equal(app.state.requests.filter(r=>r.key==='user/initialize').length,1);release();
      await expect(page.getByText('账户创建成功！正在进入游戏...',{exact:true})).toBeVisible();
    });
  } finally {await browser.close()}
}
run().catch(error=>{console.error(error);process.exitCode=1});
