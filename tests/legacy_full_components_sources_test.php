<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/legacy_full.php';require_once __DIR__.'/legacy_finance_support.php';
$db=$koneksi;$actor=(int)lf_one($db,"SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")['id'];
$expected=[1=>['SD',34676],2=>['SMP',43599],3=>['SMA',11133]];$out=[];
$native=[];foreach(['siswa_data','bayar_data','tabungan_data','transaksi_m_data','transaksi_k_data','master_biaya_lain_data'] as $t)$native[$t]=$db->query("CHECKSUM TABLE $t EXTENDED")->fetch_assoc()['Checksum'];
foreach($expected as $unit=>[$label,$count]){
    $job=null;
    if(in_array('--reuse-extracted',$argv,true)){
        $s=legacy_queue()->prepare("SELECT * FROM jobs WHERE unit=? AND name=? AND hash=? ORDER BY created DESC");$s->execute([$unit,'components-source-'.$label.'.dat',hash_file('sha256','C:/Users/lakch/Downloads/'.$label.'-5.dat')]);
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $candidate)if(is_file(legacy_root().'/'.$candidate['id'].'/finance_manifest.json')){$job=$candidate;break;}
    }
    $reused=$job!==null;$job??=legacy_enqueue('C:/Users/lakch/Downloads/'.$label.'-5.dat','components-source-'.$label.'.dat',$unit,$actor,'full');$dir=legacy_root().'/'.$job['id'];
    if(!$reused){
    $proc=proc_open([PHP_BINARY,'-d','memory_limit=768M',__DIR__.'/../scripts/legacy_worker.php','--once'],[0=>['pipe','r'],1=>['file',$dir.'/qa-worker.out.log','w'],2=>['file',$dir.'/qa-worker.err.log','w']],$pipes);fclose($pipes[0]);if(proc_close($proc)!==0)throw new RuntimeException('Source worker failed; inspect private logs.');
    }
    if(!is_file($dir.'/finance_manifest.json'))throw new RuntimeException('Source extraction did not complete; inspect private extract-error.log.');
    [$records,$manifest]=lf_extract_read(legacy_job($job['id']));lft_assert(count($records)===$count,'Original source row count differs');$catalog=lful_catalog($records);
    foreach($catalog as $key=>$c){$amounts=[];foreach($records as $r)if($r['table']===$c['table']&&lful_component_key($r['table'],$c['column'],$r['raw'])===$key){$v=$r['raw'][$c['column']]??null;if($v!==null&&(float)$v!==0.0)$amounts[]=(float)$v;}
        lft_assert(count($amounts)===$c['count'],'Independent source component count differs');if($c['total']!==null)lft_assert(abs(array_sum($amounts)-(float)$c['total'])<0.01,'Independent source component total differs');
    }
    $out[$unit]=['job'=>$job['id'],'rows'=>$count,'fingerprint'=>$manifest['fingerprint'],'components'=>array_values($catalog)];
    file_put_contents(dirname(getenv('SPP_TEST_ISOLATED_INSTANCE')).'/components-sources.json',lf_json($out));
    echo 'PASS: '.$label.' actual restore/CHECKDB/profile/extraction, '.$count.' rows, '.count($catalog).' populated components and independent totals.'.PHP_EOL;
}
foreach($native as $t=>$checksum)lft_assert($db->query("CHECKSUM TABLE $t EXTENDED")->fetch_assoc()['Checksum']===$checksum,'Source inspection changed operational test rows');
file_put_contents(dirname(getenv('SPP_TEST_ISOLATED_INSTANCE')).'/components-sources.json',lf_json($out));
echo "PASS: original SD/SMP/SMA source components; no master, student, cash or savings publication during inspection.\n";
