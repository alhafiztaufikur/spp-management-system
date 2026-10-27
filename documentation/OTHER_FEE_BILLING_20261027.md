# Tagihan dan pembayaran Biaya Lain, 27 Oktober 2026

Laporan Global menggunakan satu kolom Dibayar. Total penerimaan laporan dan
kredit pengurang suatu tagihan merupakan dua ukuran yang berbeda. Pembayaran
yang belum mempunyai dasar kewajiban tetap terlihat; tagihan/sisa bernilai null,
status Ada Pembayaran. Pemetaan ke master tidak membuktikan tarif atau kelunasan.

## Alur resmi

- Tidak Berulang tetap diterbitkan ke siswa melalui Master Biaya Lain.
- Berulang dibeli lewat formulir yang sama. Saat pembayaran berhasil, layanan
  membuat tagihan `origin=purchase`, dengan identitas kejadian berdasarkan ID
  pembayaran dan master. Tarif dibekukan; satu struk hanya mempunyai satu rincian
  untuk master yang sama. Retry, edit, pengajuan dan keputusan memakai pengaman
  transaksi existing. Tidak ada tagihan dari sekadar membuka formulir.
- Pembatalan pembelian membatalkan tagihan kejadian tersebut dan menyimpan audit.
  Pembatalan pembayaran tagihan terbit mengembalikan sisanya, tanpa membatalkan
  kewajiban. Tagihan milik transaksi lain tidak boleh dipakai untuk pembelian baru.
- Histori memakai kewajiban yang disahkan per siswa dan hubungan rincian sumber
  yang terbukti. Engine membentuk tagihan penuh beserta kredit Legacy yang sah.
  Tagihan pembukaan existing yang mewakili sisa utang tidak diubah atau dikreditkan
  dua kali. Perubahan kategori/pemilik/nominal sumber tidak boleh mempertahankan
  kredit lama secara diam-diam.
- Pembayaran dan rincian Legacy tetap berada di sumbernya. Tabel kredit hanya
  menyimpan hubungan, nominal teralokasi, fingerprint dan bukti; bukan kas baru.
  Pembaca memeriksa sumber aktif, unit, siswa, master dan fingerprint tersebut.

## Struktur dan pemasangan

`sql/install_other_fee_billing.php` adalah installer CLI dengan preview bawaan.
Ia menambahkan identitas kejadian, asal, periode dan bukti pada tagihan, memperluas
keunikan menjadi unit/master/siswa/kejadian, serta memasang tabel/view kredit dan
audit. Tagihan existing mendapat kejadian `once` dan asal `issued`. Readiness HTTP
hanya memeriksa; tidak menjalankan DDL. Installer memeriksa orphan/nominal sebelum
DDL dan memerlukan backup terverifikasi untuk apply. Installer ulang: changes 0.

```powershell
php sql/install_other_fee_billing.php --database=db_spp --port=3306 --mode=preview
php sql/install_other_fee_billing.php --database=db_spp --port=3306 --mode=apply --backup-proof=C:/private/before-schema-proof.json
php scripts/legacy_migration_runner.php --database=db_spp --port=3306 --components=other_fees --mode=preview --output=C:/private/fees-preview.json
```

Setelah DDL, buat backup baru dan verifikasi restore lagi sebelum apply data.
Bukti backup runner harus cocok dengan struktur/checksum saat itu. Gunakan
`--components=other_fees --mode=apply --backup-proof=...`, kemudian verify dan apply
ulang; hasilnya harus changes 0. Mode ini tidak merekonsiliasi komponen lain.
Publikasi sumber baru tetap menjalankan seluruh tahap melalui engine yang sama.

Workflow 14 dan operasional 18 menolak simulasi/worker lama. Metadata pembayaran
otomasi versi 1–5 tetap terbaca; tidak ada penulisan ulang metadata demi versi saja.

## Verifikasi

- Buku Ummi SD 212207015: lima pembayaran (31.000 + 4 × 17.000), total 99.000,
  kelas historis 1C; master sumber 0022 berisi kode/nama tanpa bukti pokok. Dibayar
  99.000, tagihan/sisa null, bukan Lunas.
- Jalankan `tests/other_fee_billing_integration_test.php` dan
  `tests/other_fee_billing_http_test.php` hanya pada instance disposable dengan
  manifest UUID/port/datadir yang benar. Fixture HTTP tersimpan di salinan uji.
- Bandingkan sumber independen, sembilan rekap, filter historis, subtotal dan
  PDF/Excel. Riwayat Tagihan memakai cache bukti tanpa daftar perencana penerbitan
  bulanan, untuk mengurangi memori; angka kredit tidak berubah.
- Simpan fingerprint sebelum/sesudah. Perubahan hanya boleh berupa struktur,
  tagihan/hubungan yang dibuktikan, serta auditnya; histori kas/tabungan tetap utuh.

Daftar kewajiban yang belum terbukti disimpan dalam laporan privat penerapan.
Keberhasilan menampilkan pembayaran tidak berarti seluruh kewajiban telah lunas.
