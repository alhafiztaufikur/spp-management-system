# Migrasi Legacy menjadi data kerja

**Finalisasi:** setelah simulasi siap, gunakan **Finalisasi Migrasi → Mulai Migrasi Sekarang** pada kartu dekat ringkasan. Data baru menjadi operasional setelah penerapan dan verifikasi selesai. [Urutan layar dan perbaikan memori](LEGACY_FINALIZATION_20261019.md).

**Pembaruan penyelesaian otomatis:** [Aturan saldo sumber, presisi, rekening tambahan, jurnal nol dan referensi terpisah](LEGACY_SYSTEM_RESOLUTION_20261019.md). Catatan tanpa pemilik yang lebih baru tetap berada di daftar terpisah dan tidak menjadi histori sebelum 2020.

**Pembaruan tampilan dan pemeriksaan:** modal menampilkan perubahan yang relevan, tombol tetap terlihat saat isi digulir. Pembayaran dengan total Lain-Lain nol dan rincian valid diperiksa otomatis. Histori tanpa identitas hanya diterima otomatis bila tanggal sumber sebelum 2020. [Panduan terbaru](LEGACY_AUTOMATIC_REVIEW_20261019.md).

**Pembaruan persetujuan massal:** gunakan **Setujui Semua Data** untuk siswa, kelompok, komponen dan tabungan cocok. Rekening tambahan menyediakan **Jumlah Sebenarnya** untuk menggantikan saldo akhir rekening utama yang dipilih. [Langkah dan aturan terbaru](LEGACY_BULK_REVIEW_20261019.md). Worker memakai aturan operasional versi 7; jalankan simulasi ulang pada draf lama.

Mode **Migrasi Operasional Legacy** membawa siswa, pembayaran dan tabungan bersama ke unit yang masih kosong. Impor Siswa dan Histori Keuangan tetap tersedia dengan perilaku sebelumnya. Pengujian otomatis memakai instance terisolasi. Lab copy tersedia untuk percobaan pengguna dengan database db_spp_test2; database utama dipertahankan.

## Menyiapkan server

Petunjuk PowerShell lengkap, pemeriksaan target, dan penanganan kegagalan tersedia di [Panduan Skema CLI](LEGACY_SCHEMA_CLI.md). Perintah `--check` hanya membaca; pemasangan menggunakan `--apply` setelah backup dan pemeliharaan.

1. Siapkan database SistemSPP bersih. Akun dan master boleh tersedia; siswa dan transaksi unit tujuan harus kosong.
2. Pasang layanan backup dan importer sesuai [runbook](LEGACY_IMPORT_RUNBOOK_20261003.md). Backup harus lolos pemulihan pada instance terpisah.
3. Administrator membuat backup pengaman, mengaktifkan pemeliharaan dengan penanda `tmp/financial_migration.lock`, lalu menjalankan pada konfigurasi **database tujuan yang sudah diperiksa**:

   ```powershell
   php scripts/migrate_legacy_operational.php --apply --confirm-maintenance
   ```

   Perintah tanpa `--apply` hanya memeriksa kesiapan. Setelah pemasangan dan health lulus, lepas penanda pemeliharaan dan mulai ulang worker.

4. Pastikan worker memakai source terbaru. Worker lama ditolak untuk unggahan mode operasional. Launcher worker menyediakan PHP 768 MiB; pemeriksaan sumber besar berjalan pada worker. Halaman membaca pratinjau dan bukti rekening terpilih.

Installer menambah sembilan tabel fisik beserta view dengan pembatasan unit: kelompok, pemilik, rekening, pembayaran, jurnal, tarif, data tertahan, kewajiban, dan audit. Relasi memakai ID internal, FK dan indeks. Installer tidak mengimpor sumber; web tidak memasang skema. Instalasi bersih melalui `sql/migrate_units.php` memasukkan skema ini.

## Langkah pengguna

1. Pilih SD, SMP atau SMA. Buka **Pengaturan → Import Legacy → Migrasi Penuh**, lalu unggah backup `.dat`, `.bak`, atau tanpa ekstensi, maksimal 100 MiB.
2. Setelah dibaca, kelas yang jelas, OUT, GK/TABGJ, dasar SPP/POMG, komponen pembayaran, serta tabungan yang cocok disiapkan dari backup. Tidak perlu mencentang pengesahan untuk setiap kelas atau rekening. Komponen selain pasangan yang dikenal memakai Histori Saja tanpa meminta tarif master baru.
3. Periksa tahun ajaran dan waktu mulai beralih, lalu pilih **Siapkan & Periksa Hasil**. Pemeriksaan berjalan pada worker; halaman dapat dimuat ulang atau dipakai tanpa JavaScript.
4. Baca jumlah siswa, pembayaran, saldo dan data tertahan. Pilih **Periksa Konfirmasi**, kemudian **Migrasikan Data Siap** pada konfirmasi akhir. Backup dan pengujian pemulihan berjalan otomatis sebelum/sesudah penerapan.
5. Setelah hasil terverifikasi, buka **Rekening & Transaksi**. Data yang cocok langsung dapat digunakan; rekening atau transaksi yang tertahan dapat dilengkapi dari pekerjaan yang sama.

