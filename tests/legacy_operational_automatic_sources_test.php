<?php
if(PHP_SAPI!=='cli')exit(1);define('DB_NAME','db_spp');

require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/legacy_finance_support.php';
$qa=dirname($argv[1]??'');if(!is_file($qa.'/operational-source-jobs.json')||!is_dir($qa.'/importer'))throw new RuntimeException('Private extracted sources required');putenv('SPP_LEGACY_STORAGE='.$qa.'/importer');$jobs=json_decode(file_get_contents($qa.'/operational-source-jobs.json'),true);$results=[];
foreach($jobs as $source){
    $job=legacy_job($source['id']);[$rows,$manifest]=lf_extract_read($job,true);
    $catalog=lful_prepared($rows,$manifest)+['manifest'=>$manifest,'components'=>array_values(lful_catalog($rows)),'additional_savings'=>lful_additional_savings($rows,$manifest['source_date'])];
    $cfg=lop_source_setup($rows,$manifest,['cutoff'=>'2026-10-19 08:00:00'],(int)$source['unit']);
    $proposal=lop_bulk_patch($rows,$catalog,$cfg,(int)$source['unit'],'all');$cfg=array_replace_recursive($cfg,$proposal['patch']);$cfg['other_policy']='total_details';
    $plan=lop_prepare($rows,$cfg,(int)$source['unit'],$manifest['source_date']);
    $reasons=[];foreach($plan['pending'] as $p){$k=$p['code'].':'.$p['message'];$reasons[$k]=($reasons[$k]??0)+1;}
    echo lf_json(['unit'=>$source['unit'],'version'=>$source['version'],'summary'=>$plan['summary'],'remaining'=>$reasons]).PHP_EOL;
    foreach($plan['pending'] as $p)if($p['code']==='owner_review')lft_assert(!empty($plan['owners'][$p['identity']]['group_policy']['confirmed']),'Bulk left an unapproved source group');
    if($source['unit']===1&&$source['version']==='2026'){
        $heldPayments=array_values(array_filter($plan['pending'],fn($p)=>$p['section']==='payment'));
        lft_assert($manifest['counts']['siswa']===1133&&$plan['summary']['payments']===(lma_enabled($cfg)?$manifest['counts']['bayar']:46852)&&count($heldPayments)===0&&$plan['summary']['pending']<=1&&$plan['summary']['source_balance_fixes']===6&&$plan['summary']['promoted_accounts']===1&&$plan['summary']['automatic_payments']===7881&&$plan['summary']['accepted_history']===16,'Latest SD nonnegative payment records not carried together');
        foreach($heldPayments as $held)lft_assert($held['message']==='Nominal negatif atau memiliki lebih dari dua desimal.','Valid payment still requires individual review');
    }
    $results[]=$source+['source_fingerprint'=>$manifest['fingerprint'],'summary'=>$plan['summary'],'reasons'=>$reasons];
    echo 'PASS '.$source['version'].' unit '.$source['unit'].': '.$plan['summary']['payments'].' payments ready; '.$plan['summary']['review_tasks'].' groups / '.$plan['summary']['pending'].' affected source records.'.PHP_EOL;
    unset($rows,$plan,$cfg,$proposal,$catalog);gc_collect_cycles();
}
file_put_contents('C:/laragon/backups/spp-management-system/legacy_bulk_qa_20261019/resolution-source-results.json',lf_json($results));
