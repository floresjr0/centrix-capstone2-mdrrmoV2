/**
 * Coordinator-only IndexedDB queue for offline walk-in registrations.
 * Database: coordinator_offline_queue / store: pending_walkins
 */
(function (global) {
  'use strict';

  var DB_NAME = 'coordinator_offline_queue';
  var DB_VERSION = 1;
  var STORE = 'pending_walkins';
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
        if (!db.objectStoreNames.contains(STORE)) {
          var store = db.createObjectStore(STORE, { keyPath: 'local_uuid' });
          store.createIndex('synced', 'synced', { unique: false });
          store.createIndex('center_id', 'center_id', { unique: false });
          store.createIndex('created_at', 'created_at', { unique: false });
        }
      };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { reject(req.error || new Error('IndexedDB open failed')); };
    });
    return dbPromise;
  }

  function tx(mode) {
    return openDb().then(function (db) {
      return db.transaction(STORE, mode).objectStore(STORE);
    });
  }

  function putRecord(record) {
    return tx('readwrite').then(function (store) {
      return new Promise(function (resolve, reject) {
        var req = store.put(record);
        req.onsuccess = function () { resolve(record); };
        req.onerror = function () { reject(req.error); };
      });
    });
  }

  function getAllRecords() {
    return tx('readonly').then(function (store) {
      return new Promise(function (resolve, reject) {
        var req = store.getAll();
        req.onsuccess = function () { resolve(req.result || []); };
        req.onerror = function () { reject(req.error); };
      });
    });
  }

  function getPendingRecords(centerId) {
    return getAllRecords().then(function (rows) {
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

  function getRecord(localUuid) {
    return tx('readonly').then(function (store) {
      return new Promise(function (resolve, reject) {
        var req = store.get(localUuid);
        req.onsuccess = function () { resolve(req.result || null); };
        req.onerror = function () { reject(req.error); };
      });
    });
  }

  function markSynced(localUuid, serverId) {
    return getRecord(localUuid).then(function (row) {
      if (!row) return null;
      row.synced = true;
      row.sync_error = null;
      row.server_registration_id = serverId || null;
      row.synced_at = new Date().toISOString();
      return putRecord(row);
    });
  }

  function markSyncFailed(localUuid, errorMessage) {
    return getRecord(localUuid).then(function (row) {
      if (!row) return null;
      row.sync_attempts = (row.sync_attempts || 0) + 1;
      row.last_sync_attempt = new Date().toISOString();
      row.sync_error = errorMessage || 'Sync failed';
      return putRecord(row);
    });
  }

  global.CoordinatorOfflineDB = {
    DB_NAME: DB_NAME,
    STORE: STORE,
    putRecord: putRecord,
    getAllRecords: getAllRecords,
    getPendingRecords: getPendingRecords,
    getRecord: getRecord,
    markSynced: markSynced,
    markSyncFailed: markSyncFailed
  };
})(window);