Bagian **Sesuaikan aturan dan kelompok secara manual** tersedia bila ingin mengubah usulan. Pemetaan komponen yang sudah disimpan tetap dipertahankan. Status transaksi yang belum diketahui, kelas di luar jenjang, identitas ambigu dan saldo berselisih tetap perlu ditinjau pada bagian terkait.

**Tunggakan belum diperiksa tidak berarti lunas.** Persiapan dari backup mengizinkan siswa dan transaksi yang jelas dipindahkan tanpa meminta pengesahan seluruh kewajiban lebih dahulu. Rincian utang dapat dilengkapi kemudian; tarif terbaru tidak dijadikan utang historis otomatis. Ringkasan menampilkan jumlah siswa dengan kewajiban belum diperiksa. Pembayaran baru dan periode yang telah disahkan tetap memakai pemeriksaan existing.

Aturan awal mengikuti profil yang sudah didukung: total Lain-Lain mencakup rincian, nominal pembayaran SPP sudah setelah potongan, DU terpisah, serta kolom tabungan pada pembayaran menjadi referensi. Jurnal tabungan menjadi acuan pemeriksaan. Saldo berselisih dapat disahkan melalui Saldo FIX dengan bukti dan konfirmasi; selisih dicatat terpisah sebagai penyesuaian migrasi. Identitas rekening atau jurnal yang tidak valid tetap perlu diperiksa, dan sumber mentah tidak ditimpa.

Pemasangan skema dan restart worker versi terbaru tetap diperlukan sekali pada server tujuan. Worker harus memakai aturan operasional versi 5 dan aturan Migrasi Penuh versi 6. Semua Unit hanya membaca; pilih unit pekerjaan sebelum menyimpan. Simpan backup sumber dan penyimpanan privat importer untuk melanjutkan data tertahan.

Persiapan dan simulasi tidak membuat siswa/master/tagihan operasional. Saat melanjutkan batch, data yang sudah digunakan tidak ditimpa sumber lagi. Jika verifikasi setelah penerapan gagal, unit menunggu verifikasi ulang atau pemulihan administratif.

## Kelompok dan tarif

- Kelas akademik mengikuti penempatan dan kenaikan kelas. **OUT** menjadi siswa keluar dengan transaksi tetap tersedia. **GK/TABGJ** menjadi pemilik rekening tersendiri tanpa NIS siswa, SPP otomatis atau perhitungan penggajian. **BTQ/kode lain** menunggu klasifikasi yang disahkan. Kelompok ditampilkan pada Master Kelas terpisah dari peserta kenaikan kelas.
- Awalan NIS `2021`, `2122`, `2223` dan seterusnya hanya menginformasikan tahun masuk. Keaktifan mengikuti klasifikasi dan penempatan yang disahkan.
- Dasar SPP disimpan dari `SPP_PERBULAN`; Komite dari **POMG**. Tarif Rp530.000 tidak diganti tarif kelas atau dianggap potongan. Penerbitan berikutnya dan kenaikan kelas mempertahankan dasar ini.
- Perubahan dasar tarif dilakukan tersendiri oleh Super Admin melalui **Rekening & Transaksi → Ubah Dasar Tarif SPP**, dengan alasan, pratinjau dan konfirmasi. Awal berlaku adalah periode operasional berjalan, atau awal penagihan setelah peralihan bila lebih akhir. Tagihan berbayar, dibatalkan, ditanggung PSB atau tahun tertutup dipertahankan.

## Pembayaran, saldo dan koreksi

Pembayaran memakai representasi kerja `legacy_op_payments`, terpisah dari arsip mentah. Riwayat utama, rincian, cetak dan laporan membaca representasi kerja satu kali dengan penanda Legacy. Kas historis tidak menjadi setoran kasir baru. Operator sumber dipertahankan tanpa membuat akun lama atau nomor struk historis SistemSPP.

DU tanpa tanggal tetap **Tanggal tidak tercatat**. Pembayaran tanpa tanggal tersedia pada rekening/rincian dan dihitung sebagai jumlah di luar total periode. Tanggal migrasi tidak dijadikan tanggal bayar. Tunggakan yang terbukti menjadi **Tunggakan Legacy** melalui Biaya Lain; pembayaran baru atas tagihan tersebut menjadi penerimaan pada tanggal uang diterima.

