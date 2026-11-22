# ៤. គំរូទិន្នន័យ (ERD)

តារាងកណ្តាលគឺ `rooms` និង `tenants`។ លេខ ①–⑥ = លំដាប់ដែលទិន្នន័យកើតឡើង។

| លំដាប់ | ក្រុម | តារាង |
| --- | --- | --- |
| ① | គណនី និងការកំណត់ | `users` · `personal_access_tokens` · `settings` · `activity_logs` |
| ② | បន្ទប់ | `rooms` · `room_items` |
| ③ | អ្នកជួល និងប្រាក់កក់ | `tenants` · `tenant_occupants` · `tenant_documents` · `move_in_payments` · `deposit_transactions` · `deposit_settlements` · `move_out_notices` |
| ④ | ទឹកភ្លើង | `meter_readings` · `meter_replacements` · `main_meters` · `main_meter_readings` |
| ⑤ | វិក្កយបត្រ និងប្រាក់ | `invoices` · `invoice_items` · `invoice_payments` · `payment_proofs` |
| ⑥ | ជួសជុល និងចំណាយ | `maintenance_requests` · `announcements` · `expenses` · `recurring_expenses` |

```mermaid
erDiagram
  rooms ||--o{ room_items : "មាន"
  rooms ||--o{ tenants : "ជួលដោយ"
  rooms ||--o{ meter_readings : "អាន"
  tenants ||--o{ tenant_occupants : "រួមស្នាក់"
  tenants ||--o{ tenant_documents : "ឯកសារ"
  tenants ||--o{ deposit_transactions : "ប្រាក់កក់"
  tenants ||--o{ invoices : "ទទួល"
  tenants ||--o{ maintenance_requests : "ស្នើ"
  invoices ||--o{ invoice_items : "បន្ទាត់"
  invoices ||--o{ invoice_payments : "បង់"
  invoices ||--o{ payment_proofs : "បង្កាន់ដៃ"
  main_meters ||--o{ main_meter_readings : "អាន"
  users ||--o| tenants : "គណនី portal"
```

ច្បាប់សំខាន់៖ វិក្កយបត្រមិនលុបទេ តែទុកចោល (Void)។ លេខកុងទ័រដែលមានវិក្កយបត្ររួច កែមិនបាន។ រូបឯកសារនៅក្នុង `storage/app/private` ហើយ database កត់តែផ្លូវ។
