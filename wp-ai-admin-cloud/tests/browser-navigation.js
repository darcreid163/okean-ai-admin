#!/usr/bin/env node
// Test real Chromium navigation against actual Surface.php, with WordPress/session/back-channel fixtures.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const { spawn } = require('node:child_process');
const { chromium } = require('playwright');
const here = __dirname;
const baseline = process.argv.includes('--baseline');
const php = process.env.WPAI_TEST_PHP || 'php';
let checks = 0;
const verify = (ok, message) => { assert(ok, message); checks++; };
async function freePort() {
  const server = net.createServer();
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const port = server.address().port;
  await new Promise(resolve => server.close(resolve));
  return port;
}
async function ready(url) {
  for (let i=0;i<100;i++) {
    try { await fetch(url); return; } catch { await new Promise(resolve=>setTimeout(resolve,30)); }
  }
  throw new Error('PHP fixture did not start');
}
(async () => {
  const centralPort = await freePort(), nativePort = await freePort();
  const central = 'http://127.0.0.1:' + centralPort;
  const native = 'http://localhost:' + nativePort;
  const env = { ...process.env, WPAI_BROWSER_NATIVE_ORIGIN:native, WPAI_BROWSER_CENTRAL_ORIGIN:central };
  if (baseline) {
    assert(process.env.WPAI_BASELINE_SURFACE, 'Provide exact previous release Surface.php');
    env.WPAI_SURFACE_SOURCE = path.resolve(process.env.WPAI_BASELINE_SURFACE);
  } else delete env.WPAI_SURFACE_SOURCE;
  const servers = [
    spawn(php, ['-n','-S','127.0.0.1:'+centralPort,path.join(here,'frontend-session-router.php')], {env,stdio:'ignore'}),
    spawn(php, ['-n','-S','127.0.0.1:'+nativePort,path.join(here,'browser-native-router.php')], {env,stdio:'ignore'})
  ];
  let browser;
  try {
    await Promise.all([ready(central+'/fixture-cookies'),ready('http://127.0.0.1:'+nativePort+'/fixture-site-consent')]);
    browser = await chromium.launch({headless:true,args:['--no-sandbox']});
    const context = await browser.newContext();
    await context.addCookies([{name:'wordpress_logged_in_fixture',value:'validated-session',url:central,httpOnly:true,sameSite:'Lax'}]);
    const page = await context.newPage();
    let violations = [];
    page.on('console',message=>{ if(message.text().includes('form-action')) violations.push(message.text()); });
    const response = await page.goto(central+'/wpai-cloud/connect');
    verify(response.headers()['content-security-policy'].includes("form-action 'self'"), 'Strict form-action policy missing');
    verify(!response.headers()['content-security-policy'].includes('form-action https:'), 'Policy widened to every HTTPS origin');
    await page.locator('input[name=home]').first().fill('https://wpaiadmin.ru');
    await page.getByRole('button',{name:'Подключить автоматически',exact:true}).click({noWaitAfter:true});
    await page.waitForURL(central+'/fixture-site-consent?state=*', {timeout:5000});
    verify((await page.content()).includes('same-origin-consent-reached'), 'Same-origin pairing failed');

    await page.goto(central+'/wpai-cloud/connect');
    await page.locator('input[name=home]').first().fill('https://okean.market');
    await page.getByRole('button',{name:'Подключить автоматически',exact:true}).click({noWaitAfter:true});
    if (baseline) {
      await page.waitForTimeout(700);
      verify(page.url().startsWith(central+'/wpai-cloud/connect'), 'Baseline must block cross-origin form redirect');
      verify(violations.length>0, 'Baseline did not produce CSP form-action violation');
    } else {
      await page.waitForURL(native+'/fixture-site-consent?state=*',{timeout:5000});
      verify((await page.content()).includes('Подключить этот сайт?'), 'Second-site consent not reached');
      verify(violations.length===0, 'Candidate still violates CSP');
      await page.getByRole('button',{name:'Подключить сайт',exact:true}).click({noWaitAfter:true});
      await page.waitForURL(central+'/wpai-cloud/connect?connected=1',{timeout:5000});
      verify((await page.content()).includes('second-site'), 'Second site not shown after callback');
      verify((await page.content()).includes('Подключено сайтов: <strong>2</strong>'), 'Multi-site account count wrong');
      verify((await page.content()).includes('Осталось: 492'), 'Shared account balance missing');
      await page.locator('input[name=home]').first().fill('https://okean.market');
      await Promise.all([
        page.waitForNavigation({waitUntil:'load'}),
        page.getByRole('button',{name:'Проверить связь',exact:true}).click({noWaitAfter:true})
      ]);
      verify((await page.content()).includes('Связь с сайтом подтверждена'), 'Preflight result missing');
      verify(page.url().startsWith(central+'/wpai-cloud/connect'), 'Preflight navigated to remote site');
    }
    await context.addCookies([
      {name:'fixture_connected',value:'1',url:central,httpOnly:true,sameSite:'Lax'},
      {name:'fixture_oauth',value:'ready',url:central,httpOnly:true,sameSite:'Lax'}
    ]);
    violations = [];
    await page.goto(central+'/wpai-cloud/oauth/authorize');
    for (const field of await page.locator('input[name="sites[]"]').all()) await field.check();
    await page.getByRole('button',{name:'Разрешить выбранные сайты',exact:true}).click({noWaitAfter:true});
    if (baseline) {
      await page.waitForTimeout(700);
      verify(page.url().startsWith(central+'/wpai-cloud/oauth/authorize'), 'Baseline OAuth callback must be blocked');
      verify(violations.length>0, 'Baseline OAuth redirect did not trigger CSP');
    } else {
      await page.waitForURL(native+'/oauth-client/callback?*',{timeout:5000});
      const callback = JSON.parse(await page.locator('body').innerText());
      verify(callback.query.code==='c'.repeat(43), 'OAuth code lost');
      verify(callback.query.state==='state+and&value', 'OAuth state encoding changed');
      verify(callback.query.existing==='one', 'Registered callback query lost');
      verify(callback.method==='GET', 'Form POST leaked across origins');
      verify(callback.referer===null, 'Referrer exposed callback data');
      verify(violations.length===0, 'OAuth still blocked by CSP');
      await page.goto(central+'/wpai-cloud/oauth/authorize');
      await page.getByRole('button',{name:'Отказать',exact:true}).click({noWaitAfter:true});
      await page.waitForURL(native+'/oauth-client/callback?*',{timeout:5000});
      const denied = JSON.parse(await page.locator('body').innerText());
      verify(denied.query.error==='access_denied'&&!denied.query.code, 'Deny flow widened consent');
      const rejected = await context.request.post(central+'/wpai-cloud/connect',{form:{home:'https://okean.market',connect_mode:'automatic',wpai_nonce:'invalid'}});
      verify(rejected.status()===403&&!(await rejected.text()).includes('http-equiv="refresh"'), 'Invalid nonce reached navigation');
    }
    console.log('PASS '+checks+' Chromium '+(baseline?'exact 0.3.5 baseline failure reproduction':'two-origin pairing/OAuth and shared-account checks'));
  } finally {
    if (browser) await browser.close();
    for (const server of servers) server.kill();
  }
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
