## ឃ.1 រចនាសម្ព័ន្ធ

RTRroom រត់ជា container Docker ៤ នៅលើ Mac។ Port ទាំងអស់បើកតែក្នុង Mac (`127.0.0.1`) ដូច្នេះឧបករណ៍ផ្សេងក្នុង Wi‑Fi ចូលមិនបាន។

| Container | តួនាទី | Port |
| --- | --- | --- |
| `rtr_laravel` | API Laravel 13 (PHP) ច្បាប់គណនី វិក្កយបត្រ សុវត្ថិភាព | 127.0.0.1:8000 |
| `rtr_vuejs` | ផ្ទាំងអេក្រង់ Vue 3 + AdminLTE | 127.0.0.1:5173 |
| `rtr_mysql` | Database MySQL 8 (volume `mysql_data`) | 127.0.0.1:3307 |
| `rtr_phpmyadmin` | មើល database (បើកតែពេលត្រូវការ) | 127.0.0.1:8080 |

| ថត | អ្វី | ទៅ GitHub? |
| --- | --- | --- |
| `~/Projects/RTRroom/laravel-app` | កូដ API តេស្ត migration | បាទ |
| `~/Projects/RTRroom/vuejs-app` | កូដផ្ទាំង ពុម្ពអក្សរ Khmer OS និងសៀវភៅជំនួយនេះ | បាទ |
| `laravel-app/.env` | ការកំណត់ និង APP_KEY | **ទេ** |
| `laravel-app/storage/app/private` | រូបឯកសារអ្នកជួល បង្កាន់ដៃ | **ទេ** |
| `~/RTRroom-backups` | backup database រៀងរាល់ថ្ងៃ | **ទេ** |

## ឃ.2 ដំឡើងលើ Mac ថ្មី

1. ដំឡើង Docker Desktop និង Git។ បើក FileVault និង Firewall នៅ System Settings។
2. Clone កូដទៅ `~/Projects/RTRroom` (កុំដាក់ក្នុង Documents ឬ Desktop ព្រោះ macOS ហាម cron ចូល)។
3. ចម្លង `.env` ពី backup ដែលអ៊ិនគ្រីប (ត្រូវប្រើ **APP_KEY ដដែល** បើមិនដូច្នោះ 2FA នឹងលែងដំណើរការ)។ បន្ទាត់សំខាន់៖ `APP_KEY` `APP_TIMEZONE=Asia/Phnom_Penh` `DB_*` `FRONTEND_URL`។
4. បើក container ហើយរៀបចំ database៖

```
cd ~/Projects/RTRroom
docker compose up -d
docker compose exec laravel php artisan migrate
docker compose exec laravel php artisan storage:link
docker compose exec laravel php artisan test
```

5. ស្តារ database ពី backup (ឃ.3) ហើយចម្លងថត `storage/app/private` មកវិញ។
6. ដាក់ cron backup ២១:០០ ឡើងវិញ (`crontab -e`)។

## ឃ.3 ពាក្យបញ្ជាប្រចាំថ្ងៃ

រត់ពីថត `~/Projects/RTRroom` ជានិច្ច។

| គោលបំណង | ពាក្យបញ្ជា |
| --- | --- |
| បើក / បិទប្រព័ន្ធ | `docker compose up -d` · `docker compose stop` |
| ពិនិត្យ container | `docker compose ps` |
| រត់តេស្ត | `docker compose exec laravel php artisan test` |
| មើលកំហុស Laravel | `docker compose exec laravel tail -50 storage/logs/laravel.log` |
| ពិនិត្យ backup | `tail -5 ~/RTRroom-backups/backup.log` |
| ពិនិត្យកញ្ចប់ (រៀងរាល់ ៣ ខែ) | `docker compose exec laravel composer audit` · `docker compose exec vuejs npm audit` |

**ដំឡើង patch ថ្មី** (តាមលំដាប់)៖

1. `shasum -a 256 ~/Downloads/<ឈ្មោះ>.patch` → ផ្ទៀងផ្ទាត់នឹងលេខដែលបានផ្តល់ឱ្យ។
2. `git apply --check` → `git apply` → `git status --short`។
3. បើមាន migration៖ `docker compose exec laravel php artisan migrate`។
4. `php artisan test` → គ្រប់តេស្តត្រូវ PASS។
5. ពិនិត្យលើអេក្រង់ → `git add -A` → `git commit` → `git push`។
6. មើល GitHub → **Actions** ត្រូវមាន ✅។ បើ ❌ កុំដំឡើង patch បន្ទាប់រហូតដល់ដោះស្រាយ។

**Backup និងស្តារ**៖ cron រត់ `scripts/backup.sh` រៀងរាល់ថ្ងៃ ២១:០០។ រៀងរាល់ខែ ចម្លងថត backup + `.env` ទៅ hard disk អ៊ិនគ្រីប ហើយសាកស្តារក្នុង container សាកល្បង (ត្រូវឃើញ ៣២ តារាង) តាមជំហានក្នុងឯកសារផែនការ «បញ្ចប់គម្រោង និងសុវត្ថិភាព»។ Backup ដែលមិនធ្លាប់សាកស្តារ = មិនទាន់ជា backup ពិត។

## ឃ.4 សុវត្ថិភាព និងបញ្ហាញឹកញាប់

**សុវត្ថិភាព Mac**៖ FileVault បើក · Firewall បើក · ពាក្យសម្ងាត់ Mac ខ្លាំង · update macOS · អ្នកជួលនៅលើ Wi‑Fi ភ្ញៀវ · ម្ចាស់ផ្ទះ និងបុគ្គលិកបើក 2FA · កុំចែក `.env` database ឬថត `storage` ទៅនរណា។

| បញ្ហា | មូលហេតុញឹកញាប់ | ដំណោះស្រាយ |
| --- | --- | --- |
| ទំព័របង្ហាញ «មិនអាចភ្ជាប់ server» | container បិទ ឬ Docker មិនទាន់បើក | បើក Docker Desktop → `docker compose up -d` |
| ម៉ោងខុស (UTC) | `APP_TIMEZONE` មិនត្រូវ | ពិនិត្យ `.env` ហើយ `php artisan config:clear` |
| backup.log «Operation not permitted» | គម្រោងនៅក្នុង Documents/Desktop | ផ្លាស់ទៅ `~/Projects` |
| រកលេខកុងទ័រកែមិនបាន | ខែចាក់សោ ឬមានវិក្កយបត្ររួច | ម្ចាស់ផ្ទះទុកចោលវិក្កយបត្រ ហើយបើកសោខែ |
| ម្ចាស់ផ្ទះបាត់ទូរស័ព្ទ និងលេខកូដសង្គ្រោះ | 2FA មិនអាចចូលបាន | បិទ 2FA តាម `php artisan tinker` (មើលកំណត់ត្រា D12) |
| អក្សរខ្មែរក្នុងកិច្ចសន្យាខុសពុម្ព | Cache ចាស់ | Cmd+Shift+R |
| GitHub Actions ❌ | តេស្តបរាជ័យ ឬ build ខុស | ចុចចូលមើល log ហើយផ្ញើរូបមក Claude |

កុំមើល `.env` ទាំងមូលលើអេក្រង់ពេលមានអ្នកផ្សេងនៅជិត។ ប្រើ `grep APP_TIMEZONE laravel-app/.env` ដើម្បីមើលតែបន្ទាត់ដែលត្រូវការ។
