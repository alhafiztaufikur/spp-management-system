<?php
if(PHP_SAPI!=='cli')exit(1);ob_start();
require __DIR__.'/../koneksi.php';require __DIR__.'/http_form_scope.php';spp_test_assert_database_target($koneksi);require __DIR__.'/../includes/legacy_full.php';
$base=getenv('SPP_TEST_BASE_URL');spp_test_assert_http_clone($base,DB_NAME);$sessions=[];
foreach(['super_admin','admin'] as $role){$a=lf_one($koneksi,'SELECT id FROM admin WHERE role=? AND is_active=1 LIMIT 1',[$role]);$sid='sync'.bin2hex(random_bytes(16));session_id($sid);session_start();$_SESSION=['admin_id'=>(int)$a['id'],'admin_role'=>$role,'active_unit_id'=>1,'csrf_legacy_import'=>str_repeat('d',64)];session_write_close();$sessions[$role]=$sid;}
function sync_request(string $sid,string $route,array $post=[]): array{global $base;$c=curl_init($base.'/'.$route);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$sid,CURLOPT_TIMEOUT=>90]);if($post)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);$body=curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$code,$body];}
foreach(['master_kelas.php','master_biaya_lain.php','pembayaran/lihat.php','tabungan/riwayat.php','legacy_import.php?mode=students'] as $route){[$status,$body]=sync_request($sessions['super_admin'],$route);if($status!==200||preg_match('/Fatal error|Warning:|Notice:/',$body))throw new RuntimeException('Combined page failed: '.$route.' status '.$status);}
[$status,$body]=sync_request($sessions['admin'],'legacy_import.php?mode=students');if($status!==200)throw new RuntimeException('Remote Admin student import access lost');
[$status,$body]=sync_request($sessions['admin'],'legacy_import.php?mode=finance');if($status!==403)throw new RuntimeException('Finance leaked to Admin');
[$status,$body]=sync_request($sessions['admin'],'legacy_import_api.php?action=health');if($status!==200||!is_array(json_decode($body,true)))throw new RuntimeException('Admin student API access lost');
$job=lf_one($koneksi,'SELECT id FROM legacy_full_batches WHERE unit_id=1 LIMIT 1')['id'];
[$status,$body]=sync_request($sessions['admin'],'legacy_import_api.php?action=full_status&id='.$job);if($status!==403)throw new RuntimeException('Full status leaked to Admin');
[$status,$body]=sync_request($sessions['super_admin'],'legacy_operational_review.php?id='.$job.'&section=confirm');if($status!==200||!str_contains($body,'Migrasi Selesai'))throw new RuntimeException('Legacy review completion lost');
file_put_contents(dirname(getenv('SPP_TEST_ISOLATED_INSTANCE')).'/sync-ui-fixture.json',json_encode(['base'=>$base,'session'=>$sessions['super_admin'],'job'=>$job]));
echo "PASS: merged master/history/savings/import pages, operational Admin student access, Super Admin-only finances, and completed Legacy view.\n";
