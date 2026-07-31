# Aturan Bisnis Portal Warga — Aktual

## Identitas dan akses

- **BR-01** Login memakai email valid dan password wajib; gagal menghasilkan `422 Kredensial salah.`
- **BR-02** Semua route `/api/v1/*` selain `/login` memerlukan bearer token Sanctum.
- **BR-03** Password pengguna minimal 8 karakter dan selalu di-hash saat dibuat/diubah melalui API.
- **BR-04** Izin menu frontend bukan kontrol keamanan. Server wajib menjadi sumber otorisasi; implementasi granular saat ini belum lengkap.

## Rumah dan penghuni

- **BR-05** Nomor rumah unik, maksimum 30 karakter.
- **BR-06** Status rumah hanya `occupied` atau `vacant`; default DB `vacant`.
- **BR-07** NIK tepat 16 digit dan unik.
- **BR-08** Gender hanya `M` atau `F`.
- **BR-09** Penghuni boleh tanpa rumah; penghapusan rumah membuat `resident.house_id` null.
- **BR-10** Penghapusan penghuni menghapus riwayat dan dokumen privat secara cascade pada DB; gunakan dengan sadar karena file fisik belum terbukti ikut terhapus.

## Tarif dan tagihan

- **BR-11** Nominal tarif/tagihan integer positif.
- **BR-12** Akhir masa tarif, bila ada, tidak boleh sebelum awal masa berlaku.
- **BR-13** Tipe tagihan hanya `routine` atau `special`.
- **BR-14** Kombinasi `(house_id, fee_rate_id, period)` unik di DB. Perilaku `NULL fee_rate_id` mengikuti MySQL.
- **BR-15** Status awal tagihan `unpaid`; pembayaran mengubah menjadi `partial` atau `paid`.
- **BR-16** Tagihan tidak boleh di-hard-delete lewat API generic; pembatalan tagihan khusus belum tersedia.

## Pembayaran

- **BR-17** Pembayaran bernilai integer positif, terkait rumah valid, dan memiliki tanggal bayar.
- **BR-18** Jumlah total alokasi tidak boleh melebihi pembayaran; selisih menjadi `advance_amount`.
- **BR-19** Setiap alokasi positif, bill unik dalam satu request, dan hanya menuju tagihan milik rumah pembayaran.
- **BR-20** Alokasi tidak boleh melebihi sisa tagihan atau menuju tagihan `cancelled`.
- **BR-21** Satu pembayaran hanya punya satu alokasi per tagihan.
- **BR-22** Pembuatan pembayaran, alokasi, perubahan tagihan, dan audit berjalan dalam satu transaksi.
- **BR-23** Pembayaran yang sudah `cancelled` tidak boleh dibatalkan lagi.
- **BR-24** Pembatalan mengurangi `paid_amount`; status tagihan kembali `partial` bila masih terbayar, selain itu `unpaid`.
- **BR-25** Pembatalan pembayaran memerlukan alasan maksimum 1000 karakter, tetapi alasan belum disimpan oleh implementasi.

## Pengeluaran dan kas

- **BR-26** Nominal pengeluaran integer positif dan kategori wajib ada.
- **BR-27** Pengeluaran baru berstatus `posted` dan mencatat pembuat dari token aktif.
- **BR-28** Pengeluaran tidak boleh di-hard-delete; pembatalan mencatat pelaku/waktu serta audit.
- **BR-29** Pembatalan pengeluaran memerlukan alasan maksimum 1000 karakter, tetapi alasan belum disimpan.
- **BR-30** Saldo awal unik per tanggal dan boleh negatif karena merepresentasikan koreksi/posisi awal.
- **BR-31** Saldo kas = total saldo awal + pembayaran `posted` − pengeluaran `posted`.
- **BR-32** Pemasukan laporan = pembayaran `posted` pada rentang; pengeluaran laporan = pengeluaran `posted` pada rentang.

## Dokumen dan audit

- **BR-33** Upload dokumen penghuni memerlukan tipe maksimum 50 karakter dan file maksimum 5120 KiB.
- **BR-34** Path file dibuat storage Laravel; hanya metadata path disimpan di DB.
- **BR-35** Audit mencatat user, aksi, tipe/id objek, nilai lama/baru, IP, dan timestamp.
- **BR-36** Audit log tidak dapat dihapus melalui API generic.

## Integritas dan prioritas

1. Constraint database menjaga unique/foreign key/cascade.
2. `FinanceService` menjaga invariant transaksi finansial dengan transaction dan row lock.
3. Validator controller menjaga trust boundary HTTP.
4. Dokumentasi ini menjelaskan perilaku aktual; konflik diselesaikan dengan source code dan hasil test runtime terbaru.
5. MySQL belum tersedia pada environment dokumentasi; semua aturan DB harus dibuktikan lewat migration dan integration test MySQL sebelum rilis.
