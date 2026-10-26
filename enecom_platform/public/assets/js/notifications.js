(() => {
  const root = document.querySelector('[data-notification-center]'); if (!root) return;
  const api = root.dataset.api, csrf = root.dataset.csrf;
  const list = root.querySelector('[data-notification-list]'), count = root.querySelector('[data-notification-count]');
  const enable = root.querySelector('[data-enable-notifications]'), readAll = root.querySelector('[data-read-all]');
  let newestSeen = 0, firstLoad = true, busy = false;
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function render(items) {
    if (!items.length) { list.innerHTML = '<p class="muted notification-empty">You have no notifications yet.</p>'; return; }
    list.innerHTML = items.map(n => `<a class="notification-item ${n.is_read?'read':'unread'}" href="${esc(n.target_url)}" data-notice-id="${n.id}"><span class="notification-dot"></span><span><strong>${esc(n.title)}</strong><small>${esc(n.message)}</small><time>${esc(n.created_at)}</time></span></a>`).join('');
    list.querySelectorAll('[data-notice-id]').forEach(a => a.addEventListener('click', () => markRead(Number(a.dataset.noticeId))));
  }
  async function markRead(id) { try { await fetch(api,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'read',id,csrf_token:csrf}),credentials:'same-origin'}); } catch(e){} }
  async function poll() { if (busy || document.hidden) return; busy=true; try { const res=await fetch(api+'?t='+Date.now(),{credentials:'same-origin',cache:'no-store'}); if(!res.ok) return; const data=await res.json(); if(!data.ok) return; count.textContent=String(data.unread); count.hidden=data.unread===0; render(data.items); const fresh=data.items.filter(n=>n.id>newestSeen); if(!firstLoad && fresh.length && 'Notification' in window && Notification.permission==='granted') fresh.slice().reverse().forEach(n=>{try{new Notification(n.title,{body:n.message,icon:root.dataset.icon,tag:'enecom-notice-'+n.id});}catch(e){}}); newestSeen=Math.max(newestSeen,...data.items.map(n=>n.id),0); firstLoad=false; } catch(e) { /* network can be temporarily unavailable */ } finally {busy=false;} }
  enable?.addEventListener('click', async()=>{ if(!('Notification' in window)){enable.textContent='Browser notifications unavailable';return;} const p=await Notification.requestPermission(); enable.textContent=p==='granted'?'Desktop notifications enabled':(p==='denied'?'Notifications blocked in browser settings':'Enable desktop notifications'); });
  readAll?.addEventListener('click',async()=>{try{await fetch(api,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'read_all',csrf_token:csrf}),credentials:'same-origin'}); await poll();}catch(e){}});
  poll(); setInterval(poll,20000); document.addEventListener('visibilitychange',()=>{if(!document.hidden)poll();});
})();
