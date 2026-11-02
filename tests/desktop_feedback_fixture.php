<?php
/** Private desktop QA fixture/state reader on a verified disposable server only. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/savings_owners.php';
$identity=$koneksi->query('SELECT @@server_uuid uuid,@@port port,@@event_scheduler scheduler')->fetch_assoc();
if($identity['uuid']!==getenv('SPP_MIGRATION_TEST_UUID')||(int)$identity['port']===3306||$identity['scheduler']!=='OFF')throw new RuntimeException('Owned isolated MySQL required.');
$file=(string)getenv('SPP_FEEDBACK_FIXTURE');if(!$file)throw new RuntimeException('Private fixture path required.');
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>0];unit_set_context($koneksi,0);
if(($argv[1]??'')==='state'){
    $fixture=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$state=[];
    foreach($fixture['units'] as $unit=>$items){
        unit_set_context($koneksi,(int)$unit);
        foreach(['student','account'] as $type){
            $r=so_resolve($koneksi,$items[$type]['identity']);
            if($type==='student')$count=(int)lf_one($koneksi,'SELECT (SELECT COUNT(*) FROM transaksi_m WHERE NO_INDUK=?)+(SELECT COUNT(*) FROM transaksi_k WHERE NO_INDUK=?) n',[$r['identity'],$r['identity']])['n'];
            else $count=(int)lf_one($koneksi,"SELECT COUNT(*) n FROM legacy_op_journals WHERE owner_id=? AND origin='new'",[$r['owner_id']])['n'];
            $state[$unit][$type]=['balance'=>number_format((float)$r['balance'],2,'.',''),'journals'=>$count];
        }
    }
    echo json_encode($state,JSON_THROW_ON_ERROR);exit;
}
if(is_file($file))throw new RuntimeException('Existing fixture must not be replaced.');
$fixture=['units'=>[]];$suffix=bin2hex(random_bytes(3));
foreach([1,2,3] as $unit){
    unit_set_context($koneksi,$unit);
    $class=lf_one($koneksi,'SELECT id,tingkat FROM master_kelas WHERE is_active=1 AND is_placeholder=0 AND tingkat>0 ORDER BY tingkat,id LIMIT 1');
    if(!$class)throw new RuntimeException('Regular class required.');
    $nis='QDF'.$suffix.$unit;$name='QA Desktop Feedback '.$suffix.' '.$unit;
    lf_query($koneksi,'INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,is_active,legacy_pending) VALUES(?,?,?,?,1,0)',[$nis,$name,(string)$class['tingkat'],$class['id']]);$sid=(int)$koneksi->insert_id;
    $account=lf_one($koneksi,"SELECT * FROM ".so_source($koneksi)." x WHERE owner_type='account' AND state='ready' ORDER BY owner_id LIMIT 1");
    if(!$account)throw new RuntimeException('Ready non-student account required.');
    $fixture['units'][$unit]=[
        'student'=>['identity'=>['student_id'=>$sid],'name'=>$name,'nis'=>$nis,'class_id'=>(int)$class['id']],
        'account'=>['identity'=>['owner_id'=>(int)$account['owner_id']],'name'=>$account['name'],'nis'=>$account['identity'],'class_id'=>(int)($account['master_kelas_id']??0)],
    ];
}
file_put_contents($file,json_encode($fixture,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo "PASS: isolated student/non-student desktop fixture created for three units.\n";
