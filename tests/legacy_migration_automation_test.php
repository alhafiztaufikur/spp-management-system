<?php
/** Pure regression suite: no connection, bootstrap, filesystem jobs or database writes. */
require_once __DIR__.'/legacy_operational_pure_test.php';
require_once __DIR__.'/../includes/legacy_billing.php';
function ma_row(string $table,array $raw,string $suffix=''): array {
    return ['table'=>$table,'raw'=>$raw,'row_key'=>hash('sha256',lf_json([$table,$raw,$suffix])),'reference_only'=>in_array($table,['lain','tb_total','tb_masuk','tb_keluar','bayar_backup'],true),'money_columns'=>array_values(array_intersect(array_keys($raw),['jumlah','U_SPP','U_KEGIATAN','U_LAIN','JUMLAH1','JUMLAH2','JUMLAH3','JUMLAH4','SALDO','MASUK','KELUAR']))];
}
function ma_cfg(array $rows): array {return op_config($rows)+['automation_version'=>LMA_VERSION];}
function ma_payment(string $nis,string $code,string $amount,string $date,string $class='7A'): array {
    return ma_row('bayar',['NO_INDUK'=>$nis,'LAIN_LAIN1'=>$code,'JUMLAH1'=>$amount,'U_LAIN'=>'0','TGL_BYR'=>$date,'BULAN'=>substr($date,5,2),'TAHUN'=>substr($date,0,4),'KELAS'=>$class]);
}
$student=op_raw('212200001','1A');
$rows=[$student];
foreach(['2026-04-15','2026-05-15'] as $date)$rows[]=ma_row('bayar',['NO_INDUK'=>'212200001','KELAS'=>'3A','U_KEGIATAN'=>'500','U_SPP'=>'100','TGL_BYR'=>$date,'BULAN'=>substr($date,5,2),'TAHUN'=>'2026','th_ajaran'=>'2026/2027','kelas_du'=>'4']);
foreach(['a','b'] as $suffix)$rows[]=ma_row('bayar_du',['no_induk'=>'212200001','kelas'=>'4','th_ajaran'=>'2026/2027','jumlah'=>'1000'],$suffix);
$original=$rows;$cfg=ma_cfg($rows);$p=lop_prepare($rows,$cfg,1,'2026-10-17');
lft_assert(count($p['payments'])===2&&$p['summary']['payment_total']==='1200.00'&&$p['summary']['du_reconciled']===2,'DU recap or repeated recap counted as cash');
foreach($p['payments'] as $payment){lft_assert(count($payment['items'])===2,'Mixed receipt split');foreach($payment['items'] as $item)if($item['column']==='U_KEGIATAN')lft_assert($item['category']==='daftar_ulang'&&lma_item_period($item,$payment['period'])==='2026/2027','DU prepayment assigned to monthly academic year');}
lft_assert($rows===$original&&$p===lop_prepare($rows,$cfg,1,'2026-10-17'),'Rules mutate source or are nondeterministic');
require_once __DIR__.'/../includes/legacy_operational_history.php';$keys=lop_history_reference_keys(['mode'=>'operational','automatic_checks'=>['rules_version'=>LOP_VERSION,'references'=>$p['references'],'accepted_history'=>$p['history']],'summary'=>$p['summary']]);lft_assert(is_array($keys)&&count($keys)===2,'New DU references unavailable after restoring published archives');
$cfg['students']['212200001']['coverage']=[['category'=>'daftar_ulang','period'=>'2026/2027','principal'=>'1000','disposition'=>'settled','evidence'=>'Rekap dan dua cicilan asal yang cocok tepat.']];
lft_assert(count(lop_prepare($rows,$cfg,1,'2026-10-17')['owners']['212200001']['decision']['coverage'])===1,'Mixed receipt DU credit lost during coverage');
$changed=$rows;$changed[3]['raw']['jumlah']='1100';$p=lop_prepare($changed,ma_cfg($changed),1,'2026-10-17');lft_assert($p['summary']['du_unresolved']===2&&$p['payments'][0]['items'][0]['category']!=='daftar_ulang','Different recap values guessed');
$changed=$rows;$changed[3]['raw']['th_ajaran']='';$changed[4]['raw']['th_ajaran']='';$p=lop_prepare($changed,ma_cfg($changed),1,'2026-10-17');lft_assert($p['summary']['payment_total']==='1200.00'&&$p['summary']['du_unresolved']===2,'Unknown DU year fabricated');

