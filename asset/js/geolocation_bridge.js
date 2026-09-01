/**
 * Geolocation for CENTRIX (Median WebView) and browsers.
 * In the app: shows an Allow / Deny prompt (like the browser), then native GPS permission.
 * In the browser: uses the normal navigator.geolocation permission prompt.
 */
(function () {
  'use strict';

  var POLL_MS = 500;
  var POLL_MAX = 80;
  var pollTimer = null;
  var bridgeInstalled = false;
  var locationAllowed = false;
  var DENIED_KEY = 'mdrrmo_location_denied';

  function isMedianApp() {
    var ua = navigator.userAgent || '';
    return /median|gonative/i.test(ua);
  }

  function bridgeReady() {
    return typeof median_location !== 'undefined'
      && median_location !== null
      && typeof median_location.getCurrentPosition === 'function';
  }

  function promptNativeLocationServices() {
    try {
      if (window.median && window.median.android && window.median.android.geoLocation) {
        window.median.android.geoLocation.promptLocationServices();
      }
    } catch (e) {}
  }

  function wrapPosition(result) {
    return {
      coords: {
        latitude: result.lat,
        longitude: result.lng,
        accuracy: result.accuracy || 0
      },
      timestamp: result.timestamp || Date.now()
    };
  }

  function wrapError(result) {
    var code = (result && result.code) || 1;
    return {
      code: code,
      message: (result && result.message) || 'Location permission denied.',
      PERMISSION_DENIED: 1,
      POSITION_UNAVAILABLE: 2,
      TIMEOUT: 3
    };
  }

  function ensureModalStyles() {
    if (document.getElementById('mdrrmo-geo-perm-styles')) return;
    var style = document.createElement('style');
    style.id = 'mdrrmo-geo-perm-styles';
    style.textContent =
      '#mdrrmoGeoPermBackdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);' +
      'z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px;' +
      'opacity:0;transition:opacity .25s ease;}' +
      '#mdrrmoGeoPermBackdrop.show{opacity:1;}' +
      '#mdrrmoGeoPermCard{background:#fff;border-radius:16px;max-width:340px;width:100%;' +
      'padding:22px 20px 18px;box-shadow:0 12px 40px rgba(0,0,0,.22);transform:translateY(12px);' +
      'transition:transform .25s ease;}' +
      '#mdrrmoGeoPermBackdrop.show #mdrrmoGeoPermCard{transform:translateY(0);}' +
      '#mdrrmoGeoPermTitle{font:700 1.05rem/1.35 Poppins,system-ui,sans-serif;color:#1a1a1a;margin:0 0 8px;}' +
      '#mdrrmoGeoPermMsg{font:400 .88rem/1.5 Poppins,system-ui,sans-serif;color:#555;margin:0 0 18px;}' +
      '#mdrrmoGeoPermActions{display:flex;gap:10px;}' +
      '.mdrrmo-geo-perm-btn{flex:1;border:none;border-radius:10px;padding:11px 12px;' +
      'font:600 .88rem Poppins,system-ui,sans-serif;cursor:pointer;}' +
      '#mdrrmoGeoPermDeny{background:#f3f1ed;color:#5c564a;}' +
      '#mdrrmoGeoPermAllow{background:#c0392e;color:#fff;}';
    document.head.appendChild(style);
  }

  function showLocationPermissionModal(title, message, callback) {
    ensureModalStyles();

    var existing = document.getElementById('mdrrmoGeoPermBackdrop');
    if (existing) existing.remove();

    var backdrop = document.createElement('div');
    backdrop.id = 'mdrrmoGeoPermBackdrop';
    backdrop.setAttribute('role', 'dialog');
    backdrop.setAttribute('aria-modal', 'true');
    backdrop.innerHTML =
      '<div id="mdrrmoGeoPermCard">' +
        '<p id="mdrrmoGeoPermTitle"></p>' +
        '<p id="mdrrmoGeoPermMsg"></p>' +
        '<div id="mdrrmoGeoPermActions">' +
          '<button type="button" class="mdrrmo-geo-perm-btn" id="mdrrmoGeoPermDeny">Deny</button>' +
          '<button type="button" class="mdrrmo-geo-perm-btn" id="mdrrmoGeoPermAllow">Allow</button>' +
        '</div>' +
      '</div>';

    document.body.appendChild(backdrop);
    document.getElementById('mdrrmoGeoPermTitle').textContent = title;
    document.getElementById('mdrrmoGeoPermMsg').textContent = message;

    function close(result) {
      backdrop.classList.remove('show');
      setTimeout(function () {
        if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
        callback(result);
      }, 220);
    }

    document.getElementById('mdrrmoGeoPermDeny').addEventListener('click', function () {
      close(false);
    });
    document.getElementById('mdrrmoGeoPermAllow').addEventListener('click', function () {
      close(true);
    });

    requestAnimationFrame(function () {
      backdrop.classList.add('show');
    });
  }

  function requestNativePermission(callback) {
    if (!bridgeReady() || typeof median_location.requestPermission !== 'function') {
      callback({ success: true });
      return;
    }

    var cb = '_mdrrmo_perm_' + Math.random().toString(36).slice(2);
    window[cb] = function (result) {
      delete window[cb];
      callback(result || { success: false, code: 1, message: 'Location permission denied.' });
    };

    try {
      median_location.requestPermission(cb);
    } catch (e) {
      delete window[cb];
      callback({ success: false, code: 2, message: String(e) });
    }
  }

  /**
   * Web-like Allow/Deny flow, then getCurrentPosition.
   * options: { title, message, success, error, geoOptions, force }
   */
  function requestAccess(options) {
    options = options || {};
    var onSuccess = options.success;
    var onError = options.error;
    var title = options.title || 'Allow location access?';
    var message = options.message || 'MDRRMO needs your location for evacuation services.';
    var geoOptions = options.geoOptions || { enableHighAccuracy: true };

    function runGeolocation() {
      if (!navigator.geolocation) {
        if (onError) onError(wrapError({ code: 2, message: 'Geolocation not supported.' }));
        return;
      }
      navigator.geolocation.getCurrentPosition(
        function (pos) {
          locationAllowed = true;
          if (onSuccess) onSuccess(pos);
        },
        function (err) {
          if (onError) onError(err);
        },
        geoOptions
      );
    }

    function afterUserAllowed() {
      requestNativePermission(function (result) {
        if (!result || result.success === false || result.code === 1) {
          try { sessionStorage.setItem(DENIED_KEY, '1'); } catch (e) {}
          if (onError) onError(wrapError(result || { code: 1 }));
          return;
        }
        locationAllowed = true;
        try { sessionStorage.removeItem(DENIED_KEY); } catch (e) {}
        runGeolocation();
      });
    }

    if (!isMedianApp()) {
      runGeolocation();
      return;
    }

    if (locationAllowed) {
      runGeolocation();
      return;
    }

    if (!options.force) {
      try {
        if (sessionStorage.getItem(DENIED_KEY) === '1') {
          if (onError) onError(wrapError({ code: 1, message: 'Location permission denied.' }));
          return;
        }
      } catch (e) {}
    }

    showLocationPermissionModal(title, message, function (allowed) {
      if (!allowed) {
        try { sessionStorage.setItem(DENIED_KEY, '1'); } catch (e) {}
        if (onError) onError(wrapError({ code: 1, message: 'Location permission denied.' }));
        return;
      }
      afterUserAllowed();
    });
  }

  /**
   * Same Allow/Deny flow, then watchPosition (navigation).
   */
  function requestWatchAccess(options) {
    options = options || {};
    var onSuccess = options.success;
    var onError = options.error;
    var onWatchId = options.onWatchId;
    var title = options.title || 'Allow location access?';
    var message = options.message || 'MDRRMO needs your location to guide you to the nearest evacuation center.';
    var geoOptions = options.geoOptions || { enableHighAccuracy: true, maximumAge: 0, timeout: 8000 };

    function runWatch() {
      if (!navigator.geolocation) {
        if (onError) onError(wrapError({ code: 2, message: 'Geolocation not supported.' }));
        return;
      }
      var watchId = navigator.geolocation.watchPosition(onSuccess, onError, geoOptions);
      locationAllowed = true;
      if (onWatchId) onWatchId(watchId);
    }

    function afterUserAllowed() {
      requestNativePermission(function (result) {
        if (!result || result.success === false || result.code === 1) {
          try { sessionStorage.setItem(DENIED_KEY, '1'); } catch (e) {}
          if (onError) onError(wrapError(result || { code: 1 }));
          return;
        }
        locationAllowed = true;
        try { sessionStorage.removeItem(DENIED_KEY); } catch (e) {}
        runWatch();
      });
    }

    if (!isMedianApp()) {
      runWatch();
      return;
    }

    if (locationAllowed) {
      runWatch();
      return;
    }

    showLocationPermissionModal(title, message, function (allowed) {
      if (!allowed) {
        try { sessionStorage.setItem(DENIED_KEY, '1'); } catch (e) {}
        if (onError) onError(wrapError({ code: 1, message: 'Location permission denied.' }));
        return;
      }
      afterUserAllowed();
    });
  }

  function installBridgeGeolocation() {
    if (bridgeInstalled || !bridgeReady()) return false;

    navigator.geolocation = {
      getCurrentPosition: function (success, error, options) {
        promptNativeLocationServices();
        var cb = '_mdrrmo_loc_' + Math.random().toString(36).slice(2);
        window[cb] = function (result) {
          delete window[cb];
          if (result && result.success) {
            if (typeof success === 'function') success(wrapPosition(result));
          } else if (typeof error === 'function') {
            error(wrapError(result));
          }
        };
        try {
          median_location.getCurrentPosition(cb);
        } catch (e) {
          delete window[cb];
          if (typeof error === 'function') error(wrapError({ code: 2, message: String(e) }));
        }
      },
      watchPosition: function (success, error, options) {
        promptNativeLocationServices();
        var cb = '_mdrrmo_loc_watch_' + Math.random().toString(36).slice(2);
        window[cb] = function (result) {
          if (result && result.success) {
            if (typeof success === 'function') success(wrapPosition(result));
          } else if (typeof error === 'function') {
            error(wrapError(result));
          }
        };
        try {
          median_location.startWatch(cb);
        } catch (e) {
          delete window[cb];
          if (typeof error === 'function') error(wrapError({ code: 2, message: String(e) }));
        }
        return cb;
      },
      clearWatch: function (watchId) {
        if (watchId && window[watchId]) delete window[watchId];
        try { median_location.stopWatch(); } catch (e) {}
      }
    };

    bridgeInstalled = true;
    try {
      window.dispatchEvent(new Event('mdrrmo_geolocation_ready'));
    } catch (e) {}
    return true;
  }

  function boot() {
    return installBridgeGeolocation();
  }

  function waitForBridgeAndBoot() {
    if (boot()) return;
    if (pollTimer) return;
    var attempts = 0;
    pollTimer = setInterval(function () {
      attempts += 1;
      if (boot() || attempts >= POLL_MAX) {
        clearInterval(pollTimer);
        pollTimer = null;
      }
    }, POLL_MS);
  }

  function runWhenReady(fn) {
    if (typeof fn !== 'function') return;
    var done = false;
    function run() {
      if (done) return;
      done = true;
      fn();
    }
    if (isMedianApp()) {
      window.addEventListener('mdrrmo_geolocation_ready', run, { once: true });
      waitForBridgeAndBoot();
      setTimeout(run, 10000);
      return;
    }
    boot();
    run();
  }

  waitForBridgeAndBoot();
  window.addEventListener('median_library_ready', boot);
  window.addEventListener('gonative_library_ready', boot);

  window.MDRRMOGeolocationBridge = {
    boot: boot,
    bridgeReady: bridgeReady,
    runWhenReady: runWhenReady,
    requestAccess: requestAccess,
    requestWatchAccess: requestWatchAccess,
    clearDenied: function () {
      locationAllowed = false;
      try { sessionStorage.removeItem(DENIED_KEY); } catch (e) {}
    }
  };
})();
