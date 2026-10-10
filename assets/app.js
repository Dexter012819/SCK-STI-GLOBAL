'use strict';
const $ = (selector, scope = document) => scope.querySelector(selector);
const $$ = (selector, scope = document) => [...scope.querySelectorAll(selector)];
const state = {user:null,csrf:'',sessionEpoch:0,view:'dashboard',dashboard:null,visitors:[],badges:[],accounts:[],page:1,request:0,filters:{},report:{},archive:{type:'visitors',q:'',page:1,records:{visitors:[],badges:[]},selected:new Set()}};
const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
const icon = name => '<svg class="icon" aria-hidden="true"><use href="#i-'+name+'"/></svg>';
const isAdmin = () => state.user?.role === 'admin';
const initials = name => String(name || '?').trim().split(/\s+/).slice(0,2).map(x=>x[0]).join('').toUpperCase();
const localDate = (date = new Date()) => {
  const parts = new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Manila',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(date);
  return ['year','month','day'].map(t=>parts.find(p=>p.type===t).value).join('-');
};
const fmtTime = value => value ? new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',hour:'numeric',minute:'2-digit'}).format(new Date(value)) : '—';
const fmtDate = value => value ? new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',month:'short',day:'numeric',year:'numeric'}).format(new Date(value.length===10?value+'T00:00:00+08:00':value)) : '—';
const fmtNumber = value => Number(value||0).toLocaleString('en-PH');
const status = value => '<span class="status '+esc(value)+'">'+esc(({on_campus:'On campus',checked_out:'Checked out',available:'Available',assigned:'Assigned',inactive:'Inactive',active:'Active',archived:'Archived'})[value] || value)+'</span>';
const personCell = (v,i=0) => '<div class="visitor-cell"><span class="avatar '+['','mint','lilac','peach'][i%4]+'">'+avatarContent(v)+'</span><div><span class="visitor-name">'+esc(v.name)+'</span><span class="visitor-org">'+esc(v.organization || 'Individual visitor')+'</span></div></div>';
function setConnection(connected){
  $('#connection-dot').classList.toggle('offline',!connected);
  $('#connection-label').textContent = connected?'Connected to campus':'Connection interrupted';
  $('.live-indicator').innerHTML='<span class="status-dot'+(connected?'':' offline')+'"></span>'+(connected?'Live':'Offline');
  if(connected) $('#last-sync').textContent = 'Synced '+fmtTime(new Date().toISOString());
}
async function api(action,data=null,params={}){
  const url = new URL('api.php',location.href);
  url.searchParams.set('action',action);
  Object.entries(params).forEach(([key,value])=>{if(value!==''&&value!=null)url.searchParams.set(key,value)});
  const requestEpoch=state.sessionEpoch;
  const multipart=data instanceof FormData;
  let response;
  try{
    response=await fetch(url,{method:data===null?'GET':'POST',headers:data===null?{}:multipart?{'X-CSRF-Token':state.csrf}:{'Content-Type':'application/json','X-CSRF-Token':state.csrf},body:data===null?undefined:multipart?data:JSON.stringify(data),credentials:'same-origin',cache:'no-store'});
  }catch(error){setConnection(false);throw new Error('Cannot reach the campus server. Check your connection and try again.')}
  let result;
  try{result=await response.json()}catch(error){setConnection(false);throw new Error('The server returned an unexpected response. Check the PHP setup.')}
  if(requestEpoch!==state.sessionEpoch)throw new Error('Your sign-in session changed. Please try again if needed.');
  setConnection(true);
  if(!response.ok || result.ok===false){
    if(response.status===401 && action!=='login'){state.user=null;showLogin();throw new Error('Your session has ended. Please sign in again.')}
    throw new Error(result.error || 'This request could not be completed.');
  }
  if(result.csrf) state.csrf=result.csrf;
  return result;
}
function toast(message,error=false){
  const item=document.createElement('div');
  item.className='toast'+(error?' error':'');
  item.setAttribute('role',error?'alert':'status');
  item.innerHTML=icon(error?'help':'check')+'<span>'+esc(message)+'</span><button class="icon-button" aria-label="Dismiss notification">'+icon('close')+'</button>';
  item.querySelector('button').onclick=()=>item.remove();
  while($('#toast-region').children.length>=2)$('#toast-region').firstElementChild.remove();
  $('#toast-region').append(item);
  setTimeout(()=>item.remove(),6500);
}
function showLogin(){
  state.sessionEpoch++;resetProfileContext();
  $('#loading-screen').hidden=true;$('#app').hidden=true;$('#login-screen').hidden=false;
  closeMenu();
  if($('#modal').open) $('#modal').close();
  document.title='Sign in · Smart Campus Kiosk';
}
function showApp(){
  $('#loading-screen').hidden=true;$('#login-screen').hidden=true;$('#app').hidden=false;
  updateCurrentUser(state.user);
  render();
}
function heading(title,subtitle,actions=''){
  return '<div class="page-heading"><div><h1>'+esc(title)+'</h1><p>'+esc(subtitle)+'</p></div><div class="heading-actions">'+actions+'</div></div>';
}
function registerButton(){return '<button class="button primary" data-action="register">'+icon('plus')+'Register visitor</button>'}
function empty(title,description,action=''){
  return '<div class="empty-state">'+icon('users')+'<h3>'+esc(title)+'</h3><p>'+esc(description)+'</p>'+action+'</div>';
}
function statCard(label,value,symbol,tone,foot,unit=''){
  return '<div class="stat-card"><div class="stat-top"><span class="stat-label">'+esc(label)+'</span><span class="stat-icon '+tone+'">'+icon(symbol)+'</span></div><div class="stat-value">'+fmtNumber(value)+(unit?'<small>'+esc(unit)+'</small>':'')+'</div><div class="stat-foot">'+foot+'</div></div>';
}
const viewTitles={dashboard:'Campus overview',visitors:'Visitor management',history:'Visitor history',badges:'RFID badge management',reports:'Campus reports',accounts:'User accounts',archives:'Archives',profile:'Account settings',kiosk:'Gate kiosk'};
async function render(){
  if(!state.user)return;
  let view=location.hash.slice(1).split('?')[0]||'dashboard';
  if(!(view in viewTitles)||(!isAdmin()&&['accounts','archives'].includes(view)))view='dashboard';
  clearProfilePreview();
  state.view=view;
  const version=++state.request;
  closeMenu();
  $$('[data-view]').forEach(el=>{el.classList.toggle('active',el.dataset.view===view);if(el.dataset.view===view)el.setAttribute('aria-current','page');else el.removeAttribute('aria-current')});
  $('#topbar-page').textContent=viewTitles[view];
  document.title=viewTitles[view]+' · Smart Campus Kiosk';
  $('#view').innerHTML='<div class="busy-view"><div class="spinner"></div>Loading your campus workspace…</div>';
  try{
    if(view==='dashboard'){
      const data=await api('dashboard');if(version!==state.request)return;state.dashboard=data;paintDashboard(data);
    }else if(view==='visitors'||view==='history'){
      state.filters={q:'',status:view==='visitors'?'on_campus':'all',date:'',badge:'',time_field:'check_in',from_time:'',to_time:''};state.page=1;
      paintVisitorPage();await loadVisitors();
    }else if(view==='badges'){
      const data=await api('badges');if(version!==state.request)return;state.badges=data.badges;paintBadges();
    }else if(view==='accounts'){
      const data=await api('accounts');if(version!==state.request)return;state.accounts=data.accounts;paintAccounts();
    }else if(view==='profile'){
      await loadProfile();
    }else if(view==='archives'){
      await loadArchives();
    }else if(view==='reports'){
      paintReportPage();await loadReports();
    }else if(view==='kiosk'){paintKiosk()}
  }catch(error){
    if(version!==state.request||!state.user)return;
    $('#view').innerHTML=heading(viewTitles[view],'Your campus workspace')+empty('Unable to load this page',error.message,'<button class="button secondary" data-action="retry">'+icon('refresh')+'Try again</button>');
  }
}
function paintDashboard(data){
  const s=data.stats,b=data.badges_summary;
  const date=new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',month:'short',day:'numeric',year:'numeric'}).format(new Date());
  const hour=Number(new Intl.DateTimeFormat('en-GB',{timeZone:'Asia/Manila',hour:'2-digit',hourCycle:'h23'}).format(new Date()));
  const greeting=hour<12?'Good morning':hour<18?'Good afternoon':'Good evening';
  const active=(data.visitors||[]).filter(v=>v.status==='on_campus').slice(0,6);
  $('#nav-count').textContent=s.on_campus;
  $('#view').innerHTML=heading('Campus overview','A little clarity for a safer, more connected campus.','<span class="date-pill">'+icon('clock')+esc(date)+'</span>'+registerButton())+
    '<section class="welcome-banner"><div class="banner-content"><span class="eyebrow">WELCOME TO YOUR CAMPUS WORKSPACE</span><h2>'+greeting+', '+esc(state.user.first_name||state.user.name.split(' ')[0])+'.</h2><p>Every visit, thoughtfully managed. Here’s what’s happening at STI Global City today.</p><a class="banner-link" href="#kiosk">Ready to welcome a visitor? '+icon('arrow')+'</a></div><span class="banner-dot"></span><svg class="banner-campus" viewBox="0 0 440 210" width="440" height="210" aria-hidden="true" focusable="false"><use href="#campus-scene"/></svg></section>'+
    '<section class="stats-grid" aria-label="Campus statistics">'+
    statCard('Visitors on campus',s.on_campus,'users','','<span class="dot"></span><span class="positive">Currently checked in</span>')+
    statCard('Total visits today',s.visits_today,'enter','green','Recorded since midnight')+
    statCard('Available RFID badges',s.available_badges,'card','purple','Ready for the next visitor')+
    statCard('Average visit duration',Math.round(s.avg_duration_minutes||0),'clock','gold','Completed visits today','min')+'</section>'+
    '<div class="dashboard-grid"><div class="dashboard-primary"><section class="panel"><div class="panel-header"><div><div class="panel-title">Visitors on campus <span class="count-pill">'+fmtNumber(s.on_campus)+'</span></div><p class="panel-subtitle">A live view of everyone currently checked in</p></div><a class="text-button" href="#visitors">View all '+icon('arrow')+'</a></div>'+
    (active.length?'<div class="table-wrap"><table><thead><tr><th>Visitor</th><th>Purpose</th><th>Time in</th><th>Status</th><th></th></tr></thead><tbody>'+active.map((v,i)=>'<tr><td>'+personCell(v,i)+'</td><td>'+esc(v.purpose)+'</td><td>'+esc(fmtTime(v.check_in))+'</td><td>'+status(v.status)+'</td><td><button class="table-action" data-action="detail" data-id="'+v.id+'" aria-label="View '+esc(v.name)+'">'+icon('arrow')+'</button></td></tr>').join('')+'</tbody></table></div>':empty('A quiet moment on campus','New check-ins will appear here as you welcome visitors.',registerButton()))+
    '<div class="table-footer"><span>Showing '+active.length+' of '+fmtNumber(s.on_campus)+' active visits</span><span class="live-small"><span class="status-dot"></span>Refreshes every 20 seconds</span></div></section>'+
    '<section class="panel"><div class="panel-header"><div><div class="panel-title">Today’s visitor flow</div><p class="panel-subtitle">Campus arrivals and departures by hour</p></div><div class="chart-legend"><span><i class="legend-dot"></i>Check-ins</span><span><i class="legend-dot exit"></i>Check-outs</span></div></div><div class="chart-body">'+buildChart(data.trend||[])+'<p class="chart-caption">Philippine Standard Time · Today</p></div></section></div>'+
    '<div class="dashboard-secondary"><section class="panel"><div class="panel-header"><div class="panel-title">Recent activity</div><span class="count-pill">LIVE</span></div><div class="activity-list">'+(data.activity||[]).slice(0,5).map(a=>'<div class="activity-item"><span class="activity-icon '+(String(a.type).includes('out')?'exit':String(a.type).includes('badge')?'badge':'')+'">'+icon(String(a.type).includes('out')?'exit':String(a.type).includes('badge')?'card':'enter')+'</span><div><p>'+esc(a.message)+'</p><time datetime="'+esc(a.time)+'">'+esc(fmtTime(a.time))+'</time></div></div>').join('')+
    (!(data.activity||[]).length?'<p class="panel-subtitle">Campus activity will appear here.</p>':'')+'</div><a class="panel-link-footer" href="#history">Explore visitor history '+icon('arrow')+'</a></section>'+
    '<section class="panel"><div class="panel-header"><div class="panel-title">'+icon('card')+'RFID badge availability</div></div><div class="badge-summary"><div class="badge-summary-top"><strong>'+b.available+'</strong><span>of '+b.total+' badges available</span></div><div class="segment-bar" role="img" aria-label="'+b.available+' available, '+b.assigned+' assigned, '+b.inactive+' inactive"><span style="width:'+100*b.available/Math.max(1,b.total)+'%"></span><span class="assigned" style="width:'+100*b.assigned/Math.max(1,b.total)+'%"></span><span class="inactive" style="width:'+100*b.inactive/Math.max(1,b.total)+'%"></span></div><div class="badge-legend">'+['available','assigned','inactive'].map(k=>'<div><i class="legend-dot '+k+'"></i>'+esc(k[0].toUpperCase()+k.slice(1))+'<strong>'+b[k]+'</strong></div>').join('')+'</div></div><a class="panel-link-footer" href="#badges">Manage badges '+icon('arrow')+'</a></section>'+
    '<div class="quick-note">'+icon('shield')+'<div><strong>Small steps. A safer campus.</strong><p>Verify each visitor’s identification before issuing a temporary RFID badge.</p></div></div></div></div>';
}
function buildChart(trend){
  const points=trend;
  if(!points.length)return '<div class="empty-state"><p>Visitor flow will appear after the first check-in.</p></div>';
  const max=Math.max(4,...points.flatMap(p=>[Number(p.entries)||0,Number(p.exits)||0]));
  const top=Math.ceil(max/4)*4;
  let markup='<svg class="chart" viewBox="0 0 620 170" role="img" aria-label="Hourly campus check-ins and check-outs throughout the day">';
  for(let i=0;i<5;i++){const y=133-i*28;markup+='<line class="gridline" x1="30" x2="610" y1="'+y+'" y2="'+y+'"/><text x="17" y="'+(y+3)+'" text-anchor="end">'+Math.round(top*i/4)+'</text>'}
  const width=570/points.length;
  points.forEach((p,i)=>{
    const x=36+i*width,entry=112*(p.entries||0)/top,exit=112*(p.exits||0)/top;
    markup+='<g><rect class="bar-entry" x="'+x+'" y="'+(133-entry)+'" width="'+Math.max(4,width*.26)+'" height="'+entry+'" rx="2"><title>'+esc(p.label)+': '+p.entries+' check-ins</title></rect><rect class="bar-exit" x="'+(x+width*.31)+'" y="'+(133-exit)+'" width="'+Math.max(4,width*.26)+'" height="'+exit+'" rx="2"><title>'+esc(p.label)+': '+p.exits+' check-outs</title></rect>';
    if(i%3===0)markup+='<text x="'+(x+width*.28)+'" y="153" text-anchor="middle">'+esc(p.label)+'</text>';
    markup+='</g>';
  });
  return markup+'</svg>';
}
function paintVisitorPage(){
  const history=state.view==='history';
  $('#view').innerHTML=heading(history?'Visitor history':'Visitors',history?'Every campus visit, organized and easy to find.':'Welcome visitors, view active visits, and manage check-outs.', '<button class="button secondary" data-action="export">'+icon('download')+'Export CSV</button>'+registerButton())+
    '<section class="panel"><form id="visitor-filters"><div class="filter-bar"><div class="filter-field search-field"><label for="visitor-search">Search visitors</label><div class="search-input">'+icon('search')+'<input id="visitor-search" name="q" placeholder="Search by visitor name or organization" maxlength="100"></div></div><div class="filter-field"><label for="status-filter">Visit status</label><select id="status-filter" name="status"><option value="all">All visits</option><option value="on_campus" '+(!history?'selected':'')+'>On campus</option><option value="checked_out">Checked out</option></select></div><div class="filter-field"><label for="date-filter">Visit date</label><input type="date" id="date-filter" name="date"></div><button type="button" class="button secondary" data-action="clear-filters" title="Clear all filters">'+icon('refresh')+'Reset</button></div>'+
    '<div class="advanced-filters"><div class="filter-field"><label for="time-field">Filter entry or exit</label><select id="time-field" name="time_field"><option value="check_in">Entry date / time</option><option value="check_out">Exit date / time</option></select></div><div class="filter-field"><label for="badge-filter">RFID badge</label><input id="badge-filter" name="badge" placeholder="Badge code or UID" maxlength="64"></div><div class="filter-field"><label for="from-time">Time from</label><input type="time" id="from-time" name="from_time"></div><div class="filter-field"><label for="to-time">Time to</label><input type="time" id="to-time" name="to_time"></div></div></form><div class="results-heading"><strong id="results-count">Loading visitors…</strong><span>Times shown in Philippine Standard Time</span></div><div id="visitor-table"></div></section>';
  const form=$('#visitor-filters');let timer;
  form.addEventListener('submit',e=>e.preventDefault());
  form.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>{readVisitorFilters();loadVisitors().catch(e=>toast(e.message,true))},350)});
  form.addEventListener('change',()=>{clearTimeout(timer);readVisitorFilters();loadVisitors().catch(e=>toast(e.message,true))});
}
function readVisitorFilters(){
  state.filters=Object.fromEntries(new FormData($('#visitor-filters')));state.page=1;
}
let visitorRequest=0;
async function loadVisitors(background=false){
  const version=++visitorRequest;
  const data=await api('visitors',null,{...state.filters,...(background?{background:1}:{})});
  if(version!==visitorRequest||!['visitors','history'].includes(state.view))return;
  state.visitors=data.visitors;
  paintVisitorTable();
}
function paintVisitorTable(){
  const total=state.visitors.length;
  state.page=Math.min(state.page,Math.max(1,Math.ceil(total/20)));
  const start=(state.page-1)*20,items=state.visitors.slice(start,start+20);
  $('#results-count').textContent=fmtNumber(total)+' '+(total===1?'visit':'visits')+' found';
  $('#visitor-table').innerHTML=items.length?'<div class="table-wrap"><table class="full-table"><thead><tr><th>Visitor</th><th>Purpose / host</th><th>RFID badge</th><th>Check-in</th><th>Check-out</th><th>Status</th><th>Actions</th></tr></thead><tbody>'+items.map((v,i)=>'<tr><td>'+personCell(v,i)+'</td><td>'+esc(v.purpose)+'<span class="visitor-org">'+esc(v.host)+'</span></td><td><span class="badge-code">'+esc(v.badge_code)+'</span></td><td>'+esc(fmtTime(v.check_in))+'<span class="visitor-org">'+esc(fmtDate(v.check_in))+'</span></td><td>'+esc(fmtTime(v.check_out))+'</td><td>'+status(v.status)+'</td><td><button class="table-action" data-action="detail" data-id="'+v.id+'">View</button>'+(v.status==='on_campus'?'<button class="table-action" data-action="checkout" data-id="'+v.id+'" aria-label="Check out '+esc(v.name)+'">'+icon('exit')+'</button>':'')+(isAdmin()&&v.status==='checked_out'?'<button class="table-action" data-action="archive-visitor" data-id="'+v.id+'">'+icon('archive')+'Archive</button>':'')+'</td></tr>').join('')+'</tbody></table></div><div class="table-footer"><span>Showing '+(start+1)+'–'+(start+items.length)+' of '+total+' visits</span><div><button class="button secondary small" data-action="prev-page" '+(state.page===1?'disabled':'')+'>Previous</button> <button class="button secondary small" data-action="next-page" '+(start+20>=total?'disabled':'')+'>Next</button></div></div>':empty('No visits found','Try a different search or clear the filters to see more records.','<button class="button secondary" data-action="clear-filters">'+icon('refresh')+'Clear filters</button>');
}

