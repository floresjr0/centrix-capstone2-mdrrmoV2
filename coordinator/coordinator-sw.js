/* Coordinator-only service worker — does not touch citizen caches */
var CACHE_NAME = 'coordinator-cache-v1';
var SHELL_ASSETS = [
  './coordinator-sw.js',
  '../asset/css/coordinator_offline.css',
  '../asset/css/center_walkin.css',
  '../asset/css/center_registrations.css',
  '../asset/css/coordinator_index.css',
  '../asset/js/coordinator/coordinator_offline_db.js',
  '../asset/js/coordinator/coordinator_walkin_offline.js',
  '../img/mdrrmo.png'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      return cache.addAll(SHELL_ASSETS).catch(function () {});
    }).then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (key) {
        if (key.indexOf('coordinator-cache-') === 0 && key !== CACHE_NAME) {
          return caches.delete(key);
        }
        return undefined;
      }));
    }).then(function () { return self.clients.claim(); })
  );
});

function isCoordinatorPage(url) {
  try {
    var path = new URL(url).pathname;
    return path.indexOf('/coordinator/') !== -1;
  } catch (e) {
    return false;
  }
}

function isStaticAsset(url) {
  return /\.(css|js|png|jpg|jpeg|gif|webp|svg|woff2?)(\?|$)/i.test(url);
}

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') return;

  var url = req.url;
  if (!isCoordinatorPage(url) && !isStaticAsset(url)) return;

  if (url.indexOf('/api/') !== -1) return;

  event.respondWith(
    fetch(req).then(function (res) {
      if (res && res.ok) {
        var copy = res.clone();
        caches.open(CACHE_NAME).then(function (cache) {
          cache.put(req, copy);
        });
      }
      return res;
    }).catch(function () {
      return caches.match(req).then(function (cached) {
        if (cached) return cached;
        if (isCoordinatorPage(url)) {
          return caches.match('./index.php');
        }
        throw new Error('offline');
      });
    })
  );
});
