# Penerapan DU tahun berikutnya lebih awal — 27 Oktober 2026

Selesai pada `db_spp`, `127.0.0.1:3306`, UUID `86e982af-c6db-11f1-9998-00e04ccb017f`.

Master Daftar Ulang menerima tahun berjalan dan tepat satu tahun berikutnya. Kasir memilih **Tagihan Mendatang** melalui dropdown DU existing untuk cicilan/pelunasan. Deployment menghasilkan **0 tarif dan 0 tagihan tahun mendatang** pada target; penerbitan menunggu pengguna menyimpan tarif.

## Data target yang dipertahankan

| Unit | DU tercakup PSB | Tagihan DU lama | Pokok DU terpisah | Terbayar | Sisa |
|---|---:|---:|---:|---:|---:|
| SD | 83 | 331 | Rp1.362.165.000,00 | Rp1.241.964.700,00 | Rp120.200.300,00 |
| SMP | 103 | 198 | Rp878.805.000,00 | Rp831.950.000,00 | Rp46.855.000,00 |
| SMA | 21 | 46 | Rp221.950.000,00 | Rp204.150.000,00 | Rp17.800.000,00 |

Snapshot identitas, arsip, PSB/data siswa, pembayaran native/Legacy, tabungan dan jurnal identik dengan baseline. Fingerprint seluruh siswa, master DU dan 575 tagihan DU juga identik setelah mengabaikan kolom baru yang bernilai NULL pada data lama. Pengecualian migrasi existing tetap sama.

Perubahan skema: `penempatan_id` DU menjadi nullable, `penempatan_asal_id` beserta indeks/FK ditambahkan, dua trigger menolak tagihan tanpa dasar penempatan, dan view unit diperbarui. Pemeriksaan integritas DU target: 0 masalah.

## Pengamanan dan pengulangan

Preview/apply/verify pada salinan port 3319 lulus. Apply/replay target melalui runner resmi menghasilkan 0 perubahan per unit. Verify target exit 0. Checksum **seluruh tabel** setelah replay identik dengan backup sesudah skema/sebelum apply, demikian juga snapshot terlindungi. Bukti: `target-apply.json`, `target-verify.json`, `target-replay.json`, `target-after-replay.json`.

Backup lengkap dengan routines/triggers/events dipulihkan dan diverifikasi pada server dengan UUID, port dan datadir berbeda serta event scheduler OFF. Restore awal salinan cocok seluruh checksum baseline target. Antrean privat disalin konsisten dengan 139 berkas pada `target-private-before/`.

- `target-before-schema-proof.json`: SHA-256 `6a7cc3c2a48e0bd0ad98c6b9347a6b812ce12db458c0409934539a99d89d4948`; server restore `066e617a-d1d4-11f1-99f5-00e04ccb017f`.
- `target-before-apply-proof.json`: SHA-256 `ad37cb02ab6af014908a9081f02f583c4e0c67891f6d2d8612bc5fc8b0b3373a`; server restore `3e9d47a9-d1d8-11f1-bb9b-00e04ccb017f`.
- `clone-before-schema-proof.json`: SHA-256 `19f3deeb0f23344535b257256df324e4a969561d784363e419ed3e0ac34edeb0`; server restore `b92c102b-d1d1-11f1-8dd1-00e04ccb017f`.
- `clone-before-apply-proof.json`: SHA-256 `31d142337cd88de92e7bc87b1f788887e8168e134744adda6fd48e316b00f0ce`; server restore `f47e3f57-d1d7-11f1-afdb-00e04ccb017f`.

Aturan runtime: workflow 14, operasional 18, otomasi 5, billing 6, DU 4, bulanan 2, biaya lain 1. Provenance lama tetap memakai versi yang dicatat ketika bukti tidak berubah. Worker telah dimulai ulang dan heartbeat SQL siap.

## Pengujian aktual

- Proyeksi, kelas awal/akhir, tarif parsial, cicilan, rollover, konflik dan pengikatan: 123 pemeriksaan lulus.
- Konkurensi publikasi/pembayaran/tarif (3 putaran): 15 pemeriksaan lulus.
- Pembaca 83 siswa Legacy tanpa penempatan tujuan: 83 pemeriksaan lulus.
- Master DU pada data lengkap: 824 pemeriksaan lulus.
- Integrasi DU Legacy: 224 pemeriksaan lulus.
- Transaksi PSB/DU: 66 pemeriksaan lulus.
- Master DU draf: 11 pemeriksaan lulus.
- Rekategorisasi DU: 12 pemeriksaan lulus.
- Billing digest: 5 pemeriksaan lulus.
- Integrasi bulanan: 30 pemeriksaan lulus.
- Master Biaya Lain: 22 pemeriksaan lulus.
- Browser DU existing: 43 pemeriksaan lulus.
- Browser DU mendatang: 26 pemeriksaan lulus.
- Isi PDF/XLSX: 6 pemeriksaan lulus.
- GET dengan checksum seluruh tabel identik: 4 pemeriksaan lulus.
- Suite otomasi `--draft --publication --du-publication`: lulus, termasuk backup/restore, kegagalan sebelum commit, publikasi nyata dan replay tanpa perubahan.
- Perencana DU/bulanan, otomasi murni, pelacakan biaya lain, presisi dan buku tabungan, serta pemisahan laporan DU per tahun: lulus.
- PHP lint 30 berkas, sintaks dua berkas JavaScript dan `git diff --check`: lulus.

Pada fixture GUI, 83 siswa SD diterbitkan untuk kelas 2 tahun 2027/2028; kelas aktif/penempatan/SPP/Komite tetap sama. Abdullah diuji dengan cicilan, koreksi dan pembatalan; Raisa diuji pada alur existing. Semua data tersebut hanya pada salinan. Rollover dan 1→2, 7→8, 10→11 diuji pada database audit terpisah; kenaikan resmi mengikat ID tagihan yang sama. Konflik kelas mempertahankan uang dan histori; pengulangan tidak menambah audit konflik.

Pengujian tambahan `schema_fresh_install_test.php` menyelesaikan import/DDL dan migrasi unit, lalu berhenti pada prasyarat Super Admin existing dalam bootstrap akun. Gerbang fitur memakai salinan database existing yang terverifikasi; akun target ID 1 tetap tersedia. Detail ada pada `fixture-schema_fresh_install.log`.

## Sumber dan artefak

- Unit 1, batch `62e3c4bdee2544218f5cf6a9b7f1ec56`, `SD-7.dat`: `fea9e3a4ae75d8db510ba1f1375ef90893e6c69e5c9b2aea6a7879054f8d0e92`.
- Unit 2, batch `d999bae51367722a4f47ca929b29bc6a`, `SMP-7.dat`: `462da46d04f75cee1d824a173ad7a24c32def10bba03665b4b186639586ba210`.
- Unit 3, batch `5c33fec90d5b8eba8976b3c5ae75fc2a`, `SMA-7.dat`: `b720e27cc47f6544df72cf5cf40794d94ff1d72e2ca8137d3e4aeb9a97f3dbfb`.

Artefak privat: `C:/laragon/data/spp-migrations/db_spp/20261027-du-advance/`.

`koneksi.php` dan `.gitignore` mempunyai SHA-256 yang sama sebelum/sesudah. Kode dan dokumentasi berada di workspace; tidak melakukan push. Prosedur GUI dan struktur internal ada di [DU_ADVANCE.md](DU_ADVANCE.md).
