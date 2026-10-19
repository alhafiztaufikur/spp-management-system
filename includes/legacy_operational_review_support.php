<?php
/** Source suggestions never replace the source archive or a saved decision. */
function lop_alumni_year(string $code): ?int {
    $code=mb_strtoupper(trim($code));
    if(preg_match('/^(?:ALUMNI|[AB])\s*(20[0-9]{2})$/D',$code,$m))return (int)$m[1];
    if(preg_match('/^[AB]([0-9]{2})([0-9]{2})$/D',$code,$m)&&(int)$m[2]===((int)$m[1]+1)%100)return 2000+(int)$m[2];
    return null;
}
function lop_testing_identity(array $raw): bool {
    $values=[trim((string)legacy_source_identity($raw)),trim((string)($raw['NAMA']??''))];
    foreach($values as $v)if(preg_match('/^(?:TEST|TESTING|DUMMY)(?:[\s_-]*[0-9]*)?$/Di',$v))return true;
    return false;
}
function lop_bank_summary(array $rows,array $fix=[]): array {
    $main=array_values(array_filter($rows,fn($r)=>in_array($r['table'],['tabungan','transaksi_m','transaksi_k'],true)));
    $rec=$main?lf_reconcile($main):null;$extra=0;
    foreach($rows as $r)if(in_array($r['table'],['tb_total','tbg'],true))$extra++;
    return ['accounts'=>$rec['accounts']??0,'additional_accounts'=>$extra,'source_balance'=>$rec['balance']??null,
        'journal_balance'=>$rec?lf_money(lf_cents($rec['incoming'])-lf_cents($rec['outgoing'])):null,
        'fixed_balance'=>!empty($fix['confirmed'])?($fix['amount']??null):null,
        'status'=>$rec?($rec['ready']?'Cocok':'Perlu diperiksa'):'Tidak tercatat'];
}
function lop_effective_bank_rows(array $rows,array $cfg): array {
    foreach($rows as &$row)if(in_array($row['table'],['transaksi_m','transaksi_k'],true)&&isset($cfg['op_journals'][$row['row_key']])){
        $fix=$cfg['op_journals'][$row['row_key']];
        if(($fix['confirmed']??false)!==true||!is_string($fix['evidence']??null)||mb_strlen(trim($fix['evidence']))<10||mb_strlen($fix['evidence'])>1000)throw new DomainException('Sahkan bukti koreksi jurnal sumber.');
        $row['raw']['TANGGAL']=lop_date($fix['date']??null);$row['raw']['MASUK']=lf_money(lf_cents($fix['incoming']??null));$row['raw']['KELUAR']=lf_money(lf_cents($fix['outgoing']??null));
    }unset($row);return $rows;
}
/** A FIX only resolves a numeric mismatch. Invalid/ambiguous journals stay held. */
function lop_fixed_bank(array $rows,?string $snapshot,array $fix): array {
    $bank=lf_reconcile($rows,$snapshot);$bank['source_balance']=$bank['balance'];$bank['adjustment']='0.00';
    // A unique, empty zero account has no historical movement to reconcile.
    if($bank['accounts']===1&&$bank['journals']===0&&$bank['balance']==='0.00'){
        $bank['issues']=array_values(array_filter($bank['issues'],fn($s)=>$s!=='Jurnal sumber tidak lengkap.'));
        $bank['ready']=!$bank['issues'];
    }
    if(!$fix)return $bank;
    if(($fix['confirmed']??false)!==true||!is_string($fix['evidence']??null)||mb_strlen(trim($fix['evidence']))<10||mb_strlen($fix['evidence'])>1000)throw new DomainException('Sahkan saldo FIX dan isi bukti minimal 10 karakter.');
    $amount=lf_cents($fix['amount']??null);
    $issues=array_values(array_filter($bank['issues'],fn($s)=>$s!=='Saldo sumber berbeda dengan jumlah jurnal.'));
    $bank['balance']=lf_money($amount);$bank['adjustment']=lf_money($amount-lf_cents($bank['incoming'])+lf_cents($bank['outgoing']));
    $bank['issues']=$issues;$bank['ready']=!$issues;return $bank;
}
