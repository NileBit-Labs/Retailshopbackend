# Contributing to retailbackend

Laravel API for NileBit Retail POS. SQLite for local dev, Sanctum for API auth.

## Setup

```bash
git clone https://github.com/NileBit-Labs/Retailshopmangement.git
cd Retailshopmangement
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan serve
```

API is now at `http://localhost:8000`. Run `php artisan test` before opening a PR — it must pass.

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
