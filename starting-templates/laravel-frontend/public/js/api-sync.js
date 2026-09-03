/**
 * api-sync.js — background sync between local IndexedDB and the Laravel API.
 *
 * Every local mutation calls Sync.enqueue(job) with a description of what
 * changed. This module tries to push queued jobs to the server immediately,
 * and retries on an interval + whenever the browser comes back online.
 * Nothing here blocks the UI — writes to IndexedDB already happened before
 * a job is enqueued, so the app is fully usable offline.
 *
 * ---- API contract this expects from Laravel (see backend spec) ----
 *   GET   /api/seasons/:id/bundle
 *         -> { config, players: [...], entries: [...], results: [...] }
 *   POST  /api/players                body: { client_id, name, team, active }
 *   PATCH /api/players/:client_id     body: { name?, team?, active? }
 *   PUT   /api/entries                body: { player_id, week, amount, status, note, received_on }
 *         (upsert by unique [player_id, week] — server returns canonical row;
 *          received_on is a "YYYY-MM-DD" string or null)
 *   DELETE /api/entries/:player_id/:week
 *   PUT   /api/weekly-results/:week   body: { score, winner_player_id, payout, note }
 *   POST  /api/players/import         multipart: file=<.xlsx>; merges by name+team_number
 *   PATCH /api/config                 body: { seasonLabel?, entryFee?, startWeek?, totalWeeks? }
 * ---------------------------------------------------------------------
 * Adjust ROUTES below if your backend uses different paths.
 */
const ROUTES = {
  bundle: (seasonId) => `/api/seasons/${seasonId}/bundle`,
  players: () => `/api/players`,
  playerImport: () => `/api/players/import`,
  player: (id) => `/api/players/${id}`,
  teamRename: (teamNumber) => `/api/players/team/${encodeURIComponent(teamNumber)}/name`,
  entryUpsert: () => `/api/entries`,
  entryDelete: (playerId, week) => `/api/entries/${playerId}/${week}`,
  result: (week) => `/api/weekly-results/${week}`,
  config: () => `/api/config`,
};

function csrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.content : "";
}

async function apiFetch(url, options = {}) {
  const res = await fetch(url, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-TOKEN": csrfToken(),
      Accept: "application/json",
      ...(options.headers || {}),
    },
  });
  if (!res.ok) throw new Error(`${options.method || "GET"} ${url} failed: ${res.status}`);
  const text = await res.text();
  return text ? JSON.parse(text) : null;
}

async function sendJob(job) {
  switch (job.type) {
    case "player.create":
      return apiFetch(ROUTES.players(), { method: "POST", body: JSON.stringify(job.payload) });
    case "player.update":
      return apiFetch(ROUTES.player(job.payload.id), { method: "PATCH", body: JSON.stringify(job.payload) });
    case "team.rename":
      return apiFetch(ROUTES.teamRename(job.payload.team_number), { method: "PATCH", body: JSON.stringify({ team: job.payload.team }) });
    case "entry.upsert":
      return apiFetch(ROUTES.entryUpsert(), { method: "PUT", body: JSON.stringify(job.payload) });
    case "entry.delete":
      return apiFetch(ROUTES.entryDelete(job.payload.player_id, job.payload.week), { method: "DELETE" });
    case "result.upsert":
      return apiFetch(ROUTES.result(job.payload.week), { method: "PUT", body: JSON.stringify(job.payload) });
    case "config.update":
      return apiFetch(ROUTES.config(), { method: "PATCH", body: JSON.stringify(job.payload) });
    default:
      throw new Error("Unknown job type: " + job.type);
  }
}

const listeners = new Set();
function notify(state) {
  listeners.forEach((fn) => fn(state));
}

let flushing = false;

const Sync = {
  onStatus(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
  },

  async enqueue(job) {
    await idb.put("queue", { ...job, ts: Date.now() });
    notify({ event: "queued" });
    this.flush();
  },

  async pendingCount() {
    const all = await idb.getAll("queue");
    return all.length;
  },

  async flush() {
    if (flushing) return;
    if (!navigator.onLine) {
      notify({ event: "offline" });
      return;
    }
    flushing = true;
    notify({ event: "syncing" });
    try {
      const jobs = await idb.getAll("queue");
      for (const job of jobs) {
        try {
          await sendJob(job);
          await idb.delete("queue", job.id);
        } catch (e) {
          // Leave it queued and stop here — likely a connectivity blip.
          // The interval / online listener will retry later.
          console.warn("Sync job failed, will retry:", job, e);
          break;
        }
      }
    } finally {
      flushing = false;
      const remaining = await this.pendingCount();
      notify({ event: "done", pending: remaining });
    }
  },

  // Uploads an .xlsx roster to the server for import. This is an online-only,
  // one-shot action (not part of the offline queue) — the server does the
  // parsing/dedup and returns { imported, skipped, players: [...] }.
  async uploadPlayerImport(file) {
    const form = new FormData();
    form.append("file", file);
    const res = await fetch(ROUTES.playerImport(), {
      method: "POST",
      headers: { "X-CSRF-TOKEN": csrfToken(), Accept: "application/json" },
      body: form,
    });
    let body = null;
    try { body = await res.json(); } catch (e) { /* ignore */ }
    if (!res.ok) {
      throw new Error((body && body.message) || `Import failed (${res.status})`);
    }
    return body || { imported: 0, skipped: 0, players: [] };
  },

  // Pulls the full season bundle from the server and seeds local IndexedDB.
  // Only meaningful the very first time the app is opened with a connection,
  // or as a manual "resync from server" action — local IDB is normally the
  // source of truth after that.
  async pullBundleAndSeed(seasonId) {
    const bundle = await apiFetch(ROUTES.bundle(seasonId));
    if (bundle.config) await idb.put("config", { key: "main", ...bundle.config });
    if (bundle.players?.length) await idb.putMany("players", bundle.players);
    if (bundle.entries?.length) await idb.putMany("entries", bundle.entries);
    if (bundle.results?.length) {
      await idb.putMany(
        "results",
        bundle.results.map((r) => ({ week: r.week, ...r }))
      );
    }
    return bundle;
  },

  init() {
    window.addEventListener("online", () => this.flush());
    setInterval(() => this.flush(), 5000);
    this.flush();
  },
};

window.Sync = Sync;
