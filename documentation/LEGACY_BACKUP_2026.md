# Menggunakan backup Legacy terbaru

Import Legacy menerima satu backup SQL Server **`.dat`, `.bak`, atau tanpa ekstensi**, maksimal **100 MiB**. Contoh nama yang bisa dipilih langsung: `SD171026`, `smp17102026`, dan `sma17102026`. Nama asli tampil pada riwayat; pengguna tidak perlu mengganti namanya.

## Langkah pengguna

1. Pilih unit yang sesuai pada sidebar, lalu buka **Pengaturan → Import Legacy**.
2. Pilih **Impor Siswa**, **Histori Keuangan**, atau **Migrasi Penuh**, lalu pilih file backup.
3. Klik **Unggah & Periksa**. Tunggu sampai pemeriksaan selesai. Sistem memeriksa isi backup, unit, integritas, dan struktur datanya.
4. Periksa hasil dan selesaikan pengecualian. **Berhasil dibaca** belum berarti seluruh data siap diterapkan.
5. Konfirmasikan hanya hasil yang sudah diperiksa. Migrasi Penuh membutuhkan unit tujuan kosong, pengesahan data keuangan, dan backup terverifikasi sebelum/sesudah penerapan.

Impor Siswa menyiapkan identitas yang belum aktif. Histori Keuangan menyimpan arsip, tanpa otomatis mengubah saldo atau tagihan. Migrasi Penuh digunakan untuk peralihan ke server baru.

## Yang sudah dikenali

- Profil struktur 2019 dan 2026 untuk SD, SMP, dan SMA. Profil dipilih berdasarkan tabel/kolom beserta tipe, ukuran, presisi, dan nullability; tanggal dan nama file tidak memilih profil.
- SD terbaru memakai `no_induk`; `NO_INDUK1` hanya pembanding. Pembanding kosong diperbolehkan, sedangkan perbedaan identitas ditahan. Data asli tetap disimpan.
- NIS yang sama antarunit tetap terpisah. NIS ambigu dalam unit tidak digabung berdasarkan nama.
- Metode, jam, status pembayaran, dan ID baris sumber tetap metadata. ID numerik tidak dihitung sebagai uang.
- Kolom `tabungan_wajib` numerik maupun teks diperiksa sebagai nominal. Teks kosong berarti belum tercatat; teks tidak valid menjadi pengecualian. Nominal tabungan tidak otomatis dihitung sebagai pembayaran biaya.
- Delapan objek diagram pada SMP terbaru dikenali melalui nama, jenis, konteks eksekusi, dan SHA-256 definisi yang diaudit. Importer tidak menjalankannya dan tidak memindahkannya ke MySQL. Definisi berbeda atau objek lain memerlukan audit baru.

## Masalah dan tindakan

| Tampilan | Tindakan |
|---|---|
| Struktur backup belum didukung | Minta pemeriksaan struktur sumber; jangan mengganti ekstensi untuk melewati pemeriksaan. |
| Nomor induk utama dan pembanding berbeda | Periksa identitas siswa pada sistem sumber. Baris tersebut belum boleh diterapkan. |
| Saldo berbeda dengan jurnal | Cocokkan bukti rekening dengan pengelola; sistem tidak membulatkan atau memperbaiki saldo otomatis. |
| Tabungan tambahan perlu diperiksa | Periksa pemilik dan hubungan rekening. Saldo tambahan tidak dijumlahkan ke rekening utama. |
| Histori tidak memiliki tanggal/pasangan siswa | Tinjau sumber dan klasifikasinya. Jangan membuat tanggal atau pasangan berdasarkan dugaan. |
| Unit sudah berisi siswa/transaksi | Gunakan mode impor yang sesuai atau server/unit bersih untuk Migrasi Penuh. Tidak ada penghapusan otomatis. |

Tabel tambahan SD `tb_total`, `tb_masuk`, `tb_keluar`, dan `tbg` disimpan terpisah. Migrasi Penuh tetap ditahan jika hubungan atau pemiliknya belum terbukti. Pengesahan referensi tersedia hanya untuk salinan yang cocok dengan rekening/jurnal utama, atau rekening yang jurnalnya lengkap dan bersaldo nol, disertai bukti pengguna. Data tambahan tanpa identitas tidak otomatis dianggap milik siswa.

## Catatan pemasangan

