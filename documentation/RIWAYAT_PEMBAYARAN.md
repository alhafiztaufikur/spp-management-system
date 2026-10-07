# Riwayat Pembayaran dan penguncian operator

## Pemakaian

Pilih tab Transaksi Aktif atau Transaksi Dihapus, atur periode/pencarian, lalu klik kartu transaksi. Detail menampilkan komponen pembayaran, pembuat awal, aktivitas terakhir, waktu, dan nomor transaksi. Pada arsip, periode filter adalah tanggal penghapusan, bukan tanggal bayar.

Tombol Cetak menghasilkan struk transaksi aktif yang dipilih. Tombol 12 Struk tetap tersedia untuk kelompok tahunan apabila seluruh transaksi boleh dicetak oleh akun tersebut. Transaksi dihapus hanya dapat dibaca; tidak tersedia cetak atau pemulihan.

## Hak tindakan

| Peran | Transaksi sendiri | Transaksi akun lain |
|---|---|---|
| Super Admin | Edit/hapus langsung dan cetak | Edit/hapus langsung dan cetak |
| Admin/Kasir | Ajukan edit/hapus dan cetak | Detail dan Riwayat Aktivitas |
| Bendahara | Detail, Riwayat Aktivitas, dan cetak | Detail dan Riwayat Aktivitas |

Pemilik ditentukan dari ID pembuat awal dalam jurnal `created`, termasuk bukti rekonstruksi yang tersedia. Nama dan operator terakhir tidak digunakan untuk memindahkan hak. Pembuat yang tidak dapat dipastikan ditampilkan sebagai “Tidak tercatat”; tindakan hanya tersedia bagi Super Admin.

Hak edit/hapus juga mengikuti batas transaksi lama, pengajuan yang masih menunggu, serta unit operasional. Semua Unit menyediakan pemantauan/cetak bagi Super Admin; pilih SD/SMP/SMA sebelum perubahan. Pemeriksaan berlaku pada halaman edit, pengajuan bersama, POST pembayaran, struk tunggal/kelompok, dan pintasan menu lain.

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
