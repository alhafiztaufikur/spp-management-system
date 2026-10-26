# Tarif khusus SPP per siswa dan ringkasan transaksi master biaya

## Pemakaian

Pada **Master Penerbitan SPP → Pilih Siswa**, klik ikon pensil di samping
Tarif Efektif. Dialog menampilkan nama, NIS, rombel, tahun ajaran, tarif akhir,
bulan mulai, dan pratinjau 12 bulan. Nominal menerima nol hingga
Rp9.999.999.999.999,99; maksimal dua desimal. Tarif akhir tidak dikurangi
potongan bawaan lagi.

Pengaturan berlaku dari bulan pilihan sampai Juni pada tahun ajaran tersebut.
Bulan sebelumnya dan tahun berikutnya tetap. **Gunakan Tarif Bawaan** menampilkan
pratinjau reset; perubahan baru diterapkan setelah tombol **Terapkan Tarif Bawaan** ditekan.
Reset memakai kembali tarif kelas atau tarif Legacy yang terbukti beserta
potongannya. Tahun tertutup harus dibuka kembali melalui mekanisme existing.

Klik **Terapkan Tarif** untuk memperbarui tarif dan langsung menerbitkan bulan
yang belum memiliki tagihan serta memenuhi kelayakan penerbitan. Tidak ada
langkah penerbitan ulang terpisah. Sebelum tombol ditekan, pengisian nominal
hanya memperbarui pratinjau. Jalur ini hanya menerbitkan SPP, tanpa mengubah
Komite atau bulan mulainya. Tagihan existing yang memenuhi pengaman koreksi
diperbarui; tagihan dengan alokasi pembayaran, kredit Legacy,
status terlindungi, pembatalan, atau cakupan PSB dipertahankan. Nol memakai status
`waived`. Penerbitan berikutnya dan rekonstruksi Legacy membaca tarif khusus
pada periode yang sama. Sinkronisasi potongan serta koreksi tarif kelas tidak
menimpa periode khusus.

Penyimpanan dilakukan melalui POST dengan CSRF, identitas unit, izin operasional,
versi data siswa, transaksi, dan penguncian siswa/tagihan yang selaras dengan
pembayaran. Audit mencatat aktor, alasan, rentang, nilai sebelum/sesudah, serta
hasil tiap tagihan. Alasan dicatat otomatis oleh server, tanpa isian pengguna.
Pengiriman ulang tidak menggandakan tagihan; tarif yang sudah sama tetap dapat
mengisi bulan yang belum terbit. Kegagalan penerbitan membatalkan seluruh
perubahan dalam permintaan tersebut. Pengiriman ulang yang setara tidak membuat perubahan baru;
versi lama ditolak jika tarif atau pembayaran telah berubah.

Dialog berada di luar form penerbitan. Penerapan AJAX mempertahankan tab,
pencarian, filter, checkbox, bulan penerbitan, dan posisi daftar. Nominal pada
baris siswa mengikuti bulan Mulai Tagihan, dengan penanda tarif khusus/bervariasi
atau tagihan dipertahankan sesuai kondisi. Status baris siswa, jumlah penerbitan,
status tahun, serta versi formulir diperbarui tanpa reload. Tarif dan Mulai Berlaku
memiliki lebar sama dan tinggi kontrol 48 piksel pada desktop.

Pemilih Daftar Ulang pada Input/Edit Pembayaran memakai panel desktop 520 piksel
yang dibatasi viewport. Identitas tahun/kelas dan badge berada di atas; Tagihan,
Terbayar, Sisa menjadi tiga baris label/nominal rata kanan. Nominal tidak dipotong
atau dipecah baris. Penempatan atas/bawah, scroll, pilihan, keyboard, dan tema
tetap mengikuti pemilih existing. Aturan ukuran hanya mengenai dropdown DU.

Kolom **Transaksi** di Daftar Master Biaya menampilkan jumlah rincian, tautan
Pembayaran dengan badge jumlah transaksi, total rupiah dua desimal pada baris
tersendiri, dan tautan Dibatalkan bila tersedia. Keadaan kosong tidak memiliki
tautan Pembayaran. Pembaca native/Legacy, tujuan tautan, dan aturan Bisa Dihapus
tetap memakai implementasi existing. CSS hanya mengenai ringkasan master biaya.

## Pemasangan skema

Installer hanya dijalankan lewat CLI. GET halaman tidak menjalankan DDL atau
mengisi tarif khusus. Tabel baru `spp_tarif_siswa_data`, view `spp_tarif_siswa`,
serta tiga trigger membatasi unit, siswa, penempatan, tahun, dan periode.

```powershell
php sql/install_student_spp_tariffs.php --database=db_spp_test2 --port=3306 --mode=preview
php sql/install_student_spp_tariffs.php --database=db_spp_test2 --port=3306 --mode=apply --backup-proof=C:/private/backup-proof.json
```

Apply memakai kunci akses aplikasi dan pemeriksaan backup/restore resmi
`lmr_backup_check()`. Installer membuat objek yang belum tersedia saja, termasuk
jika pemasangan sebelumnya terputus. Penerapan ulang pada skema lengkap menghasilkan
nol perubahan. Tidak ada pengisian tarif custom otomatis atau perubahan nominal
existing saat pemasangan.

## Verifikasi 26 Oktober 2026

