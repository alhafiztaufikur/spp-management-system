# Menggunakan Import Legacy

## Membaca hasil migrasi yang sudah selesai

Buka Riwayat Pembayaran dan pilih **Semua Periode** untuk seluruh histori, atau **Histori Legacy tanpa tanggal** untuk catatan tanpa tanggal saja. Bulan berjalan tetap menjadi tampilan awal. Gunakan filter sumber/pemilik dan tautan Rincian untuk transaksi Legacy; Data Siswa menyediakan tautan pembayaran serta jurnal tabungan secara terpisah. Rekening non-siswa dibuka melalui ID pemilik, bukan NIS buatan.

**Excel - Semua Hasil** mengekspor seluruh cakupan filter; **Cetak Halaman Ini** menghasilkan PDF halaman aktif. Saldo peralihan dan penyesuaian tidak menjadi kas masuk baru. [Rincian perubahan, bukti pemasangan lab, pengujian dan batasnya](LEGACY_READBACK_20261020.md).

Untuk membawa siswa, transaksi dan rekening menjadi data kerja yang dapat dikoreksi Super Admin, gunakan [panduan Migrasi Operasional](LEGACY_OPERATIONAL_MIGRATION.md). Kartu Migrasi Penuh mengarah ke peninjauan operasional; mode impor siswa dan arsip tetap tersedia.

Buka **Pengaturan → Backup & Restore → Import Legacy**, lalu pilih unit di sidebar. Hanya Super Admin aktif yang dapat mengimpor. **Semua Unit** digunakan untuk membaca hasil.

## Pilih sesuai kebutuhan

| Pilihan | Digunakan untuk | Hasil |
| --- | --- | --- |
| Migrasi Penuh | Pindah sepenuhnya ke server baru dengan unit kosong | Siswa, histori, saldo peralihan, dan tunggakan yang telah disahkan |
| Impor Siswa | Menambahkan identitas siswa ke sistem yang sudah digunakan | Siswa **Legacy — belum aktif**, untuk dilengkapi dan diaktifkan |
| Histori Keuangan | Mengarsipkan pembayaran lama, kemudian meninjau saldo dan tunggakan | Arsip sumber; saldo dan tunggakan aktif memerlukan peninjauan terpisah |

Database lokal yang sudah berisi siswa tetap dipertahankan. Migrasi Penuh tidak mengosongkannya atau berpindah mode sendiri. Gunakan **Lanjutkan Pekerjaan** pada riwayat untuk membuka kembali proses yang tertunda. Waktu pada riwayat ditampilkan dalam WIB.

## Migrasi Penuh

1. **Unggah.** Periksa ringkasan kesiapan. Lengkapi layanan atau master yang belum siap. Pilih satu backup SQL Server (`.dat`, `.bak`, atau tanpa ekstensi) final dari unit yang sesuai, lalu **Unggah & Periksa**. Saat mengambil backup final, hentikan pencatatan sistem lama.
2. **Periksa Hasil.** Tentukan waktu peralihan, tahun ajaran, dan bukti yayasan. Periksa contoh nominal pembayaran dalam rupiah dan sahkan aturan hubungan pembayaran. Pilih **Simpan Draf** bila belum selesai, atau **Simpan & Lanjut**.
3. **Selesaikan Pengecualian.** Kerjakan empat bagian berikut, satu per satu:
   - **Siswa & Kelas:** pilih kelompok sumber dan tampilkan daftar sasarannya. Tentukan status, kelas tujuan, dan awal penagihan. Bandingkan tarif sumber dengan tarif tujuan. Alumni tidak diwajibkan memiliki kelas aktif.
   - **Komponen Biaya:** cari komponen yang belum dipetakan. **Histori Saja** menyimpan pembayaran lama tanpa tarif atau tagihan baru. Pada Migrasi Operasional, usulan master boleh **Nonaktif — Tarif belum ditetapkan**; aktivasi dan penagihan tetap memerlukan tarif positif. Total histori bukan tarif master.
   - **Tabungan:** periksa **Saldo Sumber, Hasil Jurnal, dan Selisih**. Saldo cocok dapat disahkan per kelompok. Saldo berselisih dapat disahkan melalui **Saldo FIX**, bukti, dan modal konfirmasi; penyesuaian tercatat tanpa mengubah jurnal sumber. Identitas ambigu tetap diperiksa.
     Untuk **tabungan tambahan**, cari nama atau nomor induk, pilih rekening, lalu baca kartu perbandingan saldo. Daftar dibagi enam rekening per halaman; filter **Perlu bukti**, **Siap diperiksa**, dan **Tersimpan** membantu melanjutkan pemeriksaan. Pada ponsel, memilih rekening langsung menuju rinciannya; gunakan **Kembali ke daftar rekening** untuk memilih rekening lain. **Lihat bukti sumber** membuka jumlah baris per tabel dan catatan pemeriksaan. Rekening yang belum terbukti tetap ditahan; rekening yang memenuhi syarat memakai formulir hasil pemeriksaan dan referensi bukti existing. Pencarian, pilihan rekening, dan halaman tetap tersedia setelah reload, termasuk tanpa JavaScript.
   - **Tunggakan:** periksa kewajiban dan pembayaran di muka. Catat periode, status, sisa nominal, serta buktinya. **Belum Diperiksa** tidak berarti lunas.
