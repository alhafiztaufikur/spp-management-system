<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/legacy_finance_support.php';
lft_assert(legacy_source_komite(['POMG'=>'15000.0000'])==='15000.00'&&legacy_source_komite(['POMG'=>0])==='0.00','Exact monthly Komite proposal');
lft_assert(legacy_source_komite([])===null&&legacy_source_komite(['POMG'=>null])===null,'Unknown source became a zero fee');
foreach(['-1','1.001','1e4','abc','15.000,00','1000000000000'] as $bad)lft_reject(fn()=>legacy_source_komite(['POMG'=>$bad]),'Invalid POMG silently converted');
$raw=['NO_INDUK'=>'0123456789','U_LAIN'=>'15000.00','JUMLAH1'=>'15000.00','LAIN_LAIN1'=>' POMG ','U_SORGA'=>'5000.00'];$original=$raw;
$row=['table'=>'bayar','raw'=>$raw,'money_columns'=>['U_LAIN','JUMLAH1','U_SORGA'],'row_key'=>hash('sha256','pomg'),'reference_only'=>false];
$catalog=lful_catalog([$row]);$cfg=['du_policy'=>'reference','other_policy'=>'total_details','discount_policy'=>'information','accounting_reason'=>'U_LAIN matches the itemized POMG payment.','components'=>[]];$found=false;
foreach($catalog as $key=>$c){$category=$c['suggestion']?:lful_history_category($key);$cfg['components'][$key]=['category'=>$category,'reason'=>'Source meaning confirmed by foundation.'];if($c['column']==='JUMLAH1'){$found=true;lft_assert($category==='komite'&&$c['name']==='Komite (POMG)','POMG detail not mapped to Komite');}if($c['column']==='U_SORGA')lft_assert($c['suggestion']==='','U_SORGA incorrectly became Komite');}
lft_assert($found,'POMG component omitted');$payment=lful_payment($row,$cfg,[]);lft_assert($payment['total']==='20000.00'&&count($payment['items'])===2,'POMG and U_LAIN counted twice');
lft_assert($payment['items'][0]['category']==='komite'&&$row['raw']===$original,'Projection changed source or lost Komite');
foreach(['POMG','U_POMG'] as $column){$r=$row;$r['raw']=[$column=>'15000.00'];$r['money_columns']=[$column];$c=array_values(lful_catalog([$r]))[0];lft_assert($c['suggestion']==='komite','Direct POMG column mapping');}
lft_assert(!legacy_source_is_pomg('JUMLAH1',['LAIN_LAIN1'=>'POMG dan Seragam']),'Ambiguous combined item guessed');
echo "PASS: POMG monthly tariff, exact nominal/unknown validation, component mapping, unchanged source and no U_LAIN/U_SORGA double counting.\n";
