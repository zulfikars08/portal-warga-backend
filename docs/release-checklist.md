# Release Checklist

## Repository

- [ ] README dan seluruh link lokal valid.
- [ ] `.env` tidak dilacak; `.env.example` memuat seluruh key wajib tanpa secret.
- [ ] Tidak ada credential, token, data pribadi, dump database, atau URL deployment fiktif.
- [ ] Artefak build/runtime tidak ikut rilis kecuali memang diperlukan.

## Quality gate

- [ ] Dependency install berhasil dari lockfile.
- [ ] Test otomatis lulus.
- [ ] Typecheck/lint/build yang berlaku lulus.
- [ ] Dependency audit ditinjau; temuan dicatat, bukan diperbaiki paksa.
- [ ] Dokumentasi API, ERD, requirements, architecture, dan business rules cocok dengan source.

## Runtime

- [ ] Environment production, HTTPS, CORS, queue, cache, mail, storage, dan database telah dikonfirmasi.
- [ ] Migration dibackup, direview, dan dijalankan hanya dengan persetujuan operator.
- [ ] File privat tidak dapat diakses tanpa autentikasi dan permission.
- [ ] Health check dan smoke test alur utama lulus.
- [ ] Rollback dan pemulihan backup telah disiapkan.

Checklist ini tidak menjalankan deployment, migration, seed, atau perubahan data.