Backup database dipulihkan ke MySQL terpisah pada port **58526**, dengan UUID dan
direktori data berbeda dari server operasional port **3306**. Sebelum pengujian
tulis, seluruh checksum tabel dan snapshot identitas/arsip/kas/tabungan cocok.
Siswa uji dengan nama sama dan identitas berbeda dibuat pada tahun 2188/2189 di
SD, SMP, SMA. Fixture hanya berada pada server salinan.

Pengujian yang tersedia:

- `tests/student_spp_tariff_test.php`: 23 kasus nominal, termasuk batas maksimum,
  dua desimal, nol, array, kosong, negatif, dan format tidak sah.
- `tests/student_spp_tariff_model_test.php`: 74 pemeriksaan pada tiga unit;
  kenaikan/penurunan, nol, potongan, reset sebagian, penerbitan ulang, sinkronisasi,
  kelas/Legacy, PSB, kredit, tahun tertutup, versi lama, dan isolasi siswa/unit.
- `tests/student_spp_tariff_apply_test.php`: 42 pemeriksaan penerbitan langsung,
  rentang yang tepat, reset/nol, retry, bulan kosong dengan tarif sama, draft tahun,
  audit per tagihan, isolasi Komite/penempatan/kas, serta rollback kegagalan.
- `tests/student_spp_tariff_apply_browser_test.js`: 166 pemeriksaan desktop untuk
  penerapan satu tombol, penerbitan pertama, siswa berikutnya tanpa reload,
  ringkasan/status, tarif kelas existing, kontrol 48 piksel, reset dan keyboard.
- `tests/du_selector_desktop_test.js`: 161 pemeriksaan baca-saja pada Input/Edit
  Pembayaran, tiga ukuran desktop, dua tema, nominal panjang, beberapa tunggakan,
  lunas, keadaan kosong, klik/keyboard, dan batas viewport. Kasus sintetis hanya
  berada pada DOM browser; formulir pembayaran tidak dikirim.
- `tests/student_spp_tariff_race_test.php`: worker terpisah menunggu kunci siswa
  kasir; formulir lama ditolak, dan percobaan dengan versi baru mempertahankan
  snapshot pembayaran yang selesai saat menunggu.
- `tests/student_spp_tariff_browser_test.js`: 669 pemeriksaan browser/HTTP pada
  pengujian akhir desktop (`SPP_UI_DESKTOP_ONLY=1`);
  dialog, simpan/reset, state formulir, identitas siswa, CSRF, tautan serta format
  ringkasan master biaya. Versi formulir penerbitan dan edit tarif ikut diperbarui
  setelah menyimpan; penerbitan ulang langsung tetap berhasil tanpa duplikasi.
  Tidak ada exception JavaScript.
- `tests/student_spp_tariff_integration_test.php`: 8 pemeriksaan Input Pembayaran,
  laporan, alokasi struk historis, dan rekonstruksi satu tagihan Legacy dengan
  tarif khusus melalui engine resmi. Semua mutasi integrasi di-rollback.
- `tests/other_fee_master_usage_transactions_test.php`: 22 pemeriksaan existing
  untuk native/Legacy, beberapa rincian per transaksi, pembatalan, tautan, unit,
  referensi audit, dan penguncian master; fixture di-rollback.
- Pemeriksaan sintaks PHP/JavaScript dan regresi rumus tarif Juli–Juni lolos.

Pemeriksaan akhir difokuskan pada desktop 1366, 1650, dan 1920 piksel, pada
tema terang/gelap dan ketiga unit; 130 pemeriksaan desktop baca-saja lolos.
Pemasangan operasional membuat lima objek skema, penerapan ulang nol perubahan,
dan tabel tarif khusus tetap kosong. Checksum seluruh **62 tabel original** dan
snapshot identitas/arsip/kas/tabungan tetap sama sesudah pemasangan.
Artefak, backup, bukti restore, dan laporan uji
disimpan di direktori backup privat, di luar web root.

Runner Legacy juga dijalankan dalam mode verify pada server salinan. Snapshot
identitas, arsip, kas, dan tabungan tetap. Runner menemukan pekerjaan rekonsiliasi
Legacy existing yang belum diterapkan pada backup sumber (termasuk trigger
peralihan lama); kondisi tersebut bukan hasil pemasangan tarif khusus. Tidak ada
rekonsiliasi massal atau perubahan data operasional yang diterapkan dalam fitur ini.

Pengujian lanjutan alur satu tombol memakai backup baru yang dipulihkan pada
port **58626**, HTTP **58627**, UUID/datadir terpisah. Seluruh **63 tabel** dan
snapshot sumber cocok sebelum fixture dibuat. Perubahan lanjutan tidak
memerlukan migrasi skema atau pengisian data operasional. Guard Legacy terpasang
tetap diberlakukan; trigger peralihan lama yang sudah ada pada sumber tidak
diubah atau dilewati melalui fitur ini. Pengujian Legacy operasional menggunakan
rentang November 2026 ke depan pada salinan ini.
Pemeriksaan akhir kembali memastikan seluruh 63 tabel operasional, identitas,
arsip, kas, dan tabungan sama dengan backup awal pengujian lanjutan. Server
MySQL serta HTTP pengujian ditutup setelah pemeriksaan selesai; backup dan
artefak privat dipertahankan.

Regresi pemilih bersama lolos pada Chrome dan Edge (termasuk fallback panel).
Firefox tidak tersedia pada lingkungan uji; tidak ada klaim pengujian Firefox.
