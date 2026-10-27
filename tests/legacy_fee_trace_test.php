<?php
/** Pure fixture suite: no koneksi.php, SQL connection, worker, source jobs or writes. */
require_once __DIR__.'/../includes/legacy_full.php';
require_once __DIR__.'/../includes/legacy_fee_reconstruction.php';
require_once __DIR__.'/../includes/legacy_import_http.php';
$checks=0;
function fee_check(bool $ok,string $message): void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function fee_reject(callable $call,string $message): void {try{$call();}catch(DomainException $e){fee_check(true,$message);return;}throw new RuntimeException($message);}
function fee_row(string $table,array $raw,string $suffix=''): array {
    return ['table'=>$table,'raw'=>$raw,'row_key'=>hash('sha256',json_encode([$table,$raw,$suffix])),'reference_only'=>$table==='lain','student_ordinal'=>1,
        'money_columns'=>array_values(array_intersect(array_keys($raw),['SPP_PERBULAN','POMG','U_SPP','U_LAIN','JUMLAH1','JUMLAH2','JUMLAH3','JUMLAH4']))];
}
function fee_cfg(array $rows,int $unit=1): array {
    $cfg=lop_source_setup($rows,['fingerprint'=>str_repeat('a',64)],['cutoff'=>'2026-10-24 12:00:00'], $unit);
    $cfg['_fee_source_key']=str_repeat('b',64);return $cfg;
}
$student=fee_row('siswa',['NO_INDUK'=>'252601001','NAMA'=>'Siswa Fixture','KELAS'=>'1A','SPP_PERBULAN'=>'100000','POMG'=>'15000']);
$source=fee_row('bayar',['id'=>'69487','NO_INDUK'=>'252601001','KELAS'=>'1A','BULAN'=>'09','TAHUN'=>'2026','TGL_BYR'=>'2026-09-24',
    'U_LAIN'=>'0','LAIN_LAIN1'=>'057  ','JUMLAH1'=>'450000','LAIN_LAIN2'=>'057','JUMLAH2'=>'50000','LAIN_LAIN3'=>'0016','JUMLAH3'=>'20000','LAIN_LAIN4'=>'','JUMLAH4'=>'17000']);
$rows=[$student,fee_row('lain',['KODE'=>'057','NAMA'=>'Eskul Robotik']),fee_row('lain',['KODE'=>'0016','NAMA'=>'Basket']),fee_row('lain',['KODE'=>'0016','NAMA'=>'Pendalaman Materi']),$source];
$original=$rows;$cfg=fee_cfg($rows);$names=[102=>'Eskul Robotik'];$plan=lop_prepare($rows,$cfg,1,'2026-10-24',$names);$payment=$plan['payments'][0];$items=$payment['items'];
fee_check(count($plan['payments'])===1&&count($items)===4,'All four slots accepted without component review');
fee_check(!array_filter($plan['pending'],static fn($p)=>$p['section']==='payment'),'Unknown codes do not create a manual payment gate');
fee_check($payment['total']==='537000.00'&&array_sum(array_map(static fn($i)=>lf_cents($i['amount']),$items))===53700000,'Detail/header receipt counted once');
fee_check($items[0]['category']==='biaya_lain:102'&&$items[1]['category']==='biaya_lain:102','Repeated code has one target master');
fee_check(count(array_unique(array_column(array_column($items,'migration'),'detail_key')))===4,'Every slot has its own stable identity');
fee_check($items[0]['migration']['source_code_raw']==='057  '&&$items[0]['migration']['source_code']==='057','Original code and leading zero preserved');
fee_check($items[0]['migration']['source_record_id']==='69487'&&$items[0]['migration']['source_slot']===1,'Source transaction ID and slot retained');
fee_check($items[0]['migration']['target_master_id']===102&&!empty($items[0]['migration']['evidence']['source_master_rows']),'Target and source master evidence retained');
fee_check($items[2]['migration']['status']==='unresolved'&&$items[3]['migration']['status']==='unresolved','Ambiguous and missing codes stay explicitly unidentified');
fee_check(str_contains($items[2]['label'],'belum teridentifikasi')&&str_starts_with($items[2]['category'],'histori:'),'Unknown fee is accepted as source history');
fee_check($plan['summary']['fee_trace']['details']===4&&$plan['summary']['fee_trace']['master_linked']===2&&$plan['summary']['fee_trace']['trace_complete']===4,'Simulation accounts for every detail');
fee_check($rows===$original,'Raw source never mutated');
$reordered=lop_prepare(array_reverse($rows),$cfg,1,'2026-10-24',$names);fee_check($reordered['payments'][0]['items']===$items,'Source row order does not change detail identity/evidence');
lfet_validate_payment($payment+['unit_id'=>1]);fee_check(true,'Complete traced receipt validates');
$changed=$payment;$changed['items'][0]['migration']['target_master_id']=999;fee_reject(fn()=>lfet_validate_payment($changed+['unit_id'=>1]),'Invalid target trace rejected');
$changed=$payment;$changed['items'][]=$items[0];$changed['total']='987000.00';fee_reject(fn()=>lfet_validate_payment($changed+['unit_id'=>1]),'Duplicate slot identity rejected');
$changed=$payment;$changed['items'][0]['amount']='1.00';fee_reject(fn()=>lfet_validate_payment($changed+['unit_id'=>1]),'Receipt/detail mismatch rejected');

