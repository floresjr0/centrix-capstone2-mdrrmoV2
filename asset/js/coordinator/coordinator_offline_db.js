/**
 * Coordinator-only IndexedDB: walk-in queue, family cache, adjustment queue.
 */
(function (global) {
  'use strict';

  var DB_NAME = 'coordinator_offline_queue';
  var DB_VERSION = 2;
  var STORE_WALKINS = 'pending_walkins';
  var STORE_FAMILIES = 'cached_registered_families';
  var STORE_ADJUSTMENTS = 'pending_family_adjustments';
  var dbPromise = null;

  function openDb() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise(function (resolve, reject) {
      if (!global.indexedDB) {
        reject(new Error('IndexedDB unavailable'));
        return;
      }
      var req = global.indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = function (e) {
        var db = e.target.result;
        var oldVersion = e.oldVersion;

        if (!db.objectStoreNames.contains(STORE_WALKINS)) {
          var walkins = db.createObjectStore(STORE_WALKINS, { keyPath: 'local_uuid' });
          walkins.createIndex('synced', 'synced', { unique: false });
          walkins.createIndex('center_id', 'center_id', { unique: false });
          walkins.createIndex('created_at', 'created_at', { unique: false });
        }

        if (!db.objectStoreNames.contains(STORE_FAMILIES)) {
          var families = db.createObjectStore(STORE_FAMILIES, { keyPath: 'id' });
          families.createIndex('center_id', 'center_id', { unique: false });
        }

        if (!db.objectStoreNames.contains(STORE_ADJUSTMENTS)) {
          var adj = db.createObjectStore(STORE_ADJUSTMENTS, { keyPath: 'seq', autoIncrement: true });
          adj.createIndex('local_uuid', 'local_uuid', { unique: true });
          adj.createIndex('synced', 'synced', { unique: false });
          adj.createIndex('center_id', 'center_id', { unique: false });
          adj.createIndex('reg_id', 'reg_id', { unique: false });
          adj.createIndex('created_at', 'created_at', { unique: false });
        }

        if (oldVersion > 0 && oldVersion < 2) {
          /* v1 → v2 migration: existing pending_walkins preserved */
        }
      };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { reject(req.error || new Error('IndexedDB open failed')); };
    });
    return dbPromise;
  }

  function withTx(storeNames, mode) {
    return openDb().then(function (db) {
      return db.transaction(storeNames, mode);
    });
  }

  function storeOp(storeName, mode, fn) {
    return withTx([storeName], mode).then(function (tx) {
      var store = tx.objectStore(storeName);
      return new Promise(function (resolve, reject) {
        var result;
        try {
          result = fn(store);
        } catch (err) {
          reject(err);
          return;
        }
        if (result && typeof result.then === 'function') {
          result.then(resolve).catch(reject);
          return;
        }
        tx.oncomplete = function () { resolve(result); };
        tx.onerror = function () { reject(tx.error); };
        tx.onabort = function () { reject(tx.error || new Error('Transaction aborted')); };
      });
    });
  }

  function reqPromise(request) {
    return new Promise(function (resolve, reject) {
      request.onsuccess = function () { resolve(request.result); };
      request.onerror = function () { reject(request.error); };
    });
  }

  /* ── Walk-ins ── */

  function putWalkinRecord(record) {
    return storeOp(STORE_WALKINS, 'readwrite', function (store) {
      return reqPromise(store.put(record));
    });
  }

  function getAllWalkinRecords() {
    return storeOp(STORE_WALKINS, 'readonly', function (store) {
      return reqPromise(store.getAll()).then(function (rows) { return rows || []; });
    });
  }

  function getPendingWalkins(centerId) {
    return getAllWalkinRecords().then(function (rows) {
      return rows
        .filter(function (row) {
          if (row.synced) return false;
          if (centerId && Number(row.center_id) !== Number(centerId)) return false;
          return true;
        })
        .sort(function (a, b) {
          return String(a.created_at).localeCompare(String(b.created_at));
        });
    });
  }

  function getWalkinRecord(localUuid) {
    return storeOp(STORE_WALKINS, 'readonly', function (store) {
      return reqPromise(store.get(localUuid));
    });
  }

  function markWalkinSynced(localUuid, serverId) {
    return getWalkinRecord(localUuid).then(function (row) {
      if (!row) return null;
      row.synced = true;
      row.sync_error = null;
      row.server_registration_id = serverId || null;
      row.synced_at = new Date().toISOString();
      return putWalkinRecord(row);
    });
  }

  function markWalkinSyncFailed(localUuid, errorMessage) {
    return getWalkinRecord(localUuid).then(function (row) {
      if (!row) return null;
      row.sync_attempts = (row.sync_attempts || 0) + 1;
      row.last_sync_attempt = new Date().toISOString();
      row.sync_error = errorMessage || 'Sync failed';
      return putWalkinRecord(row);
    });
  }

  /* ── Cached families ── */

  function putCachedFamily(family) {
    return storeOp(STORE_FAMILIES, 'readwrite', function (store) {
      return reqPromise(store.put(family));
    });
  }

  function cacheFamilies(centerId, families) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(STORE_FAMILIES, 'readwrite');
        var store = tx.objectStore(STORE_FAMILIES);
        families.forEach(function (f) {
          f.center_id = Number(centerId);
          f.cached_at = new Date().toISOString();
          store.put(f);
        });
        tx.oncomplete = function () { resolve(); };
        tx.onerror = function () { reject(tx.error); };
      });
    });
  }

  function getCachedFamilies(centerId) {
    return storeOp(STORE_FAMILIES, 'readonly', function (store) {
      var index = store.index('center_id');
      return reqPromise(index.getAll(Number(centerId))).then(function (rows) { return rows || []; });
    });
  }

  function getCachedFamily(id) {
    return storeOp(STORE_FAMILIES, 'readonly', function (store) {
      return reqPromise(store.get(Number(id)));
    });
  }

  /* ── Adjustments ── */

  function getAllAdjustments() {
    return storeOp(STORE_ADJUSTMENTS, 'readonly', function (store) {
      return reqPromise(store.getAll()).then(function (rows) { return rows || []; });
    });
  }

  function getPendingAdjustments(centerId) {
    return getAllAdjustments().then(function (rows) {
      return rows
        .filter(function (row) {
          if (row.synced) return false;
          if (centerId && Number(row.center_id) !== Number(centerId)) return false;
          return true;
        })
        .sort(function (a, b) {
          return Number(a.seq) - Number(b.seq);
        });
    });
  }

  function getRegIdsWithPendingAdjustments(centerId) {
    return getPendingAdjustments(centerId).then(function (rows) {
      var ids = {};
      rows.forEach(function (row) { ids[row.reg_id] = true; });
      return ids;
    });
  }

  function applyOfflineAdjustment(centerId, regId, field, delta, localUuid) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction([STORE_FAMILIES, STORE_ADJUSTMENTS], 'readwrite');
        var familyStore = tx.objectStore(STORE_FAMILIES);
        var adjStore = tx.objectStore(STORE_ADJUSTMENTS);
        var familyReq = familyStore.get(Number(regId));
        var updatedFamily = null;

        familyReq.onsuccess = function () {
          var family = familyReq.result;
          if (!family) {
            tx.abort();
            reject(new Error('Family not found in local cache. Load the roster while online first.'));
            return;
          }
          var current = Number(family[field] || 0);
          var next = Math.max(0, current + delta);
          if (delta < 0 && next === current) {
            updatedFamily = family;
            return;
          }
          family[field] = next;
          family.total_members = sumDemoFields(family);
          familyStore.put(family);
          updatedFamily = family;
          adjStore.add({
            local_uuid: localUuid,
            reg_id: Number(regId),
            center_id: Number(centerId),
            field: field,
            delta: delta,
            created_at: new Date().toISOString(),
            synced: false,
            sync_attempts: 0,
            last_sync_attempt: null,
            sync_error: null
          });
        };

        tx.oncomplete = function () { resolve(updatedFamily); };
        tx.onerror = function () { reject(tx.error || new Error('Adjustment transaction failed')); };
        tx.onabort = function () { reject(tx.error || new Error('Adjustment transaction aborted')); };
      });
    });
  }

  function markAdjustmentSynced(seq) {
    return storeOp(STORE_ADJUSTMENTS, 'readwrite', function (store) {
      return reqPromise(store.delete(Number(seq)));
    });
  }

  function markAdjustmentSyncFailed(seq, errorMessage) {
    return storeOp(STORE_ADJUSTMENTS, 'readwrite', function (store) {
      return reqPromise(store.get(Number(seq))).then(function (row) {
        if (!row) return null;
        row.sync_attempts = (row.sync_attempts || 0) + 1;
        row.last_sync_attempt = new Date().toISOString();
        row.sync_error = errorMessage || 'Sync failed';
        return reqPromise(store.put(row));
      });
    });
  }

  function sumDemoFields(row) {
    var keys = [
      'adults', 'children', 'seniors', 'pwds',
      'pregnant_women', 'lactating_mothers', 'infants_toddlers'
    ];
    return keys.reduce(function (sum, key) {
      return sum + Number(row[key] || 0);
    }, 0);
  }

  function reconcileFamiliesFromServer(centerId, serverFamilies, regIdsWithPending) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(STORE_FAMILIES, 'readwrite');
        var store = tx.objectStore(STORE_FAMILIES);
        serverFamilies.forEach(function (sf) {
          if (regIdsWithPending[sf.id]) return;
          sf.center_id = Number(centerId);
          sf.cached_at = new Date().toISOString();
          store.put(sf);
        });
        tx.oncomplete = function () { resolve(); };
        tx.onerror = function () { reject(tx.error); };
      });
    });
  }

  global.CoordinatorOfflineDB = {
    DB_NAME: DB_NAME,
    DB_VERSION: DB_VERSION,
    STORE_WALKINS: STORE_WALKINS,
    STORE_FAMILIES: STORE_FAMILIES,
    STORE_ADJUSTMENTS: STORE_ADJUSTMENTS,
    putRecord: putWalkinRecord,
    getAllRecords: getAllWalkinRecords,
    getPendingRecords: getPendingWalkins,
    getRecord: getWalkinRecord,
    markSynced: markWalkinSynced,
    markSyncFailed: markWalkinSyncFailed,
    putCachedFamily: putCachedFamily,
    cacheFamilies: cacheFamilies,
    getCachedFamilies: getCachedFamilies,
    getCachedFamily: getCachedFamily,
    getPendingAdjustments: getPendingAdjustments,
    getRegIdsWithPendingAdjustments: getRegIdsWithPendingAdjustments,
    applyOfflineAdjustment: applyOfflineAdjustment,
    markAdjustmentSynced: markAdjustmentSynced,
    markAdjustmentSyncFailed: markAdjustmentSyncFailed,
    reconcileFamiliesFromServer: reconcileFamiliesFromServer,
    sumDemoFields: sumDemoFields
  };
})(window);
