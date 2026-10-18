<?php
/** Exports and dashboard are read-only; setup must use the verified private clone. */
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/../koneksi.php';require __DIR__.'/http_form_scope.php';spp_test_isolated_manifest($koneksi);spp_test_assert_http_clone(getenv('SPP_TEST_BASE_URL'),DB_NAME);
require __DIR__.'/../includes/auth.php';require __DIR__.'/../includes/reports.php';
$fixture=json_decode(file_get_contents(getenv('SPP_SAVINGS_QA').'/rombel-fixture.json'),true);
$root=getenv('SPP_HISTORY_ARTIFACTS');if(!$root||str_starts_with(strtolower(realpath($root)?:$root),strtolower(realpath(__DIR__.'/..'))))throw new RuntimeException('Private output directory required.');
$checks=0;$expected=[];
function he_get(string $route,?array $form=null):string{global $fixture;$c=curl_init(getenv('SPP_TEST_BASE_URL').'/'.$route);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$fixture['sessions']['super_admin']['id'],CURLOPT_TIMEOUT=>60]);if($form!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($form)]);$body=curl_exec($c);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);if(!in_array($status,[200,302,303],true)||$body===false)throw new RuntimeException('HTTP export failed: '.$route);return $body;}
foreach([1,2,3] as $unit){
    $_SESSION=['admin_id'=>$fixture['sessions']['super_admin']['actor'],'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($koneksi,$unit);
    he_get('unit_switch.php',['unit_id'=>$unit,'csrf_token'=>$fixture['sessions']['super_admin']['csrf'],'next'=>'/dashboard.php']);
    $query=['template'=>'kas-tabungan','unit'=>'active','tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-31'];$f=report_filters($koneksi,$query);$cash=report_build($koneksi,'kas-tabungan',$f);
    $expected[$unit]=array_intersect_key($cash,array_flip(['total_masuk','total_keluar','mutasi_bersih','count_masuk','count_keluar','count_total']));
    $expected[$unit]+=['count_masuk'=>$cash['settlement']['saving_in_count'],'count_keluar'=>$cash['settlement']['saving_out_count'],'count_total'=>$cash['settlement']['transaction_count']];
    foreach(['preview'=>'html','pdf'=>'pdf','excel'=>'xlsx'] as $format=>$ext){$params=$query+['format'=>$format,'download'=>1];$body=he_get('laporan/export_global.php?'.http_build_query($params));if(($format==='pdf'&&!str_starts_with($body,'%PDF-'))||($format==='excel'&&!str_starts_with($body,'PK'))||($format==='preview'&&!str_contains($body,'Arus Tabungan')))throw new RuntimeException('Wrong export response');file_put_contents($root.'/cash-'.$unit.'.'.$ext,$body);$checks++;}
    $html=he_get('laporan/template.php?'.http_build_query($query));foreach(['total_masuk','total_keluar','mutasi_bersih'] as $key)if(!str_contains($html,report_money($cash[$key])))throw new RuntimeException('Closing screen total mismatch');$checks++;
    $today=date('Y-m-d');$f=report_filters($koneksi,['tanggal_awal'=>$today,'tanggal_akhir'=>$today]);$f['_school_savings_cash']=true;$rows=report_savings_transactions($koneksi,$today.' 00:00:00',date('Y-m-d',strtotime($today.' +1 day')).' 00:00:00',$f);
    $amounts=['saving-in'=>array_sum(array_column($rows,'masuk')),'saving-out'=>array_sum(array_column($rows,'keluar'))];
    $html=he_get('dashboard.php');$d=new DOMDocument();@$d->loadHTML($html);$xp=new DOMXPath($d);
    foreach($amounts as $kind=>$amount){$node=$xp->query('//*[contains(@class,"'.$kind.'")]')->item(0);if(!$node||!str_contains($node->textContent,number_format($amount,0,',','.')))throw new RuntimeException('Dashboard total mismatch: '.$kind);$checks++;}
}
file_put_contents($root.'/cash-expected.json',json_encode($expected,JSON_PRETTY_PRINT));echo "PASS $checks closing screen/export and dashboard checks.\n";
