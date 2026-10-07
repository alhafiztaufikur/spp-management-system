<?php

final class SavingsBookBalanceMismatch extends RuntimeException
{
}

/**
 * Susun buku tabungan dari seluruh mutasi seorang siswa.
 * Nominal dihitung dalam sen agar pembandingan saldo tidak bergantung pada float.
 */
function savings_book_make(array $transactions, float $recordedBalance): array
{
    usort($transactions, static function (array $a, array $b): int {
        $byDate = strcmp((string)$a['tanggal'], (string)$b['tanggal']);
        if ($byDate !== 0) return $byDate;
        $byType = (int)$a['urutan_mutasi'] <=> (int)$b['urutan_mutasi'];
        return $byType !== 0 ? $byType : (int)$a['id'] <=> (int)$b['id'];
    });

    $balance = 0;
    $entries = [];
    foreach ($transactions as $transaction) {
        $incoming = (int)round((float)$transaction['masuk'] * 100);
        $outgoing = (int)round((float)$transaction['keluar'] * 100);
        $balance += $incoming - $outgoing;
        $entries[] = [
            'number' => count($entries) + 1,
            'tanggal' => (string)$transaction['tanggal'],
            'masuk' => $incoming,
            'keluar' => $outgoing,
            'saldo' => $balance,
        ];
    }

    $storedBalance = (int)round($recordedBalance * 100);
    if ($balance !== $storedBalance) {
        throw new SavingsBookBalanceMismatch('Saldo tabungan tersimpan tidak sama dengan riwayat transaksi. Hubungi administrator untuk memeriksa data sebelum mencetak buku.');
    }

    // Sampul depan dan belakang menambah dua halaman. Buku lipat harus kelipatan empat.
    $ledgerPageCount = max(2, (int)ceil(count($entries) / 18) + 2);
    while (($ledgerPageCount + 2) % 4 !== 0) $ledgerPageCount++;

    $pages = [['type' => 'front']];
    for ($page = 0; $page < $ledgerPageCount; $page++) {
        $pages[] = [
            'type' => 'ledger',
            'number' => $page + 1,
            'rows' => array_pad(array_slice($entries, $page * 18, 18), 18, null),
        ];
    }
    $pages[] = ['type' => 'back'];

    $sides = [];
    $total = count($pages);
    for ($sheet = 0; $sheet < $total / 4; $sheet++) {
        $sides[] = ['left' => $total - 1 - 2 * $sheet, 'right' => 2 * $sheet, 'sheet' => $sheet + 1, 'face' => 'front'];
        $sides[] = ['left' => 2 * $sheet + 1, 'right' => $total - 2 - 2 * $sheet, 'sheet' => $sheet + 1, 'face' => 'back'];
    }

    return ['pages' => $pages, 'sides' => $sides, 'entries' => $entries, 'balance' => $balance];
}

function savings_book_money(int $cents): string
{
    return number_format($cents / 100, $cents % 100 === 0 ? 0 : 2, ',', '.');
}