function paintBadges(){
  const totals={available:0,assigned:0,inactive:0};state.badges.forEach(b=>totals[b.status]++);
  $('#view').innerHTML=heading('RFID badges','A small badge. A connected campus visit.',isAdmin()?'<button class="button primary" data-action="new-badge">'+icon('plus')+'Add RFID badge</button>':'')+
    '<div class="notice">'+icon('card')+'<span>Verify visitor identification before issuing a temporary badge. Collect badges at check-out. Unassigned badges can be archived by an administrator.</span></div>'+
    '<div class="stats-grid">'+statCard('Total RFID badges',state.badges.length,'card','','Campus badge inventory')+statCard('Available',totals.available,'check','green','Ready to issue')+statCard('Currently assigned',totals.assigned,'users','purple','Linked to active visits')+statCard('Inactive',totals.inactive,'lock','gold','Unavailable for check-in')+'</div>'+
    '<section class="badges-grid" aria-label="RFID badge inventory">'+state.badges.map(b=>{
      const actions=isAdmin()&&b.status!=='assigned'?'<div class="rfid-card-actions"><button class="text-button" data-action="toggle-badge" data-id="'+b.id+'">'+(b.status==='inactive'?'Activate badge':'Deactivate')+'</button><button class="text-button" data-action="archive-badge" data-id="'+b.id+'">'+icon('archive')+'Archive</button></div>':b.status==='assigned'?'<button class="text-button" data-action="detail" data-id="'+b.visit_id+'">View visit</button>':'';
      return '<article class="rfid-card"><div class="rfid-card-top"><span class="rfid-icon">'+icon('card')+'</span>'+status(b.status)+'</div><h3>'+esc(b.code)+'</h3><span class="uid">UID '+esc(b.uid)+'</span><div class="rfid-assignment">'+icon(b.status==='assigned'?'user':'shield')+esc(b.visitor_name||(b.status==='inactive'?'Deactivated badge':'Ready for a new visitor'))+'</div><div class="rfid-card-footer"><span>Temporary visitor badge</span>'+actions+'</div></article>';
    }).join('')+'</section>'+
    (!state.badges.length?empty('No badges yet','Add a compatible RFID badge to start welcoming visitors.'): '');
}
function paintAccounts(){
  $('#view').innerHTML=heading('User accounts','The right access for the people keeping campus connected.','<button class="button primary" data-action="new-account">'+icon('plus')+'Add user account</button>')+
    '<div class="notice">'+icon('shield')+'<span>Administrators manage badges, accounts, and visitor records. Security personnel register visitors, process check-outs, and view campus reports.</span></div>'+
    '<section class="panel"><div class="panel-header"><div class="panel-title">Campus team <span class="count-pill">'+state.accounts.length+'</span></div></div><div class="table-wrap"><table class="full-table"><thead><tr><th>Team member</th><th>Email address</th><th>Access role</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>'+state.accounts.map((a,i)=>'<tr><td>'+personCell({...a,organization:a.id===state.user.id?'Your account':'Campus personnel'},i)+'</td><td>'+esc(a.email)+'</td><td>'+esc(a.role==='admin'?'Administrator':'Security personnel')+'</td><td>'+status(a.active?'active':'inactive')+'</td><td>'+esc(fmtDate(a.created_at))+'</td><td>'+(a.id===state.user.id?'<span class="visitor-org">Current account</span>':'<button class="table-action" data-action="toggle-account" data-id="'+a.id+'">'+(a.active?'Deactivate':'Activate')+'</button>')+'</td></tr>').join('')+'</tbody></table></div></section>';
}
function paintReportPage(){
  const today=localDate(),month=today.slice(0,7)+'-01';state.report={from:month,to:today};
  $('#view').innerHTML=heading('Campus reports','Turn everyday visitor records into a clearer picture.','<button class="button primary" data-action="export-report">'+icon('download')+'Export report</button>')+
    '<p class="report-archive-note">Historical reports include archived visits. Permanently deleted visitor records are excluded.</p><form id="report-filters" class="report-filters"><div class="filter-field"><label for="report-from">From date</label><input id="report-from" name="from" type="date" value="'+month+'" required></div><div class="filter-field"><label for="report-to">To date</label><input id="report-to" name="to" type="date" value="'+today+'" required></div><button class="button primary" type="submit">'+icon('refresh')+'Apply dates</button><button class="button secondary" type="button" data-action="report-today">Today</button></form><div id="report-results"><div class="busy-view"><div class="spinner"></div>Preparing your report…</div></div>';
  $('#report-filters').addEventListener('submit',async e=>{e.preventDefault();state.report=Object.fromEntries(new FormData(e.currentTarget));await loadReports().catch(error=>toast(error.message,true))});
}
let reportRequest=0;
async function loadReports(){
  const version=++reportRequest,params={...state.report};
  const data=await api('reports',null,params);if(state.view!=='reports'||version!==reportRequest)return;
  const s=data.summary,max=Math.max(1,...data.purposes.map(p=>p.count));
  $('#report-results').innerHTML='<section class="stats-grid">'+statCard('Total recorded visits',s.total_visits,'users','','For the selected period')+statCard('Completed visits',s.completed,'exit','green','Visitors checked out')+statCard('Still on campus',s.on_campus,'building','purple','Active visits in this period')+statCard('Average visit duration',Math.round(s.avg_duration_minutes||0),'clock','gold','Completed visits in this period','min')+'</section>'+
    '<div class="report-layout"><section class="panel"><div class="panel-header"><div><div class="panel-title">Daily visit summary</div><p class="panel-subtitle">'+esc(fmtDate(data.from))+' – '+esc(fmtDate(data.to))+'</p></div></div>'+
    (data.daily.length?'<div class="table-wrap"><table class="full-table"><thead><tr><th>Date</th><th>Total visits</th><th>Completed</th><th>Still on campus</th></tr></thead><tbody>'+data.daily.map(d=>'<tr><td>'+esc(fmtDate(d.date))+'</td><td>'+fmtNumber(d.visits)+'</td><td>'+fmtNumber(d.completed)+'</td><td>'+fmtNumber(d.visits-d.completed)+'</td></tr>').join('')+'</tbody></table></div>':empty('No visits in this period','Choose a different date range to see your campus report.'))+'</section>'+
    '<section class="panel"><div class="panel-header"><div class="panel-title">Why visitors came to campus</div></div><div class="report-purpose">'+data.purposes.map(p=>'<div class="purpose-row"><div class="purpose-label"><span>'+esc(p.label)+'</span><strong>'+fmtNumber(p.count)+'</strong></div><div class="purpose-progress"><span style="width:'+100*p.count/max+'%"></span></div></div>').join('')+(!data.purposes.length?'<p class="panel-subtitle">No visit purposes recorded for this period.</p>':'')+'</div></section></div>';
}
function paintKiosk(){
  $('#view').innerHTML=heading('Gate kiosk','A thoughtful welcome, from first check-in to final check-out.',registerButton())+
    '<div class="notice warning">'+icon('scan')+'<span>Connect a compatible RFID reader that types badge IDs into the scan field. This screen does not read RFID from a phone. A badge identifies a recorded visit; staff still verify the visitor.</span></div>'+
    '<div class="kiosk-layout"><section class="panel scan-panel"><div class="scan-art">'+icon('scan')+'</div><span class="eyebrow">GATE TERMINAL</span><h2>Ready for a badge scan</h2><p>Scan an assigned badge to check out its visitor and make the badge available again.</p><form id="scan-form"><label for="scan-uid">RFID badge identifier</label><input id="scan-uid" name="uid" class="scan-input" placeholder="Scan or enter badge UID" autocomplete="off" maxlength="64" required><button class="button primary" type="submit">'+icon('scan')+'Process check-out</button></form><div id="scan-result" role="status"></div><p class="scan-foot">'+icon('lock')+' Scans are timestamped and saved on the campus server.</p></section>'+
    '<section class="panel kiosk-steps"><h2>A simple, secure visitor journey</h2><div class="kiosk-step"><span class="step-number">01</span><div><h3>Welcome & verify</h3><p>Confirm the visitor’s identity, purpose, and person or office to visit.</p></div></div><div class="kiosk-step"><span class="step-number">02</span><div><h3>Register & issue a badge</h3><p>Register the visit and choose an available RFID badge. The server records the check-in time.</p></div></div><div class="kiosk-step"><span class="step-number">03</span><div><h3>Scan & say goodbye</h3><p>Scan the same badge at departure. Check-out is recorded and the badge can be issued again.</p></div></div><button class="button secondary" data-action="register">'+icon('plus')+'Register the next visitor</button><p class="field-help" style="margin-top:17px">Available badges must be assigned through registration before a scan can close a visit. A server connection is required.</p></section></div>';
  $('#scan-form').addEventListener('submit',async e=>{
    e.preventDefault();const button=$('button',e.currentTarget);button.disabled=true;
    try{
      const data=await api('scan',{uid:$('#scan-uid').value.trim()});
      $('#scan-result').innerHTML='<div class="scan-result">'+icon('check')+' <strong>'+esc(data.visitor.name)+'</strong> checked out at '+esc(fmtTime(data.visitor.check_out))+'. Badge '+esc(data.visitor.badge_code)+' is available again.</div>';
      e.target.reset();$('#scan-uid').focus();toast('Visitor checked out successfully.');
    }catch(error){$('#scan-result').innerHTML='<p class="form-error" role="alert">'+esc(error.message)+'</p>'}
    finally{button.disabled=false}
  });
  $('#scan-uid').focus();
}
let modalOpener=null;
function openModal(title,subtitle,content){
  if(!$('#modal').open)modalOpener=document.activeElement;
  $('#modal-heading').innerHTML='<h2>'+esc(title)+'</h2>'+(subtitle?'<p>'+esc(subtitle)+'</p>':'');
  $('#modal').setAttribute('aria-labelledby','modal-heading');
  $('#modal-content').innerHTML=content;
  if(!$('#modal').open)$('#modal').showModal();
}
function closeModal(){if($('#modal').open)$('#modal').close()}
function formActions(primaryLabel){
  return '<div class="modal-actions"><button class="button secondary" type="button" data-action="close-modal">Cancel</button><button class="button primary" type="submit">'+esc(primaryLabel)+'</button></div>';
}
function fieldMarkup(id,label,type='text',value='',full=false,extra=''){
  return '<div class="'+(full?'full':'')+'"><label for="'+id+'">'+esc(label)+'</label><input id="'+id+'" name="'+id+'" type="'+type+'" value="'+esc(value)+'" '+extra+'></div>';
}
const purposes=['Admission inquiry','Campus tour','Meeting','Document request','Delivery','Maintenance','Other'];
function visitorFields(v={}){
  return nameFields(v)+
    fieldMarkup('organization','Organization / affiliation','text',v.organization,false,'maxlength="120"')+
    fieldMarkup('phone','Contact number','tel',v.phone,false,'maxlength="30" autocomplete="tel"')+
    '<div><label for="purpose">Purpose of visit</label><select id="purpose" name="purpose" required><option value="">Select purpose</option>'+[...new Set([...purposes,...(v.purpose?[v.purpose]:[])])].map(p=>'<option '+(p===v.purpose?'selected':'')+'>'+esc(p)+'</option>').join('')+'</select></div>'+
    fieldMarkup('host','Person / office to visit','text',v.host,false,'required maxlength="120"');
}
async function openRegister(){
  const data=await api('badges');state.badges=data.badges;
  const available=data.badges.filter(b=>b.status==='available');
  openModal('Register a visitor','Make every campus visit a warm, organized welcome.',
    '<form id="register-form"><div class="modal-content-body"><div class="notice">'+icon('shield')+'<span>Verify the visitor’s identification before issuing a temporary badge. Use only the information needed to manage this visit.</span></div><div class="form-grid">'+visitorFields()+'<div class="full"><label for="badge_scan">Scan badge to assign (optional)</label><input id="badge_scan" type="text" placeholder="Scan an available badge UID" maxlength="64" autocomplete="off"><p id="badge-scan-help" class="field-help" role="status">Use a compatible reader, or choose a badge below.</p></div><div class="full"><label for="badge_id">Assign RFID badge</label><select id="badge_id" name="badge_id" required><option value="">Choose an available badge</option>'+available.map(b=>'<option value="'+b.id+'">'+esc(b.code)+' · UID '+esc(b.uid)+'</option>').join('')+'</select><p class="field-help">'+(available.length?'Check-in time is recorded automatically when you register.':'No badges are available. Check out a visitor or activate a badge first.')+'</p></div></div><p class="form-error" data-form-error role="alert" hidden></p></div>'+formActions('Register & check in')+'</form>');
  bindModalForm('register-form','register',form=>({...Object.fromEntries(new FormData(form)),badge_id:Number(form.elements.badge_id.value)}),()=>toast('Visitor registered and checked in.'));
  $('#badge_scan').addEventListener('input',event=>{
    const value=event.target.value.trim().toUpperCase();
    const badge=available.find(b=>b.uid.toUpperCase()===value);
    if(value)$('#badge_id').value=badge?String(badge.id):'';
    $('#badge-scan-help').textContent=!value?'Use a compatible reader, or choose a badge below.':badge?'Badge '+badge.code+' selected. Confirm the details to check in.':'No available badge matches this UID.';
  });
  $('#badge_scan').addEventListener('keydown',event=>{
    if(event.key==='Enter'){event.preventDefault();if($('#badge_id').value)$('#register-form button[type="submit"]').focus()}
  });
  if(!available.length)$('#register-form button[type="submit"]').disabled=true;
}
async function findVisitor(id){
  const data=await api('visitors',null,{status:'all'});
  const v=data.visitors.find(v=>v.id===Number(id));
  if(!v)throw new Error('This visitor record could not be found. Refresh and try again.');
  return v;
}
async function openDetail(id){
  const v=await findVisitor(id);
  const detail=(label,value)=>'<div><dt>'+esc(label)+'</dt><dd>'+esc(value||'—')+'</dd></div>';
  openModal('Visitor details','A clear record of this campus visit.',
    '<div class="modal-content-body"><div class="detail-avatar"><span class="avatar">'+esc(initials(v.name))+'</span><div><h3>'+esc(v.name)+'</h3><p>'+esc(v.organization||'Individual visitor')+'</p></div></div><dl class="detail-grid">'+detail('Visit status',v.status==='on_campus'?'On campus':'Checked out')+detail('RFID badge',v.badge_code)+detail('Purpose of visit',v.purpose)+detail('Person / office',v.host)+detail('Contact number',v.phone)+detail('Visit date',fmtDate(v.check_in))+detail('Checked in',fmtTime(v.check_in))+detail('Checked out',fmtTime(v.check_out))+'</dl></div><div class="modal-actions">'+(isAdmin()?'<button class="button secondary" data-action="edit-visitor" data-id="'+v.id+'">'+icon('edit')+'Edit record</button>':'')+(isAdmin()&&v.status==='checked_out'?'<button class="button secondary" data-action="archive-visitor" data-id="'+v.id+'">'+icon('archive')+'Archive</button>':'')+(v.status==='on_campus'?'<button class="button primary" data-action="checkout" data-id="'+v.id+'">'+icon('exit')+'Check out visitor</button>':'<button class="button secondary" data-action="close-modal">Close</button>')+'</div>');
}
async function openEdit(id){
  const v=await findVisitor(id);
  openModal('Edit visitor record','Update visitor details for an accurate campus record.',
    '<form id="edit-visitor-form"><div class="modal-content-body"><div class="form-grid">'+visitorFields(v)+'</div><p class="field-help" style="margin-top:15px">RFID assignments and recorded timestamps are preserved.</p><p class="form-error" data-form-error role="alert" hidden></p></div>'+formActions('Save changes')+'</form>');
  bindModalForm('edit-visitor-form','visitor_update',form=>({...Object.fromEntries(new FormData(form)),id:v.id}),()=>toast('Visitor record updated.'));
}
function bindModalForm(id,action,toData,success){
  $('#'+id).addEventListener('submit',async e=>{
    e.preventDefault();const form=e.currentTarget,button=$('button[type="submit"]',form),error=$('[data-form-error]',form);button.disabled=true;error.hidden=true;
    try{await api(action,toData(form));closeModal();success();await refreshCurrent()}
    catch(err){if(form.isConnected){error.textContent=err.message;error.hidden=false}else toast(err.message,true)}
    finally{button.disabled=false}
  });
}