4. **Konfirmasi.** Klik **Periksa Ulang & Lanjut**. Setelah semua masalah selesai, periksa siswa aktif/alumni, histori, saldo, tunggakan, awal penagihan, master baru, dan catatan histori. Konfirmasi final hanya berlaku untuk hasil yang sedang ditampilkan.
5. **Selesai.** Tunggu penerapan dan verifikasi backup. Hasil baru siap dipakai jika tertulis **Migrasi selesai dan terverifikasi**. Buka Data Siswa, Riwayat Pembayaran, atau Tabungan dari tautan hasil.

**Pengesahan kelompok:** pratinjau menampilkan sasaran dan perubahan sebelum → sesudah. **Kembali Mengubah** mengembalikan isian tanpa mengesahkan. Pergantian kelompok memuat daftar baru dan menghapus pilihan sementara kelompok sebelumnya. Koreksi individual yang sudah tersimpan dipertahankan, kecuali opsi penggantian dipilih secara jelas.

Simpan draf sebelum membuka Master Kelas, Master SPP, atau Master Biaya Lain. Setelah kembali, klik **Periksa Ulang**. Perubahan sumber, master, atau pemetaan membatalkan hasil pemeriksaan dan konfirmasi lama.

## Impor Siswa dan Histori Keuangan

Ikuti **Unggah → Periksa → Konfirmasi → Hasil**. Pilih satu backup SQL Server `.dat`, `.bak`, atau tanpa ekstensi, maksimal 100 MiB. Pencarian, filter status, pagination, dan **Unduh Hasil** tersedia untuk pemeriksaan siswa. Ringkasan sumber keuangan menampilkan jumlah baris berdasarkan jenis data; nominal yang saling mencakup tidak dijumlahkan menjadi tarif.

Setelah impor siswa, pilih **Aktifkan Siswa Legacy**. Setelah arsip keuangan tersimpan, pilih **Arsip Histori** untuk membaca sumber atau **Saldo & Tunggakan** untuk meninjau penerapannya. Pencocokan manual dilakukan dengan pencarian nama, NIS, atau kelas, kemudian memilih siswa tujuan dan memberikan alasan. Tidak perlu memasukkan ID internal.

Pada **Aktifkan & Tempatkan**, pilih kelas/rombel dan tahun ajaran. SPP mengikuti master; **POMG pada sumber Legacy adalah Komite** dan diusulkan sebagai nominal bulanan, lalu tetap diperiksa sebelum konfirmasi. Jika JavaScript tidak aktif, gunakan **Tampilkan Tarif** setelah memilih kelas/tahun. Validasi gagal mempertahankan siswa dan isian. Aktivasi hanya membuat penempatan awal; tagihan diterbitkan melalui master setelahnya.

