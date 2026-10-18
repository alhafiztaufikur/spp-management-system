# Pustaka unduhan gambar transaksi

Disimpan lokal; halaman transaksi tidak mengambil kode dari CDN saat digunakan.

| Pustaka | Versi | Lisensi lokal | SHA-256 JavaScript |
| --- | --- | --- | --- |
| html2canvas | 1.4.1 | `html2canvas/LICENSE` (MIT) | `e87e550794322e574a1fda0c1549a3c70dae5a93d9113417a429016838eab8cb` |
| JSZip | 3.10.1 | `jszip/LICENSE.markdown` (MIT / GPLv3) | `acc7e41455a80765b5fd9c7ee1b8078a6d160bbbca455aeae854de65c947d59e` |

Sumber rilis: npm melalui jsDelivr, paket `html2canvas@1.4.1/dist/html2canvas.min.js` dan `jszip@3.10.1/dist/jszip.min.js`. Salinan lisensi berasal dari paket dengan versi yang sama.

Dokumentasi resmi: [html2canvas](https://html2canvas.hertzen.com/configuration), [JSZip](https://stuk.github.io/jszip/documentation/examples/download-zip-file.html).

`assets/js/receipt_image.js` memakai pembagi halaman `receipt_print.js`, membentuk PNG 2550 × 1650 piksel secara bergiliran, kemudian melepaskan canvas dan URL objek. Beberapa halaman disatukan sebagai ZIP tanpa kompresi ulang PNG. Kegagalan ditampilkan di toolbar dan dapat dicoba kembali.