// The GUI defaults and Codex bulk path must produce the same fee mapping and identity.
$catalog=['components'=>array_values(lful_catalog($rows)),'manifest'=>['fingerprint'=>$cfg['source_fingerprint']]];
$bulk=lop_bulk_patch($rows,$catalog,$cfg,1,'all',[['id'=>102,'unit_id'=>1,'nama'=>'Eskul Robotik']]);$runnerCfg=array_replace_recursive($cfg,$bulk['patch']);
$runner=lop_prepare($rows,$runnerCfg,1,'2026-10-24',$names);
fee_check($runner['payments']===$plan['payments'],'GUI and Codex runner use identical detail engine');
$noOptIn=$cfg;unset($noOptIn['automation_version']);$manualUpload=lop_prepare($rows,$noOptIn,1,'2026-10-24',$names);
fee_check($manualUpload['payments'][0]['items'][0]['category']==='biaya_lain:102'&&isset($manualUpload['payments'][0]['items'][0]['migration']['detail_key']),'Fee automation does not require opt-in');
[$proposed,$context]=lma_configure($rows,$cfg,1,[]);$proposal=reset($proposed['new_masters']);
fee_check($proposal['nominal']==='0.00'&&$proposal['tariff_pending']===true&&$proposal['confirmed']===true,'New master proposal requires no tariff guess or user attestation');
$unresolved=lop_prepare($rows,$cfg,1,'2026-10-24')['payments'][0];$logical=$unresolved['items'][0]['category'];$resolved=$unresolved;foreach($resolved['items'] as &$i)$i=lfet_resolve_item($i,[$logical=>'biaya_lain:900']);unset($i);
fee_check($resolved['items'][0]['migration']['target_master_id']===900&&$resolved['items'][0]['migration']['detail_key']===$unresolved['items'][0]['migration']['detail_key'],'Resolving master keeps source detail identity');
$unresolved['identity']='252601001';$resolved['identity']='252601001';fee_check(lful_projection_fingerprint([$unresolved],[],[$logical=>'biaya_lain:900'])===lful_projection_fingerprint([$resolved],[],[$logical=>'biaya_lain:900']),'Publication fingerprint binds logical master and full trace');
[$foreignCfg]=lma_configure($rows,$cfg,1,[['id'=>999,'unit_id'=>2,'nama'=>'Eskul Robotik']]);fee_check(str_starts_with($foreignCfg['components']['auto-fee:'.hash('sha256','Eskul Robotik')]['category'],'biaya_baru:'),'Foreign-unit target master never reused');
$foreign=$rows;$foreign[0]['unit_id']=2;fee_reject(fn()=>lma_configure($foreign,$cfg,1),'Foreign source unit rejected');
$mixed=$rows;$mixed[0]['source_id']=1;$mixed[1]['source_id']=2;fee_reject(fn()=>lma_configure($mixed,$cfg,1),'Mixed backups rejected');
[$otherCfg,$otherContext]=lma_configure($rows,$cfg,2,[]);$other=lma_payment($source,$otherCfg,[],$otherContext);fee_check($other['items'][0]['migration']['detail_key']!==$items[0]['migration']['detail_key'],'Same code/receipt across units remains separate');
fee_check($other['items'][2]['category']!==$items[2]['category'],'Unidentified categories do not merge across units');
$anotherSource=$cfg;$anotherSource['_fee_source_key']=str_repeat('c',64);$anotherPlan=lop_prepare($rows,$anotherSource,1,'2026-10-24',$names);fee_check($anotherPlan['payments'][0]['items'][2]['category']!==$items[2]['category'],'Unidentified categories do not merge across backups');

