const assert = require('node:assert/strict');
const http = require('node:http');
const vm = require('node:vm');

const origin = process.argv[2];
if (!origin) throw new Error('Pass the local preview origin');

function get(url) {
  return new Promise((resolve, reject) => {
    http.get(url, response => {
      let body = '';
      response.setEncoding('utf8');
      response.on('data', chunk => { body += chunk; });
      response.on('end', () => resolve(body));
    }).on('error', reject);
  });
}

async function page(path) {
  const html = await get(origin + path);
  const script = [...html.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)]
    .map(match => match[1]).find(source => source.includes('function cbTab(name)'));
  assert.ok(script, 'page navigation script rendered');

  const location = {href: origin + path};
  const timers = [];
  location.assign = url => { location.href = url; };
  location.replace = url => { location.href = url; };
  const context = {
    URL, location,
    history: {replaceState: (_state, _title, url) => { location.href = url; }},
    window: {confirm: () => true, addEventListener: () => {}},
    document: {
      readyState: 'complete',
      addEventListener: () => {},
      querySelectorAll: () => [],
      getElementById: () => null
    },
    requestAnimationFrame: () => {},
    setTimeout: callback => { timers.push(callback); }
  };
  vm.createContext(context);
  vm.runInContext(script, context);
  return {context, location, timers};
}

(async () => {
  let view = await page('/Settings/unraid-certbot?tab=status&history_page=2');
  assert.equal(new URL(view.location.href).searchParams.has('history_page'), false,
    'status URL drops stale history page');
  view.context.cbTab('history');
  assert.equal(new URL(view.location.href).searchParams.has('history_page'), false,
    'returning to history starts at page one');

  view = await page('/Settings/unraid-certbot?tab=history&history_page=2');
  view.context.cbTab('status');
  assert.equal(new URL(view.location.href).searchParams.has('history_page'), false,
    'leaving history drops its page');

  view = await page('/Settings/unraid-certbot?tab=history&history_page=2');
  view.context.cbHistoryPage(1);
  assert.equal(new URL(view.location.href).searchParams.has('history_page'), false,
    'first history page has a clean URL');

  view = await page('/Settings/unraid-certbot?tab=history');
  view.context.cbHistoryPage(2);
  assert.equal(new URL(view.location.href).searchParams.get('history_page'), '2',
    'history pagination opens the requested page');

  view = await page('/Settings/unraid-certbot?tab=config');
  view.context.cbSaveResult(false, 'Settings not saved');
  assert.equal(view.timers.length, 0, 'failed save does not reload');
  view.context.cbSaveResult(true, 'Settings saved');
  assert.equal(view.timers.length, 1, 'successful save schedules reload');
  view.timers[0]();
  assert.equal(new URL(view.location.href).searchParams.get('_saved'), '1',
    'successful save reloads with a confirmation marker');

  view = await page('/Settings/unraid-certbot?tab=config&_saved=1');
  assert.equal(view.timers.length, 0, 'save confirmation does not start another reload');

  console.log('navigation URL checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
