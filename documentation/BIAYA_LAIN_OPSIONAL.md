# Master Biaya Lain: Berulang dan Tidak Berulang

Informasi halaman diringkas menjadi **“Kelola biaya tambahan di luar SPP. Berulang dibayar langsung; Tidak Berulang diterbitkan sekali per siswa.”** Aturan nominal dan penerbitan di bawah tetap berlaku. [Catatan penyempurnaan tampilan](DAFTAR_PESERTA_NAIK_KELAS_20261018.md).

Kebijakan 18 Oktober 2026 membedakan dua jenis master. Pembaruan 27 Oktober 2026 menambahkan tagihan per kejadian untuk pembelian Berulang baru dan menyatukan kolom pembayaran pada laporan. Transaksi lama dipertahankan; pengaitan historis memerlukan bukti kewajiban. Lihat [alur tagihan Biaya Lain](OTHER_FEE_BILLING_20261027.md).

## Berulang: pembelian langsung

1. Pilih SD, SMP, atau SMA; Semua Unit hanya untuk membaca.
2. Buat master dengan jenis **Berulang**, nominal referensi, dan status aktif.
3. Di Input Pembayaran pilih siswa, lalu item pada **Biaya Berulang**. **Tarif Referensi** adalah nominal wajib dari master; **Nominal Dibayar** otomatis terisi dan harus tepat sesuai tarif. Nominal kurang atau lebih ditolak dengan popup sebelum disimpan.
4. Simpan pembayaran. Sistem membuat tagihan khusus kejadian pembelian, pembayaran, dan audit dalam satu transaksi database. Pembelian berikutnya memakai master yang sama pada transaksi baru, termasuk pada hari yang sama, dengan tagihan yang berbeda.

Contoh Buku Paket bertarif Rp125.000: input Rp110.000, Rp130.000, atau nol ditolak. Dua pembelian terpisah masing-masing Rp125.000 menghasilkan penerimaan Rp250.000 dan dua tagihan kejadian yang terbayar. Satu master hanya satu baris per transaksi. Alur pembelian Berulang tetap dibayar penuh, tanpa fitur jumlah barang/stok. Pengiriman ulang dengan identitas transaksi yang sama tidak membuat penerimaan atau tagihan kedua.

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
5. Klik **Tampilkan Rekap**. Baca kolom **Tagihan, Dibayar, Sisa, Status**, kemudian gunakan Cetak/PDF/Excel bila diperlukan.

Contoh: pembayaran Ekskul Demo Rp100.000 tanggal 17 Oktober muncul pada kategori Ekskul Demo dengan tanggal transaksi 17 Oktober. Judul laporan harus sudah menyebut kategori tersebut. Jika judul masih SPP, hasil tabel masih memakai filter sebelumnya. Penanda **Pilihan filter belum diterapkan** menjelaskan keadaan ini; ekspor baru tersedia kembali setelah filter diterapkan.

Histori pembayaran yang belum mempunyai bukti total kewajiban tetap masuk **Dibayar**, dengan Tagihan/Sisa **—** dan status **Ada Pembayaran**. Pembayaran tersebut tidak otomatis melunasi tagihan lain untuk siswa dan master yang sama. Posisi tagihan berbeda tetap dibedakan menurut kewajiban, periode, dan kelas historisnya. Filter Ada Pembayaran mencakup penerimaan positif yang terbukti. SPP yang belum diterbitkan tetap berstatus Tidak Ditagihkan meskipun siswa sudah membayar Biaya Lain.

Baris/Halaman berada di sebelah Status Siswa pada desktop/tablet; pencarian siswa memakai satu baris penuh. Pada ponsel kontrol tersusun satu kolom.

## Tagihan dan transaksi lama

- **Pelunasan Tagihan Lama** hanya memuat tagihan siswa yang masih bersisa. Kolom Total Tagihan, Sudah Dibayar, Sisa, dan Bayar tetap tersedia; cicilan dan pembatasan kelebihan bayar tetap berlaku.
- Pembelian Berulang baru memakai referensi tagihan kejadian pembeliannya. Histori lama tanpa bukti kewajiban dapat tetap mempunyai referensi NULL. Membeli Seragam Sekolah tidak mengurangi tagihan seragam lama yang berbeda.
- Master nonaktif tidak ditawarkan untuk pembelian baru. Pembayaran lama tetap terbaca dan dapat dikoreksi sesuai hak akses, termasuk jika master asal sudah nonaktif.
- Nama historis berasal dari snapshot pembayaran. Perubahan nama/tarif master tidak menulis ulang rincian lama. Input baru mengikuti nama dan tarif referensi terkini; nominal baru wajib sama dengan tarif master.
- Administrator/Kasir mengajukan koreksi/hapus; penerimaan belum berubah sampai keputusan Super Admin. Koreksi langsung, pengajuan, keputusan, alasan dan arsip tetap mengikuti pengamanan transaksi existing.
- Formulir penerbitan yang sudah terbuka sebelum pembaruan ditolak server dengan pesan pengganti alur; pengiriman ulang tidak menerbitkan tagihan.

