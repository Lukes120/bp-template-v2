const CACHE_NAME = 'bp-template-v2-v64';
// Base path derivato dal SW stesso: funziona sia se servito da /bp-template-v2/
// che dalla root del vhost (es. http://bptemplate.ecotelitalia.it:8080/).
const BASE = self.location.pathname.replace(/\/[^\/]*$/, '');
const ASSETS = [
  BASE + '/',
  BASE + '/index.html',
  BASE + '/css/app.css',
  BASE + '/js/utils.js',
  BASE + '/js/categorie.js',
  BASE + '/js/calc.js',
  BASE + '/js/api.js',
  BASE + '/js/views.js',
  BASE + '/js/guida.js',
  BASE + '/js/app.js',
  BASE + '/logo.png',
  BASE + '/cursor-loading.svg',
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(ASSETS)));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', e => {
  if (e.request.url.includes('/api/')) {
    e.respondWith(fetch(e.request));
    return;
  }
  e.respondWith(
    fetch(e.request)
      .then(res => {
        const clone = res.clone();
        caches.open(CACHE_NAME).then(cache => cache.put(e.request, clone));
        return res;
      })
      .catch(() => caches.match(e.request))
  );
});
