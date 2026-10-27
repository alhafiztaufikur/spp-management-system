<?php
require __DIR__.'/http_form_scope.php';spp_test_assert_database_target();require __DIR__.'/../koneksi.php';spp_test_isolated_manifest($koneksi);
require __DIR__.'/../includes/biaya_lain.php';$db=$koneksi;$private=dirname(getenv('SPP_TEST_ISOLATED_INSTANCE'));
$a=rtrim(getenv('SPP_TEST_BASE_URL'),'/');$b=rtrim(getenv('SPP_TEST_BASE_URL_TWO'),'/');spp_test_assert_http_clone($a,DB_NAME);spp_test_assert_http_clone($b,DB_NAME);if($a===$b)throw new RuntimeException('Use two independently owned PHP workers.');
$f=json_decode(file_get_contents($private.'/billing-http.json'),true,512,JSON_THROW_ON_ERROR)['fixtures'][1];$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($db,1);
$count=static fn(string $t)=>(int)hp_one($db,"SELECT COUNT(*) n FROM $t WHERE ".($t==='bayar'?'NO_INDUK':'no_induk').'=?',[$f['nis']])['n'];
$before=['payments'=>$count('bayar'),'bills'=>$count('tagihan_biaya_lain')];$key=bin2hex(random_bytes(16));$multi=curl_multi_init();$handles=[];
foreach([[$a,$f['admin']],[$b,$f['super']]] as [$base,$session]){
    $data=['aksi'=>'input','_operational_unit'=>'1','csrf_token'=>$session['csrf'],'request_key'=>$key,'no_induk'=>$f['nis'],'tanggal_bayar'=>'2026-10-27','bulan_bayar'=>'10','tahun_bayar'=>'2026','sistem_pembayaran'=>'Tunai','payment_plan'=>'monthly','biaya_lain_tagihan_id'=>['master:'.$f['master']],'biaya_lain_nominal'=>['120000'],'biaya_lain_detail_id'=>[''],'biaya_lain_keterangan'=>['QA concurrent receipt']];
    $c=curl_init($base.'/pembayaran/proses.php');curl_setopt_array($c,[CURLOPT_COOKIE=>'PHPSESSID='.$session['id'],CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data),CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>120]);curl_multi_add_handle($multi,$c);$handles[]=$c;
}
do{$status=curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,1);}while($running&&$status===CURLM_OK);
$responses=[];foreach($handles as $c){$responses[]=['status'=>curl_getinfo($c,CURLINFO_RESPONSE_CODE),'error'=>curl_error($c)];curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);
$after=['payments'=>$count('bayar'),'bills'=>$count('tagihan_biaya_lain')];$payment=hp_one($db,'SELECT b.id,d.tagihan_biaya_lain_id FROM bayar b JOIN bayar_biaya_lain d ON d.bayar_id=b.id AND d.unit_id=b.unit_id WHERE b.NO_INDUK=? ORDER BY b.id DESC LIMIT 1',[$f['nis']]);
$passed=$after['payments']===$before['payments']+1&&$after['bills']===$before['bills']+1&&count(array_filter($responses,static fn($r)=>$r['status']===302&&$r['error']===''))===2&&other_fee_paid_for_bill($db,(int)$payment['tagihan_biaya_lain_id'])===120000.0;
file_put_contents($private.'/billing-concurrency.json',json_encode(compact('passed','before','after','responses'),JSON_PRETTY_PRINT));if(!$passed)throw new RuntimeException('Two-cashier replay duplicated or rejected both purchases.');echo "PASS two cashiers / two PHP workers: one receipt, one student bill, exact credit.\n";
