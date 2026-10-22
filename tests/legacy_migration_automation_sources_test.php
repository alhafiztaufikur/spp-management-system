<?php
/** Read-only audit of immutable archived backups; never applies a migration or bootstraps koneksi.php. */
if(PHP_SAPI!=='cli'||!in_array('--archive-readonly',$argv,true))throw new RuntimeException('Gunakan --archive-readonly dan --database=NAMA_DATABASE secara eksplisit.');
$database=null;foreach($argv as $arg)if(str_starts_with($arg,'--database='))$database=substr($arg,11);
if(!$database||!preg_match('/^[A-Za-z0-9_]+$/D',$database))throw new RuntimeException('Database arsip wajib disebutkan.');
require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/legacy_finance_support.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=new mysqli(getenv('SPP_DB_HOST')?:'localhost',getenv('SPP_DB_USER')?:'root',getenv('SPP_DB_PASS')!==false?getenv('SPP_DB_PASS'):'',$database,(int)(getenv('SPP_DB_PORT')?:3306));
$db->set_charset('utf8mb4');$db->query('SET SESSION TRANSACTION READ ONLY');$db->begin_transaction();
$profiles=json_decode(file_get_contents(__DIR__.'/../scripts/legacy_finance_profile_2026.json'),true,512,JSON_THROW_ON_ERROR);
$audited=[];
function ma_source_digest(array $records): string {$hash=hash_init('sha256');foreach($records as $r)hash_update($hash,lf_json($r));return hash_final($hash);}
try{
foreach(lf_rows($db,'SELECT id,unit_id,manifest,fingerprint FROM legacy_finance_sources_data ORDER BY unit_id') as $source){
    $unit=(int)$source['unit_id'];$manifest=json_decode($source['manifest'],true);if(($manifest['source_profile_version']??'')!=='2026')continue;$manifest['fingerprint']=$source['fingerprint'];
    $records=[];$types=$profiles[[1=>'SD',2=>'SMP',3=>'SMA'][$unit]];
    $statement=$db->prepare("SELECT source_table,row_key,row_hash,occurrence,raw_data,reference_only FROM legacy_finance_rows_data WHERE source_id=? AND source_table IN ('siswa','bayar','bayar_du','lain','tabungan','transaksi_m','transaksi_k','tb_total','tb_masuk','tb_keluar','tbg') ORDER BY id");$statement->bind_param('i',$source['id']);$statement->execute();$result=$statement->get_result();$ordinal=0;
    while($r=$result->fetch_assoc()){$table=$r['source_table'];$raw=json_decode($r['raw_data'],true);$money=[];foreach($types[$table]??[] as $c)if(in_array($c['type'],['money','smallmoney','float','real','numeric','decimal'],true)&&!in_array($c['name'],['id','nomer'],true))$money[]=$c['name'];$record=['unit_id'=>$unit,'source_id'=>$source['id'],'table'=>$table,'row_key'=>$r['row_key'],'row_hash'=>$r['row_hash'],'occurrence'=>$r['occurrence'],'raw'=>$raw,'reference_only'=>(bool)$r['reference_only'],'student_ordinal'=>$table==='siswa'?++$ordinal:null,'money_columns'=>$money];$record['money_columns']=legacy_source_money_columns($record);$records[]=$record;}$statement->close();unset($result);
    $before=ma_source_digest($records);$cfg=lop_source_setup($records,$manifest,['cutoff'=>'2026-10-19 11:00:00'],$unit);
    // Reconstruct the review catalog without upgrading archived schema signatures or job settings.
    $people=[];$banks=[];foreach($records as $r){$identity=lma_identity(legacy_source_identity($r['raw']));if($r['table']==='siswa'){$people[$identity]??=['identity'=>$identity,'source_count'=>0];$people[$identity]['source_count']++;}if(in_array($r['table'],['tabungan','transaksi_m','transaksi_k'],true))$banks[$identity][]=$r;}
    foreach($people as $identity=>&$person)$person['savings']=isset($banks[$identity])?lf_reconcile($banks[$identity],$manifest['source_date']):null;unset($person,$banks);
    $catalog=['manifest'=>$manifest,'students'=>array_values($people),'components'=>array_values(lful_catalog($records)),'additional_savings'=>lful_additional_savings($records,$manifest['source_date'])];unset($people);
    $patch=lop_bulk_patch($records,$catalog,$cfg,$unit,'all');$cfg=array_replace_recursive($cfg,$patch['patch']);$plan=lop_prepare($records,$cfg,$unit,$manifest['source_date']);
    $sourcePayments=count(array_filter($records,fn($r)=>$r['table']==='bayar'));
    lft_assert(count($plan['payments'])===$sourcePayments,'Pembayaran asal terlewat atau rekap DU menjadi penerimaan baru pada unit '.$unit);
    lft_assert(ma_source_digest($records)===$before,'Isi arsip berubah dalam simulasi');
    foreach($plan['payments'] as $payment){lft_assert($payment['source_table']==='bayar','Rekap menjadi kuitansi');$sum=0;foreach($payment['items'] as $item)$sum+=lf_cents($item['amount']);lft_assert($sum===lf_cents($payment['total']),'Total rincian berubah');}
    if($unit===1){foreach(['202107048'=>'0.00','202107049'=>'90000.00','212207087'=>'135000.00','252601093'=>'1340000.00'] as $nis=>$balance)lft_assert(($plan['owners'][$nis]['savings']['balance']??null)===$balance,'Sumber saldo salah untuk '.$nis);}
    echo lf_json(['unit'=>$unit,'source_payments'=>$sourcePayments,'summary'=>$plan['summary']]).PHP_EOL;
    $audited[$unit]=true;
    unset($plan,$records,$cfg,$catalog,$patch);gc_collect_cycles();
}
lft_assert(count($audited)===3,'Ketiga sumber unit wajib diperiksa.');
echo "PASS: simulasi tiga sumber arsip, penerimaan satu kali, saldo utama dan sumber asli tetap utuh; transaksi SQL hanya baca.\n";
}finally{$db->rollback();}
