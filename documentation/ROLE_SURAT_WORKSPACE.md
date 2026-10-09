# Role Management dan Surat Orang Tua

## Role Management

Hanya Super Admin dapat membuka halaman dan membuat, mengatur status, atau mengganti password akun. Formulir dipisahkan menjadi Informasi Akun serta Akses & Keamanan. Unit pembuatan mengikuti formulir dan pembatasan sidebar; filter daftar tidak mengganti unit pembuatan.

Pencarian daftar memakai nama, username, dan role. Parameter GET `account_role[]`, `account_status[]`, serta `account_unit[]` menerima beberapa pilihan. Nilai tunggal tetap diterima, `*` berarti semua, dan `global` pada filter unit berarti akun Super Admin. Filter Unit hanya tersedia dalam cakupan Semua Unit. Nilai tidak sah ditolak dengan HTTP 400. Dalam satu filter berlaku OR, antarfilter berlaku AND.

Jumlah tab peran dihitung setelah pencarian, unit, dan status, sebelum pembatasan peran. Judul daftar menampilkan jumlah akun yang benar-benar tampil. Tab peran memilih satu peran; Semua menghapus pembatasan peran. Filter tetap terbawa setelah tindakan akun dan Muat Ulang.

Ketika unit operasional pada sidebar diganti, filter Unit daftar akun dibersihkan agar pilihan dari cakupan sebelumnya tidak terbawa ke unit baru.

## Pesan surat

Pesan tetap merupakan tambahan surat tunggakan, tepat setelah Jumlah Tunggakan dan sebelum paragraf penutup. Draf menggunakan snapshot siswa/tagihan dan berlaku dua jam sejak dibuat. Identitas siswa memakai pasangan unit dan NIS. Surat kepala sekolah tidak menggunakan editor ini.

Editor mendukung paragraf, tebal, miring, garis bawah, daftar bernomor, dan daftar berpoin. Pesan maksimal 2.000 karakter Unicode pada teks hasil normalisasi; HTML dibatasi 40.000 byte per pesan dan permintaan tetap dibatasi 8 MB. Server hanya mengizinkan `p`, `br`, `strong`, `em`, `u`, `ul`, `ol`, dan `li` tanpa atribut. Format lama berupa teks biasa tetap di-escape, sehingga teks `<b>` lama tidak ditafsirkan sebagai perintah format.

Endpoint POST `laporan/surat_orang_tua_draf.php` menggunakan token draf dan header `X-CSRF-Token`:

- `action: save` (bawaan), `draft`, `messages`: peta identitas siswa ke string lama atau objek `{format: "rich_text", html: "..."}`. Respons berisi `ok`, `saved`, dan pesan yang telah dinormalisasi pada `messages`.
- `action: apply_message`, `draft`, `source_key`, `message`, `targets`, `overwrite`: menyimpan sumber dan menyalin ke target secara atomik. `overwrite` bawaan `false`; pesan target yang sudah terisi dilewati. Respons berisi `ok`, `messages`, `updated`, dan `skipped`.

Seluruh identitas target divalidasi sebelum penyimpanan. Duplikasi target tidak menggandakan hasil. Target dari luar draf, sumber sebagai target, pesan kosong, atau daftar target kosong ditolak. Token milik sesi lain, unit berbeda, atau kedaluwarsa tidak diterima. Penerapan tidak memperpanjang masa berlaku draf.

Dialog menyediakan pencarian penerima, Pilih Semua (termasuk penerima di luar hasil pencarian), dan jumlah target. UI mengirim `overwrite: true`: pesan dan format seluruh target yang dipilih diganti, termasuk target yang sebelumnya sudah mempunyai pesan. Dialog menampilkan satu keterangan penggantian tanpa checkbox tambahan. Bawaan `false` pada API tetap tersedia untuk pemanggilan lama. Setelah disalin, pesan tiap siswa berdiri sendiri. Autosave mengirim perubahan per siswa; penyimpanan, pergantian penerima, penerapan massal, dan pratinjau menggunakan antrean yang sama. Editor dan pemilihan penerima terkunci selama penerapan. Jika gagal, isi editor dipertahankan untuk dicoba kembali.

Halaman penyusunan mempertahankan pencarian, identitas penerima, editor/format, batas karakter, tanggal tunggakan, serta tindakan Simpan Draf dan Pratinjau. Tips dan penjelasan berulang dihapus; tampilan pratinjau/PDF tidak berubah. Lihat [proteksi tarif dan verifikasi pesan 10 Oktober 2026](SPP_RATE_LOCK_PARENT_MESSAGES_20261010.md).

Pratinjau dan unduhan memakai endpoint PDF/Dompdf yang sama. Toolbar/thumbnail viewer mengikuti browser; Buka PDF tersedia sebagai alternatif. Pesan panjang dapat melanjutkan ke halaman berikutnya.

## Verifikasi

Pengujian menggunakan `db_spp_audit_authorization_20261007`; tindakan akun memakai pengaman salinan database dan akun sementara dibersihkan setelah pengujian.

- `parent_letter_rich_draft_test.php`: sanitasi, kompatibilitas lama, Unicode/batas pesan, salinan mandiri, perlindungan pesan lama, pembaruan atomik, pemilik/unit/masa berlaku, dan PDF panjang.
- `role_parent_workspace_browser_test.js`: hasil filter dibandingkan fixture akun database, mode dropdown, format, penerapan/penggantian, Escape/fokus, kegagalan simpan, akses peran, serta desktop/tablet/ponsel dan kedua tema pada empat cakupan unit.
- `role_parent_workspace_final_test.js`: filter gabungan/unit, pencarian label peran, reset, fallback tanpa JavaScript, menu tindakan akun, seluruh perintah format, pilihan sebagian/Pilih Semua/Batal, kegagalan penerapan, serta respons autosave terlambat.
- `admin_unit_accounts_http_test.php`: pembuatan/reset/status/login Admin unit serta penolakan akses lintas unit/non-Super Admin.
- `transaction_workflows_test.php`: kompatibilitas alur draf lama dan surat kepala sekolah.
- Isi pratinjau dan unduhan dibandingkan melalui ekstraksi teks PDF untuk SD, SMP, SMA, dan Semua Unit. Pemeriksaan integritas keuangan dilakukan sebelum/sesudah pengujian.

Tidak ada migrasi database, template permanen, perubahan perhitungan keuangan, commit, atau push.
