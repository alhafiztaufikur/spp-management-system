<?php
require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/../includes/legacy_billing.php';
$checks=0;function dup_check(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$student=['migration_year'=>'2026/2027','source_row_key'=>'header','raw'=>['KELAS'=>'8A','potong_du'=>'500','DAFTAR_ULANG'=>'4500','tot_du'=>'4000']];
$tariffs=['2026/2027'=>['8'=>[['Jumlah'=>'4500','_source_row_key'=>'tariff']]]];$p=ldu_source_proof($student,$tariffs,'2026/2027');
dup_check($p['known']&&(float)$p['gross']===4500.0&&(float)$p['discount']===500.0&&(float)$p['total']===4000.0,'Individual discount separates gross from net');
dup_check($p['tariff_row_keys']===['tariff'],'Tariff source row retained');
foreach([[400000,0,'lunas',0],[100000,0,'belum_lunas',300000],[100000,300000,'lunas',0],[0,0,'belum_lunas',400000],[500000,0,'lunas',0]] as [$legacy,$native,$status,$remaining]){$v=ldu_position($p,$legacy,$native);dup_check($v['status']===$status&&$v['remaining_cents']===$remaining,'Exact native and Legacy credit');}
dup_check(ldu_position($p,500000,0)['excess_cents']===100000,'Overpayment remains a separate credit');
$exempt=$student;$exempt['raw']['potong_du']='4500';$exempt['raw']['tot_du']='0';$proof=ldu_source_proof($exempt,$tariffs,'2026/2027');dup_check($proof['known']&&ldu_position($proof,0,0)['status']==='lunas','Explicit full exemption is known zero');
$missing=$student;unset($missing['raw']['potong_du']);dup_check(!ldu_source_proof($missing,$tariffs,'2026/2027')['known'],'Missing discount is not invented');
$conflict=$tariffs;$conflict['2026/2027']['8'][]=['Jumlah'=>'4600'];dup_check(ldu_source_proof($student,$conflict,'2026/2027')['known'],'Complete individual header is authoritative over conflicting class tariffs');$noHeader=$student;unset($noHeader['raw']['DAFTAR_ULANG'],$noHeader['raw']['tot_du']);dup_check(!ldu_source_proof($noHeader,$conflict,'2026/2027')['known'],'Conflicting class tariffs alone remain unresolved');
dup_check(!ldu_source_proof($student,$tariffs,'2025/2026')['known'],'Current discount cannot prove older year');
dup_check(!ldu_source_proof($student,$tariffs,'2027/2028')['known'],'Future-year debt is not invented');
$invalid=$student;$invalid['raw']['tot_du']='3500';dup_check(!ldu_source_proof($invalid,$tariffs,'2026/2027')['known'],'Conflicting declared net is rejected');
$zero=ldu_position(['known'=>false],0,0);dup_check($zero['principal_cents']===null&&$zero['remaining_cents']===null&&$zero['status']==='unknown','Unknown is distinct from paid zero');
dup_check(ldu_position(['known'=>false],10000,0,50000)['remaining_cents']===40000,'Existing explicit bill remains authoritative');
foreach([1,2,3,4,5] as $version)dup_check(lma_metadata_supported($version),'Old and new metadata stays readable');
$preview=ldu_source_preview([['table'=>'Daftar_ulang','raw'=>['kelas'=>'8','th_ajaran'=>'2026/2027','Jumlah'=>'4500']]],['0001'=>['ready'=>true,'kind'=>'active','student'=>true,'raw'=>$student['raw'],'source_key'=>'header']],[['identity'=>'0001','period'=>'10 2026','items'=>[['category'=>'daftar_ulang','amount'=>'4000','migration'=>['version'=>4,'period'=>'2027/2028']]]]],'2026/2027');
dup_check($preview['paid_cents']===0&&$preview['remaining_cents']===400000,'Future academic-year payment is never credited to current year');
$grade1=['migration_year'=>'2026/2027','source_row_key'=>'student-one','raw'=>['KELAS'=>'1C','DAFTAR_ULANG'=>'3500000','potong_du'=>'500000','tot_du'=>'3000000']];
$proof1=ldu_source_proof($grade1,[],'2026/2027');dup_check($proof1['known']&&$proof1['grade']===1&&(float)$proof1['total']===3000000.0,'Complete grade-one header needs no class tariff');
$grade1['raw']['DAFTAR_ULANG']=null;$grade1['raw']['tot_du']='0';$grade1['raw']['PSB']='11270000';$grade1['raw']['PANGKAL_BAYAR']='11270000';dup_check(!ldu_source_proof($grade1,['2017/2018'=>['1'=>[['Jumlah'=>'1895000']]]],'2026/2027')['known'],'Old tariff and settled PSB never invent current DU');
require_once __DIR__.'/../includes/du_master_service.php';require_once __DIR__.'/../includes/legacy_du_reconstruction.php';
foreach(['','   '] as $blank)dup_check(du_master_rate_cents($blank,1)===null,'Blank rate is unchanged');
foreach(['3.500.000'=>350000000,'3500000,25'=>350000025] as $text=>$money)dup_check(du_master_rate_cents($text,8)===$money,'Exact Indonesian amount');
foreach(['0','-1','1e6','12.34',[], '999999999999999999999'] as $bad){$reject=false;try{du_master_rate_cents($bad,8);}catch(Throwable $e){$reject=true;}dup_check($reject,'Invalid rate rejected');}
$fixture=[['table'=>'lain','row_key'=>'fee','raw'=>['KODE'=>'0001','NAMA'=>'Daftar Ulang']],['table'=>'bayar','row_key'=>'receipt','raw'=>['id'=>'42','NO_INDUK'=>'001','KELAS'=>'PSB','BULAN'=>'Oktober','TAHUN'=>'2025','th_ajaran'=>'2026/2027','kelas_du'=>'1','LAIN_LAIN1'=>'0001','JUMLAH1'=>'3500000']]];
$context=lma_context($fixture,1,str_repeat('a',64));dup_check(($context['fees']['receipt']['JUMLAH1']['category']??'')==='daftar_ulang','Explicit DU source master and obligation year classify slot automatically');
$item=['column'=>'JUMLAH1','category'=>'biaya_lain:55','label'=>'Daftar Ulang','amount'=>'3500000','migration'=>['version'=>4]];$receipt=['items'=>[$item]];
$mapped=ldu_reclassify_items($receipt,$fixture[1],$context);dup_check($mapped[0]['category']==='daftar_ulang'&&$mapped[0]['migration']['period']==='2026/2027','Existing slot uses obligation year instead of cash year');dup_check(($mapped[0]['migration']['original_code']??$mapped[0]['migration']['source_code']??'')==='0001','DU reclassification preserves original zero-prefixed code');
dup_check(ldu_reclassify_items($receipt,$fixture[1],$context,true)===$receipt['items'],'Corrected receipt never overwritten');dup_check(ldu_reclassify_items(['items'=>$mapped],$fixture[1],$context)===$mapped,'Repeated mapping is unchanged');
$admin=ldu_admin_proof(['known'=>false],['migration_year'=>'2026/2027','raw'=>['potong_du'=>'500000'],'source_row_key'=>'one'],[],['id'=>99,'kelas'=>'1','Jumlah'=>'3500000'],'2026/2027');dup_check($admin['basis']==='system'&&(float)$admin['total']===3000000.0,'Admin tariff preserves proven individual current-year discount without class source tariff');
$admin=ldu_admin_proof(['known'=>false],['migration_year'=>'2026/2027','raw'=>[],'source_row_key'=>'one'],[],['id'=>99,'kelas'=>'1','Jumlah'=>'3500000'],'2026/2027');dup_check($admin['basis']==='system'&&(float)$admin['total']===3500000.0,'Missing individual evidence uses explicit admin full-rate policy');
$free=ldu_admin_proof(['known'=>true,'gross'=>3000000,'discount'=>3000000,'total'=>0],['source_row_key'=>'exempt'],[],['id'=>99,'kelas'=>'1','Jumlah'=>'3500000'],'2026/2027');dup_check((float)$free['total']===0.0,'Complete individual exemption survives an admin class tariff');
$manual=ldu_admin_proof(['known'=>false],['settings'=>['billing_v1'=>['du_individual'=>['2026/2027'=>true]]]],['DAFTAR_ULANG'=>'0','potong_du'=>'0'],['id'=>99,'kelas'=>'1','Jumlah'=>'3500000'],'2026/2027');dup_check(!$manual['known'],'Explicit incomplete individual override is preserved rather than overwritten');
$invalidDiscount=ldu_admin_proof(['known'=>false],['migration_year'=>'2026/2027','raw'=>['potong_du'=>'-1']],[],['id'=>99,'kelas'=>'1','Jumlah'=>'3500000'],'2026/2027');dup_check(!$invalidDiscount['known'],'Invalid individual discount is isolated instead of becoming a full-rate bill');
echo "PASS $checks DU planner checks; pure fixtures only.\n";
