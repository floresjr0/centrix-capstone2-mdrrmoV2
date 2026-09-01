/**
 * Syncs public evacuation center data into native offline storage (CENTRIX Android app).
 * Keeps the last valid cache when sync fails. No private user data is stored.
 */
(function () {
  'use strict';

  var SYNC_MIN_INTERVAL_MS = 30000;
  var BRIDGE_POLL_MS = 500;
  var BRIDGE_POLL_MAX = 80;
  var lastAttemptMs = 0;
  var bridgePollTimer = null;

  function publicApiUrl() {
    var path = window.location.pathname || '';
    if (path.indexOf('/pages/') !== -1) {
      return '../api/public/evacuation_centers.php';
    }
    return './api/public/evacuation_centers.php';
  }

  function centersApiUrl() {
    var path = window.location.pathname || '';
    if (path.indexOf('/pages/') !== -1) {
      return 'centers.php?action=list_available';
    }
    return 'pages/centers.php?action=list_available';
  }

  function bridgeReady() {
    return typeof median_offline !== 'undefined'
      && median_offline !== null
      && typeof median_offline.updatePublicCache === 'function';
  }

  function isOnline() {
    if (typeof navigator.onLine === 'boolean') return navigator.onLine;
    return true;
  }

  function sanitizeCenters(centers) {
    if (!Array.isArray(centers)) return [];
    return centers.map(function (c) {
      return {
        id: c.id,
        name: c.name || '',
        address: c.address || '',
        status: c.status || '',
        max_capacity_people: c.max_capacity_people,
        max_capacity_families: c.max_capacity_families,
        current_occupancy: c.current_occupancy,
        slots_remaining: c.slots_remaining,
        barangay: c.barangay || '',
        coordinator_name: c.coordinator_name || '',
        coordinator_contact: c.coordinator_contact || ''
      };
    });
  }

  function fetchJson(url, credentials) {
    return fetch(url, {
      method: 'GET',
      credentials: credentials || 'omit',
      headers: { Accept: 'application/json' },
      cache: 'no-store'
    }).then(function (response) {
      return response.json().catch(function () { return null; }).then(function (body) {
        if (!response.ok || !body || !body.ok || !Array.isArray(body.centers)) {
          return null;
        }
        return body;
      });
    }).catch(function () { return null; });
  }

  function fetchCentersPayload() {
    var path = window.location.pathname || '';
    var tries = [];

    if (path.indexOf('/pages/') !== -1) {
      tries.push(fetchJson(centersApiUrl(), 'same-origin'));
      tries.push(fetchJson(publicApiUrl(), 'omit'));
    } else {
      tries.push(fetchJson(publicApiUrl(), 'omit'));
      tries.push(fetchJson(centersApiUrl(), 'same-origin'));
    }

    return tries.reduce(function (chain, next) {
      return chain.then(function (body) {
        if (body && body.centers && body.centers.length) return body;
        return next;
      });
    }, Promise.resolve(null));
  }

  function persistPayload(body) {
    if (!body || !Array.isArray(body.centers) || !body.centers.length) return false;
    var payload = {
      lastSuccessfulSyncTimestamp: body.synced_at || new Date().toISOString(),
      centers: sanitizeCenters(body.centers)
    };
    try {
      median_offline.updatePublicCache(JSON.stringify(payload));
      return true;
    } catch (e) {
      return false;
    }
  }

  function syncNow(force) {
    if (!bridgeReady() || !isOnline()) return false;
    var now = Date.now();
    if (!force && now - lastAttemptMs < SYNC_MIN_INTERVAL_MS) return false;
    lastAttemptMs = now;

    fetchCentersPayload().then(function (body) {
      if (body) persistPayload(body);
    });

    return true;
  }

  function waitForBridgeAndSync(force) {
    if (bridgeReady()) {
      syncNow(force);
      return;
    }
    var attempts = 0;
    if (bridgePollTimer) clearInterval(bridgePollTimer);
    bridgePollTimer = setInterval(function () {
      attempts += 1;
      if (bridgeReady()) {
        clearInterval(bridgePollTimer);
        bridgePollTimer = null;
        syncNow(true);
        return;
      }
      if (attempts >= BRIDGE_POLL_MAX) {
        clearInterval(bridgePollTimer);
        bridgePollTimer = null;
      }
    }, BRIDGE_POLL_MS);
  }

  function boot() {
    waitForBridgeAndSync(true);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) waitForBridgeAndSync(false);
    });
    window.addEventListener('pageshow', function () {
      waitForBridgeAndSync(false);
    });
  }

  window.addEventListener('median_library_ready', function () {
    waitForBridgeAndSync(true);
  });
  window.addEventListener('gonative_library_ready', function () {
    waitForBridgeAndSync(true);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  window.MDRRMOOfflineCacheSync = {
    syncNow: function (force) {
      waitForBridgeAndSync(!!force);
    }
  };
})();
