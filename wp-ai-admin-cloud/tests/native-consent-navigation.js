#!/usr/bin/env node
// Actual native authorize() response/CSP/navigation; synthetic WordPress and backchannel.
const assert=require('node:assert/strict'),net=require('node:net'),path=require('node:path'),fs=require('node:fs'),os=require('node:os');
const {spawn}=require('node:child_process'),{chromium}=require('playwright');
const baseline=process.argv.includes('--baseline');let checks=0;
function verify(ok,message){assert(ok,message);checks++;}
async function port(){const s=net.createServer();await new Promise(r=>s.listen(0,'127.0.0.1',r));const p=s.address().port;await new Promise(r=>s.close(r));return p;}
(async()=>{
 const p=await port(),origin='http://localhost:'+p,tmp=fs.mkdtempSync(path.join(os.tmpdir(),'wpai-native-consent-')),store=path.join(tmp,'options.json');
 const env={...process.env,WPAI_NATIVE_FIXTURE_ORIGIN:origin,WPAI_NATIVE_FIXTURE_STORE:store};
 if(baseline){assert(process.env.WPAI_BASELINE_NATIVE_PAIRING,'Provide exact released native handler');env.WPAI_NATIVE_PAIRING_SOURCE=path.resolve(process.env.WPAI_BASELINE_NATIVE_PAIRING);}else delete env.WPAI_NATIVE_PAIRING_SOURCE;
 const server=spawn(process.env.WPAI_TEST_PHP||'php',['-n','-S','127.0.0.1:'+p,path.join(__dirname,'native-consent-router.php')],{env,stdio:'ignore'});let browser;
 try{
  let ready=false;for(let i=0;i<100;i++){try{await fetch('http://127.0.0.1:'+p+'/fixture-options');ready=true;break;}catch{await new Promise(r=>setTimeout(r,30));}}assert(ready,'PHP fixture failed');
  browser=await chromium.launch({headless:true,args:['--no-sandbox']});const context=await browser.newContext();let callbacks=[],stripRefresh=false;
  await context.route('https://wpaiadmin.ru/**',async route=>{
   const req=route.request(),u=new URL(req.url());verify(u.pathname==='/wpai-cloud/pair/callback','Callback target changed');callbacks.push({query:Object.fromEntries(u.searchParams),method:req.method(),referer:req.headers().referer});
   await route.fulfill({status:200,contentType:'text/html',body:'<!doctype html><h1>Fixture central callback reached</h1>'});
  });
  await context.route(origin+'/**',async route=>{if(stripRefresh&&route.request().method()==='POST'){const r=await route.fetch();await route.fulfill({response:r,body:(await r.text()).replace(/<meta http-equiv="refresh"[^>]*>/g,'')});}else await route.continue();});
  const page=await context.newPage();let violations=[];page.on('console',m=>{if(m.text().includes('form-action'))violations.push(m.text());});
  const url=origin+'/wp-admin/admin-post.php?action=oai_gateway_pair&state='+'s'.repeat(43);
  let response=await page.goto(url);
  verify(response.headers()['content-security-policy'].includes("form-action 'self'"),'Native consent CSP missing');
  verify(response.headers()['referrer-policy']==='no-referrer','Native consent exposes referrer');
  verify((await page.content()).includes('Fixture account'),'Account label absent');
  await page.getByRole('button',{name:'Подключить сайт',exact:true}).click({noWaitAfter:true});
  if(baseline){
   await page.waitForTimeout(800);const options=await (await fetch('http://127.0.0.1:'+p+'/fixture-options')).json();
   verify(options.codes===1,'Baseline POST did not issue code');verify(callbacks.length===0,'Baseline callback unexpectedly reached');verify(violations.length>0,'Baseline did not trigger CSP block');
  }else{
   await page.waitForURL('https://wpaiadmin.ru/wpai-cloud/pair/callback?*',{timeout:5000});
   const cb=callbacks.at(-1);verify(cb.query.state==='s'.repeat(43)&&/^[A-Za-z0-9_-]{43}$/.test(cb.query.code),'Code/state not preserved');verify(!cb.query.token&&!cb.query.verifier,'Durable secret exposed');verify(cb.method==='GET'&&!cb.referer,'POST/referrer leaked');verify(violations.length===0,'Native callback violates CSP');
   let options=await (await fetch('http://127.0.0.1:'+p+'/fixture-options')).json();verify(options.codes===1&&options.credentials===0&&options.mcp_enabled===true,'Consent side effects changed');
   await page.goto(url);await page.getByRole('button',{name:'Отмена',exact:true}).click({noWaitAfter:true});await page.waitForURL('https://wpaiadmin.ru/wpai-cloud/pair/callback?*',{timeout:5000});
   verify(callbacks.at(-1).query.error==='access_denied'&&!callbacks.at(-1).query.code,'Deny issued code');
   options=await (await fetch('http://127.0.0.1:'+p+'/fixture-options')).json();verify(options.codes===1,'Deny mutated code store');
   stripRefresh=true;await page.goto(url);await page.getByRole('button',{name:'Подключить сайт',exact:true}).click({noWaitAfter:true});await page.getByRole('link',{name:'Продолжить в кабинете',exact:true}).waitFor();
   verify((await page.content()).includes('Возврат в кабинет'),'Manual handoff missing');await page.getByRole('link',{name:'Продолжить в кабинете',exact:true}).click();await page.waitForURL('https://wpaiadmin.ru/wpai-cloud/pair/callback?*',{timeout:5000});stripRefresh=false;
   verify(callbacks.length===3&&violations.length===0,'Manual callback failed');
   const form={action:'oai_gateway_pair',state:'s'.repeat(43),decision:'allow',oai_pair_nonce:'invalid'};
   let rejected=await context.request.post(origin+'/wp-admin/admin-post.php',{form});verify(rejected.status()===403&&!(await rejected.text()).includes('http-equiv="refresh"'),'Invalid nonce navigated');
   for(const suffix of ['&fixture_reader=1','&fixture_http=1','&fixture_wrong_home=1']){rejected=await page.goto(url+suffix);verify(rejected.status()===(suffix.includes('wrong_home')?400:403)&&!(await page.content()).includes('<form'),'Invalid permission/HTTPS/descriptor showed consent');}
   rejected=await page.goto(origin+'/wp-admin/admin-post.php?action=oai_gateway_pair&state=invalid');verify(rejected.status()===400,'Invalid state accepted');
  }
  console.log('PASS '+checks+' Chromium actual native '+(baseline?'2.1.13 consent failure reproduction':'consent allow/deny/manual/denial navigation checks'));
 }finally{if(browser)await browser.close();server.kill();fs.rmSync(tmp,{recursive:true,force:true});}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