$rows=[$student,ma_row('lain',['KODE'=>'0022','NAMA'=>'Buku Ummi']),ma_row('lain',['KODE'=>'021','NAMA'=>'Basket'])];
foreach(['212200002','212200003','212200004'] as $i=>$nis){$rows[]=op_raw($nis,'1A');$rows[]=ma_payment($nis,'0022','22000','2026-02-'.(26+$i));}
$rows[]=ma_payment('212200001','0021','22000','2026-02-25');
$p=lop_prepare($rows,ma_cfg($rows),1,'2026-10-17');$target=array_values(array_filter($p['payments'],fn($r)=>$r['identity']==='212200001'))[0]['items'][0];
lft_assert($target['label']==='Buku Ummi'&&$target['migration']['status']==='inferred'&&$target['migration']['source_code']==='0021','Inference missing or numeric zero stripping chose Basket');
$insufficient=$rows;unset($insufficient[8]);$check=lop_prepare(array_values($insufficient),ma_cfg(array_values($insufficient)),1,'2026-10-17');$checkItem=array_values(array_filter($check['payments'],fn($r)=>$r['identity']==='212200001'))[0]['items'][0];lft_assert($checkItem['migration']['status']==='unresolved','Fewer than three distinct supporting students accepted');
$rows[]=ma_payment('212200002','021','22000','2026-02-25');$p=lop_prepare($rows,ma_cfg($rows),1,'2026-10-17');$target=array_values(array_filter($p['payments'],fn($r)=>$r['identity']==='212200001'))[0]['items'][0];
lft_assert(str_starts_with($target['category'],'histori:')&&$target['migration']['status']==='unresolved','Competing evidence silently discarded');
$rows=[$student,ma_row('lain',['KODE'=>'0016','NAMA'=>'Basket']),ma_row('lain',['KODE'=>'0016','NAMA'=>'Pendalaman Materi']),ma_payment('212200001','0016','60000','2017-08-22')];
$p=lop_prepare($rows,ma_cfg($rows),1,'2026-10-17');lft_assert($p['summary']['fee_unresolved']===1&&str_starts_with($p['payments'][0]['items'][0]['category'],'histori:')&&!str_contains($p['payments'][0]['items'][0]['label'],'Lain Test'),'Ambiguous historical fee became a fake master or blocked receipt');
$rows[3]['raw']['KETERANGAN']='Pembayaran Basket';$p=lop_prepare($rows,ma_cfg($rows),1,'2026-10-17');lft_assert($p['payments'][0]['items'][0]['label']==='Basket'&&$p['summary']['fee_direct']===1,'Explicit source description ignored');
$rows=[$student,ma_row('lain',['KODE'=>'0015','NAMA'=>'Buku Tabungan']),ma_payment('212200001','','7000','2026-01-02')];
$p=lop_prepare($rows,ma_cfg($rows),1,'2026-10-17');lft_assert($p['summary']['fee_unresolved']===1,'Blank code inferred from amount alone');
$copy=$rows[2];$copy['table']='bayar_backup';$copy['reference_only']=true;$copy['raw']['LAIN_LAIN1']='0015';$copy['row_key']=hash('sha256','counterpart');$rows[]=$copy;
$p=lop_prepare($rows,ma_cfg($rows),1,'2026-10-17');lft_assert($p['payments'][0]['items'][0]['label']==='Buku Tabungan'&&$p['summary']['fee_direct']===1,'Complete counterpart not used');
$rows[2]['raw']['LAIN_LAIN1']='0015';$rows[2]['raw']['LAIN_LAIN2']='0015';$rows[2]['raw']['JUMLAH2']='3000';$rows[2]['money_columns'][]='JUMLAH2';
[$configured]=lma_configure($rows,ma_cfg($rows),1,[['id'=>123,'nama'=>'Buku Tabungan']]);$p=lop_prepare($rows,$configured,1,'2026-10-17',[123=>'Buku Tabungan']);
lft_assert($p['payments'][0]['items'][0]['category']==='biaya_lain:123'&&$p['payments'][0]['items'][1]['category']==='biaya_lain:123','Slots split into different masters');

