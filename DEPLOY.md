# Deploying to bowl.thecommish.app

Laravel Forge, same server as TheCommish.app (`52.144.44.37`), **separate
database** `secondgame`. The server runs PHP 8.4 — this app targets 8.2+, so
8.4 is fine.

## One-time setup

1. **Create the database** (Forge → server → Database):
   - Name: `secondgame`
   - Add a database user with access to only that database.

2. **Create the site** (Forge → server → New Site):
   - Domain: `bowl.thecommish.app`
   - Web directory: `/public`
   - PHP version: 8.4

3. **DNS**: point `bowl.thecommish.app` (A record) at `52.144.44.37`.

4. **Git repo**: connect the site to this repository, branch `main`.
   Enable "Quick Deploy".

5. **Environment** (Forge → site → Environment) — start from `.env.example`:
   ```
   APP_NAME="Second Game Pool"
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://bowl.thecommish.app

   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_DATABASE=secondgame
   DB_USERNAME=<the db user from step 1>
   DB_PASSWORD=<its password>

   SESSION_DRIVER=file
   SESSION_SECURE_COOKIE=true

   # seeded once, then change it in-app at /password
   OPERATOR_EMAIL=jvercelletto@gmail.com
   OPERATOR_PASSWORD=<pick a strong one>
   SEASON_LABEL="2026 Second Game Pool"
   ```
   Run `php artisan key:generate` (or let Forge set `APP_KEY`).

6. **SSL**: Forge → site → SSL → Let's Encrypt.

7. **First deploy**, then seed once over SSH:
   ```
   cd /home/forge/bowl.thecommish.app
   php artisan migrate --force
   php artisan db:seed --force        # creates the operator user + 2026 season
   ```

## Deploy script (Forge default is fine, plus a couple of lines)

```bash
cd /home/forge/bowl.thecommish.app
git pull origin $FORGE_SITE_BRANCH
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`db:seed` is intentionally **not** in the deploy script — it is a one-time
step. `DatabaseSeeder` is idempotent (it won't clobber an existing operator or
season) but there's no reason to run it every push.

## After deploy

- Visit `https://bowl.thecommish.app/` → redirects to `/login`.
- Sign in, go to `/password`, set a real password.
- Open `/pool` and populate the roster from the Roster tab, either:
  - **Import from Excel (.xlsx)** — a spreadsheet with name in column 1,
    team number in column 2, team name in column 4 (a header row is fine);
    exact `name + team_number + team` duplicates are skipped, and it reports
    "X imported, Y skipped", or
  - **Import last season's roster** — the ~100 names embedded in the app.
- "Add to Home Screen" to install the PWA. Do this while signed in so the
  service worker caches an authenticated shell.

## Static assets / PWA notes

- `public/service-worker.js` and `public/manifest.json` are served from the
  site root — the SW scope is `/`, which is what it needs.
- When you change anything in `public/js/`, `public/css/`, or the icons, bump
  `CACHE_VERSION` in `public/service-worker.js` so clients pick up the new
  files instead of a stale cache.
- There is no `npm`/Vite build for the pool app — the files in `public/` are
  shipped as-is.
