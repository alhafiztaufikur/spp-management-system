<?php
require_once __DIR__.'/legacy_migration_automation.php';
/** Authorized migration policy: decisions are derived again from immutable source rows. */
function lop_reference_record(array $record,string $kind,string $reason): array {
    $amounts=[];foreach(legacy_source_money_columns($record) as $column)$amounts[$column]=legacy_source_money_value($record,$column);
    return ['row_key'=>$record['row_key'],'source_table'=>$record['table'],'identity'=>trim((string)legacy_source_identity($record['raw'])),'date'=>legacy_source_record_date($record),'amounts'=>$amounts,'kind'=>$kind,'reason'=>$reason];
}
function lop_separate_record(array $record,array $cfg=[]): ?array {
    if(!in_array($record['table'],['bayar','bayar_du','tabungan','transaksi_m','transaksi_k','tb_total','tb_masuk','tb_keluar','tbg'],true))return null;
    if(trim((string)legacy_source_identity($record['raw']))==='')return lop_reference_record($record,'unidentified','Menunggu pemilik; sumber tetap tersimpan terpisah, tanpa saldo atau kas aktif.');
    if($record['table']==='bayar_du')try{if(lf_cents($record['raw']['jumlah']??null,true)<0)return lop_reference_record($record,'du_anomaly','Referensi Anomali DU; nilai negatif asli bukan penerimaan, pengembalian uang atau tagihan.');}catch(DomainException $e){}
    if($record['table']==='bayar_du'&&lma_enabled($cfg)){$matched=isset($cfg['_du_matches'][lma_du_key($record)]);return lop_reference_record($record,$matched?'du_reconciled':'du_unresolved',$matched?'Rekap DU telah tercakup pada pembayaran asal; tidak menambah penerimaan.':'Rekap DU belum mempunyai pasangan pembayaran yang meyakinkan; nominal sumber tetap diarsipkan.');}
    if(in_array($record['table'],['transaksi_m','transaksi_k'],true)&&empty($cfg['op_journals'][$record['row_key']]))try{
        if(lf_cents($record['raw']['MASUK']??'0')===0&&lf_cents($record['raw']['KELUAR']??'0')===0)return lop_reference_record($record,'zero_journal','Jurnal nol disimpan sebagai referensi; tidak ada perpindahan uang.');
    }catch(DomainException $e){}
    return null;
}
/** Decimal-string tolerance <= Rp0.000001, and only if cents exactly match the journals. */
function lop_precision_balance(mixed $value,int $journal): ?string {
    if(!is_string($value)||!preg_match('/^([0-9]{1,13})\.([0-9]{3,})$/D',$value,$m))return null;
    $head=substr($m[2],0,2);$tail=substr($m[2],2);$size=max(4,strlen($tail));$tail=str_pad($tail,$size,'0');$up=$tail[0]>='5';
    if(!$up&&strcmp($tail,str_pad('0001',$size,'0'))>0)return null;
    if($up&&strcmp($tail,str_pad('9999',$size,'0'))<0)return null;
    try{$cents=lf_cents($m[1].'.'.$head)+($up?1:0);}catch(DomainException $e){return null;}
    return $cents===$journal?lf_money($cents):null;
}
/** Existing explicit FIX always wins. Only a unique header and valid journals can resolve. */
function lop_resolve_bank(array $rows,array $fix,?string $snapshot,string $identity,array &$resolutions,?int $sourceJournal=null): array {
    $headers=[];$bankRows=[];foreach($rows as $key=>$r)if(in_array($r['table'],['tabungan','transaksi_m','transaksi_k'],true)){$bankRows[]=$r;if($r['table']==='tabungan')$headers[]=$key;}
    if(count($headers)!==1)return [$rows,$fix];$key=$headers[0];$original=$rows[$key]['raw']['SALDO']??null;
    $journal=lf_reconcile(array_values(array_filter($bankRows,fn($r)=>$r['table']!=='tabungan')),$snapshot);
    try{$normalized=lop_precision_balance($original,$sourceJournal??(lf_cents($journal['incoming'])-lf_cents($journal['outgoing'])));}catch(DomainException $e){$normalized=null;}
    if($normalized!==null){$rows[$key]['raw']['SALDO']=$normalized;$resolutions['precision:'.$identity]=['kind'=>'precision_balance','identity'=>$identity,'row_key'=>$rows[$key]['row_key'],'original'=>$original,'amount'=>$normalized,'reason'=>'Normalisasi residu presisi sesuai jurnal, toleransi maksimal Rp0.000001; sumber asli tidak diubah.'];}
    if($fix)return [$rows,$fix];
    $bankRows=array_values(array_filter($rows,fn($r)=>in_array($r['table'],['tabungan','transaksi_m','transaksi_k'],true)));$bank=lf_reconcile($bankRows,$snapshot);
    if($bank['accounts']!==1||$bank['balance']===null||!in_array('Saldo sumber berbeda dengan jumlah jurnal.',$bank['issues'],true))return [$rows,$fix];
    $candidate=['amount'=>$bank['balance'],'confirmed'=>true,'evidence'=>'Pemeriksaan sistem: saldo akhir mengikuti kolom SALDO backup sesuai kebijakan peralihan yang disahkan; jurnal asli tetap tersimpan.','system_resolved'=>true];
    if(!lop_fixed_bank($bankRows,$snapshot,$candidate)['ready'])return [$rows,$fix];
    $resolutions['balance:'.$identity]=['kind'=>'source_balance_fix','identity'=>$identity,'source_balance'=>$bank['balance'],'journal_balance'=>lf_money(lf_cents($bank['incoming'])-lf_cents($bank['outgoing'])),'amount'=>$candidate['amount'],'reason'=>$candidate['evidence']];
    return [$rows,$candidate];
}
/** Promote a unique, reconciled extra ledger only when no primary ledger exists. */
function lop_promote_accounts(array $records,?string $snapshot,array &$resolutions): array {
    $students=[];$primary=[];$extras=[];
    foreach($records as $r){$id=lful_identity(legacy_source_identity($r['raw']));if($r['table']==='siswa')$students[$id][]=$r;if(in_array($r['table'],['tabungan','transaksi_m','transaksi_k'],true))$primary[$id]=true;if(in_array($r['table'],['tb_total','tb_masuk','tb_keluar'],true))$extras[$id][]=$r;}
    $promoted=[];foreach(lful_additional_savings($records,$snapshot) as $ledgerKey=>$ledger){$id=$ledger['identity'];
        if($id==='Tanpa identitas siswa'||isset($primary[$id])||count($students[$id]??[])!==1||legacy_source_identity_issues($students[$id][0]['raw'])||!$ledger['reconciliation']['ready'])continue;
        $promoted[$id]=true;$resolutions['promote:'.$id]=['kind'=>'promoted_account','identity'=>$id,'ledger'=>$ledgerKey,'fingerprint'=>$ledger['fingerprint'],'amount'=>$ledger['reconciliation']['balance'],'reason'=>'Identitas tunggal; rekening utama tidak tersedia; saldo rekening tambahan cocok dengan jurnal.'];
    }
    if(!$promoted)return $records;
    foreach($records as &$r){$id=lful_identity(legacy_source_identity($r['raw']));if(isset($promoted[$id])&&isset(['tb_total'=>1,'tb_masuk'=>1,'tb_keluar'=>1][$r['table']])){$r['table']=['tb_total'=>'tabungan','tb_masuk'=>'transaksi_m','tb_keluar'=>'transaksi_k'][$r['table']];$r['reference_only']=false;}}unset($r);return $records;
}
function lop_resolved_source_rows(array $records,array $cfg,?string $snapshot): array {
    $history=[];$references=[];$resolutions=[];$effective=[];
    foreach($records as $r){$old=lop_unowned_history($r);$separate=$old?null:lop_separate_record($r,$cfg);if($old)$history[$r['row_key']]=$old;elseif($separate)$references[$r['row_key']]=$separate;else $effective[]=$r;}
    $effective=lop_resolve_zero_header($effective,$snapshot,$resolutions);return [lop_promote_accounts($effective,$snapshot,$resolutions),$history,$references,$resolutions];
}
function lop_source_union(array ...$sets): array {
    $rows=[];foreach($sets as $set)foreach($set as $row)$rows[$row['row_key']]=$row;return array_values($rows);
}
/** A redundant zero header carries no extra funds when one unique owner's valid ledger proves the other header. */
function lop_resolve_zero_header(array $records,?string $snapshot,array &$resolutions): array {
    $owners=[];$banks=[];$remove=[];
    foreach($records as $r){$identity=lful_identity(legacy_source_identity($r['raw']));if($identity==='')continue;if($r['table']==='siswa')$owners[$identity][]=$r;if(in_array($r['table'],['tabungan','transaksi_m','transaksi_k'],true))$banks[$identity][]=$r;}
    foreach($banks as $identity=>$rows){
        if(count($owners[$identity]??[])!==1||legacy_source_identity_issues($owners[$identity][0]['raw']))continue;
        $headers=array_values(array_filter($rows,fn($r)=>$r['table']==='tabungan'));if(count($headers)!==2)continue;
        try{$a=lf_cents($headers[0]['raw']['SALDO']??null);$b=lf_cents($headers[1]['raw']['SALDO']??null);}catch(DomainException $e){continue;}
        if(!(($a===0&&$b>0)||($b===0&&$a>0)))continue;
        $zero=$headers[$a===0?0:1];$keep=$headers[$a===0?1:0];
        $candidate=array_values(array_filter($rows,fn($r)=>$r['row_key']!==$zero['row_key']));$bank=lf_reconcile($candidate,$snapshot);
        if(!$bank['ready'])continue;
        $identity=(string)$identity;$remove[$zero['row_key']]=true;
        $resolutions['zero-header:'.$identity]=['kind'=>'duplicate_zero_account','identity'=>$identity,'amount'=>$bank['balance'],'selected_row_key'=>$keep['row_key'],'archived_row_key'=>$zero['row_key'],'selected_source'=>$keep['raw'],'archived_source'=>$zero['raw'],'reason'=>'Satu pemilik sumber unik; dua catatan saldo berisi nol dan saldo positif. Seluruh jurnal valid tepat cocok dengan saldo positif. Catatan nol tetap diarsipkan dan tidak menambah rekening atau saldo.'];
    }
    return $remove?array_values(array_filter($records,fn($r)=>!isset($remove[$r['row_key']]))):$records;
}