function openNewBadge(){
  openModal('Add an RFID badge','Register a compatible badge in the campus inventory.',
    '<form id="new-badge-form"><div class="modal-content-body"><div class="form-grid">'+fieldMarkup('code','Badge label','text','',true,'required maxlength="30" placeholder="e.g. VIS-025"')+fieldMarkup('uid','RFID badge UID','text','',true,'required maxlength="64" placeholder="Scan or enter the physical badge identifier"')+'</div><p class="field-help" style="margin-top:14px">Each physical badge must have a unique identifier.</p><p class="form-error" data-form-error role="alert" hidden></p></div>'+formActions('Add badge')+'</form>');
  bindModalForm('new-badge-form','badge_create',form=>Object.fromEntries(new FormData(form)),()=>toast('RFID badge added to inventory.'));
}
function openNewAccount(){
  openModal('Add a user account','Give an authorized team member access to the campus workspace.',
    '<form id="new-account-form"><div class="modal-content-body"><div class="form-grid">'+nameFields()+fieldMarkup('email','Email address','email','',true,'required maxlength="120" autocomplete="off"')+fieldMarkup('password','Temporary password','password','',true,'required minlength="12" maxlength="72" autocomplete="new-password"')+'<div class="full"><label for="role">Access role</label><select id="role" name="role"><option value="security">Security personnel</option><option value="admin">Administrator</option></select><p class="field-help">Use at least 12 characters for the password. Share credentials through your approved campus process.</p></div></div><p class="form-error" data-form-error role="alert" hidden></p></div>'+formActions('Create account')+'</form>');
  bindModalForm('new-account-form','account_create',form=>Object.fromEntries(new FormData(form)),()=>toast('Campus user account created.'));
}
function confirmAction(title,message,label,action,refresh=true){
  openModal(title,'', '<div class="modal-content-body"><p style="font-size:13px;color:#65758b;line-height:1.8;margin:0">'+esc(message)+'</p><p class="form-error" data-form-error role="alert" hidden></p></div><div class="modal-actions"><button class="button secondary" data-action="close-modal">Cancel</button><button class="button primary" id="confirm-action">'+esc(label)+'</button></div>');
  $('#confirm-action').addEventListener('click',async e=>{
    const button=e.currentTarget,error=$('[data-form-error]',$('#modal'));button.disabled=true;error.hidden=true;
    try{await action();closeModal();if(refresh)await refreshCurrent()}
    catch(err){if(error.isConnected){error.textContent=err.message;error.hidden=false}else toast(err.message,true)}
    finally{button.disabled=false}
  });
}
async function confirmCheckout(id){
  const v=await findVisitor(id);
  confirmAction('Check out visitor','Check out '+v.name+' and return badge '+v.badge_code+' to the available inventory? The server will record the departure time.','Confirm check-out',async()=>{await api('checkout',{visit_id:Number(id)});toast(v.name+' checked out successfully.')});
}
function openHelp(){
  openModal('Your connected campus workspace','Smart Campus Kiosk · STI College Global City',
    '<div class="modal-content-body help-content"><div class="notice">'+icon('leaf')+'<span>This is a working project prototype. Initial visitor records are fictional demonstration data.</span></div><h3>Welcoming a visitor</h3><p>Verify identification, open <strong>Register visitor</strong>, enter the visit details, and assign an available badge. Check-in is recorded immediately. At departure, use the gate kiosk to scan the badge or check out the visitor from their record.</p><h3>Using a reader</h3><p>The kiosk supports compatible readers that type a badge UID into the scan field and send Enter. Confirm reader output matches the UID stored in your badge inventory. RFID links the badge to a visit; it does not independently verify a person’s identity.</p><h3>Access & privacy</h3><p>Accounts control access to visitor records. Administrators manage badges and team accounts. Protect exported files and follow your institution’s retention and information-handling policies. Contact your campus administrator for access assistance.</p><h3>About this prototype</h3><p>Visitor records are stored on the campus server. A network connection and compatible RFID hardware are required for actual gate operation. The system covers visitor visits and does not track daily student or staff attendance.</p></div><div class="modal-actions"><button class="button primary" data-action="close-modal">Got it</button></div>');
}
function openProfile(){
  const user=state.user;
  openModal('Your campus account','Signed in to STI Global City',
    '<div class="modal-content-body"><div class="detail-avatar"><span class="avatar">'+avatarContent(user)+'</span><div><h3>'+esc(user.name)+'</h3><p>'+esc(user.email)+'</p></div></div><div class="notice">'+icon('shield')+'<span>'+(isAdmin()?'Administrator access to visitor management, reports, badges, and user accounts.':'Security access to visitor registration, check-out, badge viewing, and campus reports.')+'</span></div><p class="field-help">Update your name, email, password, and profile picture in Account settings.</p></div><div class="modal-actions"><button class="button secondary" data-action="close-modal">Close</button><button class="button secondary" data-action="edit-profile">'+icon('edit')+'Edit profile</button><button class="button primary" data-action="logout">'+icon('exit')+'Sign out</button></div>');
}
async function refreshCurrent(){
  if(!state.user)return;
  if(state.view==='dashboard'){const d=await api('dashboard');if(state.view==='dashboard'){state.dashboard=d;paintDashboard(d)}}
  else if(['visitors','history'].includes(state.view))await loadVisitors();
  else if(state.view==='badges'){const d=await api('badges');if(state.view==='badges'){state.badges=d.badges;paintBadges()}}
  else if(state.view==='accounts'){const d=await api('accounts');if(state.view==='accounts'){state.accounts=d.accounts;paintAccounts()}}
  else if(state.view==='reports')await loadReports();
  else if(state.view==='archives')await loadArchives();
  else if(state.view==='profile')await loadProfile();
}
function exportCsv(params){
  const url=new URL('api.php',location.href);url.searchParams.set('action','export');
  Object.entries(params).forEach(([k,v])=>{if(v!==''&&v!=null)url.searchParams.set(k,v)});
  const link=document.createElement('a');link.href=url.href;link.download='campus-visitor-report.csv';document.body.append(link);link.click();link.remove();
}
$('#view').addEventListener('click',handleAction);
$('#modal-content').addEventListener('click',handleAction);
async function handleAction(event){
  const target=event.target.closest('[data-action]');if(!target||target.disabled)return;
  const action=target.dataset.action,id=target.dataset.id;
  try{
    if(action==='register')await openRegister();
    else if(action==='detail')await openDetail(id);
    else if(action==='edit-visitor')await openEdit(id);
    else if(action==='edit-profile'){closeModal();if(state.view==='profile')$('#profile-first-name')?.focus();else location.hash='profile'}
    else if(action==='remove-profile-photo')confirmRemoveProfilePhoto();
    else if(action==='checkout')await confirmCheckout(id);
    else if(action==='archive-visitor')await confirmVisitorArchive(id);
    else if(action==='archive-badge')confirmBadgeArchive(id);
    else if(action==='reload-archives')await loadArchives();
    else if(action==='archive-tab'){state.archive.type=target.dataset.type;state.archive.page=1;state.archive.q='';state.archive.selected.clear();$('#archive-search').value='';paintArchiveTable()}
    else if(action==='select-archives')selectArchivePage();
    else if(action==='clear-archives'){state.archive.selected.clear();paintArchiveTable()}
    else if(action==='delete-archives')confirmArchiveBatch('delete');
    else if(action==='restore-archives')confirmArchiveBatch('restore');
    else if(action==='restore-archive-one')confirmArchiveBatch('restore',[Number(id)]);
    else if(action==='archive-prev-page'){state.archive.page=Math.max(1,state.archive.page-1);paintArchiveTable()}
    else if(action==='archive-next-page'){state.archive.page++;paintArchiveTable()}
    else if(action==='close-modal')closeModal();
    else if(action==='retry')await render();
    else if(action==='prev-page'){state.page=Math.max(1,state.page-1);paintVisitorTable()}
    else if(action==='next-page'){state.page++;paintVisitorTable()}
    else if(action==='clear-filters'){
      $('#visitor-filters').reset();$('#status-filter').value='all';readVisitorFilters();await loadVisitors();
    }else if(action==='export')exportCsv(state.filters);
    else if(action==='export-report')exportCsv({...state.report,include_archived:1});
    else if(action==='report-today'){
      const today=localDate();$('#report-from').value=today;$('#report-to').value=today;state.report={from:today,to:today};await loadReports();
    }else if(action==='new-badge')openNewBadge();
    else if(action==='new-account')openNewAccount();
    else if(action==='toggle-badge'){
      const b=state.badges.find(b=>b.id===Number(id));if(!b)return;
      const active=b.status==='inactive';
      confirmAction(active?'Activate badge':'Deactivate badge',active?'Make '+b.code+' available for visitor registration?':'Deactivate '+b.code+' so it cannot be issued to a visitor?',active?'Activate badge':'Deactivate badge',async()=>{await api('badge_update',{id:b.id,active});toast('Badge '+(active?'activated.':'deactivated.'))});
    }else if(action==='toggle-account'){
      const a=state.accounts.find(a=>a.id===Number(id));if(!a)return;
      confirmAction(a.active?'Deactivate account':'Activate account',a.active?'Remove campus workspace access for '+a.name+'?':'Restore campus workspace access for '+a.name+'?',a.active?'Deactivate account':'Activate account',async()=>{await api('account_update',{id:a.id,active:!a.active});toast('User account updated.')});
    }else if(action==='logout')await signOut(target);
  }catch(error){toast(error.message,true)}
}
function closeMenu(){
  $('#sidebar').classList.remove('open');$('#mobile-backdrop').hidden=true;$('#menu-toggle').setAttribute('aria-expanded','false');
  $('#sidebar').inert=matchMedia('(max-width:780px)').matches;
}
$('#menu-toggle').addEventListener('click',()=>{
  const open=!$('#sidebar').classList.contains('open');$('#sidebar').classList.toggle('open',open);$('#sidebar').inert=!open&&matchMedia('(max-width:780px)').matches;$('#mobile-backdrop').hidden=!open;$('#menu-toggle').setAttribute('aria-expanded',String(open));
  if(open)$('#sidebar a').focus();
});
$('#mobile-backdrop').addEventListener('click',closeMenu);
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&$('#sidebar').classList.contains('open')){closeMenu();$('#menu-toggle').focus()}});
window.addEventListener('resize',()=>{if(!matchMedia('(max-width:780px)').matches){$('#sidebar').inert=false;$('#mobile-backdrop').hidden=true}else if(!$('#sidebar').classList.contains('open'))$('#sidebar').inert=true});
$('#modal-close').addEventListener('click',closeModal);
$('#modal').addEventListener('close',()=>{if(modalOpener?.isConnected)modalOpener.focus();else $('#main').focus()});
$('#modal').addEventListener('click',e=>{if(e.target===$('#modal')){const r=$('#modal').getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)closeModal()}});
$('#help-button').addEventListener('click',openHelp);
$('#profile-button').addEventListener('click',openProfile);
$('#login-help').addEventListener('click',()=>openModal('Need help signing in?','Your campus administrator can help.',
  '<div class="modal-content-body help-content"><p>Ask your campus administrator to create or restore your account. This local prototype includes two demonstration accounts:</p><p><strong>Administrator</strong><br><code>admin@sti.local</code><br><code>CampusAdmin!2026</code></p><p><strong>Security personnel</strong><br><code>security@sti.local</code><br><code>CampusSecurity!2026</code></p><p>Replace demonstration accounts before using actual visitor data.</p></div><div class="modal-actions"><button class="button primary" data-action="close-modal">Got it</button></div>'));
