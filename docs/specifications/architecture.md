# Arsitektur Portal Warga — As-built

## Ringkasan

```text
Browser
  -> React 19 + React Router + TanStack Query + Axios + Vite
  -> /api/v1 (JSON/multipart, Bearer Sanctum)
  -> Laravel 13 route/controller
  -> FinanceService untuk transaksi finansial
  -> Eloquent
  -> MySQL
  -> Laravel local storage untuk file privat/bukti
```

Aplikasi berupa dua proyek dalam satu repository: `portal-warga-frontend/` SPA dan `portal-warga-backend/` API. Tidak ada microservice, message broker, atau modul warga publik.

## Backend

- `routes/api.php`: kontrak HTTP `/api/v1`.
- `ApiController`: login, generic CRUD allowlist, dashboard, laporan, ekspor, dokumen, pembayaran/pembatalan.
- `FinanceService`: transaksi pembayaran/pembatalan dan audit.
- Eloquent model: rumah, penghuni, riwayat, dokumen, tarif, tagihan, pembayaran/alokasi, kategori/pengeluaran, saldo awal, audit, user.
- Sanctum: personal access token.
- Spatie Permission: schema/model role dan permission; alias middleware terdaftar, belum dipasang pada route API.
- DomPDF: ekspor PDF. Maatwebsite Excel terpasang tetapi ekspor CSV memakai `fputcsv` native.

Generic CRUD hanya mengizinkan `houses`, `residents`, `histories`, `fee-rates`, `bills`, `expense-categories`, `expenses`, `opening-balances`, `audit-logs`, dan `users`. Route dinamis lain menghasilkan `404` dari controller.

## Frontend

SPA menyediakan login petugas, shell/sidebar, dark mode, dashboard, resource table/form/detail, laporan, dan halaman 403/404. Axios memakai base URL `/api/v1`; token disimpan di `localStorage`. Query resource mengirim `search`, filter, page, dan `per_page`, tetapi backend saat ini hanya memakai `per_page`.

UI memuat menu roles/settings/payments, namun beberapa endpoint generic tidak didukung controller. Dokumentasi API mengikuti backend, bukan asumsi menu UI.

## Data dan transaksi

MySQL target memakai foreign key, unique index, dan transaction. Uang berupa integer rupiah. `FinanceService::pay` dan pembatalan memakai `DB::transaction()` serta row locking untuk bill/payment/expense terkait. Audit finansial dibuat dalam transaksi sama.

File tersimpan pada disk default Laravel:

- `payment-proofs/`
- `private-documents/`

Metadata file berada di tabel. Download diproksi Laravel melalui route autentikasi.

## Alur pembayaran

1. API memvalidasi rumah, nominal, tanggal, file, dan alokasi.
2. Bukti opsional disimpan.
3. Service menjumlahkan alokasi dan membuka transaksi DB.
4. Payment dibuat; selisih menjadi uang muka.
5. Setiap bill rumah sama dikunci, divalidasi, dialokasikan, lalu status diperbarui.
6. Audit dibuat; transaksi commit.

Catatan risiko: file bukti disimpan sebelum transaksi service; kegagalan DB dapat meninggalkan orphan file.

## Deployment minimum

```text
Reverse proxy/TLS
  ├─ frontend/dist static assets + SPA fallback
  └─ /api and /up -> PHP-FPM/Laravel
                         ├─ MySQL 8+
                         └─ private writable storage
```

Produksi wajib memakai `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, secret environment, backup DB/storage, writable `storage/` dan `bootstrap/cache/`, serta worker hanya bila queue dipakai. SPA dan API sebaiknya satu origin karena frontend memakai relative `/api/v1`.

## Keamanan aktual dan gap

Ada: hash password Laravel, Sanctum bearer auth, validator, model allowlist, DB constraints, private-path storage, JSON errors.

Wajib diperbaiki sebelum produksi: route-level permission/policy, token revoke/logout, rate limit login, MIME allowlist dan malware policy, object authorization download, token storage strategy, audit coverage, reason persistence, file cleanup, deterministic filtering/search, dan CORS/reverse-proxy hardening.

## Observabilitas dan test

Laravel logging menjadi baseline. Gunakan request logs tanpa token/NIK/path dokumen. Pemeriksaan rilis minimum:

```bash
cd portal-warga-backend && php artisan migrate:fresh --seed && php artisan test && php artisan route:list
cd ../portal-warga-frontend && npm run typecheck && npm run lint && npm run build
```

MySQL tidak tersedia pada environment dokumentasi. Diagram dan kontrak diverifikasi statis, bukan lewat integration test runtime.

## Keputusan sengaja sederhana

Monolith, REST JSON, Eloquent, local/private storage, dan CSV stdlib cukup untuk cakupan sekarang. Tambah object storage, queue, ledger akuntansi, atau service terpisah hanya saat requirement/volume membuktikan kebutuhan.
