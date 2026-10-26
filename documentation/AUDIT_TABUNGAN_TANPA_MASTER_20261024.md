# Pemeriksaan tabungan tanpa pasangan master siswa

[SQL siap dijalankan di SSMS](../sql/sqlserver_audit_tabungan_tanpa_master.sql).
Default `USE [smait_mh]`; ganti menjadi `sdit_mh` atau `smpit_mh` untuk unit lain,
lalu jalankan seluruh file dengan Ctrl+A dan F5. Pemeriksaan hanya membaca sumber.

## Dua hasil

1. **Rekening:** setiap baris `tabungan` yang NIS-nya kosong atau tidak ditemukan
   pada `siswa` unit yang sama, beserta saldo tercatat dan jumlah jurnal utama.
2. **Jurnal:** setiap baris `transaksi_m`/`transaksi_k` dengan kondisi identitas
   tersebut, termasuk jurnal dengan NIS terisi tetapi tidak memiliki rekening.
   `ada_rekening_berdasarkan_nis=0` menunjukkan rekening tidak ditemukan;
   NULL berarti NIS kosong dan tidak dapat dipasangkan. `transaksi_k` tidak
   memiliki ID sumber, sehingga `id_jurnal` pada tabel itu tetap NULL.

NIS kosong pada rekening dan jurnal tidak dipasangkan. Nol awal NIS serta nilai
nominal/tanggal NULL dipertahankan. Master/rekening dengan NIS ganda tidak
menggandakan baris jurnal. Saldo header dan jurnal tidak dijumlahkan sebagai uang
baru. Semua periode dibaca, termasuk sebelum 2020, agar identitas kosong atau
rekening lama tidak hilang akibat filter angkatan.

## Hasil pembacaan sumber, 24 Oktober 2026

Instance `(localdb)\SppLegacyImporter_399abf4802bf`, tiga database berstatus ONLINE.
Seluruh query final menghasilkan dua result set per database tanpa error.

| Unit | Rekening tanpa pasangan master | Di antaranya NIS kosong | Jurnal tanpa pasangan master | Di antaranya NIS kosong |
| --- | ---: | ---: | ---: | ---: |
| SD | 145 | 1 | 3.498 | 2 |
| SMP | 24 | 1 | 848 | 1 |
| SMA | 198 | 1 | 4.959 | 1 |

Ini jumlah **baris sumber**, bukan orang atau kasus yang masih terbuka di MySQL.
Pada snapshot ini, semua jurnal tanpa master yang NIS-nya terisi masih memiliki
pasangan di `tabungan`; script juga menangkap kondisi tanpa rekening bila ada.
Rekening guru/karyawan/non-siswa dapat sah meskipun tidak ada di master siswa.
Hasil tidak membuktikan uang hilang atau identitas orang tidak pernah tercatat.

Query diuji pula dengan fixture CTE/VALUES yang hanya membaca: NIS dengan nol
awal, spasi/huruf berbeda, identitas kosong, nominal NULL, saldo nol, master dan
rekening duplikat, serta jurnal tanpa rekening. Tidak ada tabel fixture dibuat.
Hasil sumber final membutuhkan sekitar 0,11–0,41 detik per unit pada pengujian
lokal. Deduplikasi identitas dan batas memory grant per query menghindari
permintaan memori berlebihan pada instance LocalDB; tidak ada konfigurasi server
yang diubah. Rekap bukti tanpa identitas pribadi disimpan privat di
`C:/laragon/backups/spp-management-system/source_owner_audit_20261024/results.json`.

`tb_total`, `tb_masuk`, `tb_keluar`, dan `tbg` tidak digabung ke hasil utama.
Pemeriksaan rekening tambahan SD tersedia pada bagian 5
[audit rekening existing](../sql/sqlserver_audit_rekening_legacy.sql).
Tidak menjalankan migrasi/rekonsiliasi, perubahan database aplikasi, atau perubahan
`.gitignore` maupun file kerja pengguna lainnya.
