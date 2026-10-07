<?php
require_once __DIR__ . '/../includes/savings_book.php';

function book_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function book_transaction(int $id, string $date, int $incoming, int $outgoing, int $type): array
{
    return ['id' => $id, 'tanggal' => $date, 'masuk' => $incoming, 'keluar' => $outgoing, 'urutan_mutasi' => $type];
}

$empty = savings_book_make([], 0);
book_assert(count($empty['pages']) === 4 && count($empty['sides']) === 2, 'Buku tanpa transaksi harus berisi satu lembar A5 dua sisi.');
book_assert($empty['pages'][1]['type'] === 'ledger' && $empty['pages'][2]['type'] === 'ledger', 'Buku kosong harus menyediakan dua halaman transaksi.');
book_assert($empty['sides'][0]['left'] === 3 && $empty['sides'][0]['right'] === 0, 'Sampul tidak berada pada sisi luar buku.');
book_assert($empty['sides'][1]['left'] === 1 && $empty['sides'][1]['right'] === 2, 'Halaman dalam tidak berurutan setelah dilipat.');

$mixed = savings_book_make([
    book_transaction(9, '2026-09-29 09:00:00', 0, 20000, 1),
    book_transaction(3, '2026-09-29 09:00:00', 30000, 0, 0),
    book_transaction(1, '2026-09-28 11:00:00', 10000, 0, 0),
    book_transaction(2, '2026-09-29 09:00:00', 5000, 0, 0),
], 25000);
book_assert(array_column($mixed['entries'], 'saldo') === [1000000, 1500000, 4500000, 2500000], 'Urutan waktu, jenis, ID, atau saldo berjalan keliru.');
book_assert($mixed['pages'][1]['rows'][4] === null, 'Baris setelah riwayat harus kosong untuk ditulis tangan.');
book_assert(array_column($mixed['entries'], 'number') === [1,2,3,4], 'Nomor mutasi harus berurutan.');

$many = [];
for ($i = 1; $i <= 37; $i++) {
    $many[] = book_transaction($i, sprintf('2026-09-29 09:%02d:00', $i), 1000, 0, 0);
}
$large = savings_book_make($many, 37000);
book_assert($large['pages'][1]['rows'][17]['saldo'] === 1800000, 'Halaman pertama harus memuat tepat 18 transaksi.');
book_assert($large['pages'][2]['rows'][0]['saldo'] === 1900000, 'Transaksi ke-19 harus berada pada halaman berikutnya.');
book_assert($large['pages'][2]['rows'][0]['number'] === 19, 'Nomor harus berlanjut antarhalaman.');
book_assert($large['pages'][3]['rows'][0]['saldo'] === 3700000, 'Riwayat yang melebihi dua halaman tidak lengkap.');
book_assert(count($large['pages']) % 4 === 0, 'Jumlah halaman buku harus kelipatan empat.');
book_assert(count($large['pages']) - 2 - (int)ceil(37 / 18) >= 2, 'Kurang dari dua halaman kosong setelah riwayat.');
$printedPageNumbers = [];
foreach ($large['sides'] as $side) {
    $printedPageNumbers[] = $side['left'];
    $printedPageNumbers[] = $side['right'];
}
sort($printedPageNumbers);
book_assert($printedPageNumbers === range(0, count($large['pages']) - 1), 'Ada halaman yang hilang atau tercetak dua kali.');

try {
    savings_book_make([book_transaction(1, '2026-09-29 10:00:00', 1000, 0, 0)], 900);
    throw new RuntimeException('Selisih saldo tidak diblokir.');
} catch (RuntimeException $error) {
    book_assert(str_contains($error->getMessage(), 'tidak sama'), $error->getMessage());
}

echo "OK: buku tabungan kosong, saldo berjalan, urutan transaksi, halaman lipat, dan selisih saldo tervalidasi.\n";
