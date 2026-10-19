# Modal dan pemeriksaan otomatis Migrasi Legacy

**Aturan lanjutan:** [Penyelesaian sistem untuk 18 catatan tersisa](LEGACY_SYSTEM_RESOLUTION_20261019.md) memakai operasional 7/workflow 8. Catatan hasil di bawah mencatat tahap sebelumnya; panduan lanjutan menggantikan perlakuan saldo, DU negatif dan catatan anonim yang lebih baru.

## Cara menggunakan

1. Buka pekerjaan migrasi yang sudah diperiksa. Pilih persetujuan seluruh data atau bagian yang diperlukan.
2. Modal menampilkan jumlah terkait dan perubahan saldo sebelum/sesudah. Tombol tetap berada di bawah; rincian panjang bergulir di tengah. Master tanpa tarif tetap nonaktif.
3. Pilih **Setujui & Simulasikan**, atau gunakan **Periksa Ulang / Simulasi** untuk draf yang sudah disahkan. Aturan operasional versi 6 otomatis memeriksa rincian pembayaran yang valid; tidak diperlukan pengesahan per transaksi.
4. Buka **Lihat Histori Sebelum 2020** untuk catatan tanpa identitas yang diterima sebagai histori. Pencarian, rentang tanggal, pagination 25 catatan, rincian dan CSV bekerja tanpa JavaScript.
5. Periksa sisa masalah menurut penyebabnya. Tabungan berselisih memakai **Jumlah Sebenarnya** pada rekening yang tepat. Persetujuan draf/simulasi belum menjalankan migrasi final.

## Aturan dan bukti

- U_LAIN nol/kosong + rincian positif dan valid: rincian digunakan sekali. Total positif yang mencakup rincian tetap dihitung sekali. Total positif yang bertentangan, nominal negatif, atau presisi yang tidak dapat dibaca tetap ditampilkan untuk penyelesaian.
- Pemetaan komponen, hubungan DU, potongan, kelas dan saldo FIX tidak diubah oleh pemeriksaan ini. Bukti otomatis terikat row key, sumber dan versi aturan, masuk digest simulasi dan manifest batch. Konfirmasi lama tidak berlaku pada aturan yang berubah.
- Histori anonim hanya diterima jika tanggal dari baris sumber valid dan kurang dari 2020-01-01. NIS/tahun masuk, dugaan kelulusan, dan waktu backup tidak menggantikan tanggal yang hilang.
- Semua nilai mentah, kemunculan baris berulang dan tanggal asli tetap tersimpan dalam arsip. Referensi kosong tetap kosong. Tidak dibuat siswa, NIS, rekening, tagihan, setoran atau kas baru dari histori anonim.
- Metadata keputusan memakai JSON/manifest existing; tidak ada migrasi skema baru. Halaman histori membaca staging yang sudah diverifikasi atau arsip MySQL setelah publikasi; sumber arsip tetap dapat dibaca setelah penyimpanan staging tidak tersedia.
- Catatan anonim tahun 2023/2025, saldo tanpa tanggal, dan tiga DU bernominal negatif tanpa tanggal pada contoh SD tetap membutuhkan penyelesaian. Migrasi parsial tetap membedakan data siap dari yang tertahan.

## Pengujian

- Pure projection: batas 2019/2020, tanggal kosong/invalid, nilai kosong, pola rincian nol/positif, nominal negatif, saldo/kas tidak bertambah dan hasil ulang identik.
- Enam ekstraksi asli 2019/2026 diverifikasi fingerprint-nya. SD terbaru: 46.852 pembayaran siap, termasuk 7.881 pola rincian otomatis; 16 catatan anonim lama diterima (15 referensi kosong tahun 1999 dan satu jurnal nol tahun 2011). Angka ini tidak berarti 16 penerimaan uang baru.
- Transaksi fixture pada instance terpisah: publikasi arsip dan manifest, rollback, retry, pembayaran/saldo sekali, laporan kas, pencarian/filter/CSV sesudah publikasi, serta backup sebelum/sesudah yang benar-benar dipulihkan.
- HTTP: konfirmasi dan pembatalan, input FIX tetap ada, filter/CSV histori, penolakan anonim/peran lain/lintas unit, CSRF dan versi kedaluwarsa.
- Browser: Chrome terpasang dan Chromium 109, desktop/tablet/ponsel 360/768/1366 px, kedua tema dan tiga palet unit, JavaScript aktif/nonaktif, daftar perubahan panjang, Escape, footer tetap terlihat, dan viewport/skala emulasi setara zoom 80–200%. Tidak diuji pada perangkat Windows 7 fisik atau zoom native antarmuka browser.

Pengujian mutasi memakai instance terisolasi, bukan db_spp atau database lab pengguna. Runtime importer/browser yang sudah tersedia dipertahankan. Tidak ada commit/push atau pembaruan deployment pada tahap ini.


## Hasil penerapan pada lab copy

Source utama dan 26 file terkait pada lab copy sudah sama. Worker lab memakai aturan operasional 6 dan workflow 7; tidak ada skema baru yang perlu dipasang untuk pembaruan ini.

Pekerjaan SD171026 versi draf 7 dihitung ulang: **46.852 pembayaran siap**, termasuk **7.881 pemeriksaan otomatis**, dan **16 catatan histori anonim lama**. Sisa **18 catatan dalam 4 kelompok** terdiri dari 2 identitas tanpa tanggal/bertanggal baru, 3 pembayaran DU negatif tanpa tanggal, 11 pemeriksaan tabungan, dan 2 rekening tambahan. Data ini tetap dibedakan dari hasil yang siap.

Seluruh pengaturan draf, 11 saldo FIX/penyesuaian yang disahkan pengguna, dan pekerjaan sebelumnya dipertahankan. Fingerprint 50 tabel database utama dan 59 tabel database lab identik sebelum/sesudah pekerjaan. Migrasi final belum dijalankan.