Hanya Super Admin aktif pada unit yang sesuai dapat mengoreksi pembayaran/jurnal hasil migrasi. Buka transaksi dari rincian pembayaran atau **Rekening & Transaksi**, isi alasan, pilih **Pratinjau Dampak Koreksi**, lalu konfirmasi. Identitas tujuan, periode, komponen, nominal, versi dan dampak diperiksa ulang sebelum commit. Sumber dan operator asli tetap tersimpan.

Contoh: pokok Oktober Rp530.000 dan pembayaran Rp500.000 menyisakan Rp30.000. Mengoreksi pembayaran menjadi Rp490.000 menghasilkan sisa Rp40.000; tarif tetap Rp530.000. Komponen yang belum terhubung ke kewajiban sah perlu ditinjau dahulu sebelum nominalnya memengaruhi tagihan. Pembayaran kasir yang masuk setelah pratinjau membatalkan konfirmasi jika dampaknya berubah.

Pembayaran, edit dan penghapusan pembayaran kasir juga memperbarui sisa kewajiban, versi serta pemeriksaan hasil migrasi dalam transaksi yang sama. Riwayat aktivitas pembayaran mencatat pelakunya; perubahan yang gagal ikut dibatalkan seluruhnya.

Jurnal lama dan jurnal baru membentuk saldo berjalan; saldo peralihan meneruskan jurnal lama satu kali. Koreksi/pemindahan jurnal mengunci rekening terkait dan ditolak jika saldo berjalan menjadi negatif, termasuk setelah transaksi baru. Rekening lain tetap berfungsi. Rekening yang sudah dipakai dikoreksi melalui transaksi, bukan mengubah saldo persiapan draf.

Pembatalan menyimpan status, versi dan audit sebelum/sesudah, tanpa menghapus sumber. Cetak ulang merupakan salinan transaksi Legacy dan tidak mencatat penerimaan baru.

## Pengujian dan batas penerapan

Pengujian 17–18 Oktober 2026 memakai instance MySQL/SQL Server privat terpisah, bukan database utama Laragon atau Copy.

- Worker membaca ketiga backup terbaru dan tiga contoh 2019. Jumlah siswa terbaru **SD 1.133, SMP 1.762, SMA 397** dan fingerprint ekstraksi diverifikasi. Sumber nyata masih memiliki pengecualian; pemeriksaan sumber bukan penerapan seluruh datanya.
- Fixture terisolasi menguji siswa/pembayaran bersama, rekening non-siswa, dasar 530.000, koreksi sisa, versi, konfirmasi, hak akses, saldo negatif, rollback, kelanjutan batch, idempotensi dan master baru nonaktif.
- Transaksi kasir melalui endpoint aplikasi menguji setoran/penarikan, buku gabungan, cicilan/pelunasan, pemisahan kas, PDF dan Excel. Backup sebelum/sesudah benar-benar diuji pemulihannya.
- Sebanyak 216 kasus browser/ukuran/tema/JavaScript diuji, termasuk palet SD/SMP/SMA. Browser nyata: Chrome terpasang dan Chromium **109.0.5412.0**, ukuran 390/768/1366, tema terang/gelap, JavaScript aktif/nonaktif. Windows 7 fisik belum diuji. Runtime bersama/importer tidak dihapus; tidak memasang browser baru di AppData.
- Pratinjau sumber SMP terbaru berisi 1.762 siswa diuji melalui lima panel HTTP dengan cache ringkas, tanpa menerapkan sumber nyata. Pengujian juga menolak pencetakan saldo nol untuk rekening yang masih ditahan.
- Fingerprint isi **50 tabel database utama identik** sebelum/sesudah pengujian: 2.077 siswa dan 1.020 pembayaran tetap. Koreksi/pembatalan dan pemindahan jurnal pada salinan mempertahankan uang kas baru serta dasar tarif siswa.

Tambahan pengujian persiapan otomatis: enam ekstraksi sumber 2019/2026 diproses ulang tanpa pengesahan kelompok berulang; alur formulir server sampai publikasi parsial dan backup terverifikasi lulus pada salinan unit SMP. Sebanyak 32 kasus tampilan persiapan otomatis lulus di Chrome/Chromium 109, ponsel/desktop, kedua tema, keyboard dan tanpa JavaScript. Saldo berselisih ditahan tersendiri; tunggakan tidak dikarang dari tarif.

Backup terbaru wajib lolos pemeriksaan dan simulasi sendiri. Identitas yang tidak dapat dibuktikan, rekening tambahan atau kewajiban ambigu tidak otomatis digabung atau dianggap lunas; penyelesaiannya memerlukan bukti yayasan.

