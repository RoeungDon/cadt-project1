## ឃ.1 រចនាសម្ព័ន្ធ

cadt-project1 ជាច្បាប់ចម្លងសាកល្បងរបស់ RTR Room សម្រាប់បង្ហាញក្នុងថ្នាក់។ វារត់ជា container Docker ៤ នៅលើកុំព្យូទ័រ។ Port ទាំងអស់បើកតែក្នុងកុំព្យូទ័រ (`127.0.0.1`) ដូច្នេះឧបករណ៍ផ្សេងក្នុង Wi‑Fi ចូលមិនបាន។ ទិន្នន័យទាំងអស់ជាទិន្នន័យក្លែងក្លាយពី `php artisan rtr:demo`។

| Container | តួនាទី | Port |
| --- | --- | --- |
| `cadt_laravel` | API Laravel 13 (PHP) ច្បាប់គណនី វិក្កយបត្រ សុវត្ថិភាព | 127.0.0.1:8000 |
| `cadt_vuejs` | ផ្ទាំងអេក្រង់ Vue 3 + AdminLTE | 127.0.0.1:5173 |
| `cadt_mysql` | Database MySQL 8 (volume `mysql_data`) | 127.0.0.1:3307 |
| `cadt_phpmyadmin` | មើល database (បើកតែពេលត្រូវការ) | 127.0.0.1:8080 |

| ថត / ឯកសារ | អ្វី | ទៅ GitHub? |
| --- | --- | --- |
| `laravel-app` | កូដ API តេស្ត migration និង `rtr:demo` | បាទ |
| `vuejs-app` | កូដផ្ទាំង ពុម្ពអក្សរ Khmer OS និងសៀវភៅជំនួយនេះ | បាទ |
| `docs/` | ឯកសារបង្ហាញក្នុងថ្នាក់៖ វិសាលភាព Use Case ស្ថាបត្យកម្ម ERD លំហូរ | បាទ |
| `.env` (ថតមេ) | ពាក្យសម្ងាត់ MySQL សម្រាប់ `compose.yaml` | **ទេ** |
| `laravel-app/.env` | ការកំណត់ និង APP_KEY | **ទេ** |
| `laravel-app/storage/app/private` | រូបបង្កាន់ដៃ និងឯកសារសាកល្បង | **ទេ** |

App ទូរស័ព្ទមិននៅក្នុង demo នេះទេ។ វានឹងជាគម្រោងទី ២។

## ឃ.2 ដំឡើង និងបើក demo

1. ដំឡើង Docker Desktop និង Git។
2. Clone កូដទៅ `~/Projects/cadt-project1`។
3. បើ RTR Room ពិតកំពុងរត់លើកុំព្យូទ័រដដែល បិទវាសិន (`cd ~/Projects/RTRroom && docker compose stop`) ព្រោះ port ដូចគ្នា។
4. រៀបចំ `.env` និងបើក container តាមលំដាប់៖

```
cd ~/Projects/cadt-project1
cp .env.example .env
cp laravel-app/.env.example laravel-app/.env
cp vuejs-app/.env.example vuejs-app/.env
docker compose build
docker compose run --rm laravel composer install
docker compose run --rm vuejs npm ci
docker compose up -d
docker compose exec laravel php artisan key:generate --force
docker compose exec laravel php artisan migrate --force
docker compose exec laravel php artisan storage:link
docker compose exec laravel php artisan rtr:demo
```

5. បើក `http://localhost:5173` ហើយចូលដោយគណនីសាកល្បងក្នុង `DEMO.md`។

## ឃ.3 ពាក្យបញ្ជាប្រចាំថ្ងៃ

រត់ពីថត `~/Projects/cadt-project1` ជានិច្ច។

| គោលបំណង | ពាក្យបញ្ជា |
| --- | --- |
| បើក / បិទ demo | `docker compose up -d` · `docker compose stop` |
| ពិនិត្យ container | `docker compose ps` |
| ចាប់ផ្តើមទិន្នន័យសាកល្បងឡើងវិញ | `docker compose exec laravel php artisan rtr:demo --fresh` |
| រត់តេស្ត | `docker compose exec laravel php artisan test` |
| មើលកំហុស Laravel | `docker compose exec laravel tail -50 storage/logs/laravel.log` |
| ពិនិត្យកញ្ចប់ | `docker compose exec laravel composer audit` · `docker compose exec vuejs npm audit` |

`rtr:demo` បដិសេធមិនរត់ពេល `APP_ENV=production` ដើម្បីការពារ database ពិត។

## ឃ.4 សុវត្ថិភាព និងបញ្ហាញឹកញាប់

**ច្បាប់ demo**៖ ប្រើតែទិន្នន័យក្លែងក្លាយ។ កុំចម្លង database ឬ `.env` របស់ RTR Room ពិតមកទីនេះ ព្រោះ repo នេះជា Public។

| បញ្ហា | មូលហេតុញឹកញាប់ | ដំណោះស្រាយ |
| --- | --- | --- |
| ទំព័របង្ហាញ «មិនអាចភ្ជាប់ server» | container បិទ ឬ Docker មិនទាន់បើក | បើក Docker Desktop → `docker compose up -d` |
| `port is already allocated` | RTR Room ពិតកំពុងប្រើ port ដដែល | `cd ~/Projects/RTRroom && docker compose stop` → បើក demo វិញ |
| `required variable MYSQL_PASSWORD is missing` | ខ្វះ `.env` នៅថតមេ | `cp .env.example .env` |
| phpMyAdmin «Access denied» | Browser បំពេញពាក្យសម្ងាត់ចាស់ | ប្រើ `MYSQL_PASSWORD` ពី `.env` |
| `rtr:demo` និយាយថា database មិនទទេ | មានទិន្នន័យរួចហើយ | `php artisan rtr:demo --fresh` |
| ម៉ោងខុស (UTC) | `APP_TIMEZONE` មិនត្រូវ | ពិនិត្យ `laravel-app/.env` ហើយ `php artisan config:clear` |
| អក្សរខ្មែរក្នុងកិច្ចសន្យាខុសពុម្ព | Cache ចាស់ | Cmd+Shift+R |
| GitHub Actions ❌ | តេស្តបរាជ័យ ឬ build ខុស | ចុចចូលមើល log |
