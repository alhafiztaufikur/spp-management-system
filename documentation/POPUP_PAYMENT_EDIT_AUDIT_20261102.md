# Popup desktop dan audit edit pembayaran — 2 November 2026

## Temuan dan perbaikan

Kasus Riwayat Pembayaran → Edit direproduksi tanpa mengganti unit pada server salinan yang telah diverifikasi. GET edit transaksi native menghasilkan HTML **2.474.695 byte**, tetapi formulir pembayaran tidak memiliki `_operational_unit`. POST formulir tersebut ditolak guard dengan **HTTP 409** dan pesan yang sama dengan laporan pengguna.

Form edit sebelumnya mengandalkan `operational_form_scope()` untuk menyisipkan penanda unit melalui regex yang mencakup seluruh isi formulir. Daftar siswa beserta payload tagihan membuat formulir melewati `pcre.backtrack_limit=1000000`; regex gagal dan mengembalikan HTML asli tanpa penanda. Uji murni juga mereproduksi `Backtrack limit exhausted`. Form input baru sudah menuliskan penanda secara eksplisit.

Perbaikannya terbatas pada form edit native dan pemulihan kegagalannya:

- Penanda unit ditulis langsung oleh server; tidak menaikkan batas PCRE atau melonggarkan guard.
- Draf tidak boleh menimpa unit, identitas transaksi, konteks kembali, atau CSRF yang dirender server. Kunci idempotensi edit yang valid dipertahankan agar percobaan ulang tidak menerapkan perubahan dua kali.
- Guard mempertahankan isian edit hanya pada konteks server yang cocok dengan aktor, transaksi, unit asal, dan masa berlaku. Setelah pergantian unit, pengguna harus kembali ke unit asal; tidak ada perpindahan unit otomatis.
- Kegagalan edit tidak masuk ke penyimpanan draf pembayaran baru. Tautan edit langsung yang lama mendapat konteks kembali baru setelah transaksi dan aksesnya tervalidasi.
- CSRF gagal mengembalikan pengguna ke edit beserta isian, tanpa menjalankan perubahan keuangan.

## Popup

Master DU menggunakan ringkasan singkat dan tujuh baris label–jumlah dari hasil service existing. Nilai nol tetap tampil. Header hijau untuk berhasil, kuning untuk konflik/hasil belum lengkap, dan merah untuk gagal. Isian tarif dipertahankan pada kegagalan service maupun CSRF.

`showOperationResult({type,title,message,source,details})` menerima `details` opsional berupa array `{label,value}`; pemanggilan lama tetap berlaku. Flash DU menyediakan rincian melalui atribut data yang di-escape. Render memakai `textContent`, dengan fallback inline ketika JavaScript tidak tersedia. Deduplikasi memperhitungkan judul, pesan, jenis, dan rincian.

Konfirmasi penempatan PSB memakai `workflowConfirm` existing. Opsi `appearance: 'operation'` diterapkan hanya pada penempatan, kenaikan, dan kelulusan di Master Rombel. Daftar dapat digulir, tombol Batal tetap tersedia, dan pengiriman dikunci selama konfirmasi/proses. Tampilan dialog workflow lain tetap menggunakan gaya sebelumnya.

## Verifikasi

Seluruh pengujian mutasi menggunakan `db_spp_audit_feedback_20261102` pada MySQL terpisah port `50183`, UUID `0fd74eb3-d68b-11f1-af98-047c16a47f93`, dengan event scheduler OFF. Restore sumber sebelumnya telah dibuktikan identik pada 65 tabel. Tidak ada migrasi skema atau apply data ke database operasional.

Pengujian baru:

- `payment_edit_scope_test.php`: kegagalan regex pada formulir besar, perlindungan field draf, konteks aktor/transaksi/unit/masa berlaku, dan kunci idempotensi.
- `popup_payment_edit_browser_test.js`: Riwayat → Edit → Simpan melalui UI pada SD/SMP/SMA, penanda tunggal, tanpa JavaScript, CSRF gagal, penanda hilang, pergantian unit melalui tab lain, tautan langsung, dan replay. Snapshot memastikan jumlah transaksi dan rincian/nominal tetap, serta audit mencatat perubahan metode sebelum/sesudah.
- `master_popup_browser_test.js`: hasil simpan DU resmi, hasil tanpa perubahan dengan angka nol, kegagalan dan pemulihan tarif; 1280/1440/1920 piksel pada tema terang/gelap, pesan panjang, teks aman, fokus, Escape, daftar panjang, batal dan satu POST konfirmasi PSB.
- `rombel_promotion_confirmation_browser_test.js`: dialog pada roster kenaikan/kelulusan sebenarnya, pembatalan mempertahankan pilihan, serta satu POST per konfirmasi. POST pada tes ini dicegat agar tidak mengubah penempatan.

Seluruh pengujian baru di atas lulus. Regresi tambahan juga lulus:

- 66 pemeriksaan transaksi DU–PSB dan 123 pemeriksaan DU mendatang/pergantian tahun; fixture di-rollback.
- Penempatan PSB SD/SMP/SMA: target parsial, replay, tujuan tidak sah, identitas tidak aktif, serta penolakan Semua Unit.
- Popup bersama pada CRUD/status master dan akun: hasil server sebenarnya, teks aman, fallback tanpa JavaScript, deduplikasi, dan fokus keyboard.
- Notifikasi AJAX pengaturan/backup: menunggu hasil server dan hanya memberitahu hasil akhir; seluruh mutasi API backup pada tes ini dimock.
- Surat orang tua pada SD/SMP/SMA/Semua Unit: penerapan sebagian/semua, pesan berbeda, percobaan ulang, autosave berjalan, format teks, pratinjau, dan PDF.
- Pengujian feedback pembayaran, 30 kebijakan DU–PSB murni, dan 15 pemeriksaan presisi tabungan.
- Pemeriksaan sintaks 25 PHP dan 21 JavaScript, serta `git diff --cached --check`.

Regresi DU mendatang dijalankan dengan `memory_limit=1024M` khusus proses QA; batas CLI awal 512 MB tidak mencukupi. Tidak ada perubahan konfigurasi memori aplikasi operasional.

Checksum EXTENDED **65 tabel database operasional identik sebelum dan sesudah seluruh pengujian**. Bukti HTTP, snapshot, hasil tes, screenshot, dan checksum disimpan privat di `C:/laragon/backups/spp-management-system/master_feedback_20261102/`, termasuk `edit-before.json`, `popup-edit-browser.json`, `master-popup-browser.json`, `rombel-promotion-confirmation.json`, `popup-final-checks.json`, dan `popup-edit-source-unchanged.json`.
