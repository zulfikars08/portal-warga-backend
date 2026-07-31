# Portal Warga API

Backend REST API untuk administrasi warga, rumah, tagihan, pembayaran, pengeluaran, laporan, notifikasi, pengaturan, dan kontrol akses.

## Stack

- PHP 8.3+
- Laravel 13, Sanctum, Spatie Permission
- MySQL untuk runtime; SQLite in-memory untuk test
- Composer dan Node.js/npm untuk aset Vite

## Setup lokal

> `composer setup` menjalankan `php artisan migrate --force`. Jangan gunakan command itu bila database belum boleh diubah.

Setup manual yang aman:

```bash
composer install
cp .env.example .env
php artisan key:generate
npm ci
npm run build
```

Atur koneksi MySQL, URL aplikasi, mail, queue, cache, dan batas upload dalam `.env`. Lalu, hanya setelah database target dikonfirmasi:

```bash
php artisan migrate
php artisan serve
```

Alternatif proses development terpadu:

```bash
composer dev
```

API default: `http://127.0.0.1:8000/api/v1`. Health check: `GET /up`.

## Verifikasi

```bash
php artisan route:list
php artisan test
```

Test memakai SQLite in-memory sesuai `phpunit.xml`; runtime memakai konfigurasi database `.env`.

## Autentikasi

Login melalui `POST /api/v1/login`. Endpoint terproteksi memakai bearer token Sanctum. Identitas aktif tersedia lewat `GET /api/v1/me`; logout melalui `POST /api/v1/logout`.

## Penyimpanan privat

Bukti pembayaran, bukti pengeluaran, dokumen tagihan khusus, dan dokumen warga harus tetap privat. Akses file dilakukan melalui endpoint terautentikasi, bukan URL publik langsung. Pastikan `storage/` dan `bootstrap/cache/` writable.

## Dokumentasi

- [Dokumentasi API](docs/api/api-documentation.md)
- [Postman collection](docs/api/portal-warga.postman_collection.json)
- [ERD](docs/erd/portal-warga-erd.md) dan [SVG](docs/erd/portal-warga-erd.svg)
- [Requirements](docs/specifications/requirements.md)
- [Architecture](docs/specifications/architecture.md)
- [Business rules](docs/specifications/business-rules.md)
- [Release checklist](docs/release-checklist.md)
- [Screenshot checklist](docs/screenshots/README.md)
- [Assessment notice](docs/ASSESSMENT_NOTICE.md)
- [Development Timeline](docs/development-timeline.pdf)

Semua link di atas berada dalam repository ini. Repository frontend tidak dibutuhkan untuk memahami atau menjalankan API.

## Screenshots

[View Application Screenshots](docs/screenshots/README.md)
