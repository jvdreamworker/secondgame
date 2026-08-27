# Second Game Pool — frontend (vanilla JS, offline-first)

This is a straight port of the original React prototype to plain JS + IndexedDB,
so it can be dropped into a Laravel app with **no build step required** (no npm,
no Vite, no bundler — just static files Laravel serves as-is). If you'd rather
run it through Vite/your existing frontend pipeline, that's fine too — move
`public/js/*.js` into `resources/js/` and import them from your `app.js` entry.

## Where these files go

```
public/manifest.json                        → as-is
public/service-worker.js                    → as-is (must stay at site root — service workers can only control paths at or below where they're served from)
public/icons/pool-192.png, pool-512.png     → placeholder icons, swap for real ones later
public/css/pool.css                          → as-is
public/js/idb.js                             → as-is
public/js/api-sync.js                        → as-is
public/js/pool-app.js                        → as-is
resources/views/pool/index.blade.php         → as-is
```

Add a route:
```php
// routes/web.php
Route::get('/pool', function () {
    $season = \App\Models\Season::latest()->first(); // or however you pick "current"
    return view('pool.index', ['seasonId' => $season?->id]);
});
```

## What Claude Code still needs to build (the backend half)

This frontend assumes the Laravel API contract documented at the top of
`public/js/api-sync.js`:

- `GET  /api/seasons/{id}/bundle` → `{ config, players[], entries[], results[] }`
  (a single bundle endpoint keeps the first-load-with-connection path simple —
  the frontend seeds IndexedDB from this once and then works from local data)
- `POST  /api/players`
- `PATCH /api/players/{id}`
- `PUT   /api/entries` (upsert by unique `[player_id, week]`)
- `DELETE /api/entries/{player_id}/{week}`
- `PUT   /api/weekly-results/{week}`
- `PATCH /api/config`

**Important schema note:** `players.id` should be a UUID string column (not
auto-increment integer). The frontend generates player IDs client-side with
`crypto.randomUUID()` so a payment recorded offline doesn't need to wait for
the server to hand back a real ID before it can be used elsewhere (e.g. as
`winner_player_id` on a weekly result entered in the same offline session).
Use `$table->uuid('id')->primary();` in the migration.

`entries` stays a normal auto-increment table server-side — it's looked up by
the unique `(player_id, week)` pair, never by its own ID, so there's no
offline-ID problem there.

## Business logic — keep this in sync

`computeStats()` in `pool-app.js` (pot/carryover/payout math) must match the
`PoolCalculator` service on the backend **exactly**, since the frontend
computes stats locally (for offline use) but the backend should also be able
to recompute/verify the same numbers from its own copy of the data. The rule,
one more time for reference:

- Pot(week) = Carryover(week − 1) + count of entries that week with
  status `paid` or `covered` (NOT `exempt` — exempt is a record-keeping note
  for weeks a player wasn't charged, and must never inflate a past week's pot).
- If a winner is recorded: Payout = pot (editable), Carryover(week) = Pot − Payout.
- If no winner: Carryover(week) = Pot (rolls forward).

## Known limitations to flag to the operator

- This is a **single-operator, offline-first** design — if two devices go
  offline and both record a payment for the same player/week before either
  syncs, last-write-wins (whichever syncs to the server second). Fine for one
  person running the pool from one device most nights; would need real
  conflict resolution if opened up to multiple simultaneous operators.
- The service worker caches the *app shell* (so the UI loads offline) but
  **all real data lives in IndexedDB on that device** until it syncs. If the
  operator never opens the app on a second device, there's no copy of the
  season anywhere else — the `/api/*` sync is what makes the server (and thus
  a second device) eventually have a copy too.
