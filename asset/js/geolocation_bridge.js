/**
 * Uses the native Android location bridge when the page is not a secure context (HTTP LAN)
 * or when median_location is available in the CENTRIX app WebView.
 */
(function () {
  'use strict';

  function bridgeReady() {
    return typeof median_location !== 'undefined' && median_location !== null;
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
    return {
      code: (result && result.code) || 2,
      message: (result && result.message) || 'Location unavailable',
      PERMISSION_DENIED: 1,
      POSITION_UNAVAILABLE: 2,
      TIMEOUT: 3
    };
  }

  function installBridgeGeolocation() {
    if (!bridgeReady()) return false;

    navigator.geolocation = {
      getCurrentPosition: function (success, error) {
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
      watchPosition: function (success, error) {
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

    return true;
  }

  function boot() {
    if (!bridgeReady() && window.isSecureContext) return;
    if (installBridgeGeolocation()) return;
    if (!window.isSecureContext) {
      console.warn('Geolocation requires HTTPS or the CENTRIX app location bridge.');
    }
  }

  boot();

  window.MDRRMOGeolocationBridge = { boot: boot, bridgeReady: bridgeReady };
})();