**Batas pekerjaan:** skema/impor mode operasional belum diterapkan pada database lokal utama. Folder Copy, data pengguna, paket deployment, commit dan push tidak diubah.


## Peninjauan kelompok, komponen dan saldo FIX ? 19 Oktober 2026

Pembaruan dilakukan pada source utama. Skema ini **belum dipasang pada database utama atau Copy** dalam pekerjaan ini. Administrator perlu menjalankan kembali perintah CLI pada langkah persiapan di atas, setelah backup terverifikasi dan pemeliharaan, kemudian memulai ulang worker. Installer aman dijalankan ulang: menambah jenis/tahun kelas alumni, penanda tarif belum tersedia, serta jenis jurnal penyesuaian; tidak mengimpor data atau menulis ulang transaksi.

1. **Kelompok & Tarif:** buka pengaturan manual jika diperlukan. Kode A2025/B2025 mengusulkan Alumni2025; A2122 mengusulkan Alumni2022. Periksa tahun lalu sahkan kelompok. Awalan NIS menampilkan tahun masuk, bukan keputusan lulus. OUT tetap siswa keluar. Beberapa kelompok dapat menunjuk kelas Alumni yang sama dalam satu unit. Alumni tidak masuk PSB, penerbitan SPP, atau kenaikan kelas.
2. **Lihat seluruh sasaran & tabungan:** cari nama/identitas, lihat maksimal 25 hasil per halaman, kemudian pilih **Tinjau Tabungan** pada identitas yang tepat. Rekening utama, catatan tambahan, saldo sumber, jurnal, selisih dan FIX dibedakan. Daftar dapat digulir di dalam panel; saldo tambahan tidak ikut total utama.
3. **Komponen:** nama dibaca dari lain.KODE/lain.NAMA, termasuk nol di depan kode. Rincian beberapa slot dengan kode sama ditampilkan bersama, tanpa menghapus rincian transaksi. Pilih master existing atau **Siapkan Master Baru**. Bila tarif belum diketahui, centang **Tarif belum ditetapkan**; master dibuat nonaktif dan tidak boleh diaktifkan/ditagihkan sebelum tarif positif diisi. Total histori bukan tarif.
4. **Kode testing/tanpa pasangan:** usulan **Lain Test** menampung rincian tanpa kode dan kode 022 setelah disahkan. Pada sumber SD terbaru yang diperiksa, 022 memiliki **satu rincian Rp10.000**. Kode 0016 memiliki nama Basket dan Pendalaman Materi: pilih nama berdasarkan bukti, jangan menyimpulkan keduanya sama. Master sumber tanpa pembayaran ditampilkan sebagai **Belum digunakan**.
5. **Rekening Uji Legacy:** identitas tepat seperti test ditampilkan sebagai kandidat untuk disahkan. Hasilnya memakai ID internal, tanpa NIS siswa baru atau SPP otomatis; pembayaran dan rekening yang dapat dikaitkan tetap dibawa. Nama seseorang yang hanya mengandung kata serupa tidak diklasifikasikan otomatis.
6. **Tabungan:** pilih pemilik, isi **Saldo yang Disahkan / FIX** dan bukti, lalu **Periksa Saldo FIX**. Konfirmasi menampilkan saldo sumber, FIX dan selisih. **Kembali Mengubah** mempertahankan isian. Saldo nol diperbolehkan; nominal negatif atau lebih dari dua desimal ditolak.

Contoh: jurnal bersih Rp800, saldo sumber Rp1.000, saldo FIX Rp900. Buku menampilkan jurnal Rp800 lalu **Penyesuaian Saldo Migrasi Rp100**, kemudian saldo peralihan Rp900. Penyesuaian bukan setoran atau kas masuk baru. Bukti dan nilai sebelum/sesudah dicatat bersama pelaku pada audit. Saldo berjalan berikutnya Rp900 + setoran baru ? penarikan baru. Koreksi sesudah rekening dipakai dilakukan melalui **Buka Rekening & Koreksi**, bukan mengubah draf saldo awal.

Pengesahan FIX hanya menyelesaikan selisih nominal. Rekening ganda/identitas ambigu, tanggal atau nominal jurnal yang tidak valid tidak otomatis digabung atau dihapus. Bagian valid tetap dapat diterapkan; data tertahan dilanjutkan pada batch yang sama. Perubahan draf membatalkan simulasi dan konfirmasi terdahulu.

Hasil pengujian pembaruan ini dicatat pada [QA peninjauan 19 Oktober](LEGACY_REVIEW_QA_20261019.md).