Perbarui source beserta semua berkas profil dan pembaca, lalu mulai ulang worker dengan prosedur pada runbook. Tidak ada skema MySQL tambahan untuk penyesuaian ini. Simulasi/konfirmasi lama harus diperiksa ulang ketika aturan pembaca berubah. Profil privat dipisahkan menurut unit dan signature struktur sumber.

Pemrosesan arsip menggunakan pembacaan bertahap agar salinan tabel histori tidak seluruhnya ditampung di memori. Jumlah baris dan fingerprint tetap mencakup seluruh arsip, termasuk baris identik berulang.

Backup berikutnya dengan struktur yang sama dapat diperiksa tanpa mendaftarkan hash file baru. Struktur atau data yang berbeda tetap harus melewati pemeriksaan dan simulasi sendiri. Dukungan format file bukan jaminan bahwa pengecualian keuangannya sudah selesai.

File sumber, dump, manifest privat, kredensial, dan gambar pengujian berada di luar Git/webroot.

## Verifikasi 17 Oktober 2026

| Unit | Baris tabel siswa terbaru | Baris arsip termasuk referensi |
|---|---:|---:|
| SD | 1.133 | 123.229 |
| SMP | 1.762 | 245.446 |
| SMA | 397 | 22.236 |

Jumlah siswa sumber mencakup alumni/rekening non-siswa; angka ini bukan jumlah siswa aktif yang akan diterapkan. Seluruh 390.911 baris arsip dibandingkan dengan pembacaan audit independen, termasuk nominal asli melalui fingerprint raw. Pengujian publikasi arsip ketiga sumber dilakukan dalam transaksi pada salinan, diulang untuk memastikan idempotensi, kemudian seluruhnya di-rollback. Saldo dan kas aktif tidak berubah.

- Worker nyata: 18 pemeriksaan sumber, yaitu dua generasi backup ? tiga mode ? tiga unit. Backup 2019 tetap terbaca; ketiga file terbaru dapat diunggah tanpa perubahan nama.
- Kontrak: format/nama/ukuran, identitas pembanding, nominal teks, ID metadata, manifest lama, 22 kasus profil/objek, referensi tabungan, dan fingerprint pratinjau. Unggahan HTTP menguji retry, file rusak, salah unit, Super Admin aktif, penolakan peran lain/akun nonaktif, CSRF, dan lintas unit.
- Regresi salinan: migrasi atomik SD/SMP, master baru SMA nonaktif, rollback, konflik master, pemulihan setelah commit, profil kelompok, transaksi tabungan, cicilan/pelunasan, seluruh template laporan pada empat peran/empat cakupan, serta PDF/Excel. Tujuh backup pengaman lulus pemulihan terisolasi.
- Tampilan: Chrome **155.0.8059.80** dan Chromium **109.0.5412.0**, 192 skenario halaman/unit/tema/ukuran/JavaScript ditambah 16 pemeriksaan ulang rincian tabungan. Ukuran 390 dan 1366 px. Pada jalur tanpa JavaScript, formulir/navigasi diperiksa tanpa skrip aplikasi; atribut tema diterapkan oleh pengujian hanya untuk memeriksa kedua gaya CSS. Windows 7 fisik tidak diuji.
- Fingerprint seluruh isi database operasional identik sebelum/sesudah. Folder Copy dan deployment tidak diperbarui. Tidak menjalankan migrasi skema atau impor pada database operasional.

### Mengaktifkan source yang sudah diperbarui

Worker yang masih memakai kode lama ditandai **perlu dimulai ulang**; unggahan baru ditahan sampai pembaca terbaru aktif. Dari folder project, jalankan prosedur worker yang ada:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\start_legacy_worker.ps1
```

Jalankan dengan akun Windows pemilik importer. Bila worker lama masih berjalan, hentikan secara normal setelah pekerjaan selesai, lalu jalankan ulang; jangan menghentikan proses saat menerapkan migrasi. Periksa ringkasan kesiapan sebelum unggah. Tahap ini menyiapkan source utama, bukan mengaktifkan impor pada database operasional atau memperbarui Copy.

Bukti privat pengujian berada pada folder `legacy_latest_qa_20261017` di area backup lokal, termasuk `latest-source-results.json`, `latest-archive-results.json`, dan hasil browser. Data sumber serta kredensial tidak disalin ke dokumentasi/Git.
