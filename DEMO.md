# cadt-project1 — RTR Room (demo copy)

A copy of the RTR Room rental management system (Laravel 13 + Vue 3 + AdminLTE + Docker),
filled with **made-up demo data only**. Every name, phone, ID card, account and amount is invented.

## Run

In `laravel-app/.env` (after copying it from `.env.example`) use the database from `compose.yaml`:

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
```

```
cp laravel-app/.env.example laravel-app/.env
cp vuejs-app/.env.example vuejs-app/.env
docker compose build
docker compose run --rm laravel composer install
docker compose run --rm vuejs npm ci
docker compose up -d
docker compose exec laravel php artisan key:generate --force
docker compose exec laravel php artisan migrate --force
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

## Tests

```
docker compose exec laravel php artisan test
```
