/**
 * Citizen dashboard biometric enrollment (Android WebView only).
 */
(function () {
  'use strict';

  var bridge = window.MDRRMOBiometric || null;
  var citizenEmail = window.MDRRMO_CITIZEN_EMAIL || '';

  function hasBridge() {
    return bridge && typeof bridge.isBiometricAvailable === 'function';
  }

  function normalizeBridgeResult(result) {
    if (typeof result === 'string') {
      try {
        return JSON.parse(result);
      } catch (e) {
        return result;
      }
    }
    return result;
  }

  function callBridge(method) {
    if (!bridge || typeof bridge[method] !== 'function') {
      return Promise.resolve(null);
    }
    try {
      var result = normalizeBridgeResult(bridge[method]());
      if (result && typeof result.then === 'function') {
        return result.then(normalizeBridgeResult);
      }
      return Promise.resolve(result);
    } catch (e) {
      return Promise.reject(e);
    }
  }

  function setStatus(text) {
    var el = document.getElementById('biometricStatusText');
    if (el) el.textContent = text;
  }

  function initBiometricSettings() {
    var section = document.getElementById('biometricSettings');
    if (!section || !hasBridge()) return;

    section.hidden = false;

    var enableBtn = document.getElementById('btnEnableBiometric');
    var disableBtn = document.getElementById('btnDisableBiometric');

    Promise.all([
      callBridge('isBiometricAvailable'),
      callBridge('hasBiometricCredential'),
      callBridge('getRegisteredEmail')
    ]).then(function (results) {
      var available = !!results[0];
      var hasCredential = !!results[1];
      var registeredEmail = results[2] || '';

      if (!available) {
        setStatus('Fingerprint hardware is not available on this device.');
        if (enableBtn) enableBtn.hidden = true;
        if (disableBtn) disableBtn.hidden = true;
        return;
      }

      if (hasCredential && registeredEmail.toLowerCase() === citizenEmail.toLowerCase()) {
        setStatus('Fingerprint login is enabled for this account on this device.');
        if (enableBtn) enableBtn.hidden = true;
        if (disableBtn) disableBtn.hidden = false;
        return;
      }

      setStatus('Use fingerprint for faster sign-in on this device.');
      if (enableBtn) enableBtn.hidden = false;
      if (disableBtn) disableBtn.hidden = true;
    }).catch(function () {
      setStatus('Unable to read fingerprint settings.');
    });

    if (enableBtn) {
      enableBtn.addEventListener('click', function () {
        enableBtn.disabled = true;
        callBridge('enableBiometric').then(function (result) {
          if (result && result.success) {
            setStatus('Fingerprint login enabled.');
            enableBtn.hidden = true;
            if (disableBtn) disableBtn.hidden = false;
          } else {
            setStatus('Could not enable fingerprint login. Try again after signing in with your password.');
          }
          enableBtn.disabled = false;
        }).catch(function () {
          setStatus('Could not enable fingerprint login.');
          enableBtn.disabled = false;
        });
      });
    }

    if (disableBtn) {
      disableBtn.addEventListener('click', function () {
        disableBtn.disabled = true;
        callBridge('disableBiometric').then(function (result) {
          if (result && result.success) {
            setStatus('Fingerprint login disabled for this device.');
            disableBtn.hidden = true;
            if (enableBtn) enableBtn.hidden = false;
          } else {
            setStatus('Could not disable fingerprint login.');
          }
          disableBtn.disabled = false;
        }).catch(function () {
          setStatus('Could not disable fingerprint login.');
          disableBtn.disabled = false;
        });
      });
    }
  }

  document.addEventListener('DOMContentLoaded', initBiometricSettings);
})();