## Penyimpanan dan keluaran

`includes/other_fee_optional.php` menyediakan pilihan master aktif dan validasi baris opsional. `pembayaran/biaya_lain_siswa.php` mempertahankan respons `{ok, rows}`: pilihan opsional memiliki `id=master:<id>`, `master_id`, `optional=true`; tagihan lama memakai ID numerik dan `optional=false`. Pilihan dibatasi siswa/unit aktif dan server memeriksa master serta duplikasi per transaksi.

`bayar_biaya_lain` menyimpan `master_biaya_lain_id`, `nama_biaya_snapshot`, `nominal_snapshot` aktual, dan `keterangan`. `tagihan_biaya_lain_id` menunjuk kewajiban yang dibayar; nilai NULL pada histori tidak membuktikan adanya atau tidak adanya kewajiban. Tagihan lama mempertahankan ID tagihannya. Tagihan pembelian baru memakai identitas kejadian tersendiri. `tarif_referensi_snapshot` menyimpan tarif acuan rincian baru. Transaksi lama tidak ditulis ulang; rincian lama tanpa perubahan nominal tetap sah. Perubahan nominal mengikuti otorisasi dan validasi tarif existing. Jika tarif berubah setelah formulir/pratinjau dibuka, muat ulang atau ajukan kembali.

Kwitansi dan histori mengikuti transaksi masing-masing. **Status Pembayaran** dan **Rekap Pembayaran per Item** memakai satu kolom **Dibayar**, termasuk pada PDF/Excel. Sisa dihitung hanya dari kewajiban yang terbukti dan kredit sah untuk tagihan itu. Penerimaan menjumlahkan setiap rincian aktual satu kali, tanpa memakai tarif sekarang sebagai bukti utang historis. Kredit Legacy tetap disimpan pada sumber asal dengan hubungan tersendiri; tidak disalin menjadi kas native.

## Verifikasi implementasi

Pengujian pembaruan 27 Oktober memakai instance MySQL terpisah dengan verifikasi UUID, datadir, port, dan server HTTP. `other_fee_billing_integration_test.php` memeriksa hubungan kewajiban/kredit, histori belum terbukti, cicilan, koreksi dan pembatalan. `other_fee_billing_http_test.php` menjalankan formulir serta otorisasi existing pada tiga unit. `other_fee_billing_concurrency_test.php` memeriksa retry dua kasir, dan `other_fee_billing_schema_test.php` memeriksa preflight serta idempotensi installer. Pengujian laporan membandingkan sumber fisik, pembaca bersama, tabel, PDF/Excel dan sembilan rekap. Artefak serta kredensial uji disimpan privat di luar repository.

Lihat [panduan pembaruan](WORKFLOW_UPDATE_20261017.md) dan [changelog](AI_CHANGELOG.md). Berkas kredensial akun dan artefak uji disimpan privat di luar repository.

## Cakupan rombel

Out dan rombel Custom tidak menerima penerbitan biaya sekolah baru. Custom tersedia untuk rekening tabungan melalui formulir Data Siswa. Transaksi/tagihan yang tercatat sebelum perubahan rombel tetap tersimpan pada histori dan laporan. Lihat [panduan rombel dan pembayaran](ROMBEL_PEMBAYARAN_20261018.md).

## Pemilihan sasaran penerbitan (18 Oktober 2026)

Pilih Siswa dan Pilih Rombel sekarang memakai checkbox, pencarian, jumlah terpilih, Pilih Semua Hasil, dan Kosongkan Pilihan. Pilihan tersembunyi oleh pencarian tetap tersimpan; mengganti jenis sasaran menghapus pilihan sebelumnya. Keterangan siswa memuat NIS/rombel, sedangkan rombel memuat jumlah siswa sasaran. Ctrl/Command tidak diperlukan. Tinjau Penerbitan mengikuti bentuk persegi membulat Tambah Biaya.

Pratinjau dan konfirmasi tetap memeriksa snapshot master/daftar siswa di server. Membatalkan konfirmasi mempertahankan pilihan tanpa menerbitkan tagihan. [Panduan tampilan dan verifikasi](PENYEMPURNAAN_CETAK_PEMBAYARAN_20261018.md).
