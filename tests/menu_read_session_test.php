<?php
/** Return links must survive navigation even when read-only reports release sessions. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/payment_return.php';
spp_test_assert_http_clone(getenv('SPP_TEST_BASE_URL'),DB_NAME);
session_save_path(getenv('SPP_QA_SESSION_PATH'));session_id('menucontext'.bin2hex(random_bytes(12)));session_start();$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];$cookie=session_id();session_write_close();$checks=0;
foreach(['pembayaran/lihat.php','pembayaran/riwayat_daftar_ulang.php'] as $path){
    $c=curl_init(rtrim(getenv('SPP_TEST_BASE_URL'),'/').'/'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$cookie,CURLOPT_TIMEOUT=>120]);$html=curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
    if($code!==200||$html===false||preg_match('/Fatal error|Warning:|Uncaught/',$html))throw new RuntimeException('History page failed.');
    session_id($cookie);session_start();$entries=$_SESSION['payment_returns']??[];
    preg_match_all('/(?:return_context=|data-context=["\x27])([a-f0-9]{48})/',html_entity_decode($html,ENT_QUOTES,'UTF-8'),$tokens);
    foreach(array_unique($tokens[1]) as $token){if(!isset($entries[$token])||payment_return_entry($token,(int)$entries[$token]['payment'])===null)throw new RuntimeException('Return context was rendered but not persisted.');$checks++;}
    if(!$tokens[1])throw new RuntimeException('Fixture must render return contexts.');
    session_write_close();
}
echo "PASS $checks rendered edit/correction return contexts remain valid across real HTTP requests.\n";
