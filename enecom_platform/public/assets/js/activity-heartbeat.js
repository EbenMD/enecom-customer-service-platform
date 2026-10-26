(() => {
  const cfg = window.ENE_ACTIVITY_HEARTBEAT;
  if (!cfg || !cfg.api || !cfg.csrf) return;
  const ping = async () => {
    try {
      await fetch(cfg.api, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: cfg.csrf })
      });
    } catch (_) { /* Retry on the next interval. */ }
  };
  ping();
  window.setInterval(ping, 20000);
})();
