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
book_assert(count($empty['pages'])===1&&$empty['sides']===[],'Buku kosong harus tanpa sampul atau imposisi lipat.');
book_assert($empty['pages'][0]['rows']===[],'Buku kosong tidak menambah baris kosong.');

$mixed = savings_book_make([
    book_transaction(9, '2026-09-29 09:00:00', 0, 20000, 1),
    book_transaction(3, '2026-09-29 09:00:00', 30000, 0, 0),
    book_transaction(1, '2026-09-28 11:00:00', 10000, 0, 0),
    book_transaction(2, '2026-09-29 09:00:00', 5000, 0, 0),
], 25000);
book_assert(array_column($mixed['entries'], 'saldo') === [1000000, 1500000, 4500000, 2500000], 'Urutan waktu, jenis, ID, atau saldo berjalan keliru.');
book_assert(count($mixed['pages'][0]['rows'])===4,'Hanya mutasi yang tersimpan boleh ditampilkan.');
book_assert(array_column($mixed['entries'], 'number') === [1,2,3,4], 'Nomor mutasi harus berurutan.');

$many = [];
for ($i = 1; $i <= 37; $i++) {
    $many[] = book_transaction($i, sprintf('2026-09-29 09:%02d:00', $i), 1000, 0, 0);
}
$large = savings_book_make($many, 37000);
book_assert(count($large['pages'])===2,'Kelompok data harus memuat semua mutasi tanpa sampul.');
book_assert($large['pages'][0]['rows'][25]['number']===26&&$large['pages'][1]['rows'][0]['number']===27,'Nomor berlanjut antar kelompok data.');
$numbers=[];foreach($large['pages'] as $page)$numbers=array_merge($numbers,array_column($page['rows'],'number'));
book_assert($numbers===range(1,37),'Mutasi tidak boleh hilang atau diduplikasi.');

try {
    savings_book_make([book_transaction(1, '2026-09-29 10:00:00', 1000, 0, 0)], 900);
    throw new RuntimeException('Selisih saldo tidak diblokir.');
} catch (RuntimeException $error) {
    book_assert(str_contains($error->getMessage(), 'tidak sama'), $error->getMessage());
}

echo "OK: buku tabungan kosong, saldo berjalan, urutan transaksi, tabel tanpa sampul, dan selisih saldo tervalidasi.\n";
