# Paket UAT SistemSPP

Status: **Belum dijalankan / belum ditandatangani client**. Paket ini tidak boleh diisi sebagai PASS berdasarkan test teknis otomatis; UAT memerlukan operator representatif pada browser dan perangkat target.

## Identitas pelaksanaan

| Item | Nilai |
| --- | --- |
| Release commit/tag | `<isi dari RELEASE_MANIFEST>` |
| URL/environment | `<HTTPS target UAT>` |
| Schema/migration version | `<isi>` |
| Browser/perangkat | `<isi>` |
| Dataset | Sintetis/anonymized, tanpa PII produksi |
| Tanggal dan timezone | `<tanggal>`, Asia/Jakarta |
| Admin tester | `<nama/jabatan>` |
| Bendahara tester | `<nama/jabatan>` |
| Kasir tester | `<nama/jabatan>` |
| Pendamping teknis | `<nama>` |

## Skenario UAT

Isi `PASS`, `FAIL`, atau `BLOCKED`, sertakan nomor bukti/tiket. Password/token/cookie dan data pribadi tidak boleh dimasukkan.

| ID | Role | Skenario | Acceptance | Hasil | Bukti/catatan |
| --- | --- | --- | --- | --- | --- |
| UAT-AUTH-01 | Semua | Login benar/salah, pesan generik, rate limit, logout. | Tidak ada enumeration; burst dibatasi; logout memutus sesi. | PENDING | |
| UAT-AUTH-02 | Semua | Diam sampai idle timeout dan absolute timeout. | Sesi berakhir dan mutasi tidak terjadi. | PENDING | |
| UAT-ADM-01 | Admin | Tambah akun, reset password, ubah role, hapus/disable akun. | Role tepat, session lama dicabut, admin terakhir tidak dapat hilang. | PENDING | |
| UAT-ADM-02 | Admin | Kelola master kelas/rombel. | Validasi, toggle, dan referensi histori aman. | PENDING | |
| UAT-ADM-03 | Admin | Tambah/edit/arsip/pulihkan siswa termasuk ganti NIS. | Histori/FK/audit tetap benar; siswa arsip tidak menerima transaksi baru. | PENDING | |
| UAT-ADM-04 | Admin | Buat tahun ajaran, tarif DU, publish, ubah tarif, close. | Satu tagihan/siswa/tahun, snapshot benar, aksi atomik. | PENDING | |
| UAT-ADM-05 | Admin | Buat master biaya lain dan publish target semua/tingkat/rombel/siswa. | Target tepat, tidak duplikat, histori memakai snapshot. | PENDING | |
| UAT-KSR-01 | Kasir | Pembayaran bulanan satu komponen dan kombinasi. | Total backend benar, struk tepat, operator tercatat. | PENDING | |
| UAT-KSR-02 | Kasir | Cicilan/lunas/lebih bayar SPP, DU, biaya awal, Komite, biaya lain. | Cap dan sisa benar; lebih bayar ditolak atomik. | PENDING | |
| UAT-KSR-03 | Kasir | Urutan SPP Juli-Juni dan koreksi bulan prasyarat. | Tidak dapat membuat bulan bolong. | PENDING | |
| UAT-KSR-04 | Kasir | Dua pembayaran siswa pada timestamp sama lalu koreksi satu. | Hanya child milik payment ID tersebut berubah. | PENDING | |
| UAT-KSR-05 | Kasir | Buka/edit/hapus transaksi legacy. | UI menandai legacy; direct URL/POST tetap menolak. | PENDING | |
| UAT-KSR-06 | Kasir | Kirim transaksi bernilai nol, negatif, overflow, duplikat/replay. | Sesuai DEC-009; tidak ada row/saldo liar. | PENDING | |
| UAT-SAV-01 | Kasir | Tabungan masuk, keluar, saldo pas, saldo kurang. | Ledger/cache seimbang; overdraw ditolak. | PENDING | |
| UAT-SAV-02 | Kasir | Dua penarikan bersamaan dan submit ulang. | Saldo tidak negatif dan tidak ada transaksi ganda. | PENDING | |
| UAT-BND-01 | Bendahara | Closing harian per metode dan kas disetor. | Total cocok dengan oracle DB dan bukti fisik. | PENDING | |
| UAT-BND-02 | Bendahara | Riwayat tabungan filter kosong/NIS/bulan/tahun/payload quote. | Exact filter; payload literal; saldo/row sama dengan oracle. | PENDING | |
| UAT-REP-01 | Admin/Bendahara | Laporan umum pada range kosong, satu hari, boundary waktu. | Row/subtotal/grand total tepat dan siswa arsip tetap historis. | PENDING | |
| UAT-REP-02 | Role sesuai DEC-001 | Tujuh Laporan Global: web, print, PDF, Excel. | Filter/order/total sama; akses mengikuti matriks final. | PENDING | |
| UAT-REP-03 | Admin/Bendahara | Rekap per Kelas lalu detail siswa. | Tampilan referensi tidak berubah, data dan navigasi benar. | PENDING | |
| UAT-REP-04 | Role terkait | Cetak struk ID tunggal, batch tahunan legacy, ID tidak ada. | Isi/akses tepat; tidak membocorkan record lain. | PENDING | |
| UAT-EXP-01 | Admin/Bendahara | Export dengan leading-zero NIS dan awalan `= + - @`, tab, CR. | NIS utuh; semua payload menjadi teks inert. | PENDING | |
| UAT-UX-01 | Semua | Desktop/mobile, light/dark, zoom 200%, keyboard-only. | Tidak overflow/terpotong; focus terlihat; semua aksi dapat digunakan. | PENDING | |
| UAT-UX-02 | Semua | Error validasi, DB/PDF failure sintetis. | Pesan dapat dipahami dan tidak membocorkan detail internal. | PENDING | |
| UAT-OFF-01 | Semua | Putus internet sesuai DEC-006. | Fungsi/tampilan memenuhi kebijakan asset offline final. | PENDING | |
| UAT-DR-01 | Pemilik/Infra | Backup, restore ke host kosong, rollback rehearsal. | Checksum, verifier, RPO/RTO, dan smoke lulus. | PENDING | |

## Pemeriksaan visual wajib

Ambil screenshot bernama `<run-id>__<test-id>__<role>__<viewport>__<theme>__<result>.png` untuk login, dashboard, data siswa, pembayaran input/edit/list, tabungan masuk/keluar/riwayat, setiap laporan, export preview, rekap kelas, detail siswa, dan struk. Catat console error, failed network request, focus order, label/name, contrast, zoom, overflow, empty/loading/error/success state.

Khusus `laporan/rekap_kelas.php`, bandingkan dengan baseline visual yang disetujui dan jangan mengganti/removing fungsi atau tampilannya sebagai dead code.

## Temuan UAT

| Ticket | Test ID | Severity | Ringkasan | Owner | Target retest | Status |
| --- | --- | --- | --- | --- | --- | --- |
| - | - | - | Belum ada; UAT belum dijalankan. | - | - | PENDING |

## Sign-off

Dengan menandatangani, pihak di bawah menyatakan hasil UAT, decision log, known limitations, residual risk, backup/rollback, dan scope role telah dibaca. Tanda tangan tidak boleh diberikan bila ada gate Kritis terbuka.

| Pihak | Nama/jabatan | Keputusan GO/NO-GO | Tanggal | Tanda tangan/referensi persetujuan |
| --- | --- | --- | --- | --- |
| Pemilik Sistem | | | | |
| Bendahara/Penanggung Jawab Keuangan | | | | |
| Infrastruktur/DBA | | | | |
| Audit/QA | | | | |
