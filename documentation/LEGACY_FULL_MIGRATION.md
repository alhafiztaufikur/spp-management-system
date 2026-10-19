# Migrasi penuh dari sistem lama

**Peninjauan operasional 19 Oktober 2026:** nama biaya dari master Legacy, saran Alumni, Rekening Uji, master nonaktif tanpa tarif, dan Saldo FIX dengan penyesuaian beraudit tersedia pada mode operasional. Lihat [langkah terbaru](LEGACY_OPERATIONAL_MIGRATION.md#peninjauan-kelompok-komponen-dan-saldo-fix--19-oktober-2026). Memerlukan pembaruan skema CLI pada database tujuan, lalu restart worker; pekerjaan ini tidak memasangnya pada database utama/Copy.

**Mode operasional terbaru:** lihat [Migrasi Legacy menjadi data kerja](LEGACY_OPERATIONAL_MIGRATION.md). Mode ini menyimpan dasar tarif per siswa, rekening non-siswa, pembayaran/jurnal yang dapat dikoreksi Super Admin, dan data tertahan per bagian. Memerlukan skema CLI tambahan; belum dipasang pada database lokal utama dalam pekerjaan ini. Keterangan histori terkunci di bawah tetap berlaku untuk mode arsip lama.

Panduan layar terbaru: [Cara menggunakan Import Legacy](LEGACY_UI_GUIDE.md). Pilihan awal terdiri dari Migrasi Penuh, Impor Siswa, dan Histori Keuangan. Tahap serta bagian peninjauan dapat dibuka kembali melalui URL dan tetap bekerja tanpa JavaScript.

**Pengesahan komponen, 17 Oktober 2026:** yayasan memastikan **POMG adalah Komite bulanan**. Usulan tarif siswa mengambil POMG sumber; nominal tetap disahkan pada aktivasi atau peninjauan migrasi. Kelompok dapat mengikuti nominal POMG tiap siswa atau menggunakan nominal kelompok yang dipilih secara eksplisit. Rincian pembayaran bernama tepat POMG diusulkan sebagai Komite; arsip sumber tetap utuh dan aturan total/rincian tetap mencegah penghitungan ganda. U_SORGA tidak dipetakan ke Komite. Aturan peninjauan menjadi versi 5; simulasi/konfirmasi lama harus diperiksa ulang, dan worker perlu dimulai ulang setelah source diperbarui.

Mode **Migrasi Penuh** ditujukan untuk server yayasan dengan database SistemSPP yang bersih. Pengerjaan fitur dan pengujian tidak mengimpor data ke database operasional PC pengembangan.

## Status pemasangan lokal, 16 Oktober 2026

Skema keuangan dan Migrasi Penuh sudah dipasang pada `db_spp` PC ini atas permintaan pengguna, melalui CLI `scripts/migrate_legacy_full.php --apply --confirm-maintenance`. Pemeriksaan CLI menyatakan kedua skema siap dan health `ok`. Seluruh isi 36 tabel lama tetap identik; 14 tabel baru masih kosong. Tidak ada backup `.dat` yang diimpor dalam pemasangan ini.

Backup pengaman **9e8883bd8572caa52e99fc9eb2addd86** dan backup hasil **16c11e3521812c58e93d919d6618c4a9** lulus uji pemulihan terisolasi dan tersedia pada penyimpanan backup privat. Akses aplikasi sudah dibuka kembali setelah pemeriksaan.

**SD lokal sudah berisi 458 siswa.** Migrasi Penuh tetap mensyaratkan unit tujuan kosong; pemasangan skema tidak mengubah ketentuan ini. Untuk menambahkan identitas siswa ke database lokal yang sudah berisi data, gunakan **Import Legacy → kategori Siswa**. Migrasi Penuh digunakan pada database server bersih, tanpa menghapus data lokal.

## Persiapan server

1. Pasang SistemSPP dan database bersih sesuai [panduan deployment](DEPLOY_PC_YAYASAN.md). Siapkan akun Super Admin, identitas sekolah, kelas/rombel, tahun ajaran, serta tarif seluruh tingkat. Jangan masukkan siswa atau transaksi percobaan pada unit tujuan.
2. Pasang layanan backup dan pastikan pencadangan serta uji pemulihannya berhasil. Konfigurasi backup harus milik server tersebut; jangan menyalin kredensial atau identitas instance dari PC lain.
3. Pasang SQL Server LocalDB 2019 dan worker sesuai [panduan Import Legacy](LEGACY_IMPORT_RUNBOOK_20261003.md). Jalankan worker memakai PHP dengan PDO SQLite dan memori yang cukup; rekomendasi `memory_limit=768M` untuk contoh tiga backup yang telah diuji.
4. Pada instalasi baru, alur migrasi unit CLI memasang skema migrasi penuh setelah skema identitas. Untuk database dengan struktur multiunit existing, pasang tambahan skema melalui CLI, setelah backup dan menutup akses aplikasi:

   ```powershell
   New-Item -ItemType File -Path .\tmp\financial_migration.lock
   php .\scripts\migrate_legacy_full.php --apply --confirm-maintenance
   Remove-Item -LiteralPath .\tmp\financial_migration.lock
   ```

   Gunakan akun MySQL pemasang yang memiliki izin DDL melalui konfigurasi privat. Jika migrasi identitas belum terpasang, pasang terlebih dahulu dengan prosedur CLI pada panduan Import Legacy. Jangan menghapus berkas pemeliharaan jika pemasangan gagal; periksa dan selesaikan kegagalannya dahulu.
5. Mulai ulang worker setelah memperbarui source. `php scripts/migrate_legacy_full.php` tanpa `--apply` hanya memeriksa kesiapan skema. HTTP tidak menjalankan migrasi skema.

## Cara memindahkan satu unit

Alur utama: **Unggah → Periksa Hasil → Selesaikan Pengecualian → Konfirmasi → Selesai**.

1. **Siapkan dan unggah.** Pilih unit tujuan kosong, buka Import Legacy → Migrasi Penuh, lalu periksa kartu kesiapan. Tentukan waktu peralihan WIB dan hentikan pencatatan sistem lama saat membuat backup final. Unggah satu `.dat` untuk unit tersebut. Pemeriksaan sumber berjalan di worker; halaman memperbarui status dan tetap menyediakan Muat Ulang.
2. **Periksa hasil.** Isi waktu peralihan, tahun ajaran, dan referensi bukti yayasan. Buka Aturan Pembayaran Lama untuk mengesahkan hubungan Lain-Lain/rincian, potongan SPP, dan pembayaran DU berdasarkan contoh sumber. Ringkasan menunjukkan kelompok kelas, variasi tarif sumber, serta tarif Master SPP.
3. **Sahkan kelompok dan selesaikan pengecualian.** Buka Siswa & Kelas, Komponen Biaya, Tabungan, atau Tunggakan. Pada pengesahan kelompok, pilih kelas sumber dan Tampilkan Kelompok. Status/kelas/tarif, usulan komponen, saldo cocok, serta kewajiban memiliki pratinjau tersendiri. Pilih siswa tertentu bila diperlukan; tanpa pilihan individual, seluruh kelas sumber menjadi sasaran. Periksa daftar nama dan perbandingan sebelum → sesudah, lalu klik Sahkan Data. Kembali Mengubah mengembalikan isian tanpa pengesahan. Pergantian kelas sumber memuat anggota baru dan membersihkan pilihan sementara. Pilihan yang sudah dikoreksi dipertahankan kecuali opsi penggantian dipilih. Nominal SPP yang berbeda dengan tarif efektif kelas ditahan untuk koreksi individual; selisih tidak otomatis menjadi potongan.
   Untuk komponen, pilihan Histori Saja tidak meminta tarif atau membuat master. U_SORGA diusulkan sebagai Histori Saja berdasarkan kebijakan penghentian. SPP/DU memakai usulan sumber; komponen lain dapat tetap menjadi histori atau dipasangkan ke master. Kolom tabungan pada pembayaran tetap memerlukan pengesahan hubungan dengan jurnal.
   Untuk kewajiban, isi hanya periode/status/sisa nominal yang sama pada siswa terpilih; rincian individual dapat diperbaiki lewat Tinjau Siswa. Bukti dan persetujuan seluruh kewajiban wajib diisi, termasuk bila tidak ada sisa tambahan. Status DU tahun peralihan tetap wajib dinyatakan untuk siswa aktif. Rekening berselisih dan identitas ambigu ditangani pada daftar pengecualian. Ketiadaan tagihan tidak otomatis berarti lunas.
4. **Jalankan simulasi dan konfirmasi.** Simulasi berjalan dalam antrean agar permintaan browser tidak menunggu perhitungan besar. Selesaikan Harus Diperbaiki, tinjau catatan histori, lalu buka konfirmasi akhir. Periksa siswa aktif/alumni, histori, saldo, tunggakan, mulai penagihan, dan master baru nonaktif. Perubahan sumber, master, atau draf membatalkan hasil lama; konfirmasi final berlaku lima menit.
5. **Tunggu Selesai.** Worker menguji pemulihan backup tujuan, menerapkan satu unit dalam transaksi, mencocokkan hasil, lalu menguji backup hasil. Gunakan SistemSPP setelah Migrasi Selesai. Jika tertahan sesudah commit, gunakan Verifikasi Ulang; jangan mengunggah ulang sebagai sinkronisasi atau mengubah status secara manual.

Kartu **Siap / Perlu Diperiksa / Harus Diperbaiki** menunjukkan peninjauan siswa. Kesiapan penerapan akhir selalu ditentukan oleh simulasi seluruh histori, tarif, saldo, dan kewajiban. Alumni tidak wajib memiliki kelas aktif, penempatan, atau jadwal tagihan baru; kelas sumber tetap tersedia dalam histori.

**Aturan unit:** Simpan Aturan Unit menyimpan pasangan komponen, hubungan pembayaran, dan usulan klasifikasi/kelas kelompok dalam JSON privat per unit dan struktur sumber. Master/class tujuan dicocokkan melalui nama/label, bukan menyalin ID MySQL antarserver. Profil hanya menjadi usulan dan tidak membawa saldo, tunggakan, bukti pengesahan, waktu peralihan, atau konfirmasi final ke backup lain. Profil tidak menggantikan pemeriksaan backup terbaru. Penyimpanan privat importer harus disertakan dalam pencadangan administratif terpisah dari SQL.

Mulai ulang worker setelah memperbarui source agar mendukung antrean simulasi dan heartbeat versi alur terbaru. Tidak diperlukan migrasi skema MySQL tambahan untuk penyederhanaan ini.

## Cara data dipakai

**Histori Saja:** nama asli, sumber, nominal dan total tetap tampil pada riwayat, laporan, PDF dan Excel. Kategori ini tidak memerlukan master/tarif dan tidak dapat digunakan untuk cakupan atau penerbitan tagihan. Jika kemudian ada kewajiban yang disahkan, gunakan komponen/master penagihan yang sesuai dengan bukti; jangan mengubah histori menjadi tagihan otomatis.

**Master baru dari migrasi:** menyimpan pemetaan/simulasi belum membuat master operasional. Master dibuat **Nonaktif**, bersama seluruh hasil unit dalam transaksi final; nama, tarif, dan status ditampilkan pada ringkasan/modal konfirmasi. Nominal histori tetap menggunakan sumber, bukan tarif master baru. Master nonaktif tetap dikenali oleh histori; sisa utang yang disahkan dibayar lewat master khusus Tunggakan Legacy. Tidak ada penerbitan massal otomatis dari usulan master. Jika kewajiban periode baru berbeda dari cakupan migrasi, buat master operasional terpisah.

Kolom tabungan pada pembayaran hanya dapat dipilih sebagai **Tabungan · referensi jurnal**, disertai alasan dan pengesahan hubungan sumber. Rekening/jurnal harus lengkap dan cocok; nominal tersebut tidak menjadi komponen kas atau saldo tambahan. Tarif/cache/tabel pendukung tetap referensi. Total histori sumber pada kartu komponen belum mengurangi tumpang tindih U_LAIN/rincian atau potongan; total yang akan dipakai tampil setelah simulasi sesuai aturan yang disahkan.

- **Siswa:** siswa aktif mendapat penempatan tahun tujuan; alumni tetap dapat ditemukan dalam histori. NIS sama pada unit berbeda tidak digabung. NIS Diknas yang valid dan unik ikut diisi; nilai lainnya tetap ada pada metadata sumber dan dicatat sebagai keterbatasan.
- **Histori pembayaran:** tampil pada Riwayat Pembayaran dan rincian utama dengan penanda **Legacy**. Nomor sumber, tanggal, komponen, dan operator dipertahankan. Gunakan pilihan **Histori Legacy tanpa tanggal** untuk baris tanpa tanggal. Cetak/PDF diberi judul **Salinan Histori Legacy**. Histori lama tidak dapat diedit/hapus melalui alur transaksi biasa.
- **Laporan:** pembayaran bertanggal ikut rekap historis sesuai tanggal sumber dan pasangan komponen. Histori tanpa tanggal tidak dimasukkan ke total periode; laporan menampilkan keterangannya. Operator Legacy dipisahkan dari akun aplikasi. Setoran kasir tidak menghitung histori lama sebagai setoran baru.
- **Tabungan:** jurnal lama terlihat bersama transaksi baru pada riwayat, buku, dan laporan. Saldo peralihan = saldo sumber yang sudah cocok dengan jurnal. Buku meneruskan saldo tersebut sekali; baris peralihan bukan setoran. Setoran/penarikan setelah peralihan memakai alur biasa.
- **Tunggakan:** sisa yang disahkan menjadi **Tunggakan Legacy** melalui Biaya Lain. Cicilan, pelunasan, struk baru, dan otorisasi perubahan mengikuti mekanisme existing. Penerimaan baru baru dicatat saat pembayaran dilakukan.
- **Tagihan berikutnya:** SPP dan Komite disiapkan dalam transaksi migrasi setelah konfigurasi serta cakupan selesai. Mulainya sesudah bulan peralihan; sisa bulan peralihan menggunakan Tunggakan Legacy. Bulan yang dibayar di muka atau sudah tercakup tidak diterbitkan ulang. Tarif PSB/DU sumber disimpan sebagai referensi, bukan otomatis menjadi utang baru. DU tahun berikutnya menggunakan master dan penerbitan normal.
- **Biaya Lain:** master biaya existing tidak memiliki kolom periode. Master yang sudah dipetakan ke kewajiban Legacy tidak digunakan ulang untuk kewajiban baru yang berbeda; buat master terpisah dengan nama/periode yang jelas. Ini mencegah kewajiban lama diterbitkan ulang.
- **Bukan siswa:** klasifikasi ini harus memiliki alasan/bukti. Bila memiliki data keuangan, bukti penyelesaian rekening/kewajiban di luar modul siswa juga wajib disahkan. Data tetap berada di arsip referensi; tidak menjadi transaksi atau saldo siswa. Jangan menggunakan klasifikasi ini untuk melewati saldo siswa yang bermasalah.

### Antarmuka draf komponen

API existing `full_save` menerima `patch.new_masters`, berupa objek berkunci `m_<16 karakter heksadesimal>` dengan `name`, `nominal` (string angka tanpa pemisah ribuan), `reason`, dan `confirmed` (boolean). Daftar ini menggantikan daftar usulan draf; `components` dan `students` tetap diperbarui per kunci seperti sebelumnya. Usulan yang tidak dipakai tidak dibuat. Kategori sementara `biaya_baru:<kunci>` dapat dipakai pada pemetaan komponen dan cakupan siswa. Konfigurasi lama tanpa `new_masters` tetap didukung.

Finalisasi membuat master pada unit tujuan dengan `is_active=0`, mengubah kategori sementara menjadi `biaya_lain:<id>`, dan menyimpan `resolved_masters` di manifest batch existing. Fingerprint histori menormalisasi ID tersebut kembali ke kunci usulan. Kegagalan sebelum commit membatalkan master bersama hasil lain; retry setelah commit hanya memverifikasi, tidak membuat ulang. Tidak ada tabel/menu master baru atau migrasi skema tambahan untuk fitur usulan ini.

### Antarmuka peninjauan kelompok dan simulasi

Semua aksi memakai endpoint existing `legacy_import_api.php`. Aksi tulis memerlukan Super Admin aktif, unit yang sesuai, CSRF, ID pekerjaan, dan versi draf. Semua Unit hanya membaca.

- `GET full_suggestions`: mengembalikan usulan siswa/kelompok/komponen, asal sumber, jumlah status, fingerprint sumber, dan master saat ini.
- `POST full_group_preview`: menerima `group` JSON dengan `type` (`students`, `components`, `savings`, `obligations`), `identities` berupa array string, `settings`, `evidence`, serta `replace` boolean opsional. Respons memuat daftar tepat, pengecualian, pilihan yang dipertahankan dan `digest`. Pengesahan kewajiban memerlukan `settings.confirmed: true`.
- `POST full_group_accept`: mengirim kembali `group` yang sama dan `preview_digest`. Server menghitung ulang seluruh pratinjau sebelum menyimpan. Tidak ada persetujuan terhadap identitas tambahan. Alasan, asal sumber, aktor, versi dan fingerprint pratinjau ikut manifest final.
- `POST full_profile_save`: menyimpan aturan yang telah dipilih sebagai usulan untuk sumber berikutnya. Tidak membuat master/tagihan dan tidak mewariskan pengesahan keuangan.
- `POST full_simulate` dengan `async=1`: mengembalikan `queued: true`; hasil dibaca melalui `full_status`. Worker memakai status `simulate_queued → simulating → ready/failed`. Pemanggilan tanpa opsi tetap menjalankan kontrak sinkron lama. Simulasi terputus diantrekan kembali saat worker dimulai ulang.

Kategori `histori:<32 karakter hash komponen>` stabil berdasarkan identitas komponen. Label sumber dan nominal disimpan dalam proyeksi existing, dikenali filter laporan, dan ditolak sebagai kategori cakupan kewajiban. Kategori/API/draf lama tetap didukung.

## Bila proses gagal

- Sebelum commit: seluruh siswa, saldo, histori terstruktur, dan tagihan unit dibatalkan bersama. Perbaiki masalah, simulasikan ulang, lalu konfirmasi kembali.
- Sesudah commit: unit berstatus tertahan dan mutasi ditolak oleh pengaman database. Gunakan **Verifikasi Ulang**. Worker memeriksa hasil yang sudah tersimpan dan mengulang backup verifikasi; tidak mengimpor ulang siswa atau histori.
- Bila fingerprint, saldo, atau sumber tidak cocok, jangan membuka unit dengan mengubah status secara manual. Periksa log privat dan manifest bersama administrator. Jika pemulihan diperlukan, gunakan prosedur pemulihan administratif dan backup pengaman terverifikasi. Jangan menghapus hasil migrasi setelah ada transaksi lanjutan.
- Saldo peralihan migrasi penuh tidak dibatalkan melalui halaman peninjauan arsip. Pengunggahan backup berbeda setelah unit selesai tidak menjadi sinkronisasi tambahan.
- Backup setelah migrasi dibuat saat unit masih dikunci untuk verifikasi. Setelah memulihkan backup tersebut pada server, peninjauan administratif dan verifikasi ulang diperlukan sebelum transaksi dibuka kembali.

Administrator dapat memeriksa status melalui `php scripts/verify_legacy_full.php --batch=ID_BATCH --actor=ID_SUPER_ADMIN`. Untuk batch yang tertahan atau dipulihkan, tambahkan `--verify --confirm-recovery`. Alat ini memeriksa manifest database, dapat membangun ulang antrean privat yang hilang, dan menjalankan backup terverifikasi sebelum membuka unit. Alat tidak mengimpor ulang siswa atau transaksi dan tidak melewati kegagalan rekonsiliasi. Siapkan kembali konfigurasi backup sesuai identitas instance tujuan setelah pemulihan pada PC lain.

Simpan juga backup final `.dat` dan penyimpanan privat importer pada media aman terpisah. Backup MySQL menyertakan tabel sumber/hasil/pemetaan final, tetapi tidak menyertakan file `.dat`, antrean SQLite, atau pekerjaan yang belum diterapkan.

## Bukti pengujian dan batas penggunaan

### Penyederhanaan alur, 17 Oktober 2026

- Ketiga `.dat` asli dipulihkan dan diperiksa ulang pada SQL Server khusus QA. Jumlah ekstraksi tetap SD 34.676, SMP 43.599, SMA 11.133 baris; pasangan komponen berisi nilai tetap 77/104/30. Usulan identitas dan selisih tabungan dibandingkan dengan ekstraksi yang tervalidasi. Ini membuktikan pembacaan sumber, bukan pengesahan otomatis semua rekening/kewajiban sumber 2019.
- Salinan besar berisi 3.000 siswa dan 63.000 baris sumber lolos pengesahan kelompok dan simulasi. Seluruh 60.000 pembayaran identik yang memang berulang dipertahankan; draf/simulasi tidak membuat siswa, master atau tagihan operasional.
- Uji fingerprint pratinjau, sumber/master berubah, versi lama, pilihan individual, tarif berbeda, profil privat, Histori Saja, saldo, kewajiban, dan alumni lulus. Worker nyata menjalankan simulasi antrean, pemulihan simulasi terputus, pembatalan, serta penolakan akun dicabut/nonaktif.
- Penerapan penuh salinan SD dan SMA lulus backup sebelum/sesudah beserta pemulihannya; NIS sama antarunit tetap terpisah. Alumni tidak mendapat penempatan/tagihan baru. Histori Saja tidak membuat master, tidak menjadi kas baru, dan ditolak sebagai cakupan tagihan. Retry tidak menggandakan hasil. Regresi master baru nonaktif beserta rollback master/siswa juga lulus.
- Empat peran × empat cakupan × sembilan template laporan cocok dengan total independen setelah ketiga unit memiliki hasil migrasi salinan. Transaksi tabungan, cicilan/pelunasan tunggakan, detail utama, PDF dan Excel lulus. Filter Histori Saja mempertahankan label/nominal asli; pembayaran tanpa tanggal tidak masuk total periode tertentu.
- Chrome, Edge, dan Chromium 109 dijalankan: 36 kasus tampilan/tema dengan pengesahan kelompok tanpa JavaScript dan konfirmasi akhir, disusul 48 kasus tampilan/tema/palet/read-only. Lebar 360–1920 px, fokus/Escape dan screenshot desktop/ponsel diperiksa. Windows 7 fisik belum diuji.
- Fingerprint seluruh **50 tabel operasional** identik sebelum/sesudah pengujian. Semua mutasi dilakukan pada instance MySQL privat dengan UUID/datadir/port berbeda. Tidak ada pemasangan skema, impor nyata ke `db_spp` operasional, commit/push, atau pembaruan paket deployment.
- Proses browser, HTTP, MySQL dan LocalDB QA telah ditutup. Tidak ada browser baru yang dipasang. Pembersihan sisa berkas `legacy_workflow_qa_20261017/browser-temp` serta penghapusan instance LocalDB QA ditolak pemeriksaan otomatis dengan alasan **blocked by policy**; berkas/instance dipertahankan tanpa mencoba cara penghapusan lain. Runtime dan importer utama tetap tersedia.

### Penyiapan komponen, 16 Oktober 2026

- Tiga sumber asli dipulihkan/CHECKDB/diperiksa profilnya lagi pada instance SQL Server khusus QA: SD 34.676 baris / 77 pasangan kolom-rincian berisi nilai, SMP 43.599 / 104, SMA 11.133 / 30. Jumlah dan total setiap pasangan dicocokkan dengan pembacaan sumber independen. Angka pasangan bukan jumlah jenis biaya yang sudah disahkan atau master yang otomatis dibuat. CHECKDB sempat timeout ketika RAM host rendah; setelah pengujian ulang seluruh sumber lulus. Pengaturan instance QA tidak diterapkan pada importer operasional.
- Uji usulan bersama, nama bentrok menurut collation MySQL (termasuk master nonaktif), tarif kosong/nol/negatif/presisi berlebih, persetujuan, pemetaan/sumber/master berubah, rollback sesudah pembuatan master dan sebelum commit, serta recovery setelah commit lulus. Draf/simulasi tidak membuat master/tagihan operasional.
- Worker PHP yang benar-benar diluncurkan tanpa sesi browser menyelesaikan migrasi SMA dan recovery dengan master baru. Konteks tingkat/tarif CLI mengikuti unit pekerjaan, bukan unit default sesi. Manifest menyimpan resolusi ID dan fingerprint histori tetap cocok setelah backup/pemulihan.
- Uji API, CSRF, peran/unit, versi lama, formulir individual/bulk lama tanpa JavaScript, pemulihan input, dan ringkasan master pada konfirmasi lulus. Master hasil tetap nonaktif, nominal histori berbeda dari tarif master tetap utuh, dan cicilan/pelunasan/retry Tunggakan Legacy tidak menggandakan kas. Rincian utama, PDF histori, laporan dan Excel mengenali biaya baru yang nonaktif.
- Regresi existing histori, tabungan/setoran/penarikan, saldo berjalan, pembayaran tunggakan, empat peran × empat cakupan × sembilan template, ekspor, akun dicabut dan pembatasan transaksi lintas unit lulus. Audit integritas salinan bersih.
- Chrome 155.0.8059.80, Edge 155.0.4283.45, dan Chromium 109.0.5412.0 dijalankan: 120 kasus tampilan/palette, termasuk 36 kasus katalog SMP asli yang panjang dan 36 kasus usulan master, lebar 360–1920 px, kedua tema, keyboard/Escape dan konfirmasi tanpa JavaScript. Screenshot desktop/ponsel diperiksa. Windows 7 fisik belum diuji.

Pengujian memakai salinan MySQL dengan UUID/datadir/port terpisah. Source, cookie, dump, log dan screenshot disimpan privat di luar Git. Tidak ada migrasi sumber nyata ke `db_spp` lokal, tabel/menu master baru, commit/push, atau pembaruan ZIP deployment untuk penyiapan komponen.

Backup akhir salinan sesudah cicilan/pelunasan lulus pemulihan dan audit; fingerprint seluruh **50 tabel operasional** identik sebelum/sesudah pekerjaan. Proses HTTP/MySQL QA dihentikan dan instance LocalDB QA dihapus setelah dipastikan tidak berisi database sumber. Runtime serta instance importer operasional dipertahankan. Browser uji ditutup tanpa instalasi browser baru ke AppData. Penghapusan profil/artifak sementara `C:\laragon\backups\spp-management-system\legacy_full_qa_components_20261016_185621\browser-temp` ditolak pemeriksaan otomatis dengan alasan **blocked by policy**; folder privat tersebut masih tersisa dan tidak dihapus melalui cara lain.

Contoh `.dat` yang tersedia adalah snapshot **15 Februari 2019**. Ketiganya dipulihkan kembali dengan profil penuh; jumlah arsip cocok: SD **34.676**, SMP **43.599**, SMA **11.133** baris. Pemeriksaan menemukan rekening ditahan: SD **5**, SMP **16**, SMA **4**; ini hasil rekonsiliasi rekening sumber, bukan jumlah siswa yang siap diaktifkan.

Pengujian terisolasi mencakup backup sebelum/sesudah dan pemulihannya, transaksi atomik, pembatalan sebelum commit, penguncian setelah commit, retry, fingerprint histori, komponen bertumpang tindih, tanggal kosong, operator, pemisahan unit, histori utama/PDF, saldo berjalan, dan pencegahan penagihan ulang. Pengujian browser menggunakan Chrome, Edge, dan **Chromium 109 yang benar-benar dijalankan**, pada lebar 360–1920 px, kedua tema, seluruh palet unit, fokus/Escape, serta formulir tanpa JavaScript. Windows 7 fisik belum diuji.

Versi browser yang dijalankan: Chrome **155.0.8059.80**, Edge **155.0.4283.45**, Chromium **109.0.5412.0**. Uji tambahan membuktikan penerbitan DU massal dapat mencampur siswa baru dan siswa hasil migrasi tanpa menerbitkan ulang periode yang tercakup. Fixture tes pengelompokan tagihan lama juga disesuaikan dengan komponen PSB yang berlaku saat ini. Fingerprint seluruh tabel operasional tetap identik sebelum/sesudah pekerjaan.

Server HTTP/MySQL uji dihentikan dan instance LocalDB khusus QA dihapus; runtime SQL Server importer tetap dipertahankan. Browser/profil uji ditutup. Penghapusan folder portable Chromium 109 pada `C:\laragon\backups\spp-management-system\legacy_finance_qa_20261016b\browser109` ditolak pemeriksaan otomatis dengan alasan **blocked by policy**; folder tersebut masih tersisa. Tidak ada browser baru yang dipasang ke AppData untuk pekerjaan ini.

Backup terbaru harus diperiksa dan disimulasikan sendiri. Informasi yang tidak pernah tersimpan tidak dibuat-buat. **Migrasi penuh tidak dinyatakan selesai selama masalah keuangan aktif unit belum terselesaikan.** Skema operasional, commit/push, serta ZIP deployment tidak diubah pada tahap pengembangan ini.

## Backup terbaru tanpa ekstensi ? 17 Oktober 2026

Impor mendukung `.dat`, `.bak`, dan file tanpa ekstensi melalui pemeriksaan isi dan profil 2019/2026. Lihat [panduan backup terbaru](LEGACY_BACKUP_2026.md). Dukungan pembacaan tidak mengesahkan saldo, identitas, atau kewajiban yang masih bermasalah.
