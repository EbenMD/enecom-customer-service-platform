(() => {
  const cfg = window.ENE_SUPPORT;
  if (!cfg) return;
  const $ = (id) => document.getElementById(id);
  const app = $('supportApp'), list = $('conversationList'), messages = $('chatMessages');
  const notice = $('notice'), title = $('chatTitle'), subtitle = $('chatSubtitle');
  const replyForm = $('replyForm'), replyBody = $('replyBody'), sendBtn = $('sendBtn');
  const statusSelect = $('statusSelect');
  let activeId = null, lastMessageId = 0, listTimer = null, messageTimer = null, sending = false, notificationsEnabled = false, listHasLoaded = false;
  const seenConversationMessages = new Map();
  const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const timeLabel = (s) => { const d = new Date(String(s).replace(' ', 'T') + (String(s).includes('Z') ? '' : 'Z')); return Number.isNaN(d.getTime()) ? '' : d.toLocaleString([], {month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'}); };
  function showNotice(text, type='info') { notice.innerHTML = `<div class="notice ${type}">${escapeHtml(text)}</div>`; if(type!=='error') setTimeout(()=>{ if(notice.textContent===text) notice.innerHTML=''; },4500); }
  async function api(action, opts={}) {
    const method = opts.method || 'GET';
    let url = `${cfg.base}/support-api.php?action=${encodeURIComponent(action)}`;
    const init = {method,headers:{'X-Requested-With':'XMLHttpRequest'}};
    if (method === 'GET' && opts.params) url += '&' + new URLSearchParams(opts.params).toString();
    if (method === 'POST') { const data = new FormData(); data.append('csrf_token',cfg.csrf); Object.entries(opts.data || {}).forEach(([k,v])=>data.append(k,v)); init.body=data; }
    const res = await fetch(url,init); let payload;
    try { payload=await res.json(); } catch { throw new Error('The server returned an unexpected response.'); }
    if(!res.ok || payload.error) throw new Error(payload.error || 'Request failed.');
    return payload;
  }
  async function enableNotifications() {
    if (!('Notification' in window)) { showNotice('This browser does not support desktop notifications.', 'error'); return; }
    const permission = Notification.permission === 'granted' ? 'granted' : await Notification.requestPermission();
    notificationsEnabled = permission === 'granted';
    const btn = $('notificationsBtn');
    if (btn) btn.textContent = notificationsEnabled ? 'Notifications enabled' : 'Enable notifications';
    showNotice(notificationsEnabled ? 'Desktop notifications are enabled on this device.' : 'Notifications were not enabled. You can change this in browser site settings.', notificationsEnabled ? 'success' : 'info');
  }
  function notifyConversationActivity(c) {
    const id = Number(c.last_message_id || 0);
    if (!id) return;
    const previous = seenConversationMessages.get(Number(c.id));
    seenConversationMessages.set(Number(c.id), id);
    if (!listHasLoaded || previous === undefined || previous === id) return;
    if (Number(c.last_message_sender_id) === Number(cfg.userId)) return;
    const incoming = cfg.staff ? c.last_message_direction === 'inbound' : c.last_message_direction === 'outbound';
    if (!incoming) return;
    if (notificationsEnabled && 'Notification' in window && Notification.permission === 'granted') {
      const n = new Notification('Enecom Support — New message', {body: `${c.subject || 'Customer conversation'}: ${String(c.last_message || 'New message').slice(0, 120)}`, icon: `${cfg.base}/assets/icons/icon-192.png`, tag: `enecom-message-${id}`});
      n.onclick = () => { window.focus(); openConversation(Number(c.id)); n.close(); };
    }
    if (document.hidden) showNotice('New support message received.', 'info');
  }
  function renderList(items) {
    if (!items.length) { list.innerHTML='<div class="chat-empty">No conversations yet.</div>'; return; }
    list.innerHTML=items.map(c=>`<button type="button" class="conversation ${Number(c.id)===Number(activeId)?'selected':''}" data-id="${Number(c.id)}"><span class="line"><strong>${escapeHtml(c.subject || 'Support conversation')}</strong><span class="channel-label">${escapeHtml(c.status)}</span></span>${cfg.staff?`<small>${escapeHtml(c.customer_name || 'Unknown customer')}</small>`:''}<div class="preview">${escapeHtml(c.last_message || 'No messages yet')}</div><small>${escapeHtml(timeLabel(c.last_activity_at))}${Number(c.unread)>0?' • New inbound message':''}</small></button>`).join('');
  }
  async function loadList() {
    try { const data=await api('list'); const items=data.conversations || []; items.forEach(notifyConversationActivity); listHasLoaded=true; renderList(items); }
    catch(e) { if(!list.querySelector('.conversation')) list.innerHTML=`<div class="chat-empty">${escapeHtml(e.message)}</div>`; }
  }
  function addMessage(m) {
    if(messages.querySelector('.chat-empty')) messages.innerHTML='';
    const mine=Number(m.sender_user_id)===Number(cfg.userId);
    const node=document.createElement('div'); node.className=`message ${mine?'mine':'theirs'}`;
    node.innerHTML=`<div class="sender">${escapeHtml(m.sender_name || (m.direction==='outbound'?'Enecom Support':'Customer'))}</div><div class="body">${escapeHtml(m.body || '')}</div><time>${escapeHtml(timeLabel(m.created_at))}${m.delivery_status==='failed'?' • Failed': ''}</time>`;
    messages.appendChild(node); messages.scrollTop=messages.scrollHeight;
  }
  async function openConversation(id) {
    activeId=Number(id); lastMessageId=0; app.classList.remove('show-list');
    replyBody.disabled=false; sendBtn.disabled=false; title.textContent='Loading conversation…'; subtitle.textContent=''; messages.innerHTML='<div class="chat-empty">Loading messages…</div>';
    if(messageTimer) clearInterval(messageTimer);
    await loadConversation(true);
    messageTimer=setInterval(()=>loadConversation(false),3000);
    await loadList();
  }
  async function loadConversation(initial) {
    if(!activeId) return;
    try {
      const data=await api('messages',{params:{id:activeId,after:initial?0:lastMessageId}});
      const c=data.conversation;
      title.textContent=c.subject || 'Support conversation';
      subtitle.textContent=cfg.staff?`${c.customer_name || 'Customer'}${c.customer_email?' • '+c.customer_email:''}`:'Enecom IT Solutions GH Support';
      const channel=$('channelLabel'); if(channel) channel.textContent=String(c.channel||'web_chat').replace('_',' ');
      if(statusSelect && initial) statusSelect.value=c.status;
      (data.messages||[]).forEach(m=>{ addMessage(m); lastMessageId=Math.max(lastMessageId,Number(m.id)); });
      if(initial && !(data.messages||[]).length) messages.innerHTML='<div class="chat-empty">No messages yet. Send a message to begin.</div>';
    } catch(e) { if(initial) { messages.innerHTML=`<div class="chat-empty">${escapeHtml(e.message)}</div>`; showNotice(e.message,'error'); } }
  }
  list.addEventListener('click',e=>{const b=e.target.closest('[data-id]');if(b)openConversation(b.dataset.id);});
  $('refreshBtn')?.addEventListener('click',loadList);
  $('notificationsBtn')?.addEventListener('click',enableNotifications);
  if ('Notification' in window && Notification.permission === 'granted') { notificationsEnabled = true; const btn=$('notificationsBtn'); if(btn) btn.textContent='Notifications enabled'; }
  $('backBtn')?.addEventListener('click',()=>{app.classList.add('show-list');if(messageTimer)clearInterval(messageTimer);loadList();});
  replyForm.addEventListener('submit',async e=>{
    e.preventDefault(); if(!activeId||sending)return; const body=replyBody.value.trim(); if(!body)return;
    sending=true;sendBtn.disabled=true;
    try { await api('send',{method:'POST',data:{conversation_id:activeId,body}});replyBody.value='';await loadConversation(false);await loadList(); }
    catch(err){showNotice(err.message,'error');} finally {sending=false;sendBtn.disabled=!activeId;replyBody.focus();}
  });
  $('newConversationForm')?.addEventListener('submit',async e=>{
    e.preventDefault();const form=e.currentTarget;const fd=new FormData(form);
    try {const result=await api('create',{method:'POST',data:{subject:fd.get('subject'),body:fd.get('body')}});form.reset();showNotice('Conversation started. Enecom support will reply here.','success');await loadList();await openConversation(result.conversation_id);}
    catch(err){showNotice(err.message,'error');}
  });
  statusSelect?.addEventListener('change',async()=>{if(!activeId)return;try{await api('status',{method:'POST',data:{conversation_id:activeId,status:statusSelect.value}});await loadList();showNotice('Conversation status updated.','success');}catch(e){showNotice(e.message,'error');}});
  loadList(); listTimer=setInterval(loadList,5000); window.addEventListener('beforeunload',()=>{clearInterval(listTimer);clearInterval(messageTimer);});
})();
