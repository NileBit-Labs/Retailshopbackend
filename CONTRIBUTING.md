# Contributing to retailbackend

Laravel API for NileBit Retail POS. PostgreSQL for local dev, Sanctum for API auth.

## Setup

Install Postgres and start it on port 5433, kept separate from any other local Postgres you may have running on the default 5432:

```bash
brew install postgresql@17
brew services start postgresql@17
```

Homebrew's default port is 5432 — edit `port = 5432` to `port = 5433` in `$(brew --prefix)/var/postgresql@17/postgresql.conf` before starting if you already have something on 5432, then `brew services restart postgresql@17`.

Create the shared role and database (local trust auth, no password):

```bash
createdb -h localhost -p 5433 nilebit_retail
psql -h localhost -p 5433 -d postgres -c "CREATE ROLE nilebit WITH LOGIN SUPERUSER;"
psql -h localhost -p 5433 -d postgres -c "ALTER DATABASE nilebit_retail OWNER TO nilebit;"
```

Then the app:

```bash
git clone https://github.com/NileBit-Labs/Retailshopbackend.git
cd Retailshopbackend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

API is now at `http://localhost:8200` — pinned via `SERVER_PORT` in `.env.example`, not Laravel's default 8000, since port 8000 is the default for basically every Laravel project and will collide with any other one you have running locally. If you ever see a working login form suddenly fail with "The route api/... could not be found", it almost always means something *else* is squatting on this port — check `lsof -nP -iTCP -sTCP:LISTEN` before assuming the code is broken.

Run `php artisan test` before opening a PR — it must pass (tests run against an in-memory SQLite, not Postgres, so no extra setup needed there).

## Module ownership

| Person | Track |
| --- | --- |
| Elioda Muhangi (CTO) | Foundation (auth/RBAC), Sales/POS, Offline Sync — also reviews/merges every PR |
| Collins Shema (COO) | Products & Inventory, Suppliers & Purchases, Expenses |
| Douglas Bagambe (CEO) | Customers & Credit, Users & Security, Dashboard & Reports |

Stick to your own module's tables/routes/controllers unless you're coordinating a shared change (e.g. the `stock_movements` schema, or the `POST /sales/:id/refund` contract) — flag those in a PR description or ask before touching another track's files.

## Branching & PRs

- Branch off `main`: `feature/<your-module>-<short-description>` (e.g. `feature/inventory-low-stock-alerts`)
- No direct pushes to `main` — open a PR, get at least one review before merging
- Keep PRs scoped to one module/feature at a time