Pada Migrasi Penuh, usulan Komite juga mengambil POMG masing-masing siswa. Pengesahan kelompok dapat memakai **Gunakan Komite dari POMG masing-masing siswa**; lepas pilihan ini bila ingin menetapkan nominal kelompok secara eksplisit. Koreksi individual tersimpan tetap dilindungi. POMG pada rincian pembayaran diusulkan ke Komite tanpa menjumlahkan ulang total Lain-Lain. U_SORGA tetap Histori Saja.

Formulir utama tetap bekerja tanpa JavaScript. Saat proses berjalan, gunakan **Muat Ulang Status**. Dengan JavaScript, halaman memeriksa status otomatis dan memperingatkan jika ada perubahan yang belum disimpan. Unggah ulang formulir yang sama tidak membuat pekerjaan kedua; penerapan ulang tetap mengikuti pengaman backend.

## Jika ada masalah

| Yang muncul | Langkah berikutnya |
| --- | --- |
| Unit sudah berisi data | Gunakan server bersih untuk Migrasi Penuh, atau pilih Impor Siswa/Histori Keuangan sesuai kebutuhan. Jangan menghapus data existing. |
| Skema belum siap | Administrator memasang skema melalui CLI sesuai runbook, setelah backup dan pemeliharaan. Halaman web tidak memasang skema. |
| Worker/SQL Server belum siap | Administrator menjalankan atau memulai ulang worker importer, lalu muat ulang kesiapan. |
| File salah atau pemeriksaan sumber gagal | Pilih backup SQL Server unit yang benar; baca hasil pemeriksaan. Backup terbaru harus diperiksa sendiri. |
| NIS ambigu dalam unit | Periksa identitas sumber dan rincian siswa. NIS sama antarunit tidak perlu digabung. |
| Kelas atau tarif belum tersedia | Simpan draf, lengkapi master melalui tautan terkait, lalu Periksa Ulang. |
| Tarif siswa berbeda | Periksa nominal dan potongan berdasarkan bukti; selisih tidak menjadi potongan otomatis. |
| Saldo sumber berbeda dari jurnal | Bandingkan tiga nominal dan rincian sumber. Lengkapi bukti atau perbaiki sumber sebelum migrasi final. |
| Kewajiban belum diperiksa | Periksa sisa kewajiban dan pembayaran di muka bagi sasaran yang ditampilkan, lalu sahkan. Kolom kosong bukan bukti lunas. |
| Konfirmasi atau versi berubah | Buka kembali pekerjaan, periksa hasil terbaru, lalu lakukan konfirmasi baru. |
| Gagal sebelum penerapan | Gunakan **Perbaiki & Periksa Ulang**. Pekerjaan belum dinyatakan selesai. |
| Proses masih berjalan | Gunakan **Lihat Progres/Muat Ulang Status**; jangan membuat pekerjaan pengganti. |
| Menunggu verifikasi setelah penerapan | Transaksi unit masih diblokir. Gunakan **Verifikasi Ulang** jika tersedia, atau minta administrator memeriksa log privat. Jangan menghapus hasil atau mengunggah ulang sebagai sinkronisasi. |

Rincian pemasangan, pengamanan, rekonsiliasi, dan pemulihan: [runbook Import Legacy](LEGACY_IMPORT_RUNBOOK_20261003.md), [Migrasi Penuh](LEGACY_FULL_MIGRATION.md), dan [Histori Keuangan](LEGACY_FINANCE_20261016.md).

Dukungan struktur terbaru dan penanganan pengecualian: [Backup Legacy 2026](LEGACY_BACKUP_2026.md).


