<?php
/** Stale publisher forms must not create or reopen any other-fee bill. */
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/http_form_scope.php';
spp_test_assert_database_target($koneksi);
$base=rtrim((string)getenv('SPP_TEST_BASE_URL'),'/');
spp_test_assert_http_clone($base,DB_NAME);
require_once __DIR__.'/../includes/auth.php';
$sessionPath=(string)getenv('SPP_QA_SESSION_PATH');
if (!is_dir($sessionPath)||realpath($sessionPath)===realpath(session_save_path())) {
    throw new RuntimeException('Use a private HTTP test session directory.');
}
session_save_path($sessionPath);
$paths=database_access_paths(DB_NAME,(int)getenv('SPP_DB_PORT'));
$generation=is_file($paths['generation'])?trim(file_get_contents($paths['generation'])):'0';
$actor=$koneksi->query("SELECT id,nama,unit_id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_assoc();
if (!$actor) throw new RuntimeException('Test Super Admin is missing.');
$checks=0;
function publish_check(bool $ok,string $message):void {
    global $checks;if (!$ok) throw new RuntimeException($message);$checks++;
}
function publish_http(string $sid,string $url,?array $post=null):array {
    $c=curl_init($url);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_COOKIE=>'PHPSESSID='.$sid]);
    if ($post!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
    $body=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$status,$body];
}
function publish_state(mysqli $db):string {
    $state=[];
    foreach (['master_biaya_lain','tagihan_biaya_lain','bayar_biaya_lain'] as $table) {
        $rows=array_map(static fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$db->query('SELECT * FROM '.$table)->fetch_all(MYSQLI_ASSOC));sort($rows,SORT_STRING);$state[$table]=$rows;
    }
    return hash('sha256',json_encode($state,JSON_THROW_ON_ERROR));
}
foreach ([1,2,3] as $unit) {
    unit_set_context($koneksi,$unit);$before=publish_state($koneksi);
    session_id('closedpublisher'.bin2hex(random_bytes(12)));session_start();$sid=session_id();$csrf=bin2hex(random_bytes(32));
    $_SESSION=['admin_id'=>$actor['id'],'admin_role'=>'super_admin','admin_nama'=>$actor['nama'],'admin_unit_id'=>$actor['unit_id'],'active_unit_id'=>$unit,'database_generation'=>$generation,'csrf_master_biaya_lain'=>$csrf];session_write_close();
    [$status,$html]=publish_http($sid,$base.'/master_biaya_lain.php');
    publish_check($status===200&&str_contains($html,'id="fee-publish-form"'),'Publisher must explicitly support Nonrepeat only.');
    foreach (['all','tingkat','rombel','siswa'] as $scope) {
        $post=['aksi'=>'terbitkan_tagihan','csrf_token'=>$csrf,'_operational_unit'=>$unit,'master_id'=>1,'target'=>$scope,'publish_request_key'=>bin2hex(random_bytes(16))];
        for ($replay=0;$replay<2;$replay++) {
            [$status]=publish_http($sid,$base.'/master_biaya_lain.php',$post);publish_check($status===302,'Stale publish must redirect.');
            [$status,$html]=publish_http($sid,$base.'/master_biaya_lain.php');publish_check($status===200&&str_contains($html,'Formulir penerbitan tidak valid.'),'Explain the replacement workflow.');
            publish_check(publish_state($koneksi)===$before,'Stale publish changed catalog, bill or payment data.');
        }
    }
    @unlink($sessionPath.'/sess_'.$sid);
}
echo "PASS $checks nonrepeat publisher UI, stale form, replay and data-preservation checks.\n";
