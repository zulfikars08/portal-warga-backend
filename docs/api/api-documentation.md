# API Portal Warga — Kontrak Aktual

**Base URL:** `/api/v1` · **Format:** JSON, kecuali upload/download/export · **Sumber:** `routes/api.php` dan `ApiController.php`.

## Autentikasi dan konvensi

`POST /login` publik. Semua endpoint lain memerlukan:

```http
Accept: application/json
Authorization: Bearer <token>
```

Nominal berupa integer rupiah. ID berupa integer auto-increment. Tanggal menerima format yang lolos validator Laravel. Daftar generic memakai paginator Laravel; query `per_page` default 15, maksimum 100. Parameter lain diabaikan controller generic saat ini.

Error mengikuti Laravel, umumnya:

```json
{"message":"The given data was invalid.","errors":{"field":["..."]}}
```

Status umum: `200`, `201` create, `204` delete, `401` tanpa token, `404` model/entity tidak ada, `405` delete dilarang, `422` validasi.

## Login

### `POST /login`

```json
{"email":"admin@example.com","password":"password-rahasia"}
```

Sukses `200`:

```json
{"token":"1|...","user":{"id":1,"name":"Admin","email":"admin@example.com"}}
```

Tidak ada endpoint logout/revoke atau `/me`.

## Endpoint khusus

| Method | Path | Body/query | Hasil |
|---|---|---|---|
| GET | `/dashboard` | — | `{houses,residents,receivables,cash}` |
| GET | `/reports/finance` | query `from`, `to` opsional | `{from,to,income,expense,balance}` |
| GET | `/exports/{format}` | `format=csv|pdf`; query `from`,`to` | download `report.csv`/`report.pdf` |
| POST | `/payments` | lihat bawah | payment + allocations, `201` |
| POST | `/payments/{payment}/cancel` | `reason` wajib | payment dibatalkan |
| POST | `/expenses/{expense}/cancel` | `reason` wajib | expense dibatalkan |
| POST | `/residents/{resident}/documents` | multipart `type`,`file` | metadata dokumen, `201` |
| GET | `/documents/{document}/download` | — | download file |

### Membuat pembayaran

`multipart/form-data` bila mengirim `proof`; JSON boleh tanpa file.

```json
{
  "house_id": 1,
  "amount": 150000,
  "paid_at": "2026-01-15 10:00:00",
  "note": "Tunai",
  "allocations": [
    {"bill_id": 10, "amount": 100000},
    {"bill_id": 11, "amount": 50000}
  ]
}
```

`house_id` harus ada; `amount` dan allocation positif; bill allocation distinct dan milik rumah sama. Total allocation tidak boleh melebihi pembayaran. `proof` opsional maksimum 5120 KiB.

### Pembatalan

```json
{"reason":"Salah input"}
```

`reason` wajib string maksimum 1000. Implementasi memvalidasi tetapi belum menyimpan alasan.

### Dokumen penghuni

```http
Content-Type: multipart/form-data

type=KTP
file=@/path/dokumen.pdf
```

`type` maksimum 50; file maksimum 5120 KiB. MIME tidak dibatasi implementasi.

## Generic CRUD

Pola route terautentikasi:

| Method | Path | Respons |
|---|---|---|
| GET | `/{entity}?page=1&per_page=15` | paginator Laravel |
| POST | `/{entity}` | object, `201` |
| GET | `/{entity}/{id}` | object |
| PUT/PATCH | `/{entity}/{id}` | object terbaru |
| DELETE | `/{entity}/{id}` | `204` |

Entity allowlist:

| Entity | GET | POST/PATCH | DELETE | Field write |
|---|---:|---:|---:|---|
| `houses` | ya | ya | ya | `number,address,status` |
| `residents` | ya | ya | ya | `house_id,nik,name,gender,birth_date,phone,is_head,active` |
| `histories` | ya | tidak aman/aturan kosong | ya | tidak ada validator khusus |
| `fee-rates` | ya | ya | ya | `name,amount,effective_from,effective_until,active` |
| `bills` | ya | ya | tidak (`405`) | `house_id,fee_rate_id,type,title,period,due_date,amount,note` |
| `expense-categories` | ya | ya | ya | `name,active` |
| `expenses` | ya | ya | tidak (`405`) | `expense_category_id,description,amount,spent_at` |
| `opening-balances` | ya | ya | ya | `as_of,amount,note` |
| `audit-logs` | ya | aturan kosong | tidak (`405`) | jangan tulis langsung |
| `users` | ya | ya | ya | `name,email,password` |

`payments`, `documents`, `roles`, `permissions`, dan `settings` bukan entity generic; pemanggilan pola generic menghasilkan `404`, kecuali route khusus yang cocok lebih dahulu.

## Validasi inti

- House: `number` wajib/unik/maks 30; `address` wajib/maks 500; status `occupied|vacant`.
- Resident: NIK wajib 16 digit unik; gender `M|F`; foreign key rumah opsional.
- Fee rate: amount integer min 1; akhir >= awal.
- Bill: type `routine|special`; amount integer min 1; house wajib ada.
- Expense: category wajib ada; amount integer min 1.
- Opening balance: tanggal unik; amount integer, termasuk negatif/nol.
- User: email unik; password min 8 dan di-hash.

## Health non-API

Laravel menyediakan `GET /up`, tanpa prefix `/api/v1`, melalui bootstrap. `GET /` mengembalikan view Laravel default.

## Batas verifikasi

MySQL tidak tersedia pada environment dokumentasi. Route dan payload cocok source, tetapi migration/seed/request belum diuji end-to-end di MySQL. Collection Postman tersedia di `portal-warga.postman_collection.json`.
