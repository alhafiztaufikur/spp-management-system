# Runbook Operasi SistemSPP

Dokumen ini adalah prosedur minimum operator teknis. Nilai dalam tanda `<...>` wajib diisi melalui secret store atau parameter lokal; jangan menuliskan password, cookie, token, atau PII ke command history, tiket, maupun repository.

## 1. Peran dan pemisahan tugas

| Peran | Tanggung jawab |
| --- | --- |
| Pemilik Sistem | Menyetujui role, kebijakan finansial, retensi, residual risk, UAT, dan GO/NO-GO. |
| Admin aplikasi | Mengelola akun/master/siswa sesuai matriks role; tidak memegang akses DB/server. |
| Bendahara | Rekonsiliasi, closing, laporan, approval koreksi sesuai DEC-003. |
| Kasir | Transaksi operasional sesuai scope DEC-001; tidak menerima dump/credential server. |
| Infra/DBA | Deployment, secret, TLS, backup/restore, patch, log, monitoring, dan account migrasi. |
| Auditor/support | Membaca bukti tersanitasi; akses data produksi hanya dengan tiket dan persetujuan. |

Minimal dua orang terlibat untuk restore produksi, koreksi finansial massal, migrasi, rotasi credential darurat, dan keputusan rollback.

## 2. Instalasi baru

1. Siapkan host yang memenuhi `DEPENDENCY_DEPLOYMENT_REPORT.md`, HTTPS, timezone Asia/Jakarta, dan sink log terlindungi.
2. Buat database kosong serta dua account berbeda: runtime least-privilege dan migrasi/DDL. Jangan gunakan root sebagai runtime.
3. Clone/ekstrak commit release yang tercantum pada `RELEASE_MANIFEST.md`.
4. Buat `config/app.local.php` dari contoh di luar proses commit. Pastikan HTTP ke file tersebut 403/404.
   - Set `SPP_RATE_LIMIT_KEY` sebagai secret acak stabil di environment service.
   - Set `SPP_ENABLE_HSTS=1` hanya sesudah HTTPS end-to-end, redirect HTTP, dan renewal sertifikat lulus.
5. Jalankan Composer dari lockfile dan seluruh check dependency.
6. Hanya pada database baru kosong, jalankan `sql/schema.sql` dengan account migrasi.
7. Jalankan `sql/verify_schema.sql` dan `sql/verify_data_integrity.sql`; seluruh requirement/INV harus lulus.
8. Buat admin awal melalui prosedur out-of-band dengan password unik modern; tidak ada shared/default password.
9. Jalankan smoke anonymous/admin/bendahara/kasir, CSRF/method deny, export, PDF, dan protected rekap kelas.
10. Simpan checksum paket dan bukti pada lokasi nonpublik; hapus installer/test/schema dari public package atau buktikan deny web.

`sql/schema.sql` bersifat destruktif dan dilarang untuk database yang sudah berisi data.

## 3. Upgrade database berhistori

1. Umumkan maintenance window dan hentikan mutasi aplikasi.
2. Catat commit, schema fingerprint, versi runtime, row count, ukuran database, dan ruang disk.
3. Buat backup konsisten; hitung SHA-256; restore ke database kosong; bandingkan row count dan verifier.
4. Jalankan preview queue rekonsiliasi. Nilai legacy yang tidak dapat dibuktikan tidak boleh ditebak.
5. Uji urutan migrasi kanonik pada snapshot dengan `tests/support/run_migration_matrix.ps1` atau harness ekuivalen yang menunjuk database disposable.
6. Jalankan migrasi produksi memakai account migrasi dan urutan pada `MIGRATION_MANIFEST.md`.
7. Jalankan setiap postflight, `verify_schema.sql`, dan `verify_data_integrity.sql`.
8. Lakukan smoke read-only sebelum membuka mutasi; lalu smoke satu transaksi terkontrol dengan rekonsiliasi sebelum/sesudah.
9. Buka maintenance hanya bila seluruh gate lulus. Simpan log tanpa PII/secret dan catat checksum backup.

## 4. Rekonsiliasi baseline yang ditemukan audit

Urutan yang telah dibuktikan pada salinan audit:

