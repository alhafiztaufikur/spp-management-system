> Pembaruan 24 Oktober 2026: ikuti [prosedur migrasi lengkap](LEGACY_MIGRATION_RUNBOOK.md). U_SORGA adalah Komite/POMG pada SD, SMP, SMA; keputusan ini menggantikan aturan lama.

# Persetujuan massal Migrasi Operasional Legacy

## Cara menggunakan

1. Unggah backup dan tentukan tahun ajaran/waktu peralihan seperti biasa.
2. Klik **Setujui Semua Data**. Ringkasan menampilkan kelompok siswa, pemetaan komponen, usulan master, dan rekening utama yang saldonya akan digantikan. Pilih **Setujui & Simulasikan**.
3. Siswa dan pembayaran terkait disiapkan bersama. Tidak perlu menyetujui setiap transaksi. Tombol per bagian tersedia bila hanya ingin menyetujui siswa, komponen, tabungan cocok, atau aturan pembayaran.
4. Buka Tabungan untuk selisih yang tersisa. Pada rekening tambahan, pilih **Rekening utama yang digantikan**, isi **Jumlah Sebenarnya**, lalu **Konfirmasi Saldo Sebenarnya**. Periksa pemilik, rekening, saldo sebelumnya, saldo baru, dan penyesuaian pada modal, lalu sahkan.
5. Setelah simulasi selesai, periksa ringkasan dan lakukan konfirmasi migrasi terakhir. Persetujuan massal hanya menyiapkan draf; tidak langsung menulis siswa/master/transaksi operasional.

Saldo sebenarnya menjadi **saldo akhir utama**, bukan tambahan setoran. Contoh: jurnal utama Rp1.000, bukti tambahan Rp900, pengelola mengesahkan Rp850. Saldo akhir utama Rp850 dengan penyesuaian −Rp150. Tidak ada rekening ganda atau kas masuk baru; sumber asli tetap tersimpan. Setelah rekening dipakai, koreksi melalui Rekening & Transaksi agar transaksi lanjutan ikut diperiksa.

## Aturan persetujuan

- Alumni yang dikenali disahkan per kelompok/tahun. Kelompok khusus belum diklasifikasikan ditampung sebagai rekening nonakademik. Identitas transaksi tanpa master siswa ditampung sebagai **Pemilik Legacy tanpa master siswa**, menggunakan ID internal dan identitas sumber; tidak dibuatkan siswa/NIS palsu atau SPP otomatis.
- Nama biaya mengikuti master Legacy; nama yang sama berbagi usulan. Jika satu kode memiliki beberapa nama, persetujuan massal mempertahankan gabungan nama sumber dan setiap rincian pembayaran. Usulan baru dibuat nonaktif dengan **Tarif belum ditetapkan**, bukan biaya gratis. POMG tetap Komite, U_SORGA menjadi Komite/POMG pada semua unit (keputusan 24 Oktober 2026).
- Jika U_LAIN nol, rincian yang benar-benar tersimpan menjadi nominal pembayaran. Jika total sudah mencakup rincian, keduanya dihitung sekali. Total positif yang bertentangan dengan rincian tetap ditampilkan sebagai pengecualian.
- Tabungan utama yang cocok tidak perlu diisi ulang. Untuk tambahan yang cocok dan memiliki satu pasangan utama yang jelas, ringkasan massal menampilkan saldo lama → baru sebelum disahkan. Pengesahan saldo manual yang sudah tersimpan dipertahankan.
- Masalah ditampilkan menurut kelompok penyebab dan jumlah catatan terkait. Angka kelompok masalah berbeda dari jumlah transaksi sumber; rincian dan arsip tidak dibuang untuk mengecilkan angka.

## Pengujian dan batas

Uji menggunakan instance MySQL privat terpisah dari database utama dan lab copy. Backup sebelum/sesudah publikasi fixture benar-benar dipulihkan dan diverifikasi. Publikasi, rollback, retry, penyesuaian saldo utama, audit, penolakan pengiriman ganda/CSRF/peran/unit, dan formulir server diuji. Source 2019/2026 keenam unit dibaca dari hasil ekstraksi terverifikasi; sumber SD terbaru dicocokkan hash-nya dengan `SD171026` pengguna dan disimulasikan melalui worker aplikasi pada instance terisolasi.

Pada sumber SD terbaru, **46.852 pembayaran nonnegatif** dapat disiapkan bersama. Tiga pembayaran DU bernilai **−Rp1.010.000, −Rp1.310.000, dan −Rp1.310.000** tetap ditahan sebagai satu kelompok; nilai tersebut tidak diubah menjadi penerimaan positif. Pengecualian tabungan/identitas yang tersisa dapat diselesaikan per rekening, bukan meninjau puluhan ribu transaksi satu per satu. Hasil kesiapan bukan klaim bahwa seluruh sumber sudah diterapkan.

Pembaruan memakai JSON draf dan tabel yang sudah tersedia, tanpa migrasi skema tambahan. Aturan operasional naik ke **versi 5**; mulai ulang worker dan jalankan persetujuan/simulasi ulang pada pekerjaan lama. API lama tetap tersedia; aksi tambahan `full_bulk_preview` dan `full_bulk_accept` memakai CSRF, versi draf, cakupan, serta digest sumber/master. Endpoint status mempertahankan hitungan baris lama, sementara tampilan menggunakan jumlah kelompok masalah.

Tidak ada impor nyata pada database utama atau database pengguna di lab copy. Backup, sumber, sesi, kredensial dan artefak QA tetap di luar Git. Tidak melakukan commit/push atau memperbarui deployment.

Hasil simulasi worker SD versi akhir: **4 kelompok masalah / 20 catatan pengecualian**, mencakup 3 DU negatif, identitas kosong, dan rekening yang membutuhkan saldo/pasangan. Uji UI mencakup **72 kombinasi**, Chrome 155 dan Chromium 109, lebar 360/768/1366 px, kedua tema, JavaScript aktif/nonaktif, keyboard/Escape serta input tetap tersimpan ketika konfirmasi dibatalkan. Windows 7 fisik belum diuji. Runtime/browser importer dipertahankan.
