import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { readFile, writeFile, mkdir, mkdtemp, rm, readdir } from 'node:fs/promises';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as delay } from 'node:timers/promises';

// Run with: node tests/browser-smoke.mjs. Chrome and PHP paths can be overridden.
// Each run uses an isolated database and Chrome profile, never campus.sqlite.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const chromePath = process.env.SCK_CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const phpPath = process.env.SCK_PHP || 'C:\\xampp\\php\\php.exe';
const temp = await mkdtemp(path.join(os.tmpdir(), 'sck-browser-'));
const profile = path.join(temp, 'chrome-profile');
const downloads = path.join(temp, 'downloads');
const screenshots = path.join(root, '.preview');
let php, chrome, cdp, passes = 0, stage = 'startup';
const errors = [];
const childErrors = [];

function check(value, message) { assert.ok(value, message); passes++; }
async function freePort() {
  const server = net.createServer();
  await new Promise((resolve, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', resolve); });
  const port = server.address().port;
  await new Promise(resolve => server.close(resolve));
  return port;
}
async function until(task, label, timeout = 12000) {
  const deadline = Date.now() + timeout;
  let last;
  while (Date.now() < deadline) {
    try { last = await task(); if (last) return last; } catch (error) { last = error.message; }
    await delay(100);
  }
  throw new Error(`Timed out waiting for ${label}: ${String(last)}`);
}
function start(executable, args, extra = {}) {
  const child = spawn(executable, args, { cwd: root, windowsHide: true, stdio: 'ignore', ...extra });
  child.once('error', error => childErrors.push(error.message));
  return child;
}
async function stop(child) {
  if (!child || child.exitCode !== null || child.signalCode !== null) return;
  child.kill();
  await Promise.race([new Promise(resolve => child.once('exit', resolve)), delay(3000)]);
}
class CDP {
  constructor(socket) {
    this.socket = socket;
    this.next = 0;
    this.pending = new Map();
    socket.addEventListener('message', event => {
      const message = JSON.parse(event.data);
      if (message.id) {
        const pending = this.pending.get(message.id);
        if (!pending) return;
        this.pending.delete(message.id);
        clearTimeout(pending.timer);
        message.error ? pending.reject(new Error(message.error.message)) : pending.resolve(message.result);
      } else if (message.method === 'Runtime.exceptionThrown') {
        const e = message.params.exceptionDetails;
        errors.push(e.exception?.description || e.text);
      } else if (message.method === 'Runtime.consoleAPICalled' && message.params.type === 'error') {
        errors.push(message.params.args.map(arg => arg.value || arg.description).join(' '));
      }
    });
    socket.addEventListener('close', () => {
      for (const pending of this.pending.values()) { clearTimeout(pending.timer); pending.reject(new Error('Chrome connection closed')); }
      this.pending.clear();
    });
  }
  static async connect(url) {
    const socket = new WebSocket(url);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, { once: true }); socket.addEventListener('error', reject, { once: true }); });
    return new CDP(socket);
  }
  command(method, params = {}) {
    const id = ++this.next;
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => { this.pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, 12000);
      this.pending.set(id, { resolve, reject, timer });
      this.socket.send(JSON.stringify({ id, method, params }));
    });
  }
  async eval(expression) {
    const result = await this.command('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
  }
}
async function visible(selector) {
  return cdp.eval(`(() => { const el=document.querySelector(${JSON.stringify(selector)}); return !!el && !el.hidden && el.getClientRects().length>0; })()`);
}
async function waitFor(expression, label) { return until(() => cdp.eval(expression), label); }
async function click(selector) {
  await until(() => visible(selector), `visible ${selector}`);
  await cdp.eval(`(() => { const el=document.querySelector(${JSON.stringify(selector)}); if(el.disabled)throw new Error('Button disabled'); el.click(); })()`);
}
async function fill(selector, value, event = 'input') {
  await cdp.eval(`(() => { const el=document.querySelector(${JSON.stringify(selector)}); if(!el)throw new Error('Missing input'); el.value=${JSON.stringify(value)}; el.dispatchEvent(new Event(${JSON.stringify(event)},{bubbles:true})); })()`);
}
async function submit(selector) { await cdp.eval(`document.querySelector(${JSON.stringify(selector)}).requestSubmit()`); }
async function navigate(view, heading) {
  await click(`#navigation [data-view="${view}"]`);
  await waitFor(`document.querySelector('#view h1')?.textContent===${JSON.stringify(heading)} && !document.querySelector('#view .busy-view')`, heading);
}
async function confirm() {
  await click('#confirm-action');
  await waitFor(`!document.querySelector('#modal').open`, 'confirmation closes');
}
async function escape() {
  await cdp.command('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
  await cdp.command('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
}
async function screenshot(name) {
  await cdp.eval(`[...document.querySelectorAll('#toast-region button[aria-label="Dismiss notification"]')].forEach(button=>button.click())`);
  await delay(150);
  const { data } = await cdp.command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  await writeFile(path.join(screenshots, name), Buffer.from(data, 'base64'));
}

try {
  await Promise.all([mkdir(profile), mkdir(downloads), mkdir(screenshots, { recursive: true })]);
  const port = await freePort();
  const base = `http://127.0.0.1:${port}/`;
  php = start(phpPath, ['-S', `127.0.0.1:${port}`, 'router.php'], { env: { ...process.env, SCK_DB_PATH: path.join(temp, 'campus-test.sqlite') } });
  await until(async () => (await fetch(base)).ok, 'PHP server');
  chrome = start(chromePath, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-background-networking', '--disable-component-update', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank']);
  const debugPort = await until(async () => Number((await readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]), 'Chrome debugging port');
  const target = await until(async () => (await (await fetch(`http://127.0.0.1:${debugPort}/json/list`)).json()).find(target => target.type === 'page'), 'Chrome page');
  cdp = await CDP.connect(target.webSocketDebuggerUrl);
  await cdp.command('Runtime.enable');
  await cdp.command('Page.enable');
  await cdp.command('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: downloads });
  await cdp.command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1080, deviceScaleFactor: 1, mobile: false });
  await cdp.command('Page.navigate', { url: base });

  stage = 'unauthenticated sign-in';
  await until(() => visible('#login-screen'), 'login page');
  check(await cdp.eval(`document.querySelector('#app').hidden && !document.querySelector('#view').textContent.trim()`), 'Protected dashboard must be empty before authentication');
  check(await cdp.eval(`fetch('api.php?action=dashboard').then(r=>r.status===401)`), 'Unauthenticated dashboard API is protected');
  await screenshot('login-desktop.png');
  await click('#demo-login');
  await waitFor(`!document.querySelector('#app').hidden && document.querySelector('#view h1')?.textContent==='Campus overview'`, 'admin overview');
  check(await visible('#navigation [data-view="accounts"]'), 'Administrator has user accounts navigation');
  check(await visible('#navigation [data-view="archives"]'), 'Administrator has archive navigation');
  check(await cdp.eval(`document.querySelectorAll('.stats-grid .stat-card').length===4 && document.querySelectorAll('.chart .bar-entry').length>0`), 'Overview statistics and visitor chart render');
  await screenshot('dashboard-desktop.png');

  stage = 'register a fictional visitor';
  const visitorName = `Browser Test Visitor ${Date.now()}`;
  await click('#view [data-action="register"]');
  await waitFor(`document.querySelector('#modal').open && !!document.querySelector('#register-form')`, 'registration form');
  check(await cdp.eval(`document.querySelector('#modal').getAttribute('aria-labelledby')==='modal-heading' && !!document.querySelector('#modal-heading h2').textContent`), 'Registration modal has an accessible label');
  const badge = await cdp.eval(`(() => { const option=document.querySelector('#badge_id option[value]:not([value=""])');const match=option.textContent.match(/^(\\S+).*UID\\s+(\\S+)/);return {id:option.value,code:match[1],uid:match[2]}; })()`);
  await fill('#name', visitorName);
  await fill('#organization', 'Fictional QA Team');
  await fill('#phone', '09170000000');
  await fill('#purpose', 'Meeting', 'change');
  await fill('#host', 'Demonstration Office');
  await fill('#badge_scan', badge.uid);
  check(await cdp.eval(`document.querySelector('#badge_id').value===${JSON.stringify(badge.id)}`), 'Registration UID scan selects the available badge');
  await submit('#register-form');
  await waitFor(`!document.querySelector('#modal').open && document.querySelector('#view').textContent.includes(${JSON.stringify(visitorName)})`, 'registered visitor on dashboard');
  check(true, 'Available RFID badge assigned and visitor checked in through UI');

  stage = 'visitor detail and edit';
  await navigate('visitors', 'Visitors');
  await waitFor(`document.querySelector('#visitor-table').textContent.includes(${JSON.stringify(visitorName)})`, 'visitor listing');
  await screenshot('visitors-desktop.png');
  const visitorId = await cdp.eval(`[...document.querySelectorAll('#visitor-table tbody tr')].find(row=>row.textContent.includes(${JSON.stringify(visitorName)})).querySelector('[data-action="detail"]').dataset.id`);
  await click(`#visitor-table [data-action="detail"][data-id="${visitorId}"]`);
  await waitFor(`document.querySelector('#modal').open && document.querySelector('#modal-content').textContent.includes(${JSON.stringify(visitorName)})`, 'visitor detail');
  check(await cdp.eval(`document.querySelector('#modal-content').textContent.includes(${JSON.stringify(badge.code)})`), 'Visitor detail shows assigned badge');
  await click('#modal [data-action="edit-visitor"]');
  await waitFor(`!!document.querySelector('#edit-visitor-form')`, 'edit form');
  await fill('#organization', 'Fictional QA Team Edited');
  await submit('#edit-visitor-form');
  await waitFor(`!document.querySelector('#modal').open && document.querySelector('#visitor-table').textContent.includes('Fictional QA Team Edited')`, 'edited visitor');
  check(true, 'Administrator edited the visitor record');

  stage = 'history search and entry/exit filters';
  await navigate('history', 'Visitor history');
  await fill('#visitor-search', visitorName);
  await waitFor(`document.querySelector('#results-count').textContent==='1 visit found' && document.querySelector('#visitor-table').textContent.includes(${JSON.stringify(visitorName)})`, 'name search');
  check(true, 'History search finds fictional visitor by name');
  await fill('#badge-filter', badge.code);
  await waitFor(`document.querySelector('#results-count').textContent==='1 visit found'`, 'badge search');
  check(await cdp.eval(`document.querySelector('#time-field').options.length===2 && document.querySelector('#time-field').value==='check_in'`), 'History offers entry and exit time filters');
  const visitDate = await cdp.eval(`new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Manila',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date())`);
  await fill('#date-filter', visitDate, 'change');
  await fill('#time-field', 'check_out', 'change');
  await waitFor(`document.querySelector('#results-count').textContent==='0 visits found'`, 'uncompleted visit excluded from exit filter');
  check(true, 'Exit filter excludes visits with no recorded departure');
  await fill('#time-field', 'check_in', 'change');
  await waitFor(`document.querySelector('#results-count').textContent==='1 visit found'`, 'entry filter');
  check(true, 'Entry filter includes the new visit');

  stage = 'security role and RFID scan';
  await click('#profile-button');
  await click('#modal [data-action="logout"]');
  await until(() => visible('#login-screen'), 'sign-out');
  await fill('#login-email', 'security@sti.local');
  await fill('#login-password', 'CampusSecurity!2026');
  await submit('#login-form');
  await waitFor(`!document.querySelector('#app').hidden && document.querySelector('#profile-role').textContent==='Security personnel'`, 'security login');
  check(!await visible('#navigation [data-view="accounts"]'), 'Security role has no user accounts navigation');
  check(!await visible('#navigation [data-view="archives"]'), 'Security role has no archive navigation');
  check(await cdp.eval(`fetch('api.php?action=archives').then(r=>r.status===403)`), 'Security cannot read archive API');
  await cdp.command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  await delay(150);
  await click('#menu-toggle');
  check(!await visible('#navigation [data-view="archives"]'), 'Security mobile navigation also hides archives');
  await escape();
  await cdp.command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1080, deviceScaleFactor: 1, mobile: false });
  await waitFor(`!document.querySelector('#sidebar').inert`, 'security desktop resize');
  await click('.sidebar-kiosk a[href="#kiosk"]');
  await waitFor(`!!document.querySelector('#scan-form')`, 'gate kiosk');
  await fill('#scan-uid', badge.uid);
  await submit('#scan-form');
  await waitFor(`document.querySelector('#scan-result').textContent.includes(${JSON.stringify(visitorName)}) && document.querySelector('#scan-result').textContent.includes('available again')`, 'RFID scan check-out');
  check(true, 'UID scan checks out visitor and releases assigned badge');
  await navigate('history', 'Visitor history');
  await fill('#visitor-search', visitorName);
  await fill('#date-filter', visitDate, 'change');
  await fill('#time-field', 'check_out', 'change');
  await waitFor(`document.querySelector('#results-count').textContent==='1 visit found' && document.querySelector('#visitor-table').textContent.includes('Checked out')`, 'completed history');
  check(true, 'History records completed visitor check-out');
  await click(`#visitor-table [data-action="detail"][data-id="${visitorId}"]`);
  await waitFor(`document.querySelector('#modal').open`, 'security detail modal');
  check(!await cdp.eval(`!!document.querySelector('#modal [data-action="edit-visitor"]')`), 'Security cannot edit visitor records');
  check(!await cdp.eval(`!!document.querySelector('#modal [data-action="archive-visitor"]')`), 'Security cannot archive visitor records');
  await escape();
  await waitFor(`!document.querySelector('#modal').open`, 'Escape closes modal');
  check(true, 'Native Escape dismisses the modal');
  await click('#view [data-action="register"]');
  await waitFor(`!!document.querySelector('#register-form')`, 'badge reuse registration');
  check(await cdp.eval(`[...document.querySelector('#badge_id').options].some(option=>option.value===${JSON.stringify(badge.id)})`), 'Checked-out badge is available for another registration');
  await escape();

  stage = 'report and CSV download';
  await navigate('reports', 'Campus reports');
  await waitFor(`document.querySelectorAll('#report-results .stat-card').length===4`, 'report summary');
  check(await cdp.eval(`document.querySelector('#report-results').textContent.includes('Daily visit summary')`), 'Campus reports render daily summary');
  await click('#view [data-action="export-report"]');
  const csvName = await until(async () => (await readdir(downloads)).find(name => name.endsWith('.csv')), 'CSV download');
  const csv = await readFile(path.join(downloads, csvName), 'utf8');
  check(csv.includes(visitorName) && csv.includes(badge.code) && csv.includes('Fictional QA Team Edited'), 'Report CSV downloads persisted visitor details');

  stage = 'archive completed visitors and badges';
  await click('#profile-button');
  await click('#modal [data-action="logout"]');
  await until(() => visible('#login-screen'), 'security sign-out');
  await click('#demo-login');
  await waitFor(`document.querySelector('#profile-role').textContent==='Campus administrator' && !document.querySelector('#app').hidden`, 'administrator sign-in for archive management');
  await navigate('history', 'Visitor history');
  await fill('#visitor-search', visitorName);
  await waitFor(`document.querySelector('#results-count').textContent==='1 visit found'`, 'completed visitor ready to archive');
  await click(`#visitor-table [data-action="detail"][data-id="${visitorId}"]`);
  await click(`#modal [data-action="archive-visitor"][data-id="${visitorId}"]`);
  await waitFor(`document.querySelector('#confirm-action') && document.querySelector('#modal').open`, 'visitor archive confirmation');
  await click('#modal [data-action="close-modal"]');
  await waitFor(`!document.querySelector('#modal').open`, 'cancel visitor archive');
  check(await cdp.eval(`document.querySelector('#visitor-table').textContent.includes(${JSON.stringify(visitorName)})`), 'Cancelling archive retains the visitor');
  await click(`#visitor-table [data-action="detail"][data-id="${visitorId}"]`);
  await click(`#modal [data-action="archive-visitor"][data-id="${visitorId}"]`);
  await confirm();
  await waitFor(`document.querySelector('#results-count').textContent==='0 visits found'`, 'archived visitor removed from normal history');
  check(true, 'Administrator archived a completed visitor through the detail dialog');
  await click('#view [data-action="clear-filters"]');
  await waitFor(`document.querySelector('#visitor-table tbody tr')`, 'history filters reset');
  const otherVisitorId = await cdp.eval(`[...document.querySelectorAll('#visitor-table tbody tr')].find(row=>row.textContent.includes('Checked out')).querySelector('[data-action="detail"]').dataset.id`);
  await click(`#visitor-table [data-action="detail"][data-id="${otherVisitorId}"]`);
  await click(`#modal [data-action="archive-visitor"][data-id="${otherVisitorId}"]`);
  await confirm();
  await waitFor(`!document.querySelector('#visitor-table [data-action="detail"][data-id="${otherVisitorId}"]')`, 'second visitor archived');
  await navigate('badges', 'RFID badges');
  check(await cdp.eval(`(() => { const cards=[...document.querySelectorAll('.rfid-card')].filter(card=>card.querySelector('.status.assigned'));return cards.length>0 && cards.every(card=>!card.querySelector('[data-action="archive-badge"]') || card.querySelector('[data-action="archive-badge"]').disabled); })()`), 'Assigned badges cannot be archived through the UI');
  await click(`#view [data-action="archive-badge"][data-id="${badge.id}"]`);
  await confirm();
  await waitFor(`!document.querySelector('#view [data-action="archive-badge"][data-id="${badge.id}"]')`, 'used badge archived');
  const otherBadgeId = await cdp.eval(`[...document.querySelectorAll('#view [data-action="archive-badge"]')].find(button=>!button.disabled).dataset.id`);
  await click(`#view [data-action="archive-badge"][data-id="${otherBadgeId}"]`);
  await confirm();
  await waitFor(`!document.querySelector('#view [data-action="archive-badge"][data-id="${otherBadgeId}"]')`, 'second badge archived');
  check(true, 'Administrator archived two unassigned badges');

  stage = 'archive selection, tabs, and cancelled deletion';
  await navigate('archives', 'Archives');
  await waitFor(`document.querySelectorAll('#archive-table [data-archive-id]').length===2`, 'two archived visitors');
  await screenshot('archives-desktop.png');
  await cdp.command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  await delay(150);
  check(await cdp.eval(`document.documentElement.scrollWidth<=window.innerWidth`), '390px populated archive page contains table scrolling');
  await screenshot('archives-mobile.png');
  await cdp.command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1080, deviceScaleFactor: 1, mobile: false });
  await waitFor(`!document.querySelector('#sidebar').inert`, 'desktop archive navigation');
  check(await cdp.eval(`document.querySelector('[data-action="delete-archives"]').disabled && document.querySelector('[data-action="restore-archives"]').disabled`), 'Archive actions require a selected row');
  await click(`#archive-table [data-archive-id="${visitorId}"]`);
  check(await cdp.eval(`!document.querySelector('[data-action="delete-archives"]').disabled && !document.querySelector('[data-action="restore-archives"]').disabled`), 'Selecting an archive row enables actions');
  await click('[data-action="clear-archives"]');
  check(await cdp.eval(`document.querySelector('[data-action="delete-archives"]').disabled`), 'Clear selection disables archive actions');
  await click('[data-action="select-archives"]');
  check(await cdp.eval(`document.querySelectorAll('#archive-table [data-archive-id]:checked').length===2`), 'Select button chooses every archive row on the page');
  await click('[data-action="archive-tab"][data-type="badges"]');
  await waitFor(`document.querySelectorAll('#archive-table [data-archive-id]').length===2 && document.querySelector('#archive-table').textContent.includes(${JSON.stringify(badge.code)})`, 'badges archive tab');
  check(await cdp.eval(`document.querySelector('[data-action="delete-archives"]').disabled && !document.querySelector('#archive-table [data-archive-id]:checked')`), 'Changing archive tabs clears the prior selection');
  await click(`#archive-table [data-archive-id="${badge.id}"]`);
  await click('[data-action="restore-archives"]');
  await confirm();
  await waitFor(`document.querySelectorAll('#archive-table [data-archive-id]').length===1`, 'one badge restored');
  await navigate('badges', 'RFID badges');
  check(await cdp.eval(`document.querySelector('#view [data-action="archive-badge"][data-id="${badge.id}"]').closest('.rfid-card').querySelector('.status.inactive')!==null`), 'Restored badges return inactive for deliberate activation');
  await click(`#view [data-action="archive-badge"][data-id="${badge.id}"]`);
  await confirm();
  await waitFor(`!document.querySelector('#view [data-action="archive-badge"][data-id="${badge.id}"]')`, 'restored badge rearchived');
  await navigate('archives', 'Archives');
  await waitFor(`document.querySelectorAll('#archive-table [data-archive-id]').length===2 && document.querySelector('#archive-table').textContent.includes(${JSON.stringify(badge.code)})`, 'both badges ready for selection');
  await click('#archive-select-all');
  check(await cdp.eval(`document.querySelectorAll('#archive-table [data-archive-id]:checked').length===2`), 'Select all chooses both archived badges');
  await click(`#archive-table [data-archive-id="${badge.id}"]`);
  check(await cdp.eval(`document.querySelector('#archive-select-all').indeterminate && document.querySelectorAll('#archive-table [data-archive-id]:checked').length===1`), 'A partial selection updates the select-all checkbox');
  await click('#archive-select-all');
  await click('[data-action="delete-archives"]');
  await waitFor(`document.querySelector('#modal').open && document.querySelector('#confirm-action')`, 'bulk badge delete confirmation');
  check(await cdp.eval(`document.querySelector('#modal-content').textContent.toLowerCase().includes('permanently')`), 'Delete confirmation explains permanent removal');
  await click('#modal [data-action="close-modal"]');
  await waitFor(`!document.querySelector('#modal').open`, 'cancel bulk badge delete');
  check(await cdp.eval(`document.querySelectorAll('#archive-table [data-archive-id]').length===2`), 'Cancelling permanent deletion retains selected badges');
  await click('[data-action="delete-archives"]');
  await confirm();
  await waitFor(`document.querySelectorAll('#archive-table [data-archive-id]').length===0`, 'selected badges deleted');
  check(await cdp.eval(`document.querySelector('[data-action="delete-archives"]').disabled`), 'Bulk badge deletion empties selection and disables deletion');

  stage = 'report export includes archived history after badge deletion';
  await navigate('reports', 'Campus reports');
  await waitFor(`document.querySelectorAll('#report-results .stat-card').length===4`, 'report including archive');
  // Headless Chrome may overwrite the same report filename; remove our prior test download.
  await rm(path.join(downloads, csvName));
  await click('#view [data-action="export-report"]');
  const archiveCsvName = await until(async () => (await readdir(downloads)).find(name=>name.endsWith('.csv')), 'report CSV including archive');
  const archiveCsv = await readFile(path.join(downloads, archiveCsvName), 'utf8');
  check(archiveCsv.includes(visitorName) && archiveCsv.includes(badge.code) && archiveCsv.includes(badge.uid), 'Report CSV includes archived visitor and deleted badge identifiers');
  await navigate('archives', 'Archives');

  stage = 'restore visitor, preserve removed badge history, and delete visitors';
  await click('[data-action="archive-tab"][data-type="visitors"]');
  await waitFor(`document.querySelectorAll('#archive-table [data-archive-id]').length===2`, 'visitors remain archived after badge deletion');
  await click(`#archive-table [data-archive-id="${visitorId}"]`);
  await click('[data-action="restore-archives"]');
  await confirm();
  await waitFor(`document.querySelectorAll('#archive-table [data-archive-id]').length===1`, 'selected visitor restored');
  check(!await cdp.eval(`!!document.querySelector('#archive-table [data-archive-id="${visitorId}"]')`), 'Restoration removes the selected visitor from archive');
  await navigate('history', 'Visitor history');
  await fill('#visitor-search', visitorName);
  await waitFor(`document.querySelector('#results-count').textContent==='1 visit found'`, 'restored visitor returns to history');
  check(await cdp.eval(`document.querySelector('#visitor-table').textContent.includes(${JSON.stringify(badge.code)})`), 'Restored visitor preserves the permanently removed badge label');
  await click(`#visitor-table [data-action="detail"][data-id="${visitorId}"]`);
  await click(`#modal [data-action="archive-visitor"][data-id="${visitorId}"]`);
  await confirm();
  await waitFor(`document.querySelector('#results-count').textContent==='0 visits found'`, 'restored visitor rearchived');
  await navigate('archives', 'Archives');
  await waitFor(`document.querySelectorAll('#archive-table [data-archive-id]').length===2`, 'visitor archive ready for bulk deletion');
  await click('#archive-select-all');
  await click('[data-action="delete-archives"]');
  await confirm();
  await waitFor(`document.querySelectorAll('#archive-table [data-archive-id]').length===0`, 'selected visitors deleted');
  check(true, 'Select all and confirmed deletion permanently remove both archived visitors');
  await cdp.command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  await delay(150);
  check(await cdp.eval(`document.documentElement.scrollWidth<=window.innerWidth`), '390px archive page has no document horizontal overflow');
  await cdp.command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1080, deviceScaleFactor: 1, mobile: false });
  await waitFor(`!document.querySelector('#sidebar').inert`, 'desktop resize after archive test');

  stage = 'mobile layout and navigation';
  await navigate('dashboard', 'Campus overview');
  await cdp.command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  await delay(150);
  check(await cdp.eval(`document.documentElement.scrollWidth<=window.innerWidth`), '390px dashboard has no document horizontal overflow');
  check(await cdp.eval(`document.querySelector('#sidebar').inert && document.querySelector('#menu-toggle').getAttribute('aria-expanded')==='false'`), 'Closed mobile navigation is inert');
  await screenshot('dashboard-mobile.png');
  await click('#menu-toggle');
  check(await cdp.eval(`!document.querySelector('#sidebar').inert && document.querySelector('#menu-toggle').getAttribute('aria-expanded')==='true'`), 'Mobile navigation opens and becomes interactive');
  await navigate('visitors', 'Visitors');
  check(await cdp.eval(`document.querySelector('#sidebar').inert && document.querySelector('#mobile-backdrop').hidden`), 'Choosing mobile navigation closes the drawer');
  check(await cdp.eval(`document.documentElement.scrollWidth<=window.innerWidth`), '390px visitor page keeps table scroll inside its panel');
  await click('#menu-toggle');
  await escape();
  check(await cdp.eval(`document.querySelector('#sidebar').inert && document.activeElement.id==='menu-toggle'`), 'Escape closes mobile navigation and restores focus');
  await cdp.command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1080, deviceScaleFactor: 1, mobile: false });
  await waitFor(`!document.querySelector('#sidebar').inert`, 'desktop navigation resize');
  check(true, 'Desktop navigation restores interaction after viewport resize');
  check(errors.length === 0, `No JavaScript exceptions: ${errors.join('; ')}`);
  check(childErrors.length === 0, `No child process failures: ${childErrors.join('; ')}`);
  console.log(`PASS browser smoke: ${passes} assertions; 6 screenshots saved to .preview.`);
} catch (error) {
  console.error(`FAIL browser smoke at ${stage}: ${error.message}`);
  if (errors.length) console.error(`JavaScript errors: ${errors.join('; ')}`);
  if (childErrors.length) console.error(`Process errors: ${childErrors.join('; ')}`);
  if (cdp) {
    try { console.error((await cdp.eval(`document.body.innerText`)).slice(-1800)); } catch {}
  }
  process.exitCode = 1;
} finally {
  if (cdp) { try { await cdp.command('Browser.close'); } catch {} cdp.socket.close(); }
  await stop(chrome);
  await stop(php);
  // Recursive deletion is limited to this uniquely allocated test directory.
  const absoluteTemp = path.resolve(temp);
  const tempRoot = path.resolve(os.tmpdir());
  const relative = path.relative(tempRoot, absoluteTemp);
  if (relative && !relative.startsWith('..') && !path.isAbsolute(relative) && path.basename(absoluteTemp).startsWith('sck-browser-')) {
    try { await rm(absoluteTemp, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 }); } catch (error) { console.error(`Temporary test directory cleanup: ${error.message}`); }
  }
}

