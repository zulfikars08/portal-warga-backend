# Kebutuhan Portal Warga — Admin Internal

**Sumber kebenaran:** implementasi saat dokumentasi dibuat. **Status:** as-built; belum tervalidasi product owner.

## 1. Tujuan dan batas

Aplikasi membantu petugas mengelola administrasi penghuni dan kas lingkungan melalui panel internal. Pengguna aplikasi ialah petugas/admin terdaftar. Tidak ada akses warga, pendaftaran mandiri, pengumuman, pengajuan surat, pengaduan, kegiatan, atau notifikasi.

## 2. Aktor

| Aktor | Kebutuhan |
|---|---|
| Petugas | Login; melihat dashboard; mengelola data sesuai izin |
| Bendahara | Mengelola iuran, tagihan, pembayaran, pengeluaran, laporan |
| Admin | Mengelola pengguna, role/permission, audit dan semua master |

Backend memasang middleware autentikasi Sanctum pada seluruh API kecuali login. Struktur role/permission tersedia, tetapi route aktual tidak memasang middleware role/permission per endpoint; pembatasan rinci harus diverifikasi sebelum produksi.

## 3. Kebutuhan fungsional aktual

### FR-01 Autentikasi internal
- Login memakai email dan password.
- Login sukses menghasilkan personal access token Sanctum dan objek pengguna.
- Frontend menyimpan token pada `localStorage` dan mengirim `Authorization: Bearer`.
- Logout frontend menghapus token lokal. Backend tidak memiliki endpoint revoke/logout atau `me`.

### FR-02 Rumah
- Daftar berpaginasi, detail, tambah, ubah, hapus rumah.
- Nomor rumah unik; alamat wajib; status `occupied` atau `vacant`.

### FR-03 Penghuni
- Daftar berpaginasi, detail, tambah, ubah, hapus penghuni.
- NIK 16 digit unik; nama dan gender wajib; rumah opsional.
- Gender hanya `M`/`F`; tanggal lahir dan telepon opsional; kepala keluarga dan status aktif boolean.
- Dokumen privat dapat diunggah dan diunduh lewat endpoint terautentikasi.
- Tabel riwayat rumah tangga ada dan generic read API tersedia; generic write belum memiliki aturan validasi khusus.

### FR-04 Iuran dan tagihan
- CRUD tarif iuran dan tagihan.
- Tarif menyimpan nama, nominal, masa berlaku, dan status aktif.
- Tagihan terkait rumah, opsional terkait tarif, bertipe `routine`/`special`, memiliki periode, jatuh tempo, nominal, jumlah dibayar, status, snapshot tarif, dan catatan.

### FR-05 Pembayaran
- Pembayaran terkait satu rumah dan dapat dialokasikan ke beberapa tagihan rumah sama.
- Sisa pembayaran dicatat sebagai uang muka (`advance_amount`).
- Bukti pembayaran opsional, maksimum 5 MiB menurut validator.
- Pembayaran dapat dibatalkan; alokasi dikembalikan ke tagihan.
- Generic GET pembayaran tidak tersedia karena `payments` tidak ada pada daftar model generic; pembuatan memakai endpoint khusus.

### FR-06 Pengeluaran dan saldo awal
- CRUD kategori pengeluaran.
- Pengeluaran dibuat dan dibaca melalui API generic; hard delete dilarang, pembatalan memakai endpoint khusus.
- Saldo awal dapat dikelola dan unik per tanggal.

### FR-07 Dashboard dan laporan
- Dashboard menampilkan jumlah rumah, penghuni aktif, piutang, dan saldo kas.
- Laporan menampilkan pemasukan/pengeluaran berstatus `posted` dalam rentang tanggal serta saldo keseluruhan.
- Ekspor tersedia sebagai CSV atau PDF.

### FR-08 Pengguna, akses, audit
- CRUD pengguna; password minimal delapan karakter dan di-hash.
- Tabel role/permission Spatie tersedia.
- Audit dibuat saat pembayaran dibuat/dibatalkan dan pengeluaran dibatalkan.
- Generic audit log hanya baca/detail; delete dilarang.

## 4. Nonfungsional

- API JSON terversi `/api/v1`; daftar maksimum 100 item/halaman.
- Nominal uang integer rupiah.
- Operasi pembayaran/pembatalan memakai transaksi DB dan row lock.
- File disimpan pada disk Laravel; akses download wajib autentikasi, tetapi pemeriksaan kepemilikan/izin dokumen belum tampak.
- UI responsif, punya dark mode, status loading/error/empty, dan label kontrol dasar.
- MySQL 8+ menjadi target dokumentasi instalasi; constraint wajib diuji di MySQL.
- Password, token, dokumen, bukti pembayaran, dan `.env` tidak boleh masuk Git/log.

## 5. Kriteria penerimaan

1. Login valid mengembalikan token; kredensial salah menghasilkan `422`.
2. Request terproteksi tanpa bearer token menghasilkan `401` JSON.
3. CRUD entity yang didukung mengikuti validasi controller dan status `201/204` yang didokumentasikan.
4. Alokasi pembayaran tidak melebihi nominal pembayaran atau sisa tagihan dan hanya menuju tagihan rumah sama.
5. Pembatalan pembayaran memulihkan `paid_amount` dan status seluruh tagihan terkait atomik.
6. Pengeluaran, tagihan, dan audit log tidak dapat dihapus lewat generic DELETE.
7. Laporan hanya menghitung transaksi `posted`.
8. Build frontend, test backend, migration, seed, dan smoke API lulus pada MySQL sebelum rilis.

## 6. Gap implementasi penting

- Otorisasi per role/object belum diterapkan pada route.
- Logout/revoke token dan endpoint profil sendiri belum ada.
- Generic endpoint menerima nama entity runtime; hanya allowlist controller yang mencegah entity lain.
- Search/filter dikirim frontend tetapi controller index mengabaikannya.
- `histories` dan beberapa entity punya rule validasi kosong; POST berpotensi membuat data kosong/gagal DB.
- Upload hanya membatasi ukuran, belum membatasi MIME/ekstensi.
- Download dokumen tidak mengecek hubungan pengguna dengan penghuni.
- Tidak ada bukti automated test dari pemeriksaan dokumentasi ini.

Gap bukan requirement baru; daftar wajib ditinjau sebelum produksi.