Peninjauan operasional terbaru menyediakan saran kelas Alumni per tahun, daftar sasaran beserta rekening, nama komponen dari master Legacy, serta Rekening Uji Legacy. Ikuti [langkah peninjauan dan saldo FIX](LEGACY_OPERATIONAL_MIGRATION.md#peninjauan-kelompok-komponen-dan-saldo-fix--19-oktober-2026).
# Persetujuan massal terbaru

Gunakan **Setujui Semua Data**, periksa ringkasan, lalu **Setujui & Simulasikan**. Selisih tabungan tambahan diselesaikan melalui **Jumlah Sebenarnya** dan pemilihan rekening utama; saldo digantikan satu kali. [Panduan lengkap](LEGACY_BULK_REVIEW_20261019.md).



## Konfirmasi yang ringkas dan histori sebelum 2020

Modal mengikuti tindakan yang dipilih. Daftar perubahan panjang dapat digulir tanpa kehilangan tombol **Kembali Mengubah** dan **Setujui & Simulasikan**. Angka nol untuk bagian yang tidak terkait tidak ditampilkan. Klik tutup atau Escape untuk kembali; isian Jumlah Sebenarnya tetap disimpan pada konteks konfirmasi.

Setelah **Periksa Ulang / Simulasi**, lihat **Pembayaran siap ? diperiksa sistem**. Sistem memakai rincian valid saat total Lain-Lain nol, dan tidak menghitung total/rincian dua kali. Tidak perlu memeriksa setiap baris dengan pola tersebut.

**Lihat Histori Sebelum 2020** membuka catatan tanpa pemilik yang diterima sebagai histori/referensi. Gunakan pencarian, rentang tanggal, dan **Unduh CSV**. Catatan ini tidak menambah kas atau saldo aktif. Catatan yang lebih baru, tanpa tanggal, atau tidak memiliki tanggal valid tetap berada pada daftar penyelesaian.


## Penyelesaian otomatis hasil pemeriksaan

Gunakan **Periksa Ulang / Simulasi** dengan worker terbaru. Ringkasan **penyelesaian rekening oleh sistem** menampilkan normalisasi presisi, saldo yang mengikuti backup, dan rekening utama yang disiapkan dari rekening tambahan. Buka dasar pemeriksaan untuk melihat nominal sebelum/sesudah dan alasannya. Saldo FIX yang sebelumnya sudah disimpan tetap dipertahankan.

**Catatan Belum Teridentifikasi**, **Referensi Anomali DU**, dan **Referensi Jurnal Nol** tersedia melalui tautan ringkasan migrasi/halaman catatan. Daftar ini menyimpan sumber apa adanya dan tidak lagi masuk daftar masalah yang menghambat data siap. Catatan tanpa pemilik tetap belum teridentifikasi; penerimaan atau saldo tidak dialihkan kepada seseorang berdasarkan perkiraan.


## Kelompok dan rekening setelah migrasi — pembaruan 19 Oktober 2026

Buka **Master Kelas/Rombel → Kelompok dari Legacy**. Bagian ini mengikuti lebar konten Master Kelas. Cari nama/kode kelompok atau pilih jenis kelompok; daftar berisi maksimal **25 kelompok per halaman**. Pada ponsel, setiap kelompok ditampilkan sebagai kartu. Nama tidak diulang; ejaan sumber yang berbeda dapat dibuka melalui **Ejaan sumber**.

Pilih **Lihat Rekening & Transaksi** untuk melihat identitas pada kelompok tersebut. Pencarian rekening dan pagination mempertahankan kelompok yang dipilih. **Kembali ke Kelompok** memulihkan pencarian kelompok sebelumnya. **Lihat Rincian** membuka saldo sumber, saldo peralihan, saldo berjalan, pembayaran, dan jurnal pemilik yang dipilih. Nominal yang tidak tercatat tetap dibedakan dari nol.

Halaman rekening, tarif, koreksi, histori, aktivasi, dan peninjauan migrasi memakai header/navigasi aplikasi, gaya kontrol, jarak, serta warna unit yang sama. Tabel transaksi lebar dapat digeser di dalam area tabel pada ponsel. Saat pratinjau koreksi terbuka, gunakan **Kembali Mengubah** atau Escape untuk mengembalikan isian. Tanpa JavaScript, konfirmasi tetap tersedia sebagai formulir biasa.

Perubahan ini hanya menata tampilan dan navigasi baca. Tarif, penghitungan saldo, koreksi, pengesahan saldo FIX, dan finalisasi migrasi tetap mengikuti mekanisme existing. Pada lab copy, konfigurasi database, antrean, draf, saldo FIX, dan basis source sebelum sinkronisasi remote dipertahankan. Bila tampilan lama masih tersimpan pada browser, gunakan **Ctrl+F5**.

Hasil pemeriksaan dan batas pengujian: [Pemeriksaan UI Legacy 19 Oktober 2026](LEGACY_UI_REVIEW_20261019.md).
