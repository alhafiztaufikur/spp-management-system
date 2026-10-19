<?php
/** Explicit preview only: never called by automatic source preparation. */
function lop_precision_cents(string $value): string {
    if(!preg_match('/^([0-9]{1,13})\.([0-9]{3,})$/D',$value,$m))throw new DomainException('Pecahan jurnal tidak dapat diusulkan.');
    $cents=lf_cents($m[1].'.'.substr($m[2],0,2));
    if($m[2][2]>='5')$cents++;
    $amount=lf_money($cents);lf_cents($amount);return $amount;
}
/** Bind all proposed changes to the source rows and the versioned confirmation. */
function lop_savings_fix_proposal(array $rows,array $cfg,array $fix,?string $snapshot,string $identity): array {
    foreach($rows as $r)if(lful_identity(legacy_source_identity($r['raw']))!==$identity)throw new DomainException('Pemilik rekening sumber tidak sesuai.');
    $headers=array_values(array_filter($rows,fn($r)=>$r['table']==='tabungan'));
    if(count($headers)!==1)throw new DomainException('Rekening sumber tidak unik atau tidak tersedia.');
    $changes=[];$patch=[];$effective=lop_effective_bank_rows($rows,$cfg);
    foreach($effective as $r){
        if(!in_array($r['table'],['transaksi_m','transaksi_k'],true)||isset($cfg['op_journals'][$r['row_key']]))continue;
        $column=$r['table']==='transaksi_m'?'MASUK':'KELUAR';$value=$r['raw'][$column]??null;
        if(!is_string($value)||!preg_match('/^[0-9]{1,13}\.[0-9]{3,}$/D',$value))continue;
        try{lf_cents($value);continue;}catch(DomainException $e){}
        $amount=lop_precision_cents($value);
        $patch[$r['row_key']]=['date'=>lop_date($r['raw']['TANGGAL']??null),'incoming'=>$r['table']==='transaksi_m'?$amount:lf_money(lf_cents($r['raw']['MASUK']??'0')),'outgoing'=>$r['table']==='transaksi_k'?$amount:lf_money(lf_cents($r['raw']['KELUAR']??'0')),'evidence'=>$fix['evidence']??'','confirmed'=>true,'precision_source'=>['column'=>$column,'raw'=>$value,'policy'=>'confirmed_half_up_cents_v1']];
        $changes[]=['row_key'=>$r['row_key'],'date'=>$r['raw']['TANGGAL']??null,'column'=>$column,'original'=>$value,'amount'=>$amount];
    }
    $raw=$headers[0]['raw']['SALDO']??null;
    if(is_string($raw)&&preg_match('/^[0-9]{1,13}\.[0-9]{3,}$/D',$raw))try{lf_cents($raw);}catch(DomainException $e){
        $fix['source_balance_override']=['row_key'=>$headers[0]['row_key'],'raw'=>$raw];
    }
    $updated=$cfg;$updated['op_journals']=array_replace($cfg['op_journals']??[],$patch);
    $bank=lop_fixed_bank(lop_effective_bank_rows($rows,$updated),$snapshot,$fix);
    if(!$bank['ready'])throw new DomainException(implode(' ',$bank['issues']));
    return ['fix'=>$fix,'journals'=>$patch,'journal_changes'=>$changes,'bank'=>$bank];
}
function lop_savings_fix_for_job(array $job,array $cfg,string $identity,array $fix): array {
    [$rows,$manifest]=lop_review_source($job,$identity);
    [$rows]=lop_resolved_source_rows($rows,$cfg,$manifest['source_date']);$owners=[];$bank=[];
    foreach($rows as $r)if(lful_identity(legacy_source_identity($r['raw']))===$identity){
        if($r['table']==='siswa')$owners[]=$r['raw'];
        if(in_array($r['table'],['tabungan','transaksi_m','transaksi_k'],true))$bank[]=$r;
    }
    if(count($owners)!==1)throw new DomainException('Pemilik rekening tidak ditemukan atau tidak unik.');
    return lop_savings_fix_proposal($bank,$cfg,$fix,$manifest['source_date'],$identity)+['name'=>$owners[0]['NAMA'],'source'=>$manifest['rows_sha256']];
}
