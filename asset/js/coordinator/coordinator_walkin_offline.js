/**
 * Coordinator-only offline walk-in registration + sync manager.
 * Requires CoordinatorOfflineDB and window.MDRRMO_COORDINATOR config.
 */
(function () {
  'use strict';

  var cfg = window.MDRRMO_COORDINATOR || {};
  var API_BASE = cfg.apiBase || '../api/coordinator/';
  var centerId = Number(cfg.centerId || 0);
  var syncInProgress = false;
  var syncTimer = null;
  var statusBarEl = null;
  var toastEl = null;

  function $(id) { return document.getElementById(id); }

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

  function demoKeys() {
    return [
      'adults', 'children', 'seniors', 'pwds',
      'pregnant_women', 'lactating_mothers', 'infants_toddlers'
    ];
  }

  function readFormData(form) {
    var fd = new FormData(form);
    var data = {
      family_head_name: String(fd.get('family_head_name') || '').trim(),
      contact_number: String(fd.get('contact_number') || '').trim(),
      birthday: String(fd.get('birthday') || '').trim(),
      barangay_id: Number(fd.get('barangay_id') || 0)
    };
    demoKeys().forEach(function (key) {
      data[key] = Math.max(0, parseInt(fd.get(key) || '0', 10) || 0);
    });
    var barangaySelect = form.querySelector('select[name="barangay_id"]');
    if (barangaySelect && barangaySelect.selectedOptions[0]) {
      data.barangay_name = barangaySelect.selectedOptions[0].textContent.trim();
    } else {
      data.barangay_name = '';
    }
    data.total_members = demoKeys().reduce(function (sum, key) {
      return sum + (data[key] || 0);
    }, 0);
    return data;
  }

  function validateFormData(data) {
    var errors = [];
    if (!data.family_head_name) errors.push('Head of family name is required.');
    if (!data.contact_number) errors.push('Contact number is required.');
    if (!data.birthday) errors.push('Birthday is required.');
    else if (!/^\d{4}-\d{2}-\d{2}$/.test(data.birthday)) errors.push('Invalid birthday format (YYYY-MM-DD).');
    if (!data.barangay_id) errors.push('Barangay is required.');
    if (data.total_members <= 0) errors.push('Please specify at least one member.');
    return errors;
  }

  function fetchWithTimeout(url, options, timeoutMs) {
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, timeoutMs || 8000);
    var opts = Object.assign({}, options || {}, { signal: controller.signal });
    return fetch(url, opts).finally(function () { clearTimeout(timer); });
  }

  function canReachBackend() {
    if (!navigator.onLine) return Promise.resolve(false);
    return fetchWithTimeout(API_BASE + 'ping.php', {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' }
    }, 6000).then(function (res) {
      return res.ok;
    }).catch(function () {
      return false;
    });
  }

  function submitOnline(formData, localUuid) {
    var payload = Object.assign({}, formData, {
      center_id: centerId,
      local_uuid: localUuid || undefined
    });
    return fetchWithTimeout(API_BASE + 'walkin_register.php', {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json'
      },
      body: JSON.stringify(payload)
    }, 15000).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (body) {
        return { ok: res.ok, status: res.status, body: body };
      });
    });
  }

  function saveOffline(formData, localUuid) {
    var record = {
      local_uuid: localUuid,
      center_id: centerId,
      form_data: formData,
      created_at: new Date().toISOString(),
      synced: false,
      sync_attempts: 0,
      last_sync_attempt: null,
      sync_error: null,
      server_registration_id: null
    };
    return window.CoordinatorOfflineDB.putRecord(record);
  }

  function showToast(message, type) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'coord-offline-toast';
      var anchor = $('coordOfflineBar') || document.querySelector('.dashboard');
      if (anchor && anchor.parentNode) {
        anchor.parentNode.insertBefore(toastEl, anchor.nextSibling);
      } else {
        document.body.appendChild(toastEl);
      }
    }
    toastEl.className = 'coord-offline-toast show' + (type === 'offline' ? ' offline-save' : '');
    toastEl.innerHTML = message;
    clearTimeout(showToast._timer);
    showToast._timer = setTimeout(function () {
      toastEl.classList.remove('show');
    }, 5200);
  }

  function renderStatusBar(state) {
    if (!statusBarEl) return;
    var dot = statusBarEl.querySelector('.coord-offline-dot');
    var msg = statusBarEl.querySelector('.coord-offline-msg');
    if (!dot || !msg) return;

    dot.className = 'coord-offline-dot ' + (state.kind || 'offline');
    msg.innerHTML = state.message || '';
  }

  function updateStatusBar() {
    if (!window.CoordinatorOfflineDB) return Promise.resolve();

    return window.CoordinatorOfflineDB.getPendingRecords(centerId || null).then(function (pending) {
      var errorCount = pending.filter(function (p) { return !!p.sync_error; }).length;
      var pendingCount = pending.length;

      if (syncInProgress) {
        renderStatusBar({
          kind: 'syncing',
          message: '<strong>Syncing</strong> ' + pendingCount + ' pending record' + (pendingCount === 1 ? '' : 's') + '…'
        });
        return;
      }

      return canReachBackend().then(function (online) {
        if (!online) {
          renderStatusBar({
            kind: 'offline',
            message: pendingCount
              ? '<strong>Offline</strong> — ' + pendingCount + ' pending sync'
              : '<strong>Offline</strong> — walk-in registrations will be saved on this device'
          });
          return;
        }

        if (errorCount > 0) {
          renderStatusBar({
            kind: 'error',
            message: '<strong>Online</strong> — ' + errorCount + ' sync error' + (errorCount === 1 ? '' : 's')
              + (pendingCount > errorCount ? ', ' + (pendingCount - errorCount) + ' pending sync' : '')
          });
          return;
        }

        renderStatusBar({
          kind: 'online',
          message: pendingCount
            ? '<strong>Online</strong> — ' + pendingCount + ' pending sync'
            : '<strong>Online</strong> — all synced'
        });
      });
    });
  }

  function renderPendingSection() {
    var section = $('coordPendingSection');
    var list = $('coordPendingList');
    if (!section || !list || !window.CoordinatorOfflineDB) return;

    window.CoordinatorOfflineDB.getPendingRecords(centerId || null).then(function (pending) {
      if (!pending.length) {
        section.hidden = true;
        list.innerHTML = '';
        injectPendingIntoRegistrations([]);
        return;
      }
      section.hidden = false;
      list.innerHTML = pending.map(function (row) {
        var fd = row.form_data || {};
        var badgeClass = row.sync_error ? 'coord-pending-badge error' : 'coord-pending-badge';
        var badgeText = row.sync_error ? 'Sync Error' : 'Pending Sync';
        return '<div class="coord-pending-item" data-local-uuid="' + row.local_uuid + '">'
          + '<div class="coord-pending-item-main">'
          + '<div class="coord-pending-item-name">' + escapeHtml(fd.family_head_name || 'Family') + '</div>'
          + '<div class="coord-pending-item-meta">Household members: ' + (fd.total_members || 0)
          + (fd.barangay_name ? ' · ' + escapeHtml(fd.barangay_name) : '') + '</div>'
          + (row.sync_error ? '<div class="coord-pending-item-meta">' + escapeHtml(row.sync_error) + '</div>' : '')
          + '</div>'
          + '<span class="' + badgeClass + '">' + badgeText + '</span>'
          + '</div>';
      }).join('');
      injectPendingIntoRegistrations(pending);
    });
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function injectPendingIntoRegistrations(pending) {
    var tableBody = document.querySelector('#regTable tbody');
    var cardsWrap = document.getElementById('regCards');
    document.querySelectorAll('.coord-pending-row').forEach(function (el) { el.remove(); });

    if (!pending.length) return;

    pending.forEach(function (row) {
      var fd = row.form_data || {};
      var badge = row.sync_error
        ? '<span class="coord-pending-badge error">Sync Error</span>'
        : '<span class="coord-pending-badge">Pending Sync</span>';

      if (tableBody) {
        var tr = document.createElement('tr');
        tr.className = 'reg-row coord-pending-row';
        tr.dataset.name = String(fd.family_head_name || '').toLowerCase();
        tr.dataset.barangay = String(fd.barangay_name || '').toLowerCase();
        tr.innerHTML = '<td class="cell-head">' + escapeHtml(fd.family_head_name || '') + ' ' + badge + '</td>'
          + '<td>' + escapeHtml(fd.contact_number || '') + '</td>'
          + '<td>' + escapeHtml(fd.birthday || '') + '</td>'
          + '<td>' + escapeHtml(fd.barangay_name || '') + '</td>'
          + demoKeys().map(function (key) {
            return '<td>' + (fd[key] || 0) + '</td>';
          }).join('')
          + '<td class="cell-total">' + (fd.total_members || 0) + '</td>';
        tableBody.insertBefore(tr, tableBody.firstChild);
      }

      if (cardsWrap) {
        var card = document.createElement('div');
        card.className = 'reg-card reg-row coord-pending-row';
        card.dataset.name = String((fd.family_head_name || '') + ' ' + (fd.contact_number || '')).toLowerCase();
        card.dataset.barangay = String(fd.barangay_name || '').toLowerCase();
        card.innerHTML = '<div class="reg-card-head"><div>'
          + '<div class="reg-card-name">' + escapeHtml(fd.family_head_name || '') + ' ' + badge + '</div>'
          + '<div class="reg-card-barangay">' + escapeHtml(fd.barangay_name || '') + '</div>'
          + '<div class="reg-card-contact">' + escapeHtml(fd.contact_number || '') + '</div>'
          + '</div><div class="reg-card-total"><div class="reg-card-total-num">' + (fd.total_members || 0)
          + '</div><div class="reg-card-total-label">Total</div></div></div>';
        cardsWrap.insertBefore(card, cardsWrap.firstChild);
      }
    });

    var noData = document.querySelector('.no-data');
    if (noData) noData.style.display = 'none';
  }

  function syncPendingRecords() {
    if (syncInProgress || !window.CoordinatorOfflineDB) return Promise.resolve();
    syncInProgress = true;
    updateStatusBar();

    return canReachBackend().then(function (online) {
      if (!online) {
        syncInProgress = false;
        return updateStatusBar();
      }
      return window.CoordinatorOfflineDB.getPendingRecords(centerId || null).then(function (pending) {
        var chain = Promise.resolve();
        pending.forEach(function (row) {
          chain = chain.then(function () {
            return submitOnline(row.form_data, row.local_uuid).then(function (result) {
              if (result.ok && result.body && result.body.success) {
                return window.CoordinatorOfflineDB.markSynced(row.local_uuid, result.body.id);
              }
              var errMsg = (result.body && result.body.errors && result.body.errors.join(' '))
                || (result.body && result.body.message)
                || ('HTTP ' + result.status);
              return window.CoordinatorOfflineDB.markSyncFailed(row.local_uuid, errMsg);
            }).catch(function (err) {
              return window.CoordinatorOfflineDB.markSyncFailed(row.local_uuid, String(err.message || err));
            });
          });
        });
        return chain;
      });
    }).finally(function () {
      syncInProgress = false;
      renderPendingSection();
      return updateStatusBar();
    });
  }

  function bindWalkinForm() {
    var form = $('walkinFamilyForm');
    if (!form) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var submitBtn = form.querySelector('.btn-submit');
      if (submitBtn) submitBtn.disabled = true;

      var formData = readFormData(form);
      var errors = validateFormData(formData);
      if (errors.length) {
        showFormErrors(form, errors);
        if (submitBtn) submitBtn.disabled = false;
        return;
      }
      clearFormErrors(form);

      var localUuid = generateUuid();

      canReachBackend().then(function (online) {
        if (online) {
            return submitOnline(formData, localUuid).then(function (result) {
            if (result.ok && result.body && result.body.success) {
              form.reset();
              window.location.href = window.location.pathname + '?id=' + centerId + '&added=1';
              return;
            }
            var serverErrors = (result.body && result.body.errors) || ['Registration failed. Please try again.'];
            showFormErrors(form, serverErrors);
          }).catch(function () {
            return saveOffline(formData, localUuid).then(function () {
              form.reset();
              showToast(
                '✓ <strong>Saved Offline</strong><br>This family has been saved on this device. '
                + 'It will be synchronized automatically when the connection is restored.',
                'offline'
              );
              renderPendingSection();
              updateStatusBar();
            });
          });
        }

        return saveOffline(formData, localUuid).then(function () {
          form.reset();
          showToast(
            '✓ <strong>Saved Offline</strong><br>This family has been saved on this device. '
            + 'It will be synchronized automatically when the connection is restored.',
            'offline'
          );
          renderPendingSection();
          updateStatusBar();
        });
      }).catch(function (err) {
        showFormErrors(form, [String(err.message || 'Could not save registration.')]);
      }).finally(function () {
        if (submitBtn) submitBtn.disabled = false;
      });
    });
  }

  function showFormErrors(form, errors) {
    var box = form.querySelector('.coord-form-errors');
    if (!box) {
      box = document.createElement('ul');
      box.className = 'error-box coord-form-errors';
      form.insertBefore(box, form.firstChild);
    }
    box.innerHTML = errors.map(function (err) {
      return '<li>' + escapeHtml(err) + '</li>';
    }).join('');
  }

  function clearFormErrors(form) {
    var box = form.querySelector('.coord-form-errors');
    if (box) box.remove();
  }

  function registerCoordinatorServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    navigator.serviceWorker.register('./coordinator-sw.js', { scope: './' }).catch(function () {});
  }

  function startSyncLoop() {
    if (syncTimer) clearInterval(syncTimer);
    syncTimer = setInterval(function () {
      if (document.hidden) return;
      syncPendingRecords();
    }, 30000);
  }

  function init() {
    statusBarEl = $('coordOfflineBar');
    registerCoordinatorServiceWorker();
    bindWalkinForm();
    renderPendingSection();
    updateStatusBar().then(function () {
      return syncPendingRecords();
    });
    startSyncLoop();

    window.addEventListener('online', function () {
      updateStatusBar().then(syncPendingRecords);
    });
    window.addEventListener('offline', updateStatusBar);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) {
        updateStatusBar().then(syncPendingRecords);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.CoordinatorWalkinOffline = {
    syncNow: syncPendingRecords,
    updateStatus: updateStatusBar,
    canReachBackend: canReachBackend
  };
})();