$zeros=[$student,fee_row('lain',['KODE'=>'0001','NAMA'=>'Buku']),fee_row('lain',['KODE'=>'1','NAMA'=>'Infaq']),fee_row('bayar',array_replace($source['raw'],['LAIN_LAIN1'=>'0001','JUMLAH2'=>'0','JUMLAH3'=>'0','JUMLAH4'=>'0']))];
$z=lop_prepare($zeros,fee_cfg($zeros),1,'2026-10-24',[5=>'Buku',6=>'Infaq']);fee_check($z['payments'][0]['items'][0]['category']==='biaya_lain:5','0001 never becomes code 1');
$bad=$source;$bad['row_key']=hash('sha256','bad');$bad['raw']['U_LAIN']='5';$batch=array_merge($rows,[$bad]);$p=lop_prepare($batch,fee_cfg($batch),1,'2026-10-24',$names);
fee_check(count($p['payments'])===1&&$p['summary']['fee_trace']['held_transactions']===1&&$p['owners']['252601001']['ready'],'Contradictory receipt archived without blocking another receipt');
fee_check(count(array_filter($p['pending'],static fn($p)=>$p['code']==='fee_transaction_held'))===1,'Held fee receipt does not request manual mapping');
$bad['raw']['U_LAIN']='0';$bad['raw']['JUMLAH1']='invalid';$p=lop_prepare(array_merge($rows,[$bad]),$cfg,1,'2026-10-24',$names);fee_check(count($p['payments'])===1&&$p['summary']['fee_trace']['held_transactions']===1,'Invalid amount never invented');

