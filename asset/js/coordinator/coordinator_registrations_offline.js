/**
 * Coordinator Registered Families: cache roster + offline +/- steppers.
 */
(function () {
  'use strict';

  var cfg = window.MDRRMO_COORDINATOR || {};
  var API_BASE = cfg.apiBase || '../api/coordinator/';
  var centerId = Number(cfg.centerId || 0);
  var demoLabels = window.MDRRMO_DEMO_LABELS || {};

  function $(id) { return document.getElementById(id); }

  function demoKeys() {
    return [
      'adults', 'children', 'seniors', 'pwds',
      'pregnant_women', 'lactating_mothers', 'infants_toddlers'
    ];
  }

  function generateUuid() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0;
      var v = c === 'x' ? r : (r & 0x3 | 0x8);
      return v.toString(16);
    });
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function formatBirthday(iso) {
    if (!iso) return '';
    var d = new Date(iso + 'T00:00:00');
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  }

  function canReachBackend() {
    if (window.CoordinatorWalkinOffline && window.CoordinatorWalkinOffline.canReachBackend) {
      return window.CoordinatorWalkinOffline.canReachBackend();
    }
    if (!navigator.onLine) return Promise.resolve(false);
    return fetch(API_BASE + 'ping.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok; })
      .catch(function () { return false; });
  }

  function fetchRosterLive() {
    return fetch(API_BASE + 'registrations_roster.php?center_id=' + centerId, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' }
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (body) {
        if (!res.ok || !body.success) {
          throw new Error((body.errors && body.errors[0]) || 'Could not fetch roster.');
        }
        return body.registrations || [];
      });
    });
  }

  function cacheRosterFromPage() {
    var embedded = window.MDRRMO_REGISTRATIONS_ROSTER;
    if (!embedded || !window.CoordinatorOfflineDB || !centerId) return Promise.resolve();
    return window.CoordinatorOfflineDB.cacheFamilies(centerId, embedded);
  }

  function refreshRosterOnline() {
    if (!window.CoordinatorOfflineDB || !centerId) return Promise.resolve();
    return canReachBackend().then(function (online) {
      if (!online) return null;
      return fetchRosterLive().then(function (registrations) {
        return window.CoordinatorOfflineDB.getRegIdsWithPendingAdjustments(centerId)
          .then(function (pendingRegs) {
            return window.CoordinatorOfflineDB.reconcileFamiliesFromServer(centerId, registrations, pendingRegs)
              .then(function () { return registrations; });
          });
      });
    });
  }

  function getDisplayRoster() {
    if (!window.CoordinatorOfflineDB || !centerId) return Promise.resolve([]);
    return window.CoordinatorOfflineDB.getCachedFamilies(centerId);
  }

  function updateRowDom(regId, family, hasPending) {
    var badge = hasPending
      ? ' <span class="coord-pending-badge">Pending Sync</span>'
      : '';
    document.querySelectorAll('.reg-row[data-reg-id="' + regId + '"]').forEach(function (row) {
      demoKeys().forEach(function (key) {
        row.querySelectorAll('form.inline-adjust input[name="field"][value="' + key + '"]').forEach(function (input) {
          var container = input.closest('.adjust-cell') || input.closest('.member-row-controls');
          if (!container) return;
          var valSpan = container.querySelector('.adjust-val');
          if (valSpan) valSpan.textContent = String(family[key] || 0);
        });
      });
      var totalEl = row.querySelector('.cell-total') || row.querySelector('.reg-card-total-num');
      if (totalEl) totalEl.textContent = String(family.total_members || 0);
      var headEl = row.querySelector('.cell-head') || row.querySelector('.reg-card-name');
      if (headEl) {
        headEl.innerHTML = escapeHtml(family.family_head_name || '') + (hasPending ? badge : '');
      }
    });
  }

  function refreshPendingBadges() {
    if (!window.CoordinatorOfflineDB) return Promise.resolve();
    return window.CoordinatorOfflineDB.getPendingAdjustments(centerId).then(function (pending) {
      var byReg = {};
      pending.forEach(function (p) { byReg[p.reg_id] = true; });
      document.querySelectorAll('.reg-row[data-reg-id]').forEach(function (row) {
        var regId = Number(row.dataset.regId);
        var hasPending = !!byReg[regId];
        row.classList.toggle('coord-has-pending-adj', hasPending);
        window.CoordinatorOfflineDB.getCachedFamily(regId).then(function (family) {
          if (family) updateRowDom(regId, family, hasPending);
        });
      });
    });
  }

  function applyAdjustmentOnline(regId, field, delta, localUuid) {
    return fetch(API_BASE + 'family_adjust.php', {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        center_id: centerId,
        reg_id: regId,
        field: field,
        delta: delta,
        client_adjustment_uuid: localUuid
      })
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (body) {
        return { ok: res.ok, body: body };
      });
    });
  }

  function handleAdjustSubmit(form, e) {
    e.preventDefault();
    var regId = Number(form.querySelector('input[name="reg_id"]').value);
    var field = String(form.querySelector('input[name="field"]').value);
    var delta = Number(form.querySelector('input[name="delta"]').value);
    if (!regId || !field || (delta !== 1 && delta !== -1)) return;

    var btn = form.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;
    var localUuid = generateUuid();

    canReachBackend().then(function (online) {
      if (online) {
        return applyAdjustmentOnline(regId, field, delta, localUuid).then(function (result) {
          if (result.ok && result.body && result.body.success && result.body.registration) {
            return window.CoordinatorOfflineDB.putCachedFamily(result.body.registration)
              .then(function () {
                updateRowDom(regId, result.body.registration, false);
                refreshPendingBadges();
                if (window.CoordinatorWalkinOffline && window.CoordinatorWalkinOffline.updateStatus) {
                  window.CoordinatorWalkinOffline.updateStatus();
                }
              });
          }
          throw new Error((result.body && result.body.errors && result.body.errors[0]) || 'Adjustment failed.');
        }).catch(function () {
          return queueOfflineAdjustment(regId, field, delta, localUuid);
        });
      }
      return queueOfflineAdjustment(regId, field, delta, localUuid);
    }).catch(function (err) {
      alert(err.message || 'Could not save adjustment.');
    }).finally(function () {
      if (btn) btn.disabled = false;
    });
  }

  function queueOfflineAdjustment(regId, field, delta, localUuid) {
    return window.CoordinatorOfflineDB.applyOfflineAdjustment(centerId, regId, field, delta, localUuid)
      .then(function (family) {
        if (!family) throw new Error('Local update failed.');
        updateRowDom(regId, family, true);
        refreshPendingBadges();
        if (window.CoordinatorWalkinOffline && window.CoordinatorWalkinOffline.updateStatus) {
          return window.CoordinatorWalkinOffline.updateStatus();
        }
      });
  }

  function bindAdjustForms() {
    document.querySelectorAll('form.inline-adjust').forEach(function (form) {
      if (form.dataset.coordAdjBound) return;
      form.dataset.coordAdjBound = '1';
      form.addEventListener('submit', function (e) {
        handleAdjustSubmit(form, e);
      });
    });
  }

  function renderFromCacheIfNeeded() {
    return getDisplayRoster().then(function (cached) {
      if (!cached.length) return;
      return canReachBackend().then(function (online) {
        if (online && document.querySelector('#regTable tbody tr[data-reg-id]')) {
          return refreshPendingBadges();
        }
        cached.forEach(function (family) {
          updateRowDom(family.id, family, false);
        });
        return refreshPendingBadges();
      });
    });
  }

  function initRegistrationsPage() {
    if (!document.getElementById('regTable') && !document.getElementById('regCards')) return;

    document.querySelectorAll('.reg-row[data-reg-id]').forEach(function (row) {
      if (!row.dataset.regId && row.querySelector('input[name="reg_id"]')) {
        row.dataset.regId = row.querySelector('input[name="reg_id"]').value;
      }
    });

    bindAdjustForms();

    cacheRosterFromPage()
      .then(renderFromCacheIfNeeded)
      .then(function () {
        return refreshRosterOnline();
      })
      .then(function () {
        if (arguments[0]) return refreshPendingBadges();
      })
      .catch(function () {
        return renderFromCacheIfNeeded();
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initRegistrationsPage);
  } else {
    initRegistrationsPage();
  }

  window.CoordinatorRegistrationsOffline = {
    refreshRosterOnline: refreshRosterOnline,
    refreshPendingBadges: refreshPendingBadges,
    syncAdjustments: function () {
      if (window.CoordinatorWalkinOffline && window.CoordinatorWalkinOffline.syncNow) {
        return window.CoordinatorWalkinOffline.syncNow();
      }
      return Promise.resolve();
    }
  };
})();
