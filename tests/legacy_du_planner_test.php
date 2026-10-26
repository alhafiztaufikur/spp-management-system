<?php
require_once __DIR__.'/../includes/legacy_billing.php';
$checks=0;function dup_check(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
$student=['migration_year'=>'2026/2027','source_row_key'=>'header','raw'=>['KELAS'=>'8A','potong_du'=>'500','DAFTAR_ULANG'=>'4500','tot_du'=>'4000']];
$tariffs=['2026/2027'=>['8'=>[['Jumlah'=>'4500','_source_row_key'=>'tariff']]]];$p=ldu_source_proof($student,$tariffs,'2026/2027');
dup_check($p['known']&&(float)$p['gross']===4500.0&&(float)$p['discount']===500.0&&(float)$p['total']===4000.0,'Individual discount separates gross from net');
dup_check($p['tariff_row_keys']===['tariff'],'Tariff source row retained');
foreach([[400000,0,'lunas',0],[100000,0,'belum_lunas',300000],[100000,300000,'lunas',0],[0,0,'belum_lunas',400000],[500000,0,'lunas',0]] as [$legacy,$native,$status,$remaining]){$v=ldu_position($p,$legacy,$native);dup_check($v['status']===$status&&$v['remaining_cents']===$remaining,'Exact native and Legacy credit');}
dup_check(ldu_position($p,500000,0)['excess_cents']===100000,'Overpayment remains a separate credit');
$exempt=$student;$exempt['raw']['potong_du']='4500';$exempt['raw']['tot_du']='0';$proof=ldu_source_proof($exempt,$tariffs,'2026/2027');dup_check($proof['known']&&ldu_position($proof,0,0)['status']==='lunas','Explicit full exemption is known zero');
$missing=$student;unset($missing['raw']['potong_du']);dup_check(!ldu_source_proof($missing,$tariffs,'2026/2027')['known'],'Missing discount is not invented');
$conflict=$tariffs;$conflict['2026/2027']['8'][]=['Jumlah'=>'4600'];dup_check(!ldu_source_proof($student,$conflict,'2026/2027')['known'],'Conflicting class tariffs remain unresolved');
dup_check(!ldu_source_proof($student,$tariffs,'2025/2026')['known'],'Current discount cannot prove older year');
dup_check(!ldu_source_proof($student,$tariffs,'2027/2028')['known'],'Future-year debt is not invented');
$invalid=$student;$invalid['raw']['tot_du']='3500';dup_check(!ldu_source_proof($invalid,$tariffs,'2026/2027')['known'],'Conflicting declared net is rejected');
$zero=ldu_position(['known'=>false],0,0);dup_check($zero['principal_cents']===null&&$zero['remaining_cents']===null&&$zero['status']==='unknown','Unknown is distinct from paid zero');
dup_check(ldu_position(['known'=>false],10000,0,50000)['remaining_cents']===40000,'Existing explicit bill remains authoritative');
foreach([1,2,3,4] as $version)dup_check(lma_metadata_supported($version),'Old and new metadata stays readable');
$preview=ldu_source_preview([['table'=>'Daftar_ulang','raw'=>['kelas'=>'8','th_ajaran'=>'2026/2027','Jumlah'=>'4500']]],['0001'=>['ready'=>true,'kind'=>'active','student'=>true,'raw'=>$student['raw'],'source_key'=>'header']],[['identity'=>'0001','period'=>'10 2026','items'=>[['category'=>'daftar_ulang','amount'=>'4000','migration'=>['version'=>4,'period'=>'2027/2028']]]]],'2026/2027');
dup_check($preview['paid_cents']===0&&$preview['remaining_cents']===400000,'Future academic-year payment is never credited to current year');
echo "PASS $checks DU planner checks; pure fixtures only.\n";