foreach(['202107048'=>['0','157596'],'202107049'=>['90000','22'],'212207087'=>['135000','1000000']] as $nis=>[$main,$extra]){
    $rows=[op_raw((string)$nis,'1A'),ma_row('tabungan',['NO_INDUK'=>(string)$nis,'SALDO'=>$main]),ma_row('tb_total',['NO_INDUK'=>(string)$nis,'SALDO'=>$extra]),ma_row('tb_masuk',['NO_INDUK'=>(string)$nis,'MASUK'=>$extra,'TANGGAL'=>'2023-07-12'])];
    if($main!=='0')$rows[]=ma_row('transaksi_m',['NO_INDUK'=>(string)$nis,'MASUK'=>$main,'TANGGAL'=>'2022-01-01']);
    $p=lop_prepare($rows,ma_cfg($rows),1,'2026-10-17');lft_assert($p['summary']['savings_total']===lf_money(lf_cents($main))&&$p['summary']['primary_banks_retained']===1&&!array_filter($p['pending'],static fn($v)=>$v['code']!=='monthly_evidence'),'Additional ledger overwrote main account or blocked safe source');
}
$rows=[$student,ma_row('tb_total',['NO_INDUK'=>'212200001','SALDO'=>'1000']),ma_row('tb_masuk',['NO_INDUK'=>'212200001','MASUK'=>'1000','TANGGAL'=>'2023-07-12'])];
$p=lop_prepare($rows,ma_cfg($rows),1,'2026-10-17');lft_assert($p['summary']['promoted_accounts']===1&&$p['summary']['savings_total']==='1000.00','Unique additional account not promoted');
$rows=[$student,ma_row('tabungan',['NO_INDUK'=>' 212200001 ','SALDO'=>'1000']),ma_row('transaksi_m',['NO_INDUK'=>'212200001','MASUK'=>'1000','TANGGAL'=>'2023-07-12']),ma_row('tb_total',['NO_INDUK'=>'212200001','SALDO'=>'invalid'])];$p=lop_prepare($rows,ma_cfg($rows),1,'2026-10-17');lft_assert($p['summary']['savings_total']==='1000.00'&&$p['summary']['additional_bank_exceptions']===1&&!array_filter($p['pending'],static fn($v)=>$v['code']!=='monthly_evidence'),'Invalid extra source replaced main balance, disappeared from exceptions or blocked a valid main');
$rows=[$student,ma_row('tabungan',['NO_INDUK'=>'212200001','SALDO'=>'1500']),ma_row('transaksi_m',['NO_INDUK'=>'212200001','MASUK'=>'1000','TANGGAL'=>'2019-12-31']),ma_row('transaksi_m',['NO_INDUK'=>'212200001','MASUK'=>'500','TANGGAL'=>'2026-01-01']),ma_row('tb_total',['NO_INDUK'=>'212200001','SALDO'=>'1000']),ma_row('tb_masuk',['NO_INDUK'=>'212200001','MASUK'=>'1000','TANGGAL'=>'2019-12-31'])];
[$snapshotCfg]=lma_configure($rows,ma_cfg($rows),1);lft_assert(reset($snapshotCfg['additional_savings'])['reason']==='historical_snapshot'&&lop_prepare($rows,$snapshotCfg,1,'2026-10-17')['summary']['savings_total']==='1500.00','Earlier snapshot replaced or duplicated current main balance');
$rows=[$student,ma_row('tabungan',['NO_INDUK'=>'212200001','SALDO'=>'1340000']),ma_row('transaksi_m',['NO_INDUK'=>'212200001','MASUK'=>'1320000','TANGGAL'=>'2019-12-31'])];
$p=lop_prepare($rows,ma_cfg($rows),1,'2026-10-17');lft_assert($p['summary']['savings_total']==='1340000.00'&&$p['summary']['source_balance_fixes']===1&&count($p['journals'])===2,'Closing balance adjustment or pre-2020 journal lost');
$strictConfig=ma_cfg($rows);$strictConfig['op_preserve_unproven_balances']=true;
$strictPlan=lop_prepare($rows,$strictConfig,1,'2026-10-17');
lft_assert($strictPlan['summary']['source_balance_fixes']===0&&$strictPlan['summary']['adjustments']===0&&$strictPlan['owners']['212200001']['savings']===null,'Unproven balance difference became an active closing adjustment');
lft_assert((bool)array_filter($strictPlan['pending'],static fn($v)=>$v['code']==='savings_review')&&!array_filter($strictPlan['journals'],static fn($v)=>($v['origin']??'legacy')==='adjustment'),'Unproven balance did not retain a source exception');
$foreign=$rows;$foreign[0]['unit_id']=2;lft_reject(fn()=>lop_prepare($foreign,ma_cfg($rows),1,'2026-10-17'),'Foreign unit accepted');
$old=op_config($original);$legacy=lop_prepare($original,$old,1,'2026-10-17');lft_assert(count($legacy['payments'])===4&&!isset($legacy['payments'][0]['items'][0]['migration']),'Old prepared jobs changed behavior');
$payment=$p['payments'];$receipt=['identity'=>'x','row_key'=>'k','source_table'=>'bayar','date'=>null,'operator'=>null,'period'=>'04 2026','class'=>'1A','total'=>'1.00','items'=>[['column'=>'U_KEGIATAN','category'=>'daftar_ulang','label'=>'DU','amount'=>'1.00']]];
$before=lful_projection_fingerprint([$receipt],[]);$receipt['items'][0]['migration']=['version'=>1,'period'=>'2026/2027'];$after=lful_projection_fingerprint([$receipt],[]);$receipt['items'][0]['migration']['period']='2025/2026';
lft_assert($before==='49b20bef162c81ca028168cd5593e01dd4d532c01d8b3d27022bf8c3d5b2a627','Existing format fingerprint changed');
lft_assert($before!==$after&&$after!==lful_projection_fingerprint([$receipt],[]),'Metadata is not bound to publication fingerprint');
echo "PASS: versioned DU reconciliation, mixed receipts, fee evidence/inference/ambiguity, primary savings priority, promotion, closing adjustment, unit isolation, source immutability and legacy compatibility.\n";
