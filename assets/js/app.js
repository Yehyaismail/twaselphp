/*
 * Tawasul PHP Frontend
 *
 * هذا الملف يعيد تنفيذ واجهة React الأصلية بأسلوب JavaScript مباشر مناسب للاستضافات المجانية.
 * تم وضع تعليقات بجانب الأجزاء المهمة حتى يسهل تطويره لاحقاً أو تحويله إلى إطار عمل آخر.
 */
const API = '/api';
const app = document.getElementById('app');

const state = {
  user: null,
  conversations: [],
  users: [],
  nicknames: {},
  selectedUser: null,
  messages: [],
  route: 'login',
  polling: [],
  editing: null,
  replying: null,
  settings: JSON.parse(localStorage.getItem('chat-customize') || '{}'),
};

const defaults = {
  avatarColor: '#059669', chatBgColor: '', chatBgImage: '', sentBubbleColor: '', receivedBubbleColor: '', fontFamily: '', fontSize: 'base'
};
state.settings = { ...defaults, ...state.settings };

function saveSettings(){ localStorage.setItem('chat-customize', JSON.stringify(state.settings)); }
function $(id){ return document.getElementById(id); }
function esc(v){ return String(v ?? '').replace(/[&<>'"]/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[s])); }
function initials(name){ return (name || 'U').split(/\s+/).map(w=>w[0]||'').join('').slice(0,2).toUpperCase() || 'U'; }
function icon(name, cls='w-5 h-5'){ return `<i data-lucide="${name}" class="${cls}"></i>`; }
function fmtTime(ts){ try { return new Date(ts).toLocaleTimeString('ar', {hour:'2-digit',minute:'2-digit'}); } catch { return ''; } }
function fmtDate(ts){ try { return new Date(ts).toLocaleString('ar'); } catch { return ''; } }
function apiFile(path){ return `${API}/files/${encodeURIComponent(path)}`; }
function clearTimers(){ state.polling.forEach(clearInterval); state.polling = []; }
function refreshIcons(){ if (window.lucide) window.lucide.createIcons(); }
function applyTheme(){ document.documentElement.classList.toggle('dark', localStorage.getItem('chat-theme') === 'dark'); }
applyTheme();

async function request(path, options={}){
  const opts = { credentials:'include', headers:{}, ...options };
  if (opts.body && !(opts.body instanceof FormData)) {
    opts.headers['Content-Type'] = 'application/json';
    opts.body = JSON.stringify(opts.body);
  }
  const res = await fetch(API + path, opts);
  const type = res.headers.get('content-type') || '';
  const data = type.includes('application/json') ? await res.json() : await res.text();
  if (!res.ok) throw data;
  return data;
}

async function init(){
  try { state.user = await request('/auth/me'); state.route = 'chat'; await loadData(); }
  catch { state.route = 'login'; }
  render();
}

function render(){
  clearTimers();
  if (!state.user || state.route === 'login') renderLogin();
  else if (state.route === 'admin') renderAdmin();
  else renderChat();
  refreshIcons();
}

function renderLogin(isRegister=false, error=''){
  app.innerHTML = `
    <section class="min-h-screen flex items-center justify-center login-bg p-4">
      <div class="w-full max-w-md p-8 glass-card rounded-2xl" data-testid="login-form">
        <div class="text-center mb-8">
          <h1 class="text-4xl font-light tracking-tight text-slate-900 mb-2">${isRegister ? 'إنشاء حساب' : 'تسجيل الدخول'}</h1>
          <p class="text-slate-500 text-sm">${isRegister ? 'انضم إلينا الآن' : 'مرحباً بك مجدداً'}</p>
        </div>
        <form id="authForm" class="space-y-5">
          ${isRegister ? `<div><label class="block text-sm text-slate-700 mb-2">الاسم</label><input id="name" class="w-full px-3 py-2.5 rounded-md border border-slate-200 focus:outline-none focus:ring-2 focus:ring-emerald-500" data-testid="register-name-input" placeholder="أدخل اسمك"></div>` : ''}
          <div><label class="block text-sm text-slate-700 mb-2">البريد الإلكتروني</label><input id="email" type="email" required class="w-full px-3 py-2.5 rounded-md border border-slate-200 focus:outline-none focus:ring-2 focus:ring-emerald-500" data-testid="login-email-input" placeholder="example@email.com"></div>
          <div><label class="block text-sm text-slate-700 mb-2">كلمة المرور</label><input id="password" type="password" required class="w-full px-3 py-2.5 rounded-md border border-slate-200 focus:outline-none focus:ring-2 focus:ring-emerald-500" data-testid="login-password-input" placeholder="••••••••"></div>
          ${error ? `<div class="text-red-600 text-sm bg-red-50 p-3 rounded-lg" data-testid="login-error">${esc(error)}</div>` : ''}
          <button class="w-full bg-emerald-600 hover:bg-emerald-700 text-white py-4 rounded-xl text-base font-medium transition-colors duration-200 flex items-center justify-center gap-2" data-testid="login-submit-btn">${isRegister ? 'إنشاء حساب' : 'دخول'} ${icon('arrow-right')}</button>
        </form>
        <div class="mt-6 text-center"><button id="toggleAuth" class="text-sm text-emerald-700 hover:text-emerald-800 transition-colors duration-200" data-testid="toggle-auth-mode">${isRegister ? 'لديك حساب؟ تسجيل الدخول' : 'ليس لديك حساب؟ إنشاء حساب جديد'}</button></div>
      </div>
    </section>`;
  $('toggleAuth').onclick = () => renderLogin(!isRegister);
  $('authForm').onsubmit = async (e) => {
    e.preventDefault();
    try {
      const email = $('email').value, password = $('password').value;
      state.user = isRegister ? await request('/auth/register', {method:'POST', body:{name:$('name').value,email,password}}) : await request('/auth/login', {method:'POST', body:{email,password}});
      state.route = 'chat'; await loadData(); render();
    } catch (err) { renderLogin(isRegister, err.detail || err.error || 'حدث خطأ. يرجى المحاولة مرة أخرى.'); }
  };
}

async function loadData(){
  if (!state.user) return;
  try {
    const [convs, users, nicks] = await Promise.all([request('/conversations'), request('/users'), request('/nicknames')]);
    state.nicknames = nicks || {};
    state.conversations = convs.map(c => ({...c, other_user:{...c.other_user, display_name: state.nicknames[c.other_user.id] || c.other_user.name}}));
    state.users = users.map(u => ({...u, display_name: state.nicknames[u.id] || u.name}));
    const unread = state.conversations.reduce((a,c)=>a+(c.unread_count||0),0);
    document.title = unread ? `(${unread}) محادثات` : 'محادثات';
  } catch {}
}

function renderChat(){
  app.innerHTML = `<section class="h-screen bg-slate-50 dark:bg-slate-900 grid grid-cols-12 relative" data-testid="chat-page">
    <aside id="sidebar" class="col-span-12 md:col-span-4 lg:col-span-3 border-l border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 ${state.selectedUser ? 'mobile-hidden md:block' : ''}"></aside>
    <section id="chatArea" class="col-span-12 md:col-span-8 lg:col-span-9 ${!state.selectedUser ? 'mobile-hidden md:block' : ''}"></section>
  </section>`;
  renderSidebar(); renderChatWindow();
  state.polling.push(setInterval(async()=>{ await loadData(); renderSidebar(false); }, 5000));
}

function renderSidebar(updateIcons=true){
  const side = $('sidebar'); if (!side) return;
  const adminBtn = state.user.role === 'admin' ? `<button id="adminBtn" class="w-full mb-3 flex items-center justify-center gap-2 py-2 px-3 bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 rounded-lg hover:bg-emerald-100 dark:hover:bg-emerald-900/50 transition-colors duration-200 text-sm font-medium">${icon('shield','w-4 h-4')} لوحة التحكم</button>` : '';
  side.innerHTML = `<div class="h-screen flex flex-col bg-white dark:bg-slate-800">
    <div class="p-4 border-b border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 sticky top-0 z-20 flex-shrink-0">
      <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-3"><span class="avatar w-10 h-10" style="background:${state.settings.avatarColor}">${initials(state.user.name)}</span><div><p class="font-medium text-slate-900 dark:text-slate-100">${esc(state.user.name)}</p><p class="text-xs text-slate-500 dark:text-slate-400">متصل</p></div></div>
        <div class="flex items-center gap-1"><button id="customizeBtn" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg">${icon('palette')}</button><button id="themeBtn" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg">${icon(document.documentElement.classList.contains('dark')?'sun':'moon')}</button><button id="logoutBtn" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg">${icon('log-out')}</button></div>
      </div>${adminBtn}
      <div class="relative"><span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">${icon('search','w-4 h-4')}</span><input id="search" class="w-full pr-10 px-3 py-2.5 rounded-md border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500" placeholder="ابحث عن محادثة..." data-testid="search-input"></div>
    </div><div id="chatList" class="flex-1 overflow-y-auto scroll-thin" data-testid="sidebar-chat-list"></div></div>`;
  $('logoutBtn').onclick = async()=>{ await request('/auth/logout',{method:'POST'}); state.user=null; state.selectedUser=null; state.route='login'; render(); };
  $('themeBtn').onclick = ()=>{ localStorage.setItem('chat-theme', document.documentElement.classList.contains('dark')?'light':'dark'); applyTheme(); render(); };
  $('customizeBtn').onclick = renderCustomize;
  if ($('adminBtn')) $('adminBtn').onclick = ()=>{ state.route='admin'; render(); };
  $('search').oninput = () => renderList($('search').value);
  renderList(''); if(updateIcons) refreshIcons();
}

function renderList(q=''){
  const list = $('chatList'); if (!list) return; q = q.toLowerCase();
  const convs = state.conversations.filter(c => (c.other_user.display_name||c.other_user.name).toLowerCase().includes(q));
  const users = state.users.filter(u => (u.display_name||u.name).toLowerCase().includes(q) && !convs.some(c=>c.other_user.id===u.id));
  if (!q && convs.length === 0) { list.innerHTML = `<div class="p-6 text-center text-slate-500 dark:text-slate-400">${icon('message-circle','w-12 h-12 mx-auto mb-2 text-slate-300 dark:text-slate-600')}<p class="mb-2">لا توجد محادثات بعد</p><p class="text-sm">ابحث عن مستخدم لبدء محادثة</p></div>`; refreshIcons(); return; }
  if (q && convs.length+users.length===0) { list.innerHTML = `<div class="p-6 text-center text-slate-500 dark:text-slate-400">${icon('message-circle','w-12 h-12 mx-auto mb-2 text-slate-300 dark:text-slate-600')}<p>لا توجد نتائج</p></div>`; refreshIcons(); return; }
  list.innerHTML = [...convs.map(c=>itemHtml(c.other_user,c.last_message,c.last_message_time,c.unread_count)), ...users.map(u=>itemHtml(u,u.online?'متصل':'غير متصل',null,0))].join('');
  list.querySelectorAll('[data-user]').forEach(el=>el.onclick=()=>selectUser(el.dataset.user)); refreshIcons();
}
function itemHtml(user,last,time,unread){ return `<div data-user="${user.id}" class="p-4 border-b border-slate-100 dark:border-slate-700 cursor-pointer transition-colors duration-200 hover:bg-slate-50 dark:hover:bg-slate-700/50 ${state.selectedUser?.id===user.id?'bg-slate-100 dark:bg-slate-700':''}" data-testid="chat-list-item"><div class="flex items-start gap-3"><div class="relative"><span class="avatar w-12 h-12">${initials(user.display_name||user.name)}</span>${user.online?'<span class="absolute bottom-0 left-0 w-3 h-3 bg-emerald-500 border-2 border-white dark:border-slate-800 rounded-full"></span>':''}</div><div class="flex-1 min-w-0"><div class="flex items-center justify-between mb-1"><p class="font-medium text-slate-900 dark:text-slate-100 truncate">${esc(user.display_name||user.name)}</p><span class="text-xs text-slate-400 dark:text-slate-500">${time?fmtDate(time):''}</span></div><div class="flex items-center justify-between"><p class="text-sm text-slate-500 dark:text-slate-400 truncate flex-1">${esc(last||'')}</p>${unread>0?`<span class="bg-emerald-600 text-white text-xs rounded-full px-2 py-0.5 min-w-[20px] text-center">${unread}</span>`:''}</div></div></div></div>`; }

async function selectUser(id){
  state.selectedUser = state.users.find(u=>u.id===id) || state.conversations.find(c=>c.other_user.id===id)?.other_user;
  await loadMessages(); render();
  state.polling.push(setInterval(loadMessagesSilent, 4000));
  state.polling.push(setInterval(checkTyping, 3000));
}
async function loadMessages(){ if(!state.selectedUser) return; state.messages = await request('/messages/'+state.selectedUser.id); }
async function loadMessagesSilent(){ if(!state.selectedUser) return; try { state.messages = await request('/messages/'+state.selectedUser.id); renderMessages(); } catch{} }
async function checkTyping(){ if(!state.selectedUser) return; try { const r=await request('/typing/'+state.selectedUser.id); const el=$('typing'); if(el) el.textContent = r.is_typing ? 'يكتب الآن...' : (state.selectedUser.online?'متصل':'غير متصل'); } catch{} }

function renderChatWindow(){
  const area = $('chatArea'); if(!area) return;
  if(!state.selectedUser){ area.innerHTML = `<div class="h-screen flex flex-col items-center justify-center bg-slate-50 dark:bg-slate-900"><img src="https://images.unsplash.com/photo-1755908471117-9adbf5671b1d?crop=entropy&cs=srgb&fm=jpg&ixid=M3w4NjAzMzV8MHwxfHNlYXJjaHwxfHxwZW9wbGUlMjBjaGF0dGluZyUyMHNpbGhvdWV0dGV8ZW58MHx8fHwxNzc2MTkwNzMyfDA&ixlib=rb-4.1.0&q=85" class="w-64 h-64 object-cover rounded-2xl opacity-40 dark:opacity-20 mb-6"><p class="text-2xl text-slate-400 dark:text-slate-500 font-light">اختر محادثة للبدء</p></div>`; return; }
  area.innerHTML = `<div class="h-screen flex flex-col bg-slate-50 dark:bg-slate-900" style="font-family:${esc(state.settings.fontFamily||'')}">
    <header class="p-4 bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 sticky top-0 z-10"><div class="flex items-center gap-3"><button id="backBtn" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg">${icon('arrow-right')}</button><div class="relative"><span class="avatar w-10 h-10">${initials(state.selectedUser.display_name||state.selectedUser.name)}</span>${state.selectedUser.online?'<span class="absolute bottom-0 left-0 w-3 h-3 bg-emerald-500 border-2 border-white dark:border-slate-800 rounded-full"></span>':''}</div><div class="flex-1"><p class="font-medium text-slate-900 dark:text-slate-100">${esc(state.selectedUser.display_name||state.selectedUser.name)}</p><p id="typing" class="text-xs text-slate-500 dark:text-slate-400">${state.selectedUser.online?'متصل':'غير متصل'}</p></div><button id="clearBtn" title="مسح المحادثة" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg">${icon('eraser')}</button><button id="exportBtn" title="تصدير" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg">${icon('file-down')}</button><button id="nickBtn" title="تغيير الاسم" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg">${icon('user-pen')}</button></div></header>
    <div id="messages" class="flex-1 overflow-y-auto p-4 space-y-3 scroll-thin" data-testid="chat-message-list"></div>
    <div id="actionBar"></div>
    <footer class="p-4 bg-white dark:bg-slate-800 border-t border-slate-200 dark:border-slate-700 sticky bottom-0"><form id="sendForm" class="flex items-center gap-3"><div class="relative"><button type="button" id="attachBtn" class="p-3 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-xl">${icon('paperclip')}</button><div id="attachMenu" class="hidden absolute bottom-14 right-0 bg-white dark:bg-slate-800 rounded-xl shadow-lg border border-slate-200 dark:border-slate-700 p-2 w-40 z-20"><button type="button" data-choose="image" class="flex items-center gap-3 w-full p-3 hover:bg-slate-50 dark:hover:bg-slate-700 rounded-lg text-right">${icon('image','w-5 h-5 text-emerald-600')}<span>صورة</span></button><button type="button" data-choose="video" class="flex items-center gap-3 w-full p-3 hover:bg-slate-50 dark:hover:bg-slate-700 rounded-lg text-right">${icon('video','w-5 h-5 text-purple-600')}<span>فيديو</span></button><button type="button" data-choose="file" class="flex items-center gap-3 w-full p-3 hover:bg-slate-50 dark:hover:bg-slate-700 rounded-lg text-right">${icon('file-text','w-5 h-5 text-blue-600')}<span>ملف</span></button></div></div><input id="fileInput" type="file" class="hidden-file"><button type="button" id="emojiBtn" class="p-3 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-xl">${icon('smile')}</button><textarea id="msgInput" rows="1" class="flex-1 resize-none min-h-[44px] max-h-[120px] py-2.5 px-3 rounded-xl border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-500 text-base leading-relaxed" placeholder="اكتب رسالتك..." data-testid="chat-message-input"></textarea><button class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-4 rounded-xl" data-testid="chat-send-btn">${icon('send')}</button></form></footer>
  </div>`;
  $('backBtn').onclick=()=>{state.selectedUser=null; render();};
  $('clearBtn').onclick=clearChat; $('exportBtn').onclick=()=>window.open(`${API}/messages/${state.selectedUser.id}/export`, '_blank'); $('nickBtn').onclick=setNickname;
  $('attachBtn').onclick=()=> $('attachMenu').classList.toggle('hidden');
  document.querySelectorAll('[data-choose]').forEach(b=>b.onclick=()=>{ $('fileInput').accept = b.dataset.choose==='image'?'image/*':b.dataset.choose==='video'?'video/*':'.pdf,.doc,.docx,.txt,.xls,.xlsx,.zip,.rar,.mp3,.wav,.ogg,.aac'; $('fileInput').click(); });
  $('fileInput').onchange=uploadFile;
  $('emojiBtn').onclick=()=>{ $('msgInput').value += '😊'; $('msgInput').focus(); };
  $('msgInput').oninput=()=>request('/typing',{method:'POST',body:{receiver_id:state.selectedUser.id,is_typing:true}}).catch(()=>{});
  $('sendForm').onsubmit=sendMessage;
  renderMessages(); refreshIcons();
}

function renderMessages(){
  const box=$('messages'); if(!box) return;
  const fs = {sm:'text-sm',base:'text-base',lg:'text-lg',xl:'text-xl'}[state.settings.fontSize] || 'text-base';
  if(state.settings.chatBgImage){ box.style.backgroundImage=`url(${state.settings.chatBgImage})`; box.style.backgroundSize='cover'; box.style.backgroundPosition='center'; }
  else { box.style.backgroundImage=''; box.style.backgroundColor=state.settings.chatBgColor || ''; }
  box.innerHTML = state.messages.map(m=>{
    const own = m.sender_id === state.user.id;
    const bg = own ? (state.settings.sentBubbleColor || '') : (state.settings.receivedBubbleColor || '');
    const cls = own ? 'own bg-emerald-100 dark:bg-emerald-900/40 rounded-tr-none' : 'other bg-white dark:bg-slate-800 rounded-tl-none border border-slate-100 dark:border-slate-700';
    const reactions = m.reactions ? Object.values(m.reactions).reduce((a,e)=>(a[e]=(a[e]||0)+1,a),{}) : {};
    return `<div class="message-wrap flex ${own?'justify-end':'justify-start'} group" data-testid="chat-message-bubble"><div class="message-bubble relative ${cls} text-slate-900 dark:text-slate-100 rounded-lg p-3 shadow-sm" style="${bg?'background-color:'+bg:''}">
      ${m.deleted?'<p class="text-sm italic text-slate-400">تم حذف هذه الرسالة</p>':filePreview(m)+ (m.message_type==='text'?`<p class="${fs} leading-relaxed whitespace-pre-wrap">${esc(m.text)}</p>`:'')}
      <div class="flex items-center justify-between gap-2 mt-1"><span class="text-xs text-slate-500 dark:text-slate-400">${fmtTime(m.timestamp)} ${m.edited&&!m.deleted?'<span class="mr-1">(معدّل)</span>':''}</span><span class="text-xs ${m.status==='read'?'text-blue-500':'text-slate-400'}">${own?(m.status==='sent'?'✓':'✓✓'):''}</span></div>
      <div class="flex flex-wrap gap-1 mt-1.5">${Object.entries(reactions).map(([e,c])=>`<button data-react="${m.id}|${e}" class="px-1.5 py-0.5 rounded-full text-xs bg-slate-100 dark:bg-slate-700 border">${e}${c>1?' '+c:''}</button>`).join('')}</div>
      <div class="message-actions absolute top-1 ${own?'left-1':'right-1'} flex gap-1"><button data-reply="${m.id}" class="p-1.5 bg-white dark:bg-slate-700 rounded-md shadow-sm">${icon('reply','w-3.5 h-3.5')}</button>${own&&m.message_type==='text'?`<button data-edit="${m.id}" class="p-1.5 bg-white dark:bg-slate-700 rounded-md shadow-sm">${icon('pencil','w-3.5 h-3.5')}</button>`:''}<button data-del="${m.id}" class="p-1.5 bg-white dark:bg-slate-700 rounded-md shadow-sm">${icon('trash-2','w-3.5 h-3.5 text-red-400')}</button><button data-reactquick="${m.id}" class="p-1.5 bg-white dark:bg-slate-700 rounded-md shadow-sm">❤️</button></div>
    </div></div>`;
  }).join('') + '<div id="bottom"></div>';
  box.querySelectorAll('[data-del]').forEach(b=>b.onclick=()=>deleteMessage(b.dataset.del));
  box.querySelectorAll('[data-edit]').forEach(b=>b.onclick=()=>editMessage(b.dataset.edit));
  box.querySelectorAll('[data-reply]').forEach(b=>b.onclick=()=>replyMessage(b.dataset.reply));
  box.querySelectorAll('[data-reactquick]').forEach(b=>b.onclick=()=>react(b.dataset.reactquick,'❤️'));
  box.querySelectorAll('[data-react]').forEach(b=>b.onclick=()=>{const [id,e]=b.dataset.react.split('|'); react(id,e);});
  $('bottom')?.scrollIntoView({behavior:'smooth'}); refreshIcons();
}
function filePreview(m){ if(!m.file_url) return ''; const url=apiFile(m.file_url); if(m.message_type==='image') return `<img src="${url}" class="max-w-[250px] max-h-[250px] rounded-lg object-cover mb-2 cursor-pointer" onclick="window.open('${url}','_blank')">`; if(m.message_type==='video') return `<video src="${url}" controls class="max-w-[280px] max-h-[200px] rounded-lg mb-2"></video>`; if(m.message_type==='voice') return `<audio src="${url}" controls class="max-w-[260px] mb-2"></audio>`; if(m.message_type==='file') return `<a href="${url}" download class="mb-2 flex items-center gap-3 p-3 bg-slate-50 dark:bg-slate-700/50 rounded-lg">${icon('file-text','w-5 h-5 text-emerald-600')}<span class="text-sm truncate">${esc(m.file_name||'ملف')}</span>${icon('download','w-4 h-4')}</a>`; return ''; }

async function sendMessage(e){ e.preventDefault(); const input=$('msgInput'); const text=input.value.trim(); if(!text && !state.editing) return; try{ if(state.editing){ await request('/messages/'+state.editing,{method:'PUT',body:{text}}); state.editing=null; } else { await request('/messages',{method:'POST',body:{receiver_id:state.selectedUser.id,text,message_type:'text',reply_to:state.replying}}); } input.value=''; state.replying=null; await loadMessages(); await loadData(); renderChatWindow(); }catch(err){ alert(err.detail||'تعذر إرسال الرسالة'); } }
async function uploadFile(){ const f=$('fileInput').files[0]; if(!f) return; const fd=new FormData(); fd.append('file',f); try{ const up=await request('/upload',{method:'POST',body:fd}); const mt=up.category==='image'?'image':up.category==='video'?'video':up.category==='voice'?'voice':'file'; await request('/messages',{method:'POST',body:{receiver_id:state.selectedUser.id,text:mt==='image'?'صورة':mt==='video'?'فيديو':mt==='voice'?'رسالة صوتية':f.name,message_type:mt,file_url:up.storage_path,file_name:up.original_filename,file_type:up.content_type}}); await loadMessages(); renderMessages(); }catch(e){ alert(e.detail||'فشل رفع الملف'); } }
async function deleteMessage(id){ const mine = state.messages.find(m=>m.id===id)?.sender_id===state.user.id; const mode = mine && confirm('اختر OK للحذف للجميع، أو Cancel للحذف لديك فقط') ? 'for_all' : 'for_me'; await request(`/messages/${id}/delete`,{method:'POST',body:{mode}}); await loadMessages(); renderMessages(); }
function editMessage(id){ const m=state.messages.find(x=>x.id===id); if(!m) return; state.editing=id; $('msgInput').value=m.text; $('msgInput').placeholder='عدّل الرسالة...'; $('msgInput').focus(); }
function replyMessage(id){ state.replying=id; const m=state.messages.find(x=>x.id===id); $('actionBar').innerHTML=`<div class="px-4 py-3 bg-slate-100 dark:bg-slate-800 border-t border-slate-200 dark:border-slate-700 flex items-center gap-3"><div class="flex-1 border-r-2 border-emerald-500 pr-3"><p class="text-xs font-medium text-emerald-700">الرد على رسالة</p><p class="text-sm text-slate-600 truncate">${esc(m?.text||m?.file_name||'رسالة')}</p></div><button id="cancelReply" class="p-1.5 hover:bg-slate-200 rounded-lg">${icon('x')}</button></div>`; $('cancelReply').onclick=()=>{state.replying=null;$('actionBar').innerHTML='';}; refreshIcons(); }
async function react(id,emoji){ await request(`/messages/${id}/react`,{method:'POST',body:{emoji}}); await loadMessages(); renderMessages(); }
async function clearChat(){ if(!confirm('هل تريد حذف جميع رسائل هذه المحادثة نهائياً؟')) return; await request('/messages/conversation/'+state.selectedUser.id,{method:'DELETE'}); state.messages=[]; renderMessages(); }
async function setNickname(){ const nick=prompt('اسم العرض الجديد، اتركه فارغاً لحذف اللقب:', state.selectedUser.display_name||''); if(nick===null) return; if(nick.trim()) await request('/nicknames/'+state.selectedUser.id,{method:'PUT',body:{nickname:nick.trim()}}); else await request('/nicknames/'+state.selectedUser.id,{method:'DELETE'}); await loadData(); state.selectedUser.display_name = nick.trim() || state.selectedUser.name; render(); }

function renderCustomize(){
  const modal=document.createElement('div'); modal.className='fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4';
  modal.innerHTML=`<div class="modal-card bg-white dark:bg-slate-800 rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 p-6 max-w-md w-full"><h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100 mb-4">تخصيص المظهر</h3><div class="space-y-4"><label class="block text-sm text-slate-600">لون الصورة الرمزية<input id="setAvatar" type="color" value="${state.settings.avatarColor}" class="block w-full h-10 mt-1"></label><label class="block text-sm text-slate-600">لون خلفية المحادثة<input id="setBg" type="color" value="${state.settings.chatBgColor||'#f8fafc'}" class="block w-full h-10 mt-1"></label><label class="block text-sm text-slate-600">لون رسائلي<input id="setSent" type="color" value="${state.settings.sentBubbleColor||'#d1fae5'}" class="block w-full h-10 mt-1"></label><label class="block text-sm text-slate-600">لون رسائل الطرف الآخر<input id="setRecv" type="color" value="${state.settings.receivedBubbleColor||'#ffffff'}" class="block w-full h-10 mt-1"></label><select id="setFont" class="w-full px-3 py-2 rounded-lg border"><option value="base">حجم عادي</option><option value="lg">كبير</option><option value="xl">كبير جداً</option><option value="sm">صغير</option></select></div><div class="flex gap-3 justify-end mt-6"><button id="resetCustom" class="px-4 py-2 rounded-lg border">إعادة ضبط</button><button id="closeCustom" class="px-4 py-2 rounded-lg bg-emerald-600 text-white">حفظ</button></div></div>`;
  document.body.appendChild(modal); $('setFont').value=state.settings.fontSize;
  $('closeCustom').onclick=()=>{ state.settings.avatarColor=$('setAvatar').value; state.settings.chatBgColor=$('setBg').value; state.settings.sentBubbleColor=$('setSent').value; state.settings.receivedBubbleColor=$('setRecv').value; state.settings.fontSize=$('setFont').value; saveSettings(); modal.remove(); render(); };
  $('resetCustom').onclick=()=>{ state.settings={...defaults}; saveSettings(); modal.remove(); render(); };
}

async function renderAdmin(){
  app.innerHTML=`<section class="min-h-screen overflow-y-auto bg-slate-50 dark:bg-slate-900 p-6"><div class="max-w-6xl mx-auto"><div class="flex items-center justify-between mb-6"><h1 class="text-3xl font-light text-slate-900 dark:text-slate-100">لوحة التحكم</h1><button id="backChat" class="px-4 py-2 rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700">العودة للمحادثات</button></div><div id="adminContent"></div></div></section>`;
  $('backChat').onclick=()=>{state.route='chat'; render();};
  try{ const [stats,users]=await Promise.all([request('/admin/stats'),request('/admin/users')]); $('adminContent').innerHTML=`<div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">${[['المستخدمون',stats.total_users],['المتصلون',stats.online_users],['الرسائل',stats.total_messages],['الملفات',stats.total_files]].map(x=>`<div class="bg-white dark:bg-slate-800 rounded-xl p-5 border border-slate-200 dark:border-slate-700"><p class="text-sm text-slate-500">${x[0]}</p><p class="text-3xl font-semibold text-emerald-600">${x[1]}</p></div>`).join('')}</div><div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden"><table class="w-full text-sm"><thead class="bg-slate-50 dark:bg-slate-700"><tr><th class="p-3 text-right">الاسم</th><th class="p-3 text-right">البريد</th><th class="p-3">الدور</th><th class="p-3">الرسائل</th><th class="p-3">إجراء</th></tr></thead><tbody>${users.map(u=>`<tr class="border-t border-slate-100 dark:border-slate-700"><td class="p-3">${esc(u.name)}</td><td class="p-3">${esc(u.email)}</td><td class="p-3 text-center">${u.role}</td><td class="p-3 text-center">${u.message_count}</td><td class="p-3 text-center">${u.role==='admin'?'—':`<button data-deluser="${u.id}" class="text-red-600">حذف</button>`}</td></tr>`).join('')}</tbody></table></div>`; document.querySelectorAll('[data-deluser]').forEach(b=>b.onclick=async()=>{ if(confirm('حذف المستخدم؟')){ await request('/admin/users/'+b.dataset.deluser,{method:'DELETE'}); renderAdmin(); }}); }catch(e){ $('adminContent').innerHTML='<div class="p-4 bg-red-50 text-red-600 rounded-lg">غير مصرح أو حدث خطأ.</div>'; }
}

init();
