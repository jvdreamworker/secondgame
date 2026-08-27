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
| PATCH | `/api/players/{player}` | partial update |
| PUT | `/api/entries` | upsert by `(player_id, week)` |
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

## Deployment

See [DEPLOY.md](DEPLOY.md).
