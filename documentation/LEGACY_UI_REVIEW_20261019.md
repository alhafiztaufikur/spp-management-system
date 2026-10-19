# Pemeriksaan UI Legacy — 19 Oktober 2026

## Perubahan

Kelompok Legacy kini berada di dalam konten Master Kelas, sejajar dengan kartu existing. Daftar memakai judul berikon, pencarian nama/kode, filter jenis, badge, jumlah identitas, pagination 25 kelompok, serta kartu ponsel. Ejaan sumber yang sama tidak diulang sebagai nama kedua. Tautan kelompok membawa parameter `group` dan hanya menampilkan pemilik dari kelompok dan batch tersebut.

Halaman rekening menampilkan direktori pemilik, rincian saldo, pembayaran, dan jurnal dengan tindakan yang konsisten. Filter dan konteks Master Kelas dibawa sampai tautan koreksi/tarif dan kembali ke rekening. Tabel jurnal panjang bergulir di dalam areanya; saldo berjalan tetap menggunakan urutan dan perhitungan existing. Halaman impor, migrasi penuh/operasional, histori, peninjauan keuangan, koreksi, tarif, dan aktivasi memakai header serta kontrol aplikasi yang seragam. Formulir aktivasi memisahkan tarif sumber sebagai ringkasan yang mudah dibaca.

Tidak ada perubahan skema, aturan nominal, pengesahan saldo FIX, kewajiban, maupun penerapan final. Parameter GET baru opsional; tautan batch lama tetap bekerja. Pengujian lintas unit memastikan filter tidak membuka data unit lain.

## Pengujian

| Pemeriksaan | Hasil |
| --- | --- |
| Syntax PHP untuk 14 file terkait dan syntax tiga skrip tes browser | Lulus |
| HTTP: posisi kelompok dalam konten, hasil filter, 25+1 kelompok, kondisi kosong, konteks kembali, Semua Unit, dan penolakan lintas unit | Lulus pada instance MySQL terisolasi |
| Chrome 155 dan Chromium 109; lebar 390, 768, 1366; tema terang/gelap; JavaScript aktif/nonaktif | 24 kombinasi lulus; mencakup direktori, rekening, impor, keuangan, migrasi, histori, koreksi, tarif, dan aktivasi |
| Palet SD/SMP/SMA/Semua Unit | Lulus; tidak ada overflow halaman pada skenario yang diuji |
| Pencarian lewat server, tautan kelompok, kembali ke filter sebelumnya | Lulus |
| Pratinjau koreksi, pengembalian isian lewat Escape/Kembali Mengubah, fokus keyboard | Lulus; tidak menyimpan koreksi transaksi |
| Modal pada ukuran setara zoom 80%, 125%, dan 200% | Lulus melalui viewport CSS dan device scale; header/footer tetap terlihat |
| 17 rangkaian regresi Legacy, saldo, struk, PDF/laporan dan cache | Lulus pada pemeriksaan akhir |
| Lab copy dengan 37 kelompok hasil migrasi, desktop/ponsel Chromium 109 | Lulus pada navigasi baca; screenshot diperiksa |

Pada satu pengulangan tes cache laporan yang tidak berubah, pemeriksaan paralel awal gagal. Pengulangan pada source awal dan source hasil perubahan sama-sama lulus 20 pemeriksaan; hasil awal tetap dicatat dalam bukti privat. Tidak ada perubahan cache laporan dalam pekerjaan UI ini.

Browser dijalankan di Windows saat ini. Windows 7 fisik dan dialog cetak/printer fisik tidak diuji. Skala tampilan diuji dengan viewport/device scale, bukan perubahan zoom melalui menu browser. Tampilan gelap tanpa JavaScript diperiksa dengan atribut tema pada harness; pergantian tema interaktif tetap memerlukan JavaScript seperti aplikasi existing.

## Penerapan ke lab copy dan preservasi

Perubahan UI diterapkan sebagai patch ke basis source lab yang lebih lama. Master Kelas lab hanya menerima perubahan posisi bagian Legacy dan stylesheet; tidak disalin utuh dari source utama yang memiliki dependensi workflow remote baru. Konfigurasi database, `.htaccess`, `.user.ini`, worker, dan pengaturan backup lab tidak ditimpa. Delapan hash konfigurasi/runtime tetap sama.

Fingerprint sebelum/sesudah identik untuk **50 tabel `db_spp`** dan **59 tabel `db_spp_test2`**. Draf, antrean, sumber backup, dan saldo FIX pengguna tidak ditulis oleh pekerjaan ini. Pengujian mutasi fixture dilakukan pada instance privat terisolasi, bukan database pengguna. Pengujian HTTP lab hanya memakai GET dengan sesi pengujian tersendiri.

Tidak menjalankan migrasi final, pemasangan CLI pada database utama, commit/push, atau pembaruan deployment. Readiness workflow pada source utama tetap mengikuti kondisi skema existing. Profil browser sementara ditutup melalui Playwright; runtime browser/importer existing dipertahankan.

Bukti privat: `C:/laragon/backups/spp-management-system/legacy_ui_polish_20261019/`, termasuk screenshot, hasil browser, hasil regresi, fingerprint, daftar file patch lab, dan salinan UI lab sebelum perubahan.

## Cara melihat hasil

Buka lab copy, gunakan **Ctrl+F5**, lalu **Master Kelas/Rombel → Kelompok dari Legacy**. Cari/filter kelompok, buka **Lihat Rekening & Transaksi**, lalu **Lihat Rincian**. Tombol kembali mengembalikan konteks pencarian; ejaan sumber tetap tersedia dalam rincian.
