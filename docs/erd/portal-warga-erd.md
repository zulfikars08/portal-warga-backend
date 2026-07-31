# ERD Portal Warga — Schema Aktual

Diagram mengikuti migration `2026_01_01_000000_create_portal_tables.php` plus tabel users/personal access tokens Laravel.

```mermaid
erDiagram
  USERS ||--o{ PAYMENTS : creates
  USERS ||--o{ EXPENSES : creates
  USERS ||--o{ AUDIT_LOGS : acts
  HOUSES ||--o{ RESIDENTS : occupies
  HOUSES ||--o{ HOUSEHOLD_HISTORIES : records
  RESIDENTS ||--o{ HOUSEHOLD_HISTORIES : has
  RESIDENTS ||--o{ PRIVATE_DOCUMENTS : owns
  HOUSES ||--o{ BILLS : receives
  FEE_RATES o|--o{ BILLS : prices
  HOUSES ||--o{ PAYMENTS : pays
  PAYMENTS ||--o{ PAYMENT_ALLOCATIONS : allocates
  BILLS ||--o{ PAYMENT_ALLOCATIONS : receives
  EXPENSE_CATEGORIES ||--o{ EXPENSES : classifies
  USERS ||--o{ MODEL_HAS_ROLES : assigned
  ROLES ||--o{ MODEL_HAS_ROLES : grants
  ROLES ||--o{ ROLE_HAS_PERMISSIONS : has
  PERMISSIONS ||--o{ ROLE_HAS_PERMISSIONS : grants

  HOUSES { bigint id PK; string number UK; string address; string status }
  RESIDENTS { bigint id PK; bigint house_id FK; string nik UK; string name; string gender; date birth_date; string phone; boolean is_head; boolean active }
  HOUSEHOLD_HISTORIES { bigint id PK; bigint resident_id FK; bigint house_id FK; date started_at; date ended_at }
  PRIVATE_DOCUMENTS { bigint id PK; bigint resident_id FK; string type; string path }
  FEE_RATES { bigint id PK; string name; bigint amount; date effective_from; date effective_until; boolean active }
  BILLS { bigint id PK; bigint house_id FK; bigint fee_rate_id FK; string type; string title; date period; date due_date; bigint amount; bigint paid_amount; string status; json fee_snapshot; text note }
  PAYMENTS { bigint id PK; bigint house_id FK; bigint amount; bigint advance_amount; datetime paid_at; string status; string proof_path; text note; bigint created_by FK; bigint cancelled_by FK; datetime cancelled_at }
  PAYMENT_ALLOCATIONS { bigint id PK; bigint payment_id FK; bigint bill_id FK; bigint amount }
  EXPENSE_CATEGORIES { bigint id PK; string name UK; boolean active }
  EXPENSES { bigint id PK; bigint expense_category_id FK; string description; bigint amount; date spent_at; string proof_path; string status; bigint created_by FK; bigint cancelled_by FK; datetime cancelled_at }
  OPENING_BALANCES { bigint id PK; date as_of UK; bigint amount; text note }
  AUDIT_LOGS { bigint id PK; bigint user_id FK; string action; string auditable_type; bigint auditable_id; json old_values; json new_values; string ip }
```

Semua tabel domain memiliki `created_at` dan `updated_at`. Relasi `cancelled_by`, permission langsung (`model_has_permissions`), dan token tidak digambar agar terbaca.

## Constraint penting

- `houses.number`, `residents.nik`, `expense_categories.name`, dan `opening_balances.as_of` unik.
- Bill unik pada `(house_id, fee_rate_id, period)`.
- Allocation unik pada `(payment_id, bill_id)`.
- Resident→house memakai `SET NULL`; history/document dan allocation memakai cascade sesuai migration.
- Nominal unsigned kecuali opening balance.
- Status berupa string, bukan DB enum/check; validasi/service menjaga sebagian nilai.
- Role/permission memakai tabel pivot Spatie; `users` berasal migration Laravel terpisah.

SVG asli berbasis XML tersedia di `portal-warga-erd.svg`. Itu diagram ringkas yang ditulis langsung, bukan render palsu dari runtime DB.

MySQL belum tersedia pada environment dokumentasi; ERD diverifikasi statis dari migration.