[$ready,$context]=lma_configure($rows,$cfg,1,[['id'=>102,'nama'=>'Eskul Robotik']]);$old=$payment+['unit_id'=>1,'source_period'=>'09 2026','cancelled'=>1];foreach($old['items'] as &$i){unset($i['migration']);$i['category']=lful_history_category(lful_component_key('bayar',$i['column'],$source['raw']));$i['label']='Old archive';}unset($i);
$enriched=lfer_enrich_payment($old,$source,$ready,$names,$context);$again=lfer_enrich_payment(array_replace($old,['items'=>$enriched]),$source,$ready,$names,$context);
fee_check($enriched===$again,'Existing enrichment is idempotent');
fee_check(!lfer_needs_enrichment(array_replace($old,['items'=>$enriched]),$cfg['_fee_source_key']),'Up-to-date receipt needs no enrichment');
fee_check(lfer_needs_enrichment($old,$cfg['_fee_source_key']),'Old receipt is automatically eligible for enrichment');
fee_check(array_column($enriched,'amount')===array_column($old['items'],'amount')&&$old['cancelled']===1,'Cancelled receipt amounts and state preserved');
$corrected=$old;$corrected['items'][0]['amount']='400000.00';$saved=lfer_enrich_payment($corrected,$source,$ready,$names,$context,true);
fee_check($saved[0]['amount']==='400000.00'&&$saved[0]['category']===$corrected['items'][0]['category']&&$saved[0]['migration']['correction_preserved'],'Existing user correction is enriched without overwrite');
$authorized=lfet_corrected_item(array_replace($items[0],['category'=>'biaya_lain:777']));fee_check($authorized['migration']['target_master_id']===777&&$authorized['migration']['status']==='manual'&&$authorized['migration']['source_code_raw']==='057  ','Authorized correction retains source proof and updates target');
foreach([1,2,3] as $version)fee_check(lma_item_period(['category'=>'daftar_ulang','migration'=>['version'=>$version,'period'=>'2026/2027']],'04 2026')==='2026/2027','Old/new DU metadata version '.$version.' remains readable');
$receipt=lfet_receipt_items($payment+['unit_id'=>1]);fee_check($receipt[0]['source_trace']['transaction_id']==='69487'&&$receipt[0]['source_trace']['master_id']===102,'Receipt reader sees exact source and master');
fee_check(str_contains(lfet_item_reference($receipt[0]),'Legacy #69487')&&str_contains(lfet_item_reference($receipt[0]),'kode 057'),'Existing detail/print/export can display source reference');
$archived=lfet_receipt_items($old,$source['raw']);fee_check($archived[0]['source_trace']['transaction_id']==='69487'&&$archived[0]['source_trace']['code']==='057','Old receipts can derive a reference without writes');
$pomg=fee_row('bayar',array_replace($source['raw'],['LAIN_LAIN1'=>'POMG','JUMLAH1'=>'15000','JUMLAH2'=>'0','JUMLAH3'=>'0','JUMLAH4'=>'0']));$pomgRows=[$student,$pomg];$pomgPlan=lop_prepare($pomgRows,fee_cfg($pomgRows),1,'2026-10-24');
fee_check($pomgPlan['payments'][0]['items'][0]['category']==='komite'&&$pomgPlan['payments'][0]['items'][0]['migration']['rule']==='source_pomg','Existing POMG slot semantics remain Komite');
$manualCfg=$cfg;$manualKey=lful_component_key('bayar','JUMLAH1',$source['raw']);$manualCfg['components'][$manualKey]=['category'=>'biaya_lain:777','reason'=>'Koreksi pemetaan eksplisit yang telah disahkan.','automatic_mapping'=>false,'confirmed'=>true];$manualPlan=lop_prepare($rows,$manualCfg,1,'2026-10-24',$names+[777=>'Biaya Koreksi']);
fee_check($manualPlan['payments'][0]['items'][0]['category']==='biaya_lain:777'&&$manualPlan['payments'][0]['items'][0]['migration']['status']==='manual','Optional explicit mapping remains available');
$source2=fee_row('bayar',array_replace($source['raw'],['KELAS'=>'7A','LAIN_LAIN1'=>'057','JUMLAH2'=>'0','JUMLAH3'=>'0','JUMLAH4'=>'0']));$rows2=[fee_row('siswa',array_replace($student['raw'],['KELAS'=>'7A'])),fee_row('lain',['KODE'=>'057','NAMA'=>'Basket SMP']),$source2];
$plan2=lop_prepare($rows2,fee_cfg($rows2,2),2,'2026-10-24',[202=>'Basket SMP']);fee_check($plan2['payments'][0]['items'][0]['category']==='biaya_lain:202','Same source code in SMP resolves to its own fee');
fee_check(LOP_VERSION===18&&LFUL_RULES_VERSION===14&&LMA_VERSION===5,'New rule versions reject stale simulations/workers');
fee_check(legacy_http_operational_upload('full',[]),'Ordinary full-upload form defaults to automatic engine');
fee_check(legacy_http_operational_upload('full',['mode'=>'operational']),'Operational upload uses identical engine');
$collisionPayment=fee_row('bayar',array_replace($source['raw'],['U_INFAQ'=>'1000','LAIN_LAIN1'=>'057','JUMLAH1'=>'2000','JUMLAH2'=>'0','JUMLAH3'=>'0','JUMLAH4'=>'0']));$collisionPayment['money_columns'][]='U_INFAQ';
$collisionRows=[$student,fee_row('lain',['KODE'=>'057','NAMA'=>'INFAQ']),$collisionPayment];$collisionConfig=fee_cfg($collisionRows);
$collisionBulk=lop_bulk_patch($collisionRows,['components'=>array_values(lful_catalog($collisionRows)),'manifest'=>['fingerprint'=>str_repeat('a',64)]],$collisionConfig,1,'components');
[$collisionConfig]=lma_configure($collisionRows,array_replace_recursive($collisionConfig,$collisionBulk['patch']),1);
$collisionKeys=[];foreach($collisionConfig['components'] as $map)if(str_starts_with($map['category']??'','biaya_baru:'))$collisionKeys[$map['category']]=true;
fee_check(count($collisionKeys)===1,'Column and detail proposals share one case-insensitive fee master');
$reordered=$payment;foreach($reordered['items'] as &$item)if(isset($item['migration']['evidence']))$item['migration']['evidence']=array_reverse($item['migration']['evidence'],true);unset($item);
fee_check(lful_projection_fingerprint([$payment],[])===lful_projection_fingerprint([$reordered],[]),'Nested trace object order survives MySQL JSON storage');
$reordered['items'][0]['migration']['evidence']['supporting_rows'][]=str_repeat('f',64);
fee_check(lful_projection_fingerprint([$payment],[])!==lful_projection_fingerprint([$reordered],[]),'Changed nested trace evidence is still rejected');
fee_check(!legacy_http_operational_upload('full',['mode'=>'archive']),'Explicit historical archive mode remains compatible');
fee_check(!legacy_http_operational_upload('students',[]),'Identity-only upload is not converted into financial migration');
fee_check(!legacy_http_operational_upload('finance',[]),'Archive-only finance upload retains its purpose');
echo "$checks fee trace checks passed; fixtures only, no database access.\n";
