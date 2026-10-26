const CACHE_NAME = 'enecom-shell-v4';
const SHELL = [
  './login.php',
  './assets/css/app.css',
  './assets/js/app.js',
  './assets/js/support.js',
  './manifest.webmanifest',
  './assets/icons/icon-192.png',
  './assets/icons/icon-512.png',
  './assets/img/enecom-logo.png'
];
self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || url.origin !== self.location.origin) return;
  // Only cache the static shell. Never cache authenticated/private API or dashboard responses.
  if (url.pathname.includes('/assets/') || url.pathname.endsWith('/manifest.webmanifest')) {
    event.respondWith(caches.match(event.request).then(cached => cached || fetch(event.request)));
  }
});