async function signIn(email,password,button){
  state.sessionEpoch++;resetProfileContext();
  const error=$('#login-error');button.disabled=true;error.hidden=true;
  try{
    await api('session');
    const data=await api('login',{email,password});state.user=data.user;
    if(!state.user)throw new Error('The server did not return an account. Try signing in again.');
    $('#login-password').value='';showApp();
  }catch(err){error.textContent=err.message;error.hidden=false}
  finally{button.disabled=false}
}
$('#login-form').addEventListener('submit',e=>{e.preventDefault();signIn($('#login-email').value.trim(),$('#login-password').value,$('button[type="submit"]',e.currentTarget))});
$('#demo-login').addEventListener('click',e=>signIn('admin@sti.local','CampusAdmin!2026',e.currentTarget));
window.addEventListener('hashchange',()=>{if(state.user)render()});
function updateClock(){
  $('#header-clock').textContent=new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',weekday:'short',hour:'numeric',minute:'2-digit'}).format(new Date());
}
updateClock();setInterval(updateClock,1000);
let polling=false;
setInterval(async()=>{
  if(!state.user||document.hidden||$('#modal').open||polling)return;
  polling=true;
  try{
    const data=await api('dashboard');state.dashboard=data;$('#nav-count').textContent=data.stats.on_campus;
    if(state.view==='dashboard')paintDashboard(data);
    else if(['visitors','history'].includes(state.view))await loadVisitors(true);
  }catch(error){if(state.user)setConnection(false)}
  finally{polling=false}
},20000);
(async()=>{
  try{const data=await api('session');state.user=data.user;if(state.user)showApp();else showLogin()}
  catch(error){showLogin();$('#login-error').textContent=error.message;$('#login-error').hidden=false}
})();


