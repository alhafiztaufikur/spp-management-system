# Master Biaya Lain: Berulang dan Tidak Berulang

Informasi halaman diringkas menjadi **“Kelola biaya tambahan di luar SPP. Berulang dibayar langsung; Tidak Berulang diterbitkan sekali per siswa.”** Aturan nominal dan penerbitan di bawah tetap berlaku. [Catatan penyempurnaan tampilan](DAFTAR_PESERTA_NAIK_KELAS_20261018.md).

Kebijakan aktif 18 Oktober 2026 membedakan dua jenis master. Master yang sudah ada tetap **Berulang**; transaksi dan tagihan sebelumnya tidak dikonversi. Kebijakan ini menggantikan aturan seluruh biaya baru opsional.

## Berulang: pembelian langsung

1. Pilih SD, SMP, atau SMA; Semua Unit hanya untuk membaca.
2. Buat master dengan jenis **Berulang**, nominal referensi, dan status aktif.
3. Di Input Pembayaran pilih siswa, lalu item pada **Biaya Berulang**. **Tarif Referensi** adalah nominal wajib dari master; **Nominal Dibayar** otomatis terisi dan harus tepat sesuai tarif. Nominal kurang atau lebih ditolak dengan popup sebelum disimpan.
4. Simpan pembayaran. Pembelian berikutnya memakai master yang sama pada transaksi baru, termasuk pada hari yang sama.

Contoh Buku Paket bertarif Rp125.000: input Rp110.000, Rp130.000, atau nol ditolak. Dua pembelian terpisah masing-masing Rp125.000 menghasilkan penerimaan Rp250.000. Satu master hanya satu baris per transaksi. Tidak ada cicilan, sisa tagihan, atau fitur jumlah barang/stok untuk Berulang.

## Tidak Berulang: kewajiban sekali per siswa

1. Buat master dengan jenis **Tidak Berulang** dan status aktif.
2. Pada panel **Terbitkan Biaya Tidak Berulang**, pilih master, sasaran seluruh siswa aktif/rombel/siswa, kemudian tinjau ringkasan dan konfirmasi.
3. Di Input Pembayaran pilih **Tagihan Tidak Berulang / Tagihan Lama**. Kolom Total Tagihan, Sudah Dibayar, Sisa, dan Bayar menunjukkan kewajiban yang diterbitkan.
4. Bayar penuh atau cicil. Penerbitan ulang master yang sama melewati siswa yang sudah memiliki tagihan, termasuk yang telah lunas; tidak menerbitkan ulang pada tahun berikutnya.

Mengubah jenis master tidak mengubah transaksi atau tagihan sebelumnya. Pembelian Berulang tidak melunasi tagihan master yang sama. Tagihan existing tetap dapat dilunasi; item Berulang/nonaktif yang lama tetap terbaca dalam transaksi historis. Koreksi/hapus Pembayaran oleh Administrator tetap memerlukan keputusan Super Admin.

## Melihat pembayaran di Laporan Global

1. Buka **Laporan Global ? Rekap Pembayaran per Item** dan pilih unit pemilik siswa.
2. Pada **Kategori**, pilih item yang dibayar, misalnya **Biaya Lain Ekskul Demo**. Pilihan awal SPP menampilkan tagihan SPP, bukan pembelian Biaya Lain.
3. Untuk Biaya Lain, isi **Tanggal Transaksi** sesuai tanggal uang diterima. Ini berbeda dari periode bulan tagihan SPP. Rentang tanggal dapat mencakup beberapa pembelian.
4. Pilih siswa atau isi pencarian. Gunakan **Semua status** atau **Ada Pembayaran** untuk melihat pembelian opsional. Pastikan Status Siswa sesuai Aktif/Arsip.
5. Klik **Tampilkan Rekap**. Baca nominal pada kolom **Pembayaran Opsional**, kemudian gunakan Cetak/PDF/Excel bila diperlukan.

Contoh: pembayaran Ekskul Demo Rp100.000 tanggal 17 Oktober muncul pada kategori Ekskul Demo dengan tanggal transaksi 17 Oktober. Judul laporan harus sudah menyebut kategori tersebut. Jika judul masih SPP, hasil tabel masih memakai filter sebelumnya. Penanda **Pilihan filter belum diterapkan** menjelaskan keadaan ini; ekspor baru tersedia kembali setelah filter diterapkan.

Pembelian opsional yang tercatat tanpa tagihan lama berstatus **Ada Pembayaran**. Jika siswa juga memiliki tagihan lama untuk item yang sama, status tetap mengikuti tagihan lama (Belum Bayar/Cicilan/Lunas), sementara kolom Pembayaran Opsional tetap terpisah. Filter Ada Pembayaran mencakup penerimaan opsional tersebut. SPP yang belum diterbitkan tetap berstatus Tidak Ditagihkan meskipun siswa sudah membayar Biaya Lain.

Baris/Halaman berada di sebelah Status Siswa pada desktop/tablet; pencarian siswa memakai satu baris penuh. Pada ponsel kontrol tersusun satu kolom.

## Tagihan dan transaksi lama

