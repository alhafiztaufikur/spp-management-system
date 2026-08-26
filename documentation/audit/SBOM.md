# Software Bill of Materials SistemSPP

Snapshot: 26 Agustus 2026  
Sumber: `composer.lock` melalui `composer show --locked --no-dev --format=json`  
Scope: dependency PHP produksi; tidak mencakup XAMPP/OS/browser yang dikelola sebagai platform deployment

| Package | Versi lock | Direct | Lisensi dari metadata Composer | Source |
|---|---:|:---:|---|---|
| `dompdf/dompdf` | `3.1.6` | Ya | LGPL-2.1 | `https://github.com/dompdf/dompdf/tree/v3.1.6` |
| `dompdf/php-font-lib` | `1.0.2` | Tidak | LGPL-2.1-or-later | `https://github.com/dompdf/php-font-lib/tree/1.0.2` |
| `dompdf/php-svg-lib` | `1.0.2` | Tidak | LGPL-3.0-or-later | `https://github.com/dompdf/php-svg-lib/tree/1.0.2` |
| `masterminds/html5` | `2.10.1` | Tidak | MIT | `https://github.com/Masterminds/html5-php/tree/2.10.1` |
| `sabberworm/php-css-parser` | `9.4.0` | Tidak | MIT | `https://github.com/MyIntervals/PHP-CSS-Parser/tree/v9.4.0` |
| `thecodingmachine/safe` | `2.5.0` | Tidak | MIT | `https://github.com/thecodingmachine/safe/tree/v2.5.0` |

`composer validate --strict`, `composer audit --locked --no-dev`, dan `composer check-platform-reqs --no-dev` telah lulus pada lingkungan audit lokal. Pemeriksaan advisory harus diulang pada CI/host berjaringan saat commit/tag release dibuat karena status advisory dapat berubah. Paket produksi wajib dibangun dari lockfile dan menyertakan notice lisensi yang relevan; tabel ini adalah inventaris teknis, bukan nasihat hukum.