let archiveRequest=0;
function paintArchivesPage(){
  const a=state.archive;
  $('#view').innerHTML=heading('Archives','Manage archived visitor records and RFID badges.','<button class="button secondary" data-action="reload-archives">'+icon('refresh')+'Refresh</button>')+
    '<div class="notice">'+icon('archive')+'<span>Archive completed visits and unassigned badges to remove them from everyday lists. Restore records here, or select archived records for permanent deletion.</span></div>'+
    '<section class="panel"><div class="archive-tabs" id="archive-tabs" role="tablist" aria-label="Archive categories">'+
    ['visitors','badges'].map(type=>'<button id="archive-tab-'+type+'" class="archive-tab" role="tab" aria-controls="archive-list" data-action="archive-tab" data-type="'+type+'">'+icon(type==='visitors'?'users':'card')+esc(type==='visitors'?'Visitors':'RFID badges')+' <span class="count-pill" data-archive-count="'+type+'">'+a.records[type].length+'</span></button>').join('')+
    '</div><div id="archive-list" role="tabpanel"><div class="archive-toolbar"><div class="archive-search"><label for="archive-search">Search archived records</label><div class="search-input">'+icon('search')+'<input id="archive-search" type="search" placeholder="Search by name, badge code, or UID" maxlength="120" value="'+esc(a.q)+'"></div></div><div class="archive-selection-actions"><button class="button secondary small" data-action="select-archives">Select all on page</button><button class="button secondary small" data-action="clear-archives">Clear selection</button></div></div>'+
    '<div class="archive-actionbar"><span id="archive-selection-count" role="status">0 selected</span><div><button class="button secondary small" data-action="restore-archives" disabled>'+icon('refresh')+'Restore selected</button><button class="button danger small" data-action="delete-archives" disabled>'+icon('trash')+'Delete selected</button></div></div><div id="archive-table"></div></div></section>';
  $('#archive-search').addEventListener('input',event=>{a.q=event.target.value;a.page=1;a.selected.clear();paintArchiveTable()});
  $('#archive-tabs').addEventListener('keydown',event=>{
    if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
    event.preventDefault();const tabs=$$('[role="tab"]',event.currentTarget),index=tabs.findIndex(t=>t.dataset.type===a.type);
    const next=event.key==='Home'?0:event.key==='End'?tabs.length-1:(index+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;
    tabs[next].click();tabs[next].focus();
  });
  paintArchiveTable();
}
function filteredArchives(){
  const a=state.archive,q=a.q.trim().toLowerCase();
  return a.records[a.type].filter(record=>!q||[record.name,record.organization,record.purpose,record.host,record.badge_code,record.rfid_uid,record.code,record.uid].some(value=>String(value||'').toLowerCase().includes(q)));
}
function visibleArchives(){
  const records=filteredArchives(),a=state.archive;
  a.page=Math.min(a.page,Math.max(1,Math.ceil(records.length/20)));
  return records.slice((a.page-1)*20,a.page*20);
}
function paintArchiveTable(){
  if(state.view!=='archives'||!$('#archive-table'))return;
  const a=state.archive,visitors=a.type==='visitors',records=filteredArchives(),items=visibleArchives();
  $$('[data-archive-count]').forEach(el=>el.textContent=a.records[el.dataset.archiveCount].length);
  $$('[data-action="archive-tab"]').forEach(el=>{
    const active=el.dataset.type===a.type;el.classList.toggle('active',active);el.setAttribute('aria-selected',String(active));el.tabIndex=active?0:-1;
  });
  $('#archive-list').setAttribute('aria-labelledby','archive-tab-'+a.type);
  $('#archive-table').innerHTML=items.length?'<div class="table-wrap"><table class="full-table archive-table"><thead><tr><th class="archive-check-cell"><input class="archive-checkbox" type="checkbox" id="archive-select-all" aria-label="Select all records on this page"></th><th>'+(visitors?'Visitor':'RFID badge')+'</th><th>'+(visitors?'Purpose / office':'RFID UID')+'</th>'+(visitors?'<th>Badge</th><th>Checked out</th>':'')+'<th>Archived</th><th>Actions</th></tr></thead><tbody>'+
    items.map((record,i)=>'<tr data-archive-row="'+record.id+'"><td class="archive-check-cell"><input class="archive-checkbox" type="checkbox" data-archive-id="'+record.id+'" aria-label="Select '+esc(visitors?record.name:record.code)+'" '+(a.selected.has(record.id)?'checked':'')+'></td><td>'+(visitors?personCell(record,i):'<strong class="visitor-name">'+esc(record.code)+'</strong>'+status('archived'))+'</td><td>'+esc(visitors?record.purpose:record.uid)+(visitors?'<span class="visitor-org">'+esc(record.host)+'</span>':'')+'</td>'+(visitors?'<td><span class="badge-code">'+esc(record.badge_code)+'</span></td><td>'+esc(fmtTime(record.check_out))+'<span class="visitor-org">'+esc(fmtDate(record.check_out))+'</span></td>':'')+'<td>'+esc(fmtDate(record.archived_at))+'<span class="visitor-org">'+esc(fmtTime(record.archived_at))+'</span></td><td><button class="table-action" data-action="restore-archive-one" data-id="'+record.id+'">'+icon('refresh')+'Restore</button></td></tr>').join('')+
    '</tbody></table></div><div class="table-footer"><span>Showing '+((a.page-1)*20+1)+'-'+((a.page-1)*20+items.length)+' of '+records.length+' archived '+(visitors?'visits':'badges')+'</span><div><button class="button secondary small" data-action="archive-prev-page" '+(a.page===1?'disabled':'')+'>Previous</button> <button class="button secondary small" data-action="archive-next-page" '+(a.page*20>=records.length?'disabled':'')+'>Next</button></div></div>':
    empty(a.q?'No matching archived records':'No archived '+(visitors?'visitors':'badges'),a.q?'Try another search to find an archived record.':'Archived '+(visitors?'completed visits':'unassigned badges')+' will appear here.');
  updateArchiveSelection();
}
function updateArchiveSelection(){
  const a=state.archive,items=visibleArchives(),count=a.selected.size;
  $('#archive-selection-count').textContent=count+' '+(count===1?'record selected':'records selected');
  $$('[data-action="delete-archives"],[data-action="restore-archives"],[data-action="clear-archives"]').forEach(button=>button.disabled=count===0);
  $('[data-action="select-archives"]').disabled=items.length===0;
  const all=$('#archive-select-all');
  if(all){const selected=items.filter(r=>a.selected.has(r.id)).length;all.checked=items.length>0&&selected===items.length;all.indeterminate=selected>0&&selected<items.length}
}
function selectArchivePage(checked=true){
  const a=state.archive,items=visibleArchives();
  const proposed=new Set(a.selected);
  items.forEach(record=>checked?proposed.add(record.id):proposed.delete(record.id));
  if(proposed.size>100){toast('Select up to 100 archived records at a time.',true);updateArchiveSelection();return}
  const restoreHeaderFocus=document.activeElement?.id==='archive-select-all';
  a.selected=proposed;paintArchiveTable();
  if(restoreHeaderFocus)$('#archive-select-all')?.focus();
}
$('#view').addEventListener('change',event=>{
  if(state.view!=='archives')return;
  const input=event.target;
  if(input.id==='archive-select-all'){selectArchivePage(input.checked);return}
  if(!input.matches('[data-archive-id]'))return;
  const a=state.archive,id=Number(input.dataset.archiveId);
  if(input.checked&&a.selected.size>=100&&!a.selected.has(id)){input.checked=false;toast('Select up to 100 archived records at a time.',true);return}
  input.checked?a.selected.add(id):a.selected.delete(id);updateArchiveSelection();
});
async function loadArchives(){
  const version=++archiveRequest,data=await api('archives');
  if(version!==archiveRequest||state.view!=='archives')return;
  state.archive.records={visitors:data.visitors,badges:data.badges};
  const existing=new Set(data[state.archive.type].map(record=>record.id));
  state.archive.selected=new Set([...state.archive.selected].filter(id=>existing.has(id)));
  if(!$('#archive-table'))paintArchivesPage();else paintArchiveTable();
}
async function confirmVisitorArchive(id){
  const v=await findVisitor(id);
  if(v.status!=='checked_out'){toast('Check out this visitor before archiving the visit.',true);return}
  confirmAction('Archive visitor record','Move the completed visit for '+v.name+' to Archives? You can restore it there.','Archive visitor',async()=>{
    await api('visitor_archive',{id:v.id});toast('Visitor record archived.');
  });
}
function confirmBadgeArchive(id){
  const b=state.badges.find(b=>b.id===Number(id));
  if(!b)return;
  if(b.status==='assigned'){toast('Check out the visitor before archiving this badge.',true);return}
  confirmAction('Archive RFID badge','Move '+b.code+' to Archives? It will be unavailable for visitor registration and scans until restored and activated.','Archive badge',async()=>{
    await api('badge_archive',{id:b.id});toast('RFID badge archived.');
  });
}
function confirmArchiveBatch(operation,explicitIds=null){
  const a=state.archive,type=a.type,ids=explicitIds||[...a.selected];
  if(!ids.length)return;
  const noun=type==='visitors'?'visitor record':'RFID badge',records=ids.length+' archived '+noun+(ids.length===1?'':'s'),deleting=operation==='delete';
  const message=deleting?'Permanently delete '+records+'? This cannot be undone.'+(type==='badges'?' Past visits will retain their recorded badge code and UID.':''):
    'Restore '+records+' to the main lists?'+(type==='badges'?' Restored badges remain inactive until you activate them in RFID badges.':'');
  confirmAction(deleting?'Delete archived records':'Restore archived records',message,deleting?'Delete permanently':'Restore selected',async()=>{
    const result=await api(deleting?'archive_delete':'archive_restore',{type,ids});
    ids.forEach(id=>a.selected.delete(id));toast(result.count+' '+noun+(result.count===1?'':'s')+' '+(deleting?'deleted permanently.':'restored.'));
  });
  if(deleting)$('#confirm-action').className='button danger';
}


function personInitials(person){
  return person.first_name&&person.last_name?initials(person.first_name+' '+person.last_name):initials(person.name);
}
function avatarContent(person){
  const letters=personInitials(person);
  return person.profile_photo_url?'<img class="account-avatar-image" src="'+esc(person.profile_photo_url)+'" alt="" data-fallback-initials="'+esc(letters)+'">':esc(letters);
}
function updateCurrentUser(user){
  state.user=user;
  $('#profile-initials').innerHTML=avatarContent(user);
  $('#profile-name').textContent=user.name;
  $('#profile-role').textContent=isAdmin()?'Campus administrator':'Security personnel';
  $$('[data-admin]').forEach(el=>el.hidden=!isAdmin());
}
document.addEventListener('error',event=>{
  const img=event.target;
  if(img instanceof HTMLImageElement&&img.matches('.account-avatar-image')&&img.parentElement){
    img.parentElement.textContent=img.dataset.fallbackInitials||'?';
  }
},true);
function nameFields(person={},prefix=''){
  const input=(key,label,autocomplete,required,max,extraClass='')=>{
    const id=prefix?prefix+'-'+key.replaceAll('_','-'):key;
    return '<div class="'+extraClass+'"><label for="'+id+'">'+label+'</label><input id="'+id+'" name="'+key+'" type="text" value="'+esc(person[key]||'')+'" maxlength="'+max+'" '+(required?'required ':'')+'autocomplete="'+autocomplete+'"></div>';
  };
  return '<div class="name-fields full">'+input('first_name','First name','given-name',true,50)+
    input('middle_initial','Middle initial <span class="field-optional">(optional)</span>','off',false,2,'name-field-middle')+
    input('last_name','Last name','family-name',true,50,'name-field-last')+'</div>';
}
let profileRequest=0;
let profilePreviewURL=null;
let profileBusy=false;
let profileMutationPending=null;
let signOutPending=false;
function clearProfilePreview(){
  if(profilePreviewURL){URL.revokeObjectURL(profilePreviewURL);profilePreviewURL=null}
}

function resetProfileContext(){
  clearProfilePreview();profileBusy=false;profileMutationPending=null;
}

function beginProfileMutation(){
  profileBusy=true;
  let resolve;
  const pending=new Promise(done=>resolve=done);
  profileMutationPending=pending;refreshProfileControls();
  return epoch=>{
    if(profileMutationPending===pending)profileMutationPending=null;
    if(epoch===state.sessionEpoch){profileBusy=false;refreshProfileControls()}
    resolve();
  };
}
async function signOut(button){
  if(signOutPending)return;
  signOutPending=true;button.disabled=true;
  try{
    const pending=profileMutationPending;
    if(pending)await pending;
    state.sessionEpoch++;resetProfileContext();
    await api('session');
    await api('logout',{});
    state.user=null;state.dashboard=null;state.visitors=[];state.badges=[];state.accounts=[];
    state.archive.records={visitors:[],badges:[]};state.archive.selected.clear();
    $('#login-form').reset();showLogin();toast('You have signed out.');
  }finally{signOutPending=false;if(button.isConnected)button.disabled=false}
}

function refreshProfileControls(){
  if(state.view!=='profile')return;
  const save=$('#profile-form button[type="submit"]'),upload=$('#profile-photo-form button[type="submit"]'),remove=$('[data-action="remove-profile-photo"]');
  if(save)save.disabled=profileBusy;
  if(upload)upload.disabled=profileBusy||!$('#profile-photo-input')?.files[0];
  if(remove)remove.disabled=profileBusy||!state.user?.profile_photo_url;
}
function refreshProfileIdentity(){
  const name=$('#profile-photo-name'),preview=$('#profile-photo-preview');
  if(name)name.textContent=state.user.name;
  if(preview&&!profilePreviewURL)preview.innerHTML=avatarContent(state.user);
  refreshProfileControls();
}

async function loadProfile(){
  const version=++profileRequest,data=await api('profile');
  if(version!==profileRequest||state.view!=='profile')return;
  updateCurrentUser(data.user);paintProfileSettings();
}
function paintProfileSettings(){
  clearProfilePreview();
  const user=state.user;
  const ownField=(id,name,label,type,value='',extra='',help='')=>'<div class="full"><label for="'+id+'">'+label+'</label><input id="'+id+'" name="'+name+'" type="'+type+'" value="'+esc(value)+'" '+extra+'>'+(help?'<p class="field-help">'+help+'</p>':'')+'</div>';
  $('#view').innerHTML=heading('Account settings','Update your details, password, and profile picture.','<a class="button secondary" href="#dashboard">'+icon('grid')+'View dashboard</a>')+
    '<div class="account-settings-grid"><section class="panel"><div class="panel-header"><div><div class="panel-title">'+icon('user')+'Your account details</div><p class="panel-subtitle">These details appear on your campus account.</p></div></div>'+
    '<form id="profile-form"><div class="account-form-body"><div class="form-grid">'+nameFields(user,'profile')+
    ownField('profile-email','email','Email address','email',user.email,'required maxlength="254" autocomplete="email"')+
    '<div class="account-password-divider full"><h2>Sign-in security</h2><p>Enter your current password when changing your email or setting a new password.</p></div>'+
    ownField('profile-current-password','current_password','Current password','password','','autocomplete="current-password" maxlength="72"')+
    ownField('profile-new-password','new_password','New password <span class="field-optional inline">(optional)</span>','password','','autocomplete="new-password" minlength="12" maxlength="72"','Leave blank to keep your password. Use at least 12 characters for a new password.')+
    ownField('profile-confirm-password','confirm_password','Confirm new password','password','','autocomplete="new-password" maxlength="72"')+
    '</div><p class="form-error" id="profile-error" role="alert" hidden></p></div><div class="account-form-actions"><button class="button primary" type="submit">'+icon('check')+'Save account details</button></div></form></section>'+
    '<div class="account-side-panels"><section class="panel"><div class="panel-header"><div><div class="panel-title">Profile picture</div><p class="panel-subtitle">A familiar face for your campus workspace.</p></div></div><div class="account-photo-body"><div class="account-photo-preview avatar" id="profile-photo-preview">'+avatarContent(user)+'</div><strong class="account-photo-name" id="profile-photo-name">'+esc(user.name)+'</strong><span class="account-photo-role">'+esc(user.role==='admin'?'Campus administrator':'Security personnel')+'</span>'+
    '<form id="profile-photo-form" enctype="multipart/form-data"><label for="profile-photo-input">Choose a profile picture</label><input id="profile-photo-input" name="photo" type="file" accept="image/jpeg,image/png,image/webp"><p class="field-help">JPG, PNG, or WebP. Maximum 2 MB and 4096 pixels per side.</p><p class="form-error" id="profile-photo-error" role="alert" hidden></p><div class="account-photo-actions"><button class="button primary" type="submit" disabled>'+icon('plus')+'Upload photo</button><button class="button secondary" type="button" data-action="remove-profile-photo" '+(!user.profile_photo_url?'disabled':'')+'>'+icon('trash')+'Remove photo</button></div></form></div></section>'+
    '<div class="quick-note">'+icon('shield')+'<div><strong>Your personal campus account</strong><p>You can change your details and picture. Your access role is managed by a campus administrator.</p></div></div></div></div>';
  const form=$('#profile-form'),current=form.elements.current_password,next=form.elements.new_password,confirm=form.elements.confirm_password;
  const syncPasswords=()=>{
    current.required=form.elements.email.value.trim().toLowerCase()!==String(state.user.email).toLowerCase()||next.value!=='';
    confirm.required=next.value!=='';
    if(next.value===''){confirm.value='';confirm.setCustomValidity('')}
    else confirm.setCustomValidity(confirm.value!==next.value?'The new passwords must match.':'');
  };
  form.addEventListener('input',syncPasswords);
  form.addEventListener('submit',async event=>{
    event.preventDefault();
    if(profileBusy)return;
    const epoch=state.sessionEpoch,inputs=[...form.querySelectorAll('input')],error=$('#profile-error');
    const payload={
      first_name:form.elements.first_name.value,middle_initial:form.elements.middle_initial.value,last_name:form.elements.last_name.value,
      email:form.elements.email.value,current_password:current.value,new_password:next.value
    };
    const release=beginProfileMutation();inputs.forEach(input=>input.disabled=true);error.hidden=true;
    try{
      const data=await api('profile_update',payload);
      updateCurrentUser(data.user);toast('Your account details have been saved.');
      if(form.isConnected&&state.view==='profile'){
        ['first_name','middle_initial','last_name','email'].forEach(key=>form.elements[key].value=data.user[key]||'');
        current.value='';next.value='';confirm.value='';syncPasswords();refreshProfileIdentity();
      }
    }catch(err){if(epoch===state.sessionEpoch){if(error.isConnected){error.textContent=err.message;error.hidden=false}else toast(err.message,true)}}
    finally{
      release(epoch);
      if(form.isConnected)inputs.forEach(input=>input.disabled=false);
    }
  });
  const photoForm=$('#profile-photo-form'),fileInput=$('#profile-photo-input'),photoError=$('#profile-photo-error'),upload=$('button[type="submit"]',photoForm);
  fileInput.addEventListener('change',()=>{
    clearProfilePreview();photoError.hidden=true;upload.disabled=true;
    const file=fileInput.files[0];
    $('#profile-photo-preview').innerHTML=avatarContent(state.user);
    if(!file)return;
    if(file.size===0||file.size>2*1024*1024||(file.type&&!['image/jpeg','image/png','image/webp'].includes(file.type))){
      photoError.textContent='Choose a JPG, PNG, or WebP image no larger than 2 MB.';photoError.hidden=false;fileInput.value='';return;
    }
    profilePreviewURL=URL.createObjectURL(file);
    $('#profile-photo-preview').innerHTML='<img class="account-avatar-image" src="'+esc(profilePreviewURL)+'" alt="Selected profile picture preview" data-fallback-initials="'+esc(personInitials(state.user))+'">';
    refreshProfileControls();
  });
  photoForm.addEventListener('submit',async event=>{
    event.preventDefault();
    if(!fileInput.files[0]||profileBusy)return;
    const epoch=state.sessionEpoch,body=new FormData(photoForm);
    const release=beginProfileMutation();fileInput.disabled=true;photoError.hidden=true;
    try{
      const data=await api('profile_photo',body);updateCurrentUser(data.user);toast('Your profile picture has been updated.');
      if(photoForm.isConnected&&state.view==='profile'){
        clearProfilePreview();fileInput.value='';refreshProfileIdentity();
      }
    }catch(err){if(epoch===state.sessionEpoch){if(photoError.isConnected){photoError.textContent=err.message;photoError.hidden=false}else toast(err.message,true)}}
    finally{
      release(epoch);
      if(fileInput.isConnected)fileInput.disabled=false;
    }
  });
  refreshProfileControls();
}
function confirmRemoveProfilePhoto(){
  if(!state.user.profile_photo_url||profileBusy)return;
  const photoForm=$('#profile-photo-form');
  confirmAction('Remove profile picture','Remove your current picture and use your name initials instead?','Remove photo',async()=>{
    const epoch=state.sessionEpoch,release=beginProfileMutation();
    try{
      const data=await api('profile_photo_remove',{});updateCurrentUser(data.user);toast('Your profile picture has been removed.');
      if(photoForm?.isConnected&&state.view==='profile')refreshProfileIdentity();
    }finally{release(epoch)}
  },false);
}