1. `add_annual_payment_receipts.sql` untuk claim periode SPP;
2. `allow_spp_installments.sql` untuk kontrak cicilan/index;
3. `sync_student_initial_fee_paid_totals.sql` untuk cache biaya awal;
4. `normalize_transaction_operators.sql` untuk exact-match username ke admin ID;
5. `verify_schema.sql` dan `verify_data_integrity.sql`.

Sebelum langkah 1, simpan query preview jumlah eligible/mismatch/unknown. Migrasi operator hanya boleh mengubah exact match `admin.username`; unknown tetap berada di queue manual. Pembayaran legacy versi 0, child tanpa parent, nominal/tahun/bulan ambigu, dan operator tidak dikenal tidak boleh disimpulkan dari tanggal, NIS, role, atau urutan.

## 5. Backup, restore, dan rollback

### Backup

- Gunakan backup konsisten yang menyertakan trigger/routine/event bila digunakan.
- Simpan terenkripsi di luar web root dan di media/lokasi terpisah.
- Evidence root dan backup harus mematikan inheritance ACL yang memberi akses umum; izinkan hanya owner audit, `Administrators`, dan `SYSTEM` (atau grup layanan eksplisit yang disetujui). Verifikasi ACL root dan child setelah setiap run.
- Catat waktu mulai/selesai, ukuran, checksum SHA-256, schema, commit, owner, dan hasil monitor.
- Jangan menganggap backup berhasil sebelum restore drill dan verifier lulus.

### Restore drill

1. Buat database baru dengan nama audit unik; jangan reuse target lama.
2. Restore backup dengan account terpisah.
3. Bandingkan daftar tabel, row count, agregat finansial tersanitasi, schema verifier, dan INV-001–017.
4. Jalankan smoke dengan credential test, bukan credential produksi.
5. Bersihkan database drill melalui guard nama eksplisit setelah bukti disimpan.

### Rollback release

- Jika migrasi belum berjalan: kembalikan paket aplikasi ke release sebelumnya dan ulangi smoke.
- Jika migrasi additive dan kompatibel: gunakan aplikasi sebelumnya hanya bila manifest menyatakan backward-compatible.
- Jika migrasi/data reconciliation sudah mengubah data: hentikan aplikasi, simpan backup keadaan gagal untuk forensik, restore backup preflight ke database baru, validasi, lalu alihkan koneksi secara terkontrol.
- Jangan memakai `DROP`, `TRUNCATE`, reverse SQL improvisasi, atau restore menimpa database aktif tanpa dua-person check dan target absolut yang diverifikasi.

Target RPO/RTO wajib ditetapkan client sebelum GO; run audit lokal hanya membuktikan mekanisme, bukan target layanan.

## 6. Akun, password, dan session

- Tambah akun memakai password unik dan role minimum. Kirim secret awal melalui kanal terpisah; paksa perubahan pada login pertama bila workflow tersedia.
- Password reset/perubahan role/delete/disable harus menaikkan `session_version` sehingga cookie lama langsung gugur.
- Dilarang membagikan akun kasir atau menyimpan password pada browser/perangkat bersama tanpa kebijakan.
- Review akun aktif dan role berkala; catat pembuat, approver, alasan, dan tanggal.
- Untuk kehilangan akses admin, gunakan prosedur DBA out-of-band yang menghasilkan hash modern; jangan mengaktifkan default credential dari schema/history.

### 6.1 Rotasi credential DB dan pemisahan secret (`DBSEC-004`)

Temuan lokal menunjukkan akun runtime aplikasi dan audit dapat berbagi secret bila provisioning tidak dipisahkan. Pada target, jalankan prosedur berikut melalui dua-person check:

1. Buat dua secret acak berbeda pada secret store; jangan menaruh nilainya di command history, tiket, repository, atau log.
2. Ubah password account runtime dan account audit secara terpisah menggunakan account DBA/migrasi yang disetujui; pertahankan host scope dan grant least-privilege.
3. Perbarui `config/app.local.php`/environment service dan credential test secara atomik, lalu restart service terkontrol.
4. Probe koneksi aplikasi, audit read-only, `SHOW GRANTS`, dan host wildcard; pastikan tidak ada `GRANT OPTION` atau schema wildcard.
5. Jalankan smoke login, verifier, dan regression pada database disposable. Setelah bukti lulus, cabut secret lama dari secret store dan catat waktu rotasi tanpa nilai secret.

