# cadt-project1 — RTR Room (demo copy)

A copy of the RTR Room rental management system (Laravel 13 + Vue 3 + AdminLTE + Docker),
filled with **made-up demo data only**. Every name, phone, ID card, account and amount is invented.
This is project 1 (the web system). The tenant phone app (Flutter) is project 2 and is not in this repo.

Class slides and diagrams (Khmer, Mermaid): [`docs/`](docs/README.md) — scope, use cases, architecture, ERD, flows and a 10-minute demo script.

## Run

Stop the real RTR Room first if it runs on the same computer (same ports):
`cd ~/Projects/RTRroom && docker compose stop`.

1. Copy the example settings:

```
cd ~/Projects/cadt-project1
cp .env.example .env
cp laravel-app/.env.example laravel-app/.env
cp vuejs-app/.env.example vuejs-app/.env
```

2. In `laravel-app/.env` use the database from `compose.yaml` (`DB_PASSWORD` = `MYSQL_PASSWORD` in `.env`):

```
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=rtr_room
DB_USERNAME=rtr_user
DB_PASSWORD=secret
FRONTEND_URL=http://localhost:5173
SANCTUM_STATEFUL_DOMAINS=localhost:5173
SESSION_DOMAIN=localhost
APP_TIMEZONE=Asia/Phnom_Penh
```

3. Build, start and fill with demo data:

```
docker compose build
docker compose run --rm laravel composer install
docker compose run --rm vuejs npm ci
docker compose up -d
docker compose exec laravel php artisan key:generate --force
docker compose exec laravel php artisan migrate --force
docker compose exec laravel php artisan storage:link
docker compose exec laravel php artisan rtr:demo
```

Open http://localhost:5173 and log in (password `Demo1234`):

| Role   | Phone     |
|--------|-----------|
| owner  | 010000001 |
| staff  | 010000002 |
| viewer | 010000003 |

`rtr:demo` prints two tenant portal links (`/qr#…`); open one and type the tenant phone shown next to it.
To start over: `php artisan rtr:demo --fresh` (drops every table first). It refuses to run when `APP_ENV=production`.

## What the demo holds

6 rooms (D101–D106, one empty, D105 pays EDC itself), 5 tenants, 4 months of meter readings and invoices
(the last month partly unpaid), a KHQR receipt waiting for review, repairs, a move-out notice,
main meters (EDC/PPWSA), monthly and one-off expenses, room item lists for the contract annex.

## Billing days

- Meters are read on the 28th; invoices are made on the 30th (the last day of February).
- The invoice for month P = water and electricity of P + rent of P+1.
- A tenant who moves in on or after the 28th gets a "Rent only" first invoice; one who paid next month's rent on moving in shows "Nothing to charge".
- The sidebar "Today" box counts down to invoice day and turns red when invoices are late.

## Tests

```
docker compose exec laravel php artisan test
```

GitHub Actions runs the Laravel tests, `composer audit` and the Vue build on every push.
