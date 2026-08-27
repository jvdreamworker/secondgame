/**
 * idb.js — tiny promisified IndexedDB wrapper for the Second Game Pool app.
 *
 * This is the local, offline source of truth on the device. The UI reads
 * and writes here first (never blocks on the network); a separate sync
 * queue (see api-sync.js) pushes changes to the Laravel API in the
 * background whenever a connection is available.
 *
 * Object stores:
 *   config    keyPath 'key'         — single row, key: 'main'
 *   players   keyPath 'id'
 *   entries   keyPath 'id', index 'by_player_week' on [player_id, week]
 *   results   keyPath 'week'        — one row per week number
 *   queue     keyPath 'id' (autoIncrement) — pending writes to sync
 */
const DB_NAME = "poolDB";
const DB_VERSION = 1;

function openDB() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains("config")) {
        db.createObjectStore("config", { keyPath: "key" });
      }
      if (!db.objectStoreNames.contains("players")) {
        db.createObjectStore("players", { keyPath: "id" });
      }
      if (!db.objectStoreNames.contains("entries")) {
        const store = db.createObjectStore("entries", { keyPath: "id" });
        store.createIndex("by_player_week", ["player_id", "week"], { unique: true });
      }
      if (!db.objectStoreNames.contains("results")) {
        db.createObjectStore("results", { keyPath: "week" });
      }
      if (!db.objectStoreNames.contains("queue")) {
        db.createObjectStore("queue", { keyPath: "id", autoIncrement: true });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

let dbPromise = null;
function getDB() {
  if (!dbPromise) dbPromise = openDB();
  return dbPromise;
}

async function tx(storeName, mode) {
  const db = await getDB();
  return db.transaction(storeName, mode).objectStore(storeName);
}

const idb = {
  async getAll(storeName) {
    const store = await tx(storeName, "readonly");
    return new Promise((resolve, reject) => {
      const req = store.getAll();
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  },

  async get(storeName, key) {
    const store = await tx(storeName, "readonly");
    return new Promise((resolve, reject) => {
      const req = store.get(key);
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  },

  async put(storeName, value) {
    const store = await tx(storeName, "readwrite");
    return new Promise((resolve, reject) => {
      const req = store.put(value);
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  },

  async putMany(storeName, values) {
    const store = await tx(storeName, "readwrite");
    return new Promise((resolve, reject) => {
      values.forEach((v) => store.put(v));
      store.transaction.oncomplete = () => resolve();
      store.transaction.onerror = () => reject(store.transaction.error);
    });
  },

  async delete(storeName, key) {
    const store = await tx(storeName, "readwrite");
    return new Promise((resolve, reject) => {
      const req = store.delete(key);
      req.onsuccess = () => resolve();
      req.onerror = () => reject(req.error);
    });
  },

  async clear(storeName) {
    const store = await tx(storeName, "readwrite");
    return new Promise((resolve, reject) => {
      const req = store.clear();
      req.onsuccess = () => resolve();
      req.onerror = () => reject(req.error);
    });
  },

  // Look up an entry by [player_id, week] via the index — used to find the
  // existing local record before deciding whether to insert or update.
  async getEntryByPlayerWeek(playerId, week) {
    const store = await tx("entries", "readonly");
    const index = store.index("by_player_week");
    return new Promise((resolve, reject) => {
      const req = index.get([playerId, week]);
      req.onsuccess = () => resolve(req.result || null);
      req.onerror = () => reject(req.error);
    });
  },
};

window.idb = idb;