Status saat ini: prosedur siap, tetapi rotasi target belum dijalankan atau diterima client.

## 7. Closing dan rekonsiliasi harian

1. Kasir menutup input pada cutoff yang disepakati.
2. Bandingkan jumlah metode pembayaran, penerimaan tunai bersih, tabungan masuk/keluar, dan kas disetor dengan ledger/database.
3. Periksa transaksi nol, nominal negatif, pembayaran tanpa claim, child yatim, saldo/cache mismatch, dan operator tidak dapat dipetakan.
4. Jalankan data verifier read-only dengan account audit; semua INV wajib PASS.
5. Bendahara menandatangani selisih nol atau membuat tiket koreksi dengan bukti. Jangan mengedit row langsung dari DB untuk menutup selisih.

## 8. Koreksi transaksi

Sampai DEC-003 disetujui, koreksi produksi belum mempunyai workflow final. Minimum sementara:

- hanya role yang disetujui;
- alasan wajib dan referensi tiket;
- before/after, actor, waktu server, request/correlation ID, serta hasil disimpan append-only;
- transaksi legacy tidak diedit/dihapus otomatis;
- koreksi tidak boleh membuat urutan SPP bolong, tagihan overpaid, saldo negatif, child silang, atau operator yatim;
- dua orang memeriksa koreksi massal atau restore.

## 9. Tahun ajaran dan tagihan

- Perbarui kelas/status siswa sebelum publish.
- Simpan enam tarif, publish penempatan dan tagihan dalam satu transaksi.
- Ulangi publish hanya melalui aksi idempoten yang disediakan; periksa jumlah siswa versus tagihan.
- Closed year tidak boleh mengubah tarif/penerbitan. Pembayaran tunggakan mengikuti DEC-002.
- Perpindahan kelas tidak menulis ulang snapshot histori.

## 10. Monitoring dan alert minimum

Alert harus mencakup:

- lonjakan login gagal/rate-limit dan akun terkunci;
- perubahan role/password/delete akun;
- edit/delete/reversal transaksi serta export massal;
- error DB/constraint/deadlock, disk hampir penuh, backup gagal, dan certificate renewal;
- hasil INV gagal, unknown reconciliation queue, atau schema drift;
- HTTP 5xx, latency/memory melampaui budget, dan percobaan akses path internal.

Log harus memakai correlation ID, timezone jelas, masking, akses read-only untuk auditor, rotasi, backup, dan retensi sesuai DEC-010. Jangan log password, CSRF/session token, full cookie, secret DB, atau payload PII berlebihan.

## 11. Incident response

1. Catat waktu, reporter, gejala, scope, dan correlation ID; jangan menyalin secret/PII ke kanal umum.
2. Contain: batasi akses, revoke session/account, rotasi credential terdampak, dan pertahankan bukti.
3. Backup keadaan insiden secara read-only sebelum remediasi bila aman.
4. Tentukan apakah data finansial/PII berubah atau terekspos melalui verifier, audit event, access log, dan checksum.
5. Pulihkan dari release/backup tervalidasi; lakukan smoke serta rekonsiliasi.
6. Dokumentasikan root cause, dampak, notifikasi, tindakan, owner, dan pencegahan; review bersama pemilik sistem.

Severity operasional dan target respons harus ditetapkan client. Temuan kritis seperti credential/source publik, mutasi finansial tidak sah, atau integritas ledger gagal memerlukan penghentian mutasi sampai containment selesai.

## 12. Checklist sebelum membuka layanan

- [ ] Commit/tag/checksum sesuai release manifest.
- [ ] Backup dan restore drill terbaru lulus.
- [ ] Secret/config tidak masuk repository/paket publik.
- [ ] Database runtime bukan root dan grant tervalidasi.
- [ ] Schema verifier dan INV-001–017 seluruhnya PASS.
- [ ] Migration/regression/security/report/export/PDF/browser/UAT lulus.
- [ ] HTTPS, cookie, header, timezone, error/log/alert lulus pada target.
- [ ] Decision log, known limitations, RPO/RTO, PIC, dan sign-off terisi.
- [ ] Rollback point dan prosedur komunikasi tersedia.
