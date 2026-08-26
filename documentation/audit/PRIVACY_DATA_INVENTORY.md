# Inventaris Privasi dan Data SistemSPP

Dokumen ini adalah inventaris teknis, bukan penetapan dasar hukum. Masa simpan, pemberitahuan privasi, hak subjek data, serta pemusnahan menunggu DEC-010 dan persetujuan pemilik/penanggung jawab data sekolah.

## Kategori data

| Kategori | Field/contoh | Tujuan aplikasi | Penyimpanan utama | Role yang saat ini dapat melihat | Export/backup | Retensi/status |
| --- | --- | --- | --- | --- | --- | --- |
| Identitas siswa | NIS internal, NIS Diknas, nama | Identifikasi pembayaran, tabungan, tagihan, laporan | `siswa`; snapshot/referensi pada tabel transaksi/tagihan | Admin; kasir pada transaksi; bendahara pada laporan | Web, struk, PDF, Excel, backup | Pending DEC-010 |
| Status akademik | kelas/rombel, aktif/arsip, tahun ajaran, penempatan | Scope tagihan dan laporan historis | `siswa`, `master_kelas`, `siswa_tahun_ajaran` | Admin; kasir/bendahara sesuai route | Laporan/export/backup | Histori tidak boleh ditulis ulang; masa simpan pending |
| Kewajiban finansial | tarif, potongan, tagihan DU/Biaya Lain, snapshot SPP/Komite | Menghitung total, cicilan, sisa | master, `siswa`, `tagihan_*`, placement snapshot | Admin; kasir transaksi; bendahara laporan | Laporan/export/backup | Pending DEC-010 |
| Pembayaran | komponen, metode, tanggal, periode, catatan, total, operator | Penerimaan, struk, closing, rekonsiliasi | `bayar` dan child | Admin/kasir; bendahara laporan | Struk/PDF/Excel/backup | Koreksi/retensi pending DEC-003/005/010 |
| Tabungan | saldo, setoran, penarikan, waktu, operator | Ledger tabungan siswa | `tabungan`, `transaksi_m`, `transaksi_k` | Admin/kasir; bendahara riwayat/laporan | Laporan/export/backup | Pending DEC-010 |
| Identitas petugas | username, nama, role, admin ID | Autentikasi, otorisasi, attribution | `admin`; snapshot operator/audit | Admin; nama operator pada laporan/struk | Laporan/audit/backup | Pending DEC-010 |
| Kredensial/session | password hash modern, session ID/CSRF, session version | Login dan revokasi | DB admin; PHP session store; cookie browser | Server/auth; admin hanya reset, tidak membaca hash | Backup DB dapat memuat hash | Password/token tidak boleh diekspor/log; retensi teknis pending |
| Telemetry login | HMAC username+source, count/window/block | Throttling brute force | `login_rate_limit` setelah security migration | Server/DBA/auditor terbatas | Log/backup | Bounded cleanup runtime; kebijakan final pending |
| Audit/error | actor, action, before/after terpilih, request ID, error detail server | Rekonsiliasi, keamanan, forensik | tabel audit + protected server log | Admin terbatas/auditor/DBA sesuai kebijakan | Backup; bukan export kasir | Pending DEC-003/010 |

## Aliran dan lokasi salinan

| Lokasi | Risiko | Kontrol minimum |
| --- | --- | --- |
| Database aplikasi | Akses langsung/SQL compromise | Account runtime least-privilege, network ACL, encryption at rest bila tersedia, backup, audit, patch. |
| PHP session store | Cookie reuse/session theft | HTTPS, Secure/HttpOnly/SameSite, timeout, regeneration, revocation, filesystem permission. |
| Browser/perangkat bersama | Autofill, history, print/download tersisa | `Cache-Control: no-store`, logout POST, larang shared password, bersihkan download/print queue sesuai SOP. Cache header tidak menghapus file yang sudah diunduh. |
| PDF/Excel/print | Salinan mudah diteruskan | Role minimum, batas export, watermark/timestamp bila disetujui, penyimpanan nonpublik, pemusnahan. |
| Backup/evidence audit | Snapshot penuh termasuk hash dan PII | Di luar web root, encryption, checksum, restricted ACL, access log, retensi dan secure destruction. |
| Server/error log | PII atau detail teknis berlebih | Correlation ID, masking, no password/token/cookie, rotation/retention, akses terbatas. |
| Repository/public web | Source/schema/default credential | Secret tidak di-commit; path internal 403/404; history scan dan rotasi. |
| Google Fonts | IP/user-agent dan availability ke pihak ketiga | Menunggu DEC-006; rekomendasi self-host font dan CSP `self`. Tidak ada data aplikasi yang sengaja dikirim. |

## Prinsip minimisasi

- Query verifier dan bukti audit repository hanya menampilkan hitungan/agregat serta ID sintetis; jangan menyalin NIS/nama nyata.
- Error eksternal tidak memuat SQL, path, stack, credential, atau payload siswa.
- Audit before/after hanya menyimpan field yang diperlukan untuk merekonstruksi perubahan finansial/privilege; password hash dan token selalu dikecualikan.
- Export harus dibatasi pada periode/role/tujuan yang sah dan dicatat bila massal.
- Data siswa nonaktif diarsipkan untuk histori; hard-delete dan pemusnahan akhir mengikuti DEC-005/010.

## Keputusan dan bukti yang belum tersedia

- PIC data, tujuan formal, dasar penggunaan, durasi per kategori, dan prosedur permintaan/koreksi/pemusnahan.
- Lokasi fisik/logis server dan backup client, enkripsi, pihak yang mendapat akses, serta transfer pihak ketiga.
- Kebijakan perangkat bersama, browser yang didukung, lokasi download, print queue, dan salinan kertas.
- Privacy notice/syarat penggunaan yang benar-benar disetujui. Link placeholder tidak boleh ditampilkan sebagai dokumen yang seolah tersedia.
- Uji target bahwa download tidak dicache proxy/browser secara tidak tepat dan log tidak merekam query/token sensitif.

Tanpa keputusan di atas, gate privasi/operasional tetap `PENDING`, bukan PASS.
