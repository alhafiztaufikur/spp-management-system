# Riwayat Pembayaran dan penguncian operator

## Filter Kelas/Rombel — 18 Oktober 2026

Dropdown beberapa rombel mengikuti keanggotaan siswa sekarang, termasuk PSB, Out, custom dan rombel nonaktif beranggota. Filter diterapkan sebelum ringkasan/pagination aktif dan arsip; snapshot kelas transaksi tidak diubah. Default Semua Kelas/Rombel mempertahankan URL lama. Tautan detail, pagination, tab dan kembali dari edit menyimpan pilihan. [Aturan lengkap](FILTER_ROMBEL_TABUNGAN_CUSTOM_20261018.md).

## Pemakaian

Pilih tab Transaksi Aktif atau Transaksi Dihapus, atur periode/pencarian/operator, lalu klik kartu transaksi. Detail menampilkan komponen pembayaran, pembuat awal, aktivitas terakhir, waktu, dan nomor transaksi. Pada arsip, periode filter adalah tanggal penghapusan, bukan tanggal bayar.

**Cetak Bukti Transaksi** tersedia pada setiap kartu transaksi aktif dan di panel detail, dengan ikon printer serta tombol membulat. Tombol membuka kwitansi existing pada tab baru tanpa mengubah pilihan detail. Hak cetak diperiksa server; tautan cetak merupakan elemen terpisah dari tautan pemilih kartu, tanpa tautan bersarang. Tombol 12 Struk tetap tersedia untuk kelompok tahunan apabila seluruh transaksi boleh dicetak oleh akun tersebut. Transaksi dihapus hanya dapat dibaca; tidak tersedia cetak atau pemulihan.

## Hak tindakan

| Peran | Transaksi sendiri maupun petugas lain |
|---|---|
| Super Admin | Edit/hapus langsung dan cetak |
| Administrator | Ajukan edit/hapus, membaca aktivitas, dan cetak |

Pembuat awal tetap tersimpan di jurnal dan tidak berubah saat koreksi. Identitas tidak jelas ditampilkan sebagai Tidak tercatat. Pending dan transaksi lama yang belum direkonsiliasi tetap terkunci; Semua Unit hanya membaca/mencetak. Administrator tidak dapat memutuskan otorisasi atau mengelola akun. Garis putih di sambungan isi dan tombol cetak dihilangkan; indikator pilihan/fokus dipertahankan.

## Antarmuka

- `GET pembayaran/detail.php?id=...&view=active|deleted&unit_id=...` mengembalikan `{ok, html, capabilities}`. Kemampuan dihitung server: `can_edit`, `can_delete`, `can_print`, `can_read_activity`, serta alasan penguncian.
- `GET pembayaran/aktivitas.php?id=...&unit_id=...` menyediakan kejadian, usulan, keputusan, dan perubahan nyata sesuai bukti jurnal/otorisasi. Unit hanya dapat mempersempit cakupan sesi.
- Pemilihan detail mendukung tautan tanpa JavaScript, pembatalan respons lama, pemuatan ulang ketika gagal, navigasi kartu, serta pagination. Data transaksi lain tetap terlihat dalam unit akun.
- PDF Otorisasi hanya tersedia bagi Super Admin, termasuk pratinjau dan akses URL langsung. Dialog konfirmasi persetujuan, penolakan, dan pembatalan menggantikan konfirmasi browser.

## Pengujian

Pengujian mutasi menggunakan salinan `db_spp_audit_authorization_20261007`; database keuangan aktif tidak diubah.

- `payment_history_redesign_browser_test.js`: tiga unit, seluruh peran/pemilik, transaksi asing/tidak tercatat, struk kelompok campuran, seluruh keputusan, CSRF, eksekusi berulang, snapshot berubah, akun nonaktif, arsip, pemilik setelah edit Super Admin, dan pembatasan PDF.
- Matriks tampilan: SD/SMP/SMA/Semua Unit, aktif/arsip, desktop/tablet/ponsel, terang/gelap. Diperiksa pula pagination, navigasi cepat, dan pemilihan tanpa JavaScript.
- `payment_history_readonly_browser_test.js`: kondisi gagal/retry, input/metode/unit/sesi endpoint detail, UI terkunci, akses aktivitas, struk Bendahara, serta validasi/fokus/Escape pada dialog modern.
- `payment_activity_test.php` dan `authorization_policy_test.php`: jurnal permanen, rollback, idempotensi, keputusan Super Admin, akun nonaktif, dan isolasi unit.
- `readiness_integrity_audit.php`: pemeriksaan integritas keuangan pada salinan sebelum/sesudah.

Tidak ada migrasi, commit, atau push. Perubahan lokal sebelumnya tetap dipertahankan.

## Filter operator ? 10 Oktober 2026

Dropdown Operator menyediakan pencarian nama/username dan checkbox untuk memilih satu atau beberapa petugas. Pilih Semua operator untuk cakupan penuh; Tidak tercatat untuk transaksi tanpa bukti pembuat. Akun nonaktif dan identitas operator historis tetap tersedia dalam cakupan unit. Pilihan baru diterapkan setelah Tampilkan Rekap, kembali ke halaman pertama, dan dipertahankan pada pagination, tab aktif/dihapus, detail, serta kembali dari edit/pengajuan. Reset mengembalikan Semua operator.

Parameter `operator[]` menggunakan ID akun atau `unknown`; `*` berarti semua. Filter memakai event `created` pertama menurut waktu/ID, bukan operator terakhir atau `bayar.user_id`. Filter berlangsung sebelum pagination. Endpoint detail membawa konteks pencarian yang sama; rute kembali hanya `lihat.php` atau `riwayat_daftar_ulang.php` dan dibuat server.

Verifikasi tambahan: `history_operator_pdf_test.php` dan `history_operator_pdf_browser_test.js`, pada salinan database audit terpisah.

Lihat [penyempurnaan cetak dan histori Input Pembayaran](PENYEMPURNAAN_CETAK_PEMBAYARAN_20261018.md) untuk aturan terbaru jarak struk, Sisa PSB/DU, nama penanda tangan kapital, dan histori tanpa Export/pagination.
