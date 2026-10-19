# Rancangan migrasi penuh saat pindah ke SistemSPP

Status: keputusan awal yang menjadi dasar implementasi mode Migrasi Penuh. Perilaku final, pemasangan, batas profil sumber, dan pemulihan dijelaskan pada [panduan implementasi](LEGACY_FULL_MIGRATION.md). Fitur dibuat dan diuji pada salinan; belum dipasang atau dijalankan pada database operasional.

Keputusan pengguna: yayasan akan **pindah sepenuhnya ke SistemSPP**, bukan mengoperasikan dua sistem secara bersamaan. Targetnya mengunggah backup SQL Server `.dat`, meninjau hasil pemeriksaan, lalu menggabungkan data yang sudah terbukti ke SistemSPP melalui satu proses migrasi.

## Hasil yang dituju

| Data | Perilaku tujuan |
|---|---|
| Identitas siswa | Dicocokkan dengan siswa existing melalui identitas sumber dan unit; pasangan yang terbukti tidak dibuat ganda. |
| Kelas, status, tarif | Berlaku sesuai periode dan pemetaan yang disahkan. Histori penempatan atau tarif yang tidak tersedia tidak ditebak. |
| Pembayaran lama | Terlihat pada riwayat/laporan utama dengan penanda Legacy serta komponen/tanggal/operator sumber yang tersedia. Tidak menjadi penerimaan baru pada tanggal migrasi. |
| Tabungan | Histori lama dapat dibaca bersama histori baru; saldo pada tanggal peralihan direkonsiliasi tepat. Saldo awal dan jurnal yang sama tidak dijumlahkan dua kali. |
| Sisa kewajiban | Menjadi tagihan yang dapat dibayar setelah komponen, periode, nominal tersisa, dan benturan tagihan existing dibuktikan. |
| Data tidak lengkap | Tetap tersimpan bersama alasan masalah dan belum dipublikasikan sebagai transaksi/tagihan aktif. |

Histori tanpa tanggal tidak diberi tanggal buatan atau dimasukkan ke total harian tertentu. Operator sumber tidak dibuat menjadi akun aplikasi. Referensi nomor/identitas sumber dipertahankan; tidak membuat nomor struk historis seolah diterbitkan SistemSPP pada masa lalu. Data akun non-siswa perlu klasifikasi tersendiri sebelum dapat masuk ke modul siswa.

## Alur migrasi satu kali

1. Tentukan tanggal/jam peralihan dan cakupan SD, SMP, SMA. Hentikan pencatatan pada sistem lama saat mengambil backup final. Tentukan transaksi SistemSPP existing yang termasuk baseline sah; data existing tidak dihapus otomatis.
2. Buat backup pengaman SistemSPP dan buktikan pemulihannya pada instance terpisah.
3. Unggah sumber per unit. Periksa header, integritas, struktur/tabel/tipe, serta apakah backup benar-benar snapshot final yang diharapkan.
4. Ekstrak ke staging privat. Lakukan pencocokan siswa, komponen, periode, transaksi, rekening, serta pemeriksaan tumpang tindih dengan data existing. Pengunggahan backup berbeda tidak boleh dianggap bebas duplikasi hanya karena hash filenya berbeda.
5. Tampilkan pratinjau jumlah baris, total per komponen/periode yang dapat dibuktikan, saldo, kewajiban, serta daftar masalah. Selesaikan pemetaan yang dibutuhkan dan ulangi simulasi sampai hasil cocok dengan bukti yayasan.
6. Konfirmasi hasil migrasi dengan manifest dan versi pemeriksaan yang sama. Blokir transaksi selama penerapan final; publikasi hanya setelah semua tahap wajib lengkap, dengan checkpoint dan kemampuan pemulihan yang jelas.
7. Cocokkan kembali saldo, histori dan tagihan setelah penerapan. Buat backup terverifikasi, kemudian mulai transaksi baru hanya di SistemSPP.

## Kebutuhan yang ditangani implementasi

- Mode migrasi penuh terpisah dari mode arsip existing, dengan batch/cutoff dan pemeriksaan versi sumber. Target final adalah unit kosong; data existing tidak digabung otomatis.
- Satu snapshot final per unit; backup berbeda boleh ditinjau sebelum penerapan, tetapi tidak menjadi impor susulan setelah final. Hash baris dan nomor kemunculan mempertahankan transaksi identik yang benar-benar berulang. Pencocokan transaksi lintas snapshot pada database terisi tidak termasuk mode ini.
- Penyajian histori Legacy pada riwayat/laporan/buku utama, dengan pemisahan dampak kas dan saldo menurut tanggal peralihan. Penanda asal harus bertahan pada ekspor, rincian, audit, dan kebijakan perubahan histori.
- Pemetaan kelas/status/tarif/komponen yang dapat ditinjau bersama, serta pemeriksaan kewajiban tersisa berdasarkan bukti sumber yang memadai.
- Simulasi menyeluruh memakai backup terbaru, pengujian konflik/retry/rollback, dan penerimaan hasil oleh yayasan sebelum instalasi operasional.

## Batas bukti yang tersedia

Ketiga contoh `.dat` yang sudah diuji merupakan snapshot 15 Februari 2019. Audit menemukan pembayaran DU tanpa tanggal/header yang terbukti, transaksi SMP tanpa ID transaksi tunggal, komponen dengan arti belum jelas, dan tidak adanya penempatan/tagihan historis lengkap. Struktur tabel saja tidak membuktikan arti komponen atau jumlah utang.

Backup terbaru perlu diperiksa sendiri. Bila struktur dan bukti keuangannya lengkap, lebih banyak data dapat digabungkan otomatis setelah pemetaan. Bila ada informasi yang tidak pernah tersimpan dalam sumber, diperlukan bukti tambahan atau data tersebut tetap ditahan. Belum ada jaminan bahwa sembarang `.dat` dapat langsung menjadi seluruh data operasional tanpa pemeriksaan.

Rancangan ini tidak memasang skema, mengimpor ke operasional, mengubah data existing, melakukan commit/push, atau memperbarui paket deployment.
