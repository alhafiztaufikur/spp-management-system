<?php
/** Actual owned worker extraction. No publication of these real source datasets. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/legacy_finance_support.php';
$qa=dirname(getenv('SPP_TEST_ISOLATED_INSTANCE'));$audit=getenv('SPP_LATEST_AUDIT_ROOT');
if(!$audit||!is_file($audit.'/sources.json'))throw new RuntimeException('Set SPP_LATEST_AUDIT_ROOT to the private audited sources.');
$sources=json_decode(file_get_contents($audit.'/sources.json'),true,512,JSON_THROW_ON_ERROR);$index=$qa.'/latest-source-jobs.json';
if(in_array('--enqueue',$argv,true)){
    $actor=(int)lf_one($koneksi,"SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")['id'];$jobs=[];
    foreach(['2026','2019'] as $version)foreach(['students','finance','full'] as $mode)foreach($sources as $s){
        $path=$version==='2026'?$s['path']:'C:/Users/lakch/Downloads/'.$s['label'].'-5.dat';
        $name=$version==='2026'?basename($path):basename($path);$j=legacy_enqueue($path,$name,(int)$s['unit'],$actor,$mode);
        $jobs[]=['id'=>$j['id'],'unit'=>(int)$s['unit'],'version'=>$version,'mode'=>$mode,'audit_job'=>$s['job']];
    }
    file_put_contents($index,lf_json($jobs));echo 'Queued '.count($jobs)." source examinations (no application).\n";exit;
}
$jobs=json_decode(file_get_contents($index),true,512,JSON_THROW_ON_ERROR);
if(in_array('--worker',$argv,true)){
    for($n=0;$n<count($jobs);$n++){$next=legacy_queue()->query("SELECT id FROM jobs WHERE state='queued' ORDER BY created,id LIMIT 1")->fetchColumn();if(!$next)break;$item=null;foreach($jobs as $candidate)if($candidate['id']===$next)$item=$candidate;if(!$item)throw new RuntimeException('Unexpected job in isolated queue');
        $p=proc_open([PHP_BINARY,'-d','memory_limit=768M',__DIR__.'/../scripts/legacy_worker.php','--once'],[0=>['pipe','r'],1=>['file',$qa.'/latest-worker.out','a'],2=>['file',$qa.'/latest-worker.err','a']],$pipes);fclose($pipes[0]);$exit=proc_close($p);
        $job=legacy_job($item['id']);echo ($n+1).'/'.count($jobs).' '.$item['version'].' '.$item['mode'].' unit '.$item['unit'].' '.$job['state'].' '.$job['message'].PHP_EOL;
        lft_assert($exit===0&&$job['state']==='ready','Real source examination failed; inspect private logs.');
    }exit;
}
// Deliberately independent of lf_extract_stream: compare the original raw-row multiset.
function latest_independent(string $file,int $unit,string $hash): array {
    $auditManifest=json_decode(ltrim(file_get_contents(dirname($file).'/finance_manifest.json'),"\xEF\xBB\xBF"),true,512,JSON_THROW_ON_ERROR);$counts=array_fill_keys(array_keys($auditManifest['counts']),0);$keys=[];$duplicates=[];$fp=fopen($file,'rb');try{while(($line=fgets($fp))!==false){$r=json_decode($line,true,512,JSON_THROW_ON_ERROR);$raw=$r['raw'];ksort($raw,SORT_STRING);$digest=hash('sha256',json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$t=$r['table'];$occ=($duplicates[$t][$digest]??0)+1;$duplicates[$t][$digest]=$occ;$counts[$t]=($counts[$t]??0)+1;$keys[]=hash('sha256',$unit.'|'.$hash.'|'.$t.'|'.$digest.'|'.$occ);}}finally{fclose($fp);}sort($keys,SORT_STRING);return ['counts'=>$counts,'total'=>array_sum($counts),'fingerprint'=>hash('sha256',implode("\n",$keys))];
}
$expected=[1=>1133,2=>1762,3=>397];$proof=[];$baseline=[];
foreach($jobs as $item){$j=legacy_job($item['id']);lft_assert($j['state']==='ready','Not ready');$dir=legacy_root().'/'.$j['id'];
    if($item['mode']==='students'){$m=json_decode(ltrim(file_get_contents($dir.'/source_manifest.json'),"\xEF\xBB\xBF"),true);lft_assert((int)$m['rows']===($item['version']==='2026'?$expected[$item['unit']]:[1=>369,2=>787,3=>131][$item['unit']]),'Student count');$q=legacy_queue()->prepare('SELECT COUNT(*) FROM rows WHERE job=? AND nis IS NOT NULL AND nis<>\'\'');$q->execute([$j['id']]);lft_assert((int)$q->fetchColumn()===(int)$m['rows'],'Lowercase SD identities missing');}
    else{$m=null;foreach(lf_extract_stream($j,$m) as $r){if($r['table']==='bayar')lft_assert(!in_array('id',$r['money_columns'],true),'Metadata id counted as money');}
        if($item['version']==='2026'){$expectedRaw=$baseline[$item['unit']]??=latest_independent($audit.'/'.$item['audit_job'].'/finance.jsonl',$item['unit'],$j['hash']);lft_assert($m['counts']===$expectedRaw['counts']&&$m['total']===$expectedRaw['total']&&$m['fingerprint']===$expectedRaw['fingerprint'],'Independent raw data/count/fingerprint differs');}
        if($item['mode']==='full'){$catalog=lful_catalog_read($j['id']);lft_assert($catalog['schema_signature']===$m['source_schema_signature'],'Wrong actual source signature');if($item['version']==='2026'&&$item['unit']===1)lft_assert(count($catalog['additional_savings'])>=12,'Additional ledgers omitted');$sim=lful_simulate($koneksi,$j['id']);lft_assert(!$sim['ready'],'Unreviewed real finance incorrectly ready');}
    }
    lft_assert($m['source_profile_version']===$item['version'],'Wrong profile');$proof[]=$item+['counts'=>$m['counts']??['siswa'=>$m['rows']],'fingerprint'=>$m['fingerprint']??null];echo 'PASS: '.$item['version'].'/'.$item['mode'].'/'.$item['unit'].PHP_EOL;
}
unit_set_context($koneksi,0);foreach(['siswa','bayar','tabungan','legacy_finance_sources','legacy_full_batches'] as $t)lft_assert(!lf_one($koneksi,"SELECT id FROM $t LIMIT 1"),'Source test published operational data');
file_put_contents($qa.'/latest-source-results.json',lf_json($proof));echo "PASS: all real backups, three modes, 2019 compatibility, exact independent source fingerprints; no application.\n";
