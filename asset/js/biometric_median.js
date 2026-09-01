/**
 * Biometric login UI for Median Android WebView (median_biometric bridge).
 */
(function () {
  'use strict';

  var TRUSTED_HOST = window.location.hostname;
  var BASE_URL = window.location.origin + window.location.pathname.replace(/\/[^/]*$/, '').replace(/\/pages$/, '');
  var DASHBOARD_URL = BASE_URL + '/pages/citizen_dashboard.php';
  var CITIZEN_EMAIL = window.MDRRMO_CITIZEN_EMAIL || '';
  var pollTimer = null;
  var pollAttempts = 0;
  var MAX_POLL_ATTEMPTS = 40;
  var uiInitialized = false;
  var enableInProgress = false;

  function bridgeReady() {
    return typeof median_biometric !== 'undefined' && median_biometric !== null;
  }

  function applyServerConfig() {
    if (!bridgeReady() || typeof median_biometric.getServerConfig !== 'function') return;
    try {
      var cfg = JSON.parse(median_biometric.getServerConfig());
      if (cfg.trustedHost) TRUSTED_HOST = cfg.trustedHost;
      if (cfg.baseUrl) BASE_URL = cfg.baseUrl.replace(/\/$/, '');
      if (cfg.dashboardUrl) DASHBOARD_URL = cfg.dashboardUrl;
    } catch (e) {}
  }

  function parseStatus(raw) {
    try {
      return typeof raw === 'string' ? JSON.parse(raw) : (raw || {});
    } catch (e) {
      return {};
    }
  }

  function getStatus() {
    if (!bridgeReady() || typeof median_biometric.getStatus !== 'function') {
      return { available: false, enabled: false, availability: 'NO_BRIDGE' };
    }
    try {
      return parseStatus(median_biometric.getStatus());
    } catch (e) {
      return { available: false, enabled: false, availability: 'ERROR', message: String(e) };
    }
  }

  function callbackName(prefix) {
    return '_mdrrmo_bio_' + prefix + '_' + Math.random().toString(36).slice(2);
  }

  function isLoginPage() {
    return window.location.pathname.indexOf('index.php') !== -1
      || window.location.pathname.endsWith('/mdrrmo/')
      || window.location.pathname.endsWith('/mdrrmo');
  }

  function isDashboardPage() {
    return window.location.pathname.indexOf('citizen_dashboard') !== -1;
  }

  function setStatusText(text) {
    var statusText = document.getElementById('biometricStatusText');
    if (statusText) statusText.textContent = text;
  }

  function formatError(result) {
    if (!result) return 'Unknown error.';
    if (result.message) return result.message;
    if (result.error === 'not_logged_in') return 'Your login session expired. Log in again with your password, then retry.';
    if (result.error === 'authentication_failed') return 'Server rejected registration. Make sure you ran add_citizen_device_tokens.sql on your database.';
    if (result.error) return String(result.error);
    return 'Unknown error.';
  }

  function registerDeviceOnServer(deviceId, deviceToken, username) {
    return fetch(BASE_URL + '/api/device/register.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        device_id: deviceId,
        device_token: deviceToken,
        username: username
      })
    }).then(function (response) {
      return response.json().catch(function () { return {}; }).then(function (body) {
        if (response.ok && body && body.success) {
          return { success: true, body: body };
        }
        return {
          success: false,
          error: (body && body.error) || 'server_error',
          message: (body && body.message) || ('HTTP ' + response.status)
        };
      });
    }).catch(function (err) {
      return { success: false, error: 'network_error', message: String(err) };
    });
  }

  function commitRegistration(deviceId, deviceToken, username, onDone) {
    if (!bridgeReady() || typeof median_biometric.commitRegistration !== 'function') {
      onDone({ success: false, error: 'bridge_error', message: 'App bridge missing commitRegistration.' });
      return;
    }
    var cb = callbackName('commit');
    window[cb] = function (result) {
      delete window[cb];
      if (onDone) onDone(result || {});
    };
    median_biometric.commitRegistration(deviceId, deviceToken, username, cb);
  }

  function promptEnable(email, onDone) {
    if (!bridgeReady() || enableInProgress) return;
    enableInProgress = true;
    var cb = callbackName('enable');
    var finished = false;

    function finish(result) {
      if (finished) return;
      finished = true;
      enableInProgress = false;
      delete window[cb];
      if (onDone) onDone(result || {});
    }

    window[cb] = function (result) {
      if (result && result.action === 'biometric_verified') {
        registerDeviceOnServer(result.device_id, result.device_token, result.username || email)
          .then(function (regResult) {
            if (!regResult.success) {
              finish(regResult);
              return;
            }
            commitRegistration(result.device_id, result.device_token, result.username || email, finish);
          });
        return;
      }
      finish(result);
    };

    setTimeout(function () {
      if (!finished) {
        finish({ success: false, error: 'timeout', message: 'Fingerprint setup timed out. Try again.' });
      }
    }, 45000);

    try {
      median_biometric.promptEnable(email, cb);
    } catch (e) {
      finish({ success: false, error: 'bridge_error', message: String(e) });
    }
  }

  function promptLogin(onDone) {
    if (!bridgeReady()) return;
    var cb = callbackName('login');
    window[cb] = function (result) {
      delete window[cb];
      if (onDone) onDone(result || {});
    };
    median_biometric.promptLogin(cb);
  }

  function disableBiometric(onDone) {
    if (!bridgeReady()) return;
    var cb = callbackName('disable');
    window[cb] = function (result) {
      delete window[cb];
      if (onDone) onDone(result || {});
    };
    median_biometric.disable(cb);
  }

  function resolveRedirect(url) {
    if (!url) return DASHBOARD_URL;
    if (/^https?:\/\//i.test(url)) return url;
    if (url.indexOf('/api/device/') !== -1) return DASHBOARD_URL;
    if (url.charAt(0) === '/') {
      return window.location.origin + url;
    }
    return BASE_URL + '/' + String(url).replace(/^\//, '');
  }

  function mountLoginButton() {
    if (!isLoginPage() || !bridgeReady()) return;
    applyServerConfig();
    var status = getStatus();
    if (!status.available || !status.enabled || status.trustedOrigin === false) return;

    ['mob-form', 'dt-form'].forEach(function (formId) {
      var form = document.getElementById(formId);
      if (!form || form.querySelector('.mdrrmo-biometric-login-btn')) return;

      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'mdrrmo-biometric-login-btn btn-biometric-login';
      btn.textContent = 'Login with Fingerprint';
      btn.addEventListener('click', function () {
        btn.disabled = true;
        setStatusText && setStatusText('Confirm fingerprint to sign in…');
        promptLogin(function (result) {
          btn.disabled = false;
          if (result && result.success) {
            window.location.href = resolveRedirect(result.redirect);
            return;
          }
          if (result && result.error === 'authentication_error') return;
          alert(formatError(result) || 'Fingerprint login failed. Please use your password.');
        });
      });

      var submit = form.querySelector('button[type="submit"]');
      if (submit && submit.parentNode) {
        submit.parentNode.insertBefore(btn, submit.nextSibling);
      } else {
        form.appendChild(btn);
      }
      btn.hidden = false;
      btn.style.display = '';
    });
  }

  function runEnable(email) {
    if (!email) {
      alert('Could not determine your account email. Please log out and log in again.');
      return;
    }
    setStatusText('Confirm fingerprint on your device…');
    promptEnable(email, function (result) {
      if (result && result.success) {
        setStatusText('Fingerprint login is enabled for this device.');
        mountDashboardSettings();
        mountLoginButton();
        alert('Fingerprint login enabled.');
        return;
      }
      setStatusText('Tap below to enable fingerprint login for this account.');
      alert(formatError(result) || 'Could not enable fingerprint login.');
    });
  }

  function mountDashboardSettings() {
    if (!isDashboardPage()) return;
    var section = document.getElementById('biometricSettings');
    if (!section) return;

    var enableBtn = document.getElementById('btnEnableBiometric');
    var disableBtn = document.getElementById('btnDisableBiometric');
    var email = CITIZEN_EMAIL || sessionStorage.getItem('centrix_last_login_email') || '';

    section.hidden = false;

    if (!bridgeReady()) {
      setStatusText('Waiting for CENTRIX app… (' + pollAttempts + '/' + MAX_POLL_ATTEMPTS + ')');
      if (enableBtn) enableBtn.hidden = true;
      if (disableBtn) disableBtn.hidden = true;
      return;
    }

    applyServerConfig();
    var status = getStatus();

    if (status.trustedOrigin === false) {
      setStatusText(
        'Page host mismatch. Current: ' + (status.currentHost || window.location.hostname) +
        ', expected: ' + (status.expectedHost || TRUSTED_HOST) + '.'
      );
      if (enableBtn) enableBtn.hidden = true;
      if (disableBtn) disableBtn.hidden = true;
      return;
    }

    if (!status.available) {
      var reason = status.availability || 'NOT_SUPPORTED';
      if (reason === 'NO_BIOMETRIC_ENROLLED') {
        setStatusText('Set up a fingerprint in your phone Settings first, then return here.');
      } else {
        setStatusText('Fingerprint not available on this device (' + reason + ').');
      }
      if (enableBtn) enableBtn.hidden = true;
      if (disableBtn) disableBtn.hidden = true;
      return;
    }

    if (status.enabled) {
      setStatusText('Fingerprint login is enabled for this device.');
      if (enableBtn) enableBtn.hidden = true;
      if (disableBtn) disableBtn.hidden = false;
      return;
    }

    setStatusText('Tap below to enable fingerprint login for this account.');
    if (enableBtn) enableBtn.hidden = false;
    if (disableBtn) disableBtn.hidden = true;

    if (enableBtn && !enableBtn.dataset.bound) {
      enableBtn.dataset.bound = '1';
      enableBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        runEnable(email);
      });
    }

    if (disableBtn && !disableBtn.dataset.bound) {
      disableBtn.dataset.bound = '1';
      disableBtn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        disableBtn.disabled = true;
        disableBiometric(function (result) {
          disableBtn.disabled = false;
          if (result && result.success) {
            mountDashboardSettings();
            return;
          }
          alert(formatError(result) || 'Could not disable fingerprint login.');
        });
      });
    }
  }

  function rememberLoginEmail() {
    ['mob-form', 'dt-form'].forEach(function (formId) {
      var form = document.getElementById(formId);
      if (!form || form.dataset.bioEmailBound) return;
      form.dataset.bioEmailBound = '1';
      form.addEventListener('submit', function () {
        var emailEl = form.querySelector('input[name="email"]');
        if (emailEl && emailEl.value) {
          sessionStorage.setItem('centrix_last_login_email', emailEl.value.trim());
        }
      }, true);
    });
  }

  function tick() {
    pollAttempts += 1;
    rememberLoginEmail();
    mountLoginButton();
    mountDashboardSettings();

    if (bridgeReady() || pollAttempts >= MAX_POLL_ATTEMPTS) {
      if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
      }
      if (!bridgeReady() && isDashboardPage()) {
        setStatusText('CENTRIX app bridge not found. Use the installed APK, not Chrome.');
      }
    }
  }

  function start() {
    if (uiInitialized) {
      tick();
      return;
    }
    uiInitialized = true;
    pollAttempts = 0;
    tick();
    if (!pollTimer) {
      pollTimer = setInterval(tick, 500);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  window.MDRRMOBiometricUI = {
    start: start,
    getStatus: getStatus,
    enableNow: function () {
      runEnable(CITIZEN_EMAIL || sessionStorage.getItem('centrix_last_login_email') || '');
    }
  };
})();
