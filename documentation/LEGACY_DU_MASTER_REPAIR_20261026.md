# Penerapan perbaikan Master DU ? 26 Oktober 2026

Target `db_spp_test3`, MySQL `127.0.0.1:3306`, unit SD/SMP/SMA.
Aturan workflow 12, operasional 16, otomasi 5, billing 4, DU 2.

Satu formulir **Simpan Tarif & Lengkapi Tagihan** mendukung tarif kelas awal
pada tahun Terbit dan Draf, dengan input kelas lain boleh kosong. Total terbit
memakai tagihan tersimpan. Snapshot terbayar, koreksi dan pembatalan dipertahankan.
Perencana DU menerima header individual lengkap dan rincian DU yang tegas,
dengan tahun kewajiban sebagai dasar kredit. GUI dan runner memakai engine sama.

575 jejak DU existing diperbarui: SD 331, SMP 198, SMA 46. Belum terbukti:
SD 83, SMP 103, SMA 21; nominal kelas awal tidak diisi dari contoh. Tarif admin
yang sah dapat ditetapkan melalui GUI, tanpa pemetaan manual per siswa.
Raisa DU Rp4.310.000 tetap lunas; kelas 8A dipertahankan. Abdullah tetap 1C;
penerbitan memakai tarif fixture hanya diuji di salinan.

Penerapan, verify dan penerapan ulang resmi lulus; pengulangan 0 perubahan pada
ketiga unit. Checksum seluruh tabel sesudah replay sama. Hanya tabel kewajiban,
audit operasional dan manifest batch berubah. Arsip, identitas, kas, tabungan,
tagihan/tarif native serta perubahan pengguna tetap terlindungi. Koreksi SPP
pengguna dipertahankan; fingerprint SD yang tertinggal diselaraskan dengan audit.

Pengujian: layanan Master DU 627, integrasi DU 224, perencana murni 45,
Draf/native 9, rekategorisasi 12, fingerprint 5, browser 38, serta suite biaya
lain/otomasi/bulanan dan publikasi sumber baru dengan backup/restore terisolasi.
Worker aktif dengan versi terbaru dan SQL ready. Perubahan belum di-push.

Backup/restore sebelum dan setelah perbaikan terverifikasi. Laporan, hash, daftar
sumber/batch, CSV per periode dan bukti checksum berada di storage privat:

`C:/laragon/data/spp-migrations/db_spp_test3/20261026-du-v2/REPORT.md`

Ikuti [runbook](LEGACY_MIGRATION_RUNBOOK.md) dan
[panduan DU](LEGACY_DU_TRACE_20261026.md) untuk migrasi berikutnya.
