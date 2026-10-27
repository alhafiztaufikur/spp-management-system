# Pengesahan rekening tertahan dan penggabungan DU — 27 Oktober 2026

Target `db_spp`, port 3306, UUID `86e982af-c6db-11f1-9998-00e04ccb017f`.

Satu rekening tertahan dipulihkan berdasarkan pengesahan eksplisit pengguna. Saldo serta selisih jurnal mengikuti bukti dan nominal yang disahkan pengguna. Jurnal asli dipertahankan beserta satu penyesuaian migrasi yang diaudit. Tidak ada setoran/penerimaan kas baru pada target. Status peninjauan rekening terselesaikan dan layanan tabungan menyatakan rekening siap digunakan. Identitas serta bukti pribadi tersimpan pada artefak privat di luar webroot.

## Kode dan sinkronisasi

Perubahan DU lokal diamankan pada commit `321df59`. Empat commit remote sampai `91dd825` digabungkan tanpa mengganti histori. UI Master DU teman, engine Biaya Lain/laporan remote, serta penerbitan dan pembayaran DU mendatang lokal tetap tersedia. Commit penggabungan: `f19eda8b07aa9815107d1e8b685714f2fea9c7ef`.

Runtime: workflow 15, operasional 19, otomasi 5, billing 7, DU 4. Metadata sebelumnya tetap dapat dibaca; worker dimulai ulang dengan heartbeat SQL sehat. Installer resmi Biaya Lain memasang kebutuhan skema dengan backup terverifikasi. Deployment tidak menerbitkan tagihan baru pada target.

Runner resmi menerima `--components=savings` dan settings pengesahan terbatas unit/batch/sumber/versi/identitas. Proposal FIX dan penulisan rekening/jurnal dibagi dengan engine GUI existing. Transaksi membatalkan perubahan jika baris di luar rekening terpilih berubah. Rekening yang sudah digunakan tetap mengikuti koreksi existing.

## Bukti penerapan

- Backup lengkap sebelum skema, sebelum penerapan saldo, dan setelah perbaikan berhasil dipulihkan pada server terisolasi dengan UUID/datadir berbeda serta event scheduler OFF.
- Restore awal cocok seluruh checksum tabel dan snapshot terlindungi. Salinan konsisten antrean privat beserta 139 berkas disimpan sebelum perubahan.
- Preview rekening menunjukkan satu perubahan; apply target menghasilkan satu perubahan yang disahkan. Verify dan penerapan ulang masing-masing menghasilkan nol perubahan. Checksum seluruh tabel serta snapshot setelah replay identik dengan backup sesudah perbaikan.
- Perbandingan 65 tabel memastikan setiap baris di luar rekening dan metadata yang diizinkan tetap identik. Semua arsip mentah, kas, identitas, rekening lain, tagihan, serta kredit pembayaran dipertahankan.
- 575 tagihan DU lama dan 207 cakupan PSB tetap sama. Rekening dengan `savings_review` turun dari 21 menjadi 20; pengecualian lain tetap dipertahankan.
- Hash `koneksi.php` dan `.gitignore` sama sebelum/sesudah. Tidak ada perubahan konfigurasi pengguna.

## Pengujian aktual

- Pemulihan FIX, validasi, ringkasan dan replay: 16 pemeriksaan lulus.
- GUI rekening pulih: 15 pemeriksaan lulus.
- Master DU: 824 pemeriksaan lulus.
- DU Legacy: 224 pemeriksaan lulus.
- DU mendatang pada data lengkap: 114 pemeriksaan lulus.
- DU mendatang dan rollover fixture: 123 pemeriksaan lulus.
- PSB/DU fixture: 66 pemeriksaan lulus.
- Master DU draf: 11 pemeriksaan lulus.
- Rekategorisasi DU: 12 pemeriksaan lulus.
- Billing digest: 5 pemeriksaan lulus.
- Bulanan: 30 pemeriksaan lulus.
- Master Biaya Lain: 22 pemeriksaan lulus.
- Tagihan Biaya Lain: 79 pemeriksaan lulus.
- HTTP pembayaran dan otorisasi Biaya Lain: 57 pemeriksaan lulus.
- Keamanan skema Biaya Lain: 5 pemeriksaan lulus.
- Browser UI DU existing: 43 pemeriksaan lulus.
- Browser DU mendatang: 26 pemeriksaan lulus.
- Isi PDF/Excel: 6 pemeriksaan lulus.
- Dua kasir pada dua proses PHP: satu request menghasilkan tepat satu struk/tagihan dan kredit yang benar.
- Suite otomasi `--draft --publication --du-publication`: lulus, termasuk backup/restore, rollback sebelum commit, penerapan dan replay.
- GUI tabungan menguji saldo awal, setoran, penarikan, penolakan kelebihan penarikan, koreksi, pembatalan serta permintaan ganda. Seluruh transaksi uji berada pada salinan.
- UI Master DU diuji desktop, ponsel dan tema gelap; PDF/Excel dibaca ulang isinya. PHP lint/sintaks JavaScript serta `git diff --check` lulus.

Rincian perintah dan kontrak settings: [runbook migrasi](LEGACY_MIGRATION_RUNBOOK.md). Bukti database, antrean, pengesahan, screenshot dan log pengujian disimpan privat.