- **Pelunasan Tagihan Lama** hanya memuat tagihan siswa yang masih bersisa. Kolom Total Tagihan, Sudah Dibayar, Sisa, dan Bayar tetap tersedia; cicilan dan pembatasan kelebihan bayar tetap berlaku.
- Pembelian opsional memakai referensi tagihan NULL. Membeli Seragam Sekolah opsional tidak mengurangi tagihan seragam lama yang masih terutang.
- Master nonaktif tidak ditawarkan untuk pembelian baru. Pembayaran lama tetap terbaca dan dapat dikoreksi sesuai hak akses, termasuk jika master asal sudah nonaktif.
- Nama historis berasal dari snapshot pembayaran. Perubahan nama/tarif master tidak menulis ulang rincian lama. Input baru mengikuti nama dan tarif referensi terkini; nominal baru wajib sama dengan tarif master.
- Administrator/Kasir mengajukan koreksi/hapus; penerimaan belum berubah sampai keputusan Super Admin. Koreksi langsung, pengajuan, keputusan, alasan dan arsip tetap mengikuti pengamanan transaksi existing.
- Formulir penerbitan yang sudah terbuka sebelum pembaruan ditolak server dengan pesan pengganti alur; pengiriman ulang tidak menerbitkan tagihan.

## Penyimpanan dan keluaran

`includes/other_fee_optional.php` menyediakan pilihan master aktif dan validasi baris opsional. `pembayaran/biaya_lain_siswa.php` mempertahankan respons `{ok, rows}`: pilihan opsional memiliki `id=master:<id>`, `master_id`, `optional=true`; tagihan lama memakai ID numerik dan `optional=false`. Pilihan dibatasi siswa/unit aktif dan server memeriksa master serta duplikasi per transaksi.

`bayar_biaya_lain` menyimpan `master_biaya_lain_id`, `nama_biaya_snapshot`, `nominal_snapshot` aktual, dan `keterangan`; `tagihan_biaya_lain_id=NULL` membedakan opsional. Tagihan lama mempertahankan ID tagihannya. `tarif_referensi_snapshot` menyimpan tarif acuan rincian baru. Transaksi lama tidak diubah; rincian lama tanpa perubahan nominal tetap sah. Perubahan nominal mengikuti tarif master saat ini, termasuk pengajuan dan persetujuan. Jika tarif berubah setelah formulir/pratinjau dibuka, muat ulang atau ajukan kembali.

Kwitansi dan histori mengikuti transaksi masing-masing. Laporan **Rekap Pembayaran per Item** memiliki **Dibayar Tagihan**, **Pembayaran Opsional**, dan **Sisa** secara terpisah. Sisa dihitung hanya dari tagihan lama dan pembayarannya. Penerimaan menjumlahkan setiap rincian aktual satu kali, tanpa menambahkan tarif master sebagai utang siswa.

## Verifikasi implementasi

Pengujian terbaru memakai instance MySQL terpisah dengan verifikasi UUID, datadir, port, dan server HTTP. `workflow_update_http_test.php` memeriksa penerbitan sekali, pembelian berulang, cicilan, saldo, histori 500+, dan koreksi PSB. `workflow_update_security_test.php` memeriksa POST langsung, CSRF/unit/versi, keputusan otorisasi, pending lama, dan akun. `workflow_update_report_test.php` memeriksa PSB bersih, pemisahan penerimaan, laporan PDF/Excel, SPP normal, transaksi gabungan DU, dan 12 Struk. `workflow_update_browser_test.js` mencakup Chrome/Edge/Chromium 109, tiga palet, dua tema, dan tiga ukuran layar. Formulir penerbitan lama tanpa snapshot master tetap ditolak; penerbitan Tidak Berulang yang sah tersedia.

Lihat [panduan pembaruan](WORKFLOW_UPDATE_20261017.md) dan [changelog](AI_CHANGELOG.md). Berkas kredensial akun dan artefak uji disimpan privat di luar repository.

## Cakupan rombel

Out dan rombel Custom tidak menerima penerbitan biaya sekolah baru. Custom tersedia untuk rekening tabungan melalui formulir Data Siswa. Transaksi/tagihan yang tercatat sebelum perubahan rombel tetap tersimpan pada histori dan laporan. Lihat [panduan rombel dan pembayaran](ROMBEL_PEMBAYARAN_20261018.md).

## Pemilihan sasaran penerbitan (18 Oktober 2026)

Pilih Siswa dan Pilih Rombel sekarang memakai checkbox, pencarian, jumlah terpilih, Pilih Semua Hasil, dan Kosongkan Pilihan. Pilihan tersembunyi oleh pencarian tetap tersimpan; mengganti jenis sasaran menghapus pilihan sebelumnya. Keterangan siswa memuat NIS/rombel, sedangkan rombel memuat jumlah siswa sasaran. Ctrl/Command tidak diperlukan. Tinjau Penerbitan mengikuti bentuk persegi membulat Tambah Biaya.

Pratinjau dan konfirmasi tetap memeriksa snapshot master/daftar siswa di server. Membatalkan konfirmasi mempertahankan pilihan tanpa menerbitkan tagihan. [Panduan tampilan dan verifikasi](PENYEMPURNAAN_CETAK_PEMBAYARAN_20261018.md).
