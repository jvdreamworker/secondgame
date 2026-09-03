# Second Game Pool — backend

Laravel API + PWA shell for the bowling-league second-game side pot.
Deploys to `bowl.thecommish.app`. Completely separate from TheCommish.app
(its own codebase, its own `secondgame` database).

## What this is

- **API-only backend** (Laravel 12, PHP 8.2+). No server-side rendering
  beyond the app shell and a login form.
- **Offline-first PWA frontend** (vanilla JS + IndexedDB) served as static
  files from `public/`. It is the reference implementation of the pool math;
  the backend mirrors it in `App\Services\PoolCalculator`.
- **Single operator.** Session auth, one seeded user, no registration.

The domain rules and the API contract are described in
`starting-templates/laravel-frontend/README.md` and at the top of
`public/js/api-sync.js`. Read those before changing anything.

## Local setup

WAMP ships several PHP builds; this project needs 8.2+. Use the 8.2 binary
explicitly (the shell default is 8.1):

```
PHP="C:/wamp64/bin/php/php8.2.0/php.exe"

# database
"C:/wamp64/bin/mysql/mysql8.0.31/bin/mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS secondgame CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

$PHP artisan migrate --seed      # prints a throwaway operator password
$PHP artisan serve --port=8000   # http://localhost:8000  ->  /pool
$PHP artisan test
```

`.env` is already pointed at MySQL `secondgame` on `127.0.0.1:3306` as `root`
with no password (WAMP default).

## Routes

| Method | Path | Purpose |
|---|---|---|
| GET | `/` | → `/pool` |
| GET | `/login`, POST `/login`, POST `/logout` | operator session auth |
| GET/PUT | `/password` | operator changes their own password |
| GET | `/pool` | the PWA shell (auth) |
| GET | `/api/seasons/{season}/bundle` | full season snapshot for IndexedDB seeding |
| GET | `/api/seasons/{season}/stats` | server-computed pot/carry (verification aid) |
| POST | `/api/players` | create (idempotent on client UUID) |
| POST | `/api/players/import` | multipart `.xlsx` upload — cols 1=name, 2=team_number, 3=team (falls back to col 4 for the old layout). **Merges** by name+team_number: matched players updated in place (id + entries kept), new rows inserted, players absent from the file deactivated. Returns `{imported, updated, deactivated, skipped, players[]}` |
| PATCH | `/api/players/{player}` | partial update (name / team_number / team / active) |
| PATCH | `/api/players/team/{teamNumber}/name` | bulk-set the team **name** on every current-season player with that team number |
| PUT | `/api/entries` | upsert by `(player_id, week)` — body `{player_id, week, amount, status, note, received_on}` (`received_on` = `"YYYY-MM-DD"` cash-received date or `null`) |
| DELETE | `/api/entries/{player}/{week}` | remove (204 even if absent) |
| PUT | `/api/weekly-results/{week}` | upsert result for the current season |
| PATCH | `/api/config` | update the current season's settings |

All `/api/*` routes are in `routes/web.php` on purpose — they use the same
session cookie + CSRF token the frontend already sends.

## The pool math

`App\Services\PoolCalculator` is a line-for-line port of `computeStats()` in
`public/js/pool-app.js`. `tests/Unit/PoolCalculatorTest.php` pins the numbers.
If you change the rule in one place, change it in both and keep that test green.

- `pot(week) = carry(week-1) + count of paid/covered entries that week`
  (`exempt` entries are never counted).
- winner ⇒ `payout = recorded payout`, `carry = pot - payout`.
- no winner ⇒ `carry = pot` (rolls forward).

## Frontend notes

- No build step. `resources/views/pool/index.blade.php` appends `?v=<filemtime>`
  to the CSS/JS URLs, so a `git pull` on deploy busts the browser cache. When
  you change anything under `public/js/` or `public/css/`, **also bump
  `CACHE_VERSION` in `public/service-worker.js`** (offline clients keep the old
  precache otherwise) and mirror the changed files into
  `starting-templates/laravel-frontend/public/`.
- `WEEK_DATES` in `pool-app.js` maps weeks 3–34 to their Thursday dates
  (2026-27 season). The dashboard opens on the week whose Thursday falls in the
  current Sun–Sat window (so it advances on Sunday, giving the weekend to
  record results), falling back to the next upcoming week, then week 34.
- The 2026-27 season runs weeks **3–34** (`start_week = 3`, `total_weeks = 32`).
  Set this on the Settings screen or via `Season::current()->update([...])`;
  the seeder default is still `1` / `33`.

## Roster maintenance commands

```
$PHP artisan players:flush [--season=ID] [--force]        # delete every player in a season + their entries
$PHP artisan players:purge-split-names [--season=ID] [--force]  # delete comma-less names (old split-import artifacts)
$PHP artisan entries:cleanup-orphans [--force]            # delete entries whose player_id no longer exists
```

Re-importing the roster is a safe merge (see the routes table) — no checkbox,
no data loss.

## Deployment

See [DEPLOY.md](DEPLOY.md).
