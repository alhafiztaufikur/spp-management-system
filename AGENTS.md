# Migrasi dan rekonsiliasi Legacy

Untuk pekerjaan migrasi (termasuk backup yang dilampirkan lewat chat Codex), baca
[prosedur lengkap](documentation/LEGACY_MIGRATION_RUNBOOK.md) sebelum menerapkan data.
Jalankan pipeline melalui `scripts/legacy_migration_runner.php`; default `preview`.
Selesainya impor identitas/pembayaran belum berarti migrasi selesai: tahap PSB,
pelacakan biaya lain, SPP–Komite, kredit periode mendatang, tabungan, dan verifikasi
wajib diperiksa.

- Prioritaskan periode Januari 2020 ke atas serta siswa aktif. Pertahankan histori sebelumnya.
- `U_SORGA` adalah Komite/POMG pada SD, SMP, SMA. Keputusan 24 Oktober 2026
  menggantikan catatan lama tentang Histori Saja/penghentian U_SORGA.
- PSB master = pokok sebelum potongan; simpan potongan terpisah. Header kumulatif
  pembayaran Legacy sudah mencakup rincian: jangan dijumlahkan dua kali.
- Kewajiban mengikuti periode BULAN–TAHUN, bukan tanggal kas. Tarif/kelas harus
  terbukti pada periodenya. Batas peralihan adalah metadata, bukan larangan terbit.
- Gunakan engine yang sama untuk sumber baru dan batch existing; jangan membuat
  SQL ad hoc yang melewati pembentukan tagihan atau audit.
- Biaya lain diproses otomatis pada unggahan GUI maupun runner Codex. Hubungkan
  LAIN_LAIN1–4/JUMLAH1–4 dengan master dalam unit/sumber yang sama; pertahankan
  nol di depan kode serta jejak unit, sumber, baris transaksi dan slot.
- Kode kosong/ambigu diterima otomatis sebagai Biaya Legacy belum teridentifikasi,
  tanpa menunggu pemetaan pengguna. Nominal tidak sah/total bertentangan diarsipkan
  otomatis per transaksi; data valid lainnya lanjut. Koreksi manual tetap opsional.
- Riwayat biaya bukan tarif atau tagihan baru. Master baru dari bukti nama sumber
  tetap nonaktif dan tarif belum ditetapkan. Jangan menyalin penerimaan Legacy ke
  bayar/bayar_biaya_lain untuk membuatnya terlihat pada sistem baru.
- Rekening tabungan saja tidak mendapat tagihan otomatis. Penetapan eksplisit lewat
  CRUD boleh dilakukan tanpa larangan profesi/label guru.
- Migrasi baru menahan selisih saldo yang belum terbukti (`op_preserve_unproven_balances`).
  Jangan membuat jurnal penyeimbang otomatis hanya untuk menyamakan header dan jurnal.
- Gunakan GUI yang tersedia; jangan menambahkan tombol/menu migrasi baru.
- Pertahankan koreksi pengguna, arsip mentah, identitas, transaksi kas, tabungan,
  serta perubahan pengguna pada `.gitignore`.
- Uji pada server salinan yang berbeda, verifikasi restore backup dan identitas
  target sebelum apply. Transaksi per unit; penerapan ulang wajib nol perubahan.
- Catat pengecualian dan hasil per komponen/periode. Jangan menyatakan data yang
  belum terbukti sebagai nol/lunas atau menghapus histori untuk meloloskan verifikasi.
- Verifikasi Master Biaya Lain: hitungan Transaksi, rincian/nominal, tautan ke
  riwayat, dan Bisa Dihapus harus memakai pembayaran native serta Legacy.
  Master yang pernah direferensikan transaksi/audit tidak boleh dianggap kosong.
- Ringkasan kewajiban Komite harus memakai kredit native + Legacy seperti rincian
  formulir; periode yang lunas tidak boleh diminta dibayar kembali. Nama item pada
  struk/riwayat tidak diberi tambahan ID/kode/slot; jejak tersebut tetap disimpan.

- DU wajib dilacak sampai transaksi, tagihan per siswa, Riwayat Daftar Ulang,
  Input Pembayaran, laporan/struk/ekspor, dan Master Daftar Ulang. Gunakan
  `ldu_reconcile()` pada pipeline resmi untuk sumber baru dan existing.
- DU otomatis hanya untuk siswa aktif pada tahun ajaran berjalan; pertahankan
  histori, tahun tertutup, pembatalan, dan koreksi pengguna. Tarif, kelas, tahun,
  serta potongan harus terbukti. `bayar_du` sumber adalah bukti rekap, bukan kas kedua.
  Terbitkan tahun draf melalui fungsi resmi tanpa menunggu semua kelas lengkap.
  Data belum terbukti menjadi pengecualian otomatis, bukan nol/lunas atau tugas wajib pengguna.
- Versi saat ini: workflow 14, operasional 18, otomasi 5, billing 6, DU 4.
  Metadata otomasi versi 1, 2, 3, dan 4 tetap terbaca; worker/simulasi lama wajib ditolak.

Rincian DU: [pelacakan otomatis dan pengujian](documentation/LEGACY_DU_TRACE_20261026.md).

- Master DU memakai satu formulir **Simpan Tarif & Lengkapi Tagihan**. Tarif kosong
  berarti belum ditetapkan/tidak diubah; kelas lain tidak wajib lengkap. Kelas awal
  mengikuti cakupan PSB, tanpa tarif DU terpisah. Tarif admin adalah dasar kewajiban baru
  yang diaudit terpisah dari bukti Legacy; tidak menimpa snapshot terbayar/koreksi.
- Kebijakan klien 27 Oktober 2026: DU SD 1/SMP 7/SMA 10 tercakup PSB sejak
  penempatan tahun berjalan, termasuk PSB dicicil. Simpan `du_covered_by_psb`
  beserta audit DU pada proses tulis existing; GET tetap baca saja. Jangan membuat
  tarif/tagihan nol atau kas DU buatan. DU tersendiri yang sudah ada menjadi
  konflik dan dipertahankan; histori lama/tahun tertutup tidak diubah otomatis.
- Regresi wajib: kelas awal menerima kosong/0 dan menolak positif; kelas lanjutan
  tetap menerima tarif parsial; kenaikan kelas tidak membawa cakupan tahun lama;
  kredit, pembatalan/koreksi dan replay nol tetap terlindungi.
  Rincian: [kebijakan DU–PSB](documentation/DU_PSB_POLICY_20261027.md).

- Penerbitan DU eksplisit melalui Master DU boleh untuk tahun berjalan dan tepat
  satu tahun berikutnya. Tahun mendatang memakai penempatan tujuan bila tersedia,
  atau proyeksi satu tingkat dari penempatan berjalan; kelas akhir/nonakademik
  dan siswa tidak aktif tidak diproyeksikan. Tidak membuat penempatan, SPP/Komite.
- Tagihan awal memiliki `penempatan_asal_id`, sementara `penempatan_id` boleh NULL.
  Kenaikan resmi mengikat tagihan yang sama; uang, ID, koreksi dan histori dijaga.
  Tagihan tetap terbaca saat pergantian kalender sebelum kenaikan.
- Tagihan Mendatang dapat dibayar melalui dropdown DU existing, tidak dipilih
  otomatis dan tidak dihitung sebagai tunggakan berjalan. Tahun/kelas kewajiban
  pada struk, laporan dan ekspor berasal dari tagihan; tanggal kas tetap aktual.
- Pemasangan skema melalui `scripts/migrate_du_advance_schema.php` dengan backup
  terverifikasi; deployment tidak menerbitkan tagihan mendatang secara otomatis.
