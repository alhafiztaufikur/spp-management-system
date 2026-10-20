<?php

final class SavingsBookBalanceMismatch extends RuntimeException
{
}

/**
 * Susun buku tabungan dari seluruh mutasi seorang siswa.
 * Nominal dihitung dalam sen agar pembandingan saldo tidak bergantung pada float.
 */
function savings_book_make(array $transactions, float $recordedBalance, ?array $opening = null, array $legacy = []): array
{
    usort($transactions, static function (array $a, array $b): int {
        $byDate = strcmp((string)$a['tanggal'], (string)$b['tanggal']);
        if ($byDate !== 0) return $byDate;
        $byType = (int)$a['urutan_mutasi'] <=> (int)$b['urutan_mutasi'];
        return $byType !== 0 ? $byType : (int)$a['id'] <=> (int)$b['id'];
    });

    $balance = 0;
    $entries = [];
    if($legacy){
        foreach($legacy as $transaction){$incoming=(int)round((float)$transaction['masuk']*100);$outgoing=(int)round((float)$transaction['keluar']*100);$balance+=$incoming-$outgoing;
            $entries[]=['number'=>count($entries)+1,'id'=>(int)$transaction['id'],'jenis'=>$incoming>0?'masuk':'keluar','tanggal'=>$transaction['tanggal'],'masuk'=>$incoming,'keluar'=>$outgoing,'saldo'=>$balance,'label'=>($transaction['origin']??'')==='adjustment'?'Penyesuaian Saldo Migrasi':'Legacy'];}
        if(!$opening||$balance!==(int)round((float)$opening['amount']*100))throw new SavingsBookBalanceMismatch('Histori Legacy berbeda dari saldo peralihan.');
    }
    if($opening){
        $balance=(int)round((float)$opening['amount']*100);
        $entries[]=['number'=>count($entries)+1,'tanggal'=>$opening['effective_date'],'masuk'=>0,'keluar'=>0,'saldo'=>$balance,'label'=>$legacy?'Saldo peralihan Legacy':'Saldo awal Legacy'];
    }
    foreach ($transactions as $transaction) {
        $incoming = (int)round((float)$transaction['masuk'] * 100);
        $outgoing = (int)round((float)$transaction['keluar'] * 100);
        $balance += $incoming - $outgoing;
        $entries[] = [
            'number' => count($entries) + 1,'id'=>(int)$transaction['id'],'jenis'=>(int)$transaction['urutan_mutasi']===0?'masuk':'keluar',
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

    $pages=[];
    foreach(array_chunk($entries,26) as $index=>$rows)$pages[]=['type'=>'ledger','number'=>$index+1,'rows'=>$rows];
    if(!$pages)$pages[]=['type'=>'ledger','number'=>1,'rows'=>[]];
    return ['pages'=>$pages,'sides'=>[],'entries'=>$entries,'balance'=>$balance];
}

function savings_book_money(int $cents): string
{
    return number_format($cents / 100, $cents % 100 === 0 ? 0 : 2, ',', '.');
}
