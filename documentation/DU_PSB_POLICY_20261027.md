# DU kelas awal tercakup PSB

Keputusan klien 27 Oktober 2026 menggantikan aturan sebelumnya yang mengizinkan
tarif DU tersendiri pada kelas awal. SD kelas 1, SMP kelas 7, dan SMA kelas 10
mengikuti paket PSB. Cicilan PSB tetap menjadi kewajiban PSB. Nilai bagian DU
di dalam paket tidak ditaksir. Tidak dibuat tarif/tagihan nol atau kas DU baru.

Layanan bersama `includes/du_psb.php` menentukan status dari unit dan penempatan
tahun ajaran, bukan NIS atau pelunasan PSB. Penanda `du_covered_by_psb` disimpan
pada penempatan melalui proses tulis existing, disertai audit DU dengan dasar
`client_psb_policy`. Pembaca GET tidak menyimpan penanda atau audit.

Master Daftar Ulang tetap memakai **Simpan Tarif & Lengkapi Tagihan**. Kartu kelas
awal menunjukkan cakupan dan jumlah siswa, tanpa kolom tarif. Kiriman kosong/0
diterima; nominal positif ditolak sebelum perubahan tarif lain disimpan. Kelas
lanjutan menerima tarif parsial seperti biasa. Penempatan tahun berikutnya
memiliki snapshot tersendiri; kenaikan 1→2, 7→8, dan 10→11 memakai DU normal.

Status `covered_psb` berlabel **Tercakup PSB**, terpisah dari lunas/tunggakan atau
belum diketahui. Riwayat, kasir, laporan dan ekspor menggunakan pembaca yang sama.
Baris cakupan pada riwayat merupakan metadata kebijakan, bukan transaksi kas.
Tagihan DU tersendiri, bukti sumber DU, pembayaran, pembatalan dan koreksi existing
menjadi konflik: tidak dibatalkan atau dipindahkan otomatis. Histori lama dan
tahun tertutup tidak diberi cakupan baru.
Pengulangan penempatan PSB juga tidak membuka kembali tagihan DU kelas awal
yang pernah dibatalkan melalui koreksi pendaftaran; tagihan itu tetap konflik.

Aturan gabungan: workflow 14, operasional 18, otomasi 5, billing 5, DU 3, bulanan 2,
pelacakan biaya lain 1. Metadata otomasi 1–5 dan bukti billing lama tetap terbaca.
Worker dan simulasi sebelumnya harus ditolak/dimulai ulang.

Peningkatan skema menggunakan `scripts/migrate_du_psb_schema.php` (default preview)
dengan bukti backup terverifikasi untuk apply. Pembentukan cakupan dan penyelesaian
`du_evidence` memakai `scripts/legacy_migration_runner.php` melalui `ldu_reconcile()`.
Ikuti runbook: backup penuh sebelum skema/data, restore server terisolasi, semua
checksum cocok, uji salinan, preview/apply/verify, kemudian target dan replay nol.

Regresi: `du_psb_test.php`, `du_psb_transactions_test.php`, `du_master_service_test.php`,
`du_master_draft_test.php`, `legacy_du_planner_test.php`, `legacy_du_integration_test.php`,
`legacy_du_reclassification_test.php`, suite otomasi lengkap, dan browser DU.
Seluruh pengujian tulis wajib pada UUID/port/database salinan yang diperiksa.
Artefak penerapan dan bukti aktual disimpan privat di
`C:/laragon/data/spp-migrations/db_spp/20261027-du-psb/`.
