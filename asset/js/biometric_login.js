/**
 * MDRRMO citizen biometric login bridge (Android WebView native integration).
 *
 * Native Android exposes window.MDRRMOBiometric with:
 *   isBiometricAvailable(): Promise<boolean>
 *   hasBiometricCredential(): Promise<boolean>
 *   getRegisteredEmail(): Promise<string|null>
 *   enableBiometric(): Promise<{success:boolean, error?:string}>
 *   authenticateWithBiometric(): Promise<{success:boolean, error?:string}>
 *   disableBiometric(): Promise<{success:boolean, error?:string}>
 *
 * The raw device token never leaves native code except in HTTPS POST bodies
 * to api/device/register.php and api/device/authenticate.php.
 */
(function () {
  'use strict';

  var API_BASE = './api/device/';
  var bridge = window.MDRRMOBiometric || null;

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

  function postJson(url, payload) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(payload)
    }).then(function (res) {
      return res.json().then(function (data) {
        return { ok: res.ok, status: res.status, data: data };
      });
    });
  }

  function showBiometricError(message) {
    var container = document.getElementById('notifContainer');
    if (!container) {
      alert(message);
      return;
    }
    var item = document.createElement('div');
    item.className = 'notif-item';
    item.innerHTML =
      '<div class="notif-icon">⚠</div>' +
      '<div class="notif-body"><ul><li>' + message + '</li></ul></div>';
    container.appendChild(item);
  }

  function setLoading(button, loading) {
    if (!button) return;
    button.disabled = loading;
    button.classList.toggle('loading', loading);
    var text = button.querySelector('.btn-text');
    if (text) {
      text.textContent = loading ? 'Authenticating…' : 'Login with Fingerprint';
    }
  }

  function initBiometricLoginButtons() {
    if (!hasBridge()) return;

    var buttons = document.querySelectorAll('.btn-biometric-login');
    if (!buttons.length) return;

    Promise.all([
      callBridge('isBiometricAvailable'),
      callBridge('hasBiometricCredential')
    ]).then(function (results) {
      var available = !!results[0];
      var hasCredential = !!results[1];
      if (!available || !hasCredential) return;

      return callBridge('getRegisteredEmail').then(function (email) {
        buttons.forEach(function (btn) {
          btn.hidden = false;
          btn.style.display = '';
          if (email) {
            var hint = btn.querySelector('.biometric-email-hint');
            if (hint) {
              hint.textContent = 'Continue as ' + email;
            }
          }
        });
      });
    }).catch(function () {
      /* hide biometric option on bridge errors */
    });

    buttons.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        setLoading(btn, true);

        var bar = document.getElementById('page-loading-bar');
        if (bar) bar.classList.add('active');

        callBridge('authenticateWithBiometric')
          .then(function (result) {
            if (!result || !result.success) {
              var err = (result && result.error) || 'cancelled';
              if (err === 'cancelled') {
                showBiometricError('Fingerprint login cancelled. You can sign in with your password.');
              } else {
                showBiometricError('Fingerprint login failed. Please use your email and password.');
              }
              setLoading(btn, false);
              if (bar) bar.classList.remove('active');
              return;
            }

            if (result.redirect) {
              window.location.href = result.redirect;
              return;
            }

            showBiometricError('Fingerprint login failed. Please use your email and password.');
            setLoading(btn, false);
            if (bar) bar.classList.remove('active');
          })
          .catch(function () {
            showBiometricError('Fingerprint login failed. Please use your email and password.');
            setLoading(btn, false);
            if (bar) bar.classList.remove('active');
          });
      });
    });
  }

  function initAccountSwitchGuard() {
    if (!hasBridge()) return;

    var forms = [document.getElementById('mob-form'), document.getElementById('dt-form')];
    forms.forEach(function (form) {
      if (!form) return;
      form.addEventListener('submit', function () {
        var emailInput = form.querySelector('input[name="email"]');
        if (!emailInput) return;
        var typedEmail = (emailInput.value || '').trim().toLowerCase();
        if (!typedEmail) return;

        callBridge('getRegisteredEmail').then(function (registered) {
          if (!registered) return;
          if (registered.toLowerCase() === typedEmail) return;
          if (typeof bridge.onDifferentAccountLogin === 'function') {
            bridge.onDifferentAccountLogin(typedEmail);
          }
        }).catch(function () {});
      }, true);
    });
  }

  window.MDRRMOBiometricWeb = {
    hasBridge: hasBridge,
    postJson: postJson,
    apiBase: API_BASE,
    callBridge: callBridge
  };

  document.addEventListener('DOMContentLoaded', function () {
    initBiometricLoginButtons();
    initAccountSwitchGuard();
  });
})();
