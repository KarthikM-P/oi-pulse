# OI Pulse — PHP backend

Fetches NSE's Nifty option chain, computes PCR/support-resistance/Black-Scholes
delta, and serves it to the React frontend via three endpoints. A Windows cron
job does the fetching and pushes calculated snapshots to Hostinger; the API
endpoints only ever read from MySQL, so page loads are fast and NSE never sees
per-visitor traffic.

## Architecture

```
Windows (local machine)
  └─ cron/fetch_snapshot.php (every 5 minutes)
       ├─ Fetches NSE option-chain data
       ├─ Runs calculations (PCR, support/resistance, Black-Scholes delta)
       └─ POSTs snapshot to Hostinger → /api/push_snapshot.php

Hostinger (production)
  ├─ /api/push_snapshot.php  ← receives + stores snapshot (authenticated)
  ├─ /api/latest.php         ← serves latest snapshot to frontend
  ├─ /api/history.php        ← serves intraday PCR/spot history
  └─ /api/signal.php         ← computes trading signal from latest snapshot
```

## Configuration

All secrets and environment-specific values are loaded from **environment
variables** or a local override file. **No credentials are stored in the
source code.**

### Environment Variables

| Variable           | Description                                        |
| ------------------ | -------------------------------------------------- |
| `OIPULSE_DB_HOST`  | MySQL hostname (default: `127.0.0.1`)              |
| `OIPULSE_DB_NAME`  | MySQL database name (default: `oi_pulse`)          |
| `OIPULSE_DB_USER`  | MySQL username (default: `root`)                   |
| `OIPULSE_DB_PASS`  | MySQL password                                     |
| `OIPULSE_SECRET`   | Shared secret for the `X-OIPULSE-SECRET` header    |

### Local Development

For local development, create `src/config.local.php`:

```php
<?php

return [
    'db_host' => '127.0.0.1',
    'db_name' => 'oi_pulse',
    'db_user' => 'root',
    'db_pass' => 'YOUR_LOCAL_PASSWORD_HERE',
    'oipulse_secret' => 'YOUR_SECRET_HERE',
];
```

> **⚠️ Never commit `src/config.local.php` to Git.** It is already in
> `.gitignore`.

The config loading priority is:
1. `src/config.local.php` (if it exists, values override defaults)
2. Environment variables (`OIPULSE_DB_HOST`, etc.)
3. Safe defaults (localhost, empty password)

## Production Deployment (Hostinger)

### 1. Set Environment Variables

Production must provide the following through a **server-side / private
configuration mechanism** (Hostinger hPanel, `.htaccess` `SetEnv`, or a
private config file outside `public_html`):

- `OIPULSE_DB_HOST`
- `OIPULSE_DB_NAME`
- `OIPULSE_DB_USER`
- `OIPULSE_DB_PASS`
- `OIPULSE_SECRET`

**Do not put actual production values in the repository.**

If Hostinger does not support environment variables directly, you can create
`src/config.local.php` on the server (outside `public_html`) with your
production credentials. This file is `.gitignored` and will not be overwritten
by deployments.

### 2. Database

1. In Hostinger hPanel: Databases → MySQL Databases → create a database + user.
2. Import `schema.sql` (phpMyAdmin → Import, or `mysql -u USER -p DBNAME < schema.sql`).

### 3. Deploy the Files

- `public/api/*.php` → your `public_html/api/` (or wherever the frontend build lands)
- `src/`, `cron/`, `schema.sql` → **outside** `public_html`,
  e.g. a sibling `backend/` folder, so `config.php` (credential lookups) is
  never web-accessible.

### 4. Windows Cron Setup

The Windows machine runs `cron/fetch_snapshot.php` every 5 minutes:

```
run_fetch.bat  →  php cron/fetch_snapshot.php
```

The script fetches NSE data, calculates the snapshot, and pushes it to:

```
https://mediumblue-cassowary-262239.hostingersite.com/api/push_snapshot.php
```

### 5. Optional: History Cleanup

Run `cron/cleanup_history.php` daily to prune old history rows (> 30 days):

```
30 0 * * * /usr/bin/php /path/to/cron/cleanup_history.php
```

## Step 1 — Test the NSE fetch

```bash
php test_fetch.php
```

This calls NSE, parses the response, and prints spot/PCR/sample data — or a clear
error. If you get a 401/403: the NSE anti-bot protection may be blocking the
request.

## Step 2 — Point the frontend at it

**In production on Hostinger**, if the built frontend and `public/api/` share
the same domain, no change is needed — the frontend already calls relative
paths like `/api/latest.php`.

**For local development**, run a local PHP server alongside the Vite dev server:

```bash
# Terminal 1 — backend
cd oi-pulse-backend
php -S localhost:8000 -t public

# Terminal 2 — frontend
cd oi-pulse-review
npm run dev
```

Then add a dev-only proxy so the frontend's `/api/*` calls reach PHP on port
8000 — in `oi-pulse-review/vite.config.ts`:

```ts
export default defineConfig({
  tanstackStart: { server: { entry: "server" } },
  vite: {
    server: {
      proxy: {
        "/api": { target: "http://localhost:8000", changeOrigin: true },
      },
    },
  },
});
```

## Known Simplifications

- **`spotChng`** (the "+44.03" next to the Nifty price) is computed as *change
  since today's first cron snapshot*, not NSE's own "change vs. previous
  close." It'll read 0 on the very first poll of the day and won't exactly
  match the number NSE's own UI shows.
- **PCR window** uses ±8 strikes *by position* (8 strikes up, 8 down, however
  they're spaced), which is actually more correct than the frontend mock's
  fixed ±400-point assumption — real NSE strike spacing widens away from ATM.
- **Lot size** is a manual value in `config.php` (`75` as of writing) since
  NSE's option-chain endpoint doesn't return it — update it there if NSE
  revises Nifty's lot size.
- On fetch/parse failure, the cron job deliberately leaves the last good
  snapshot in place rather than overwriting it with an error — the frontend
  will just show slightly stale data with its existing "stale" indicator,
  rather than breaking.
