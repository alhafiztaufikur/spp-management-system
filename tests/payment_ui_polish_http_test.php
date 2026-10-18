<?php
/** HTTP metadata, read-only details and an uncapped history on a verified clone. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';
spp_test_assert_database_target($koneksi);spp_test_isolated_manifest($koneksi);spp_test_assert_http_clone(getenv('SPP_TEST_BASE_URL'),DB_NAME);
require_once __DIR__.'/../includes/auth.php';
$fixture=json_decode(file_get_contents(getenv('SPP_SAVINGS_QA').'/rombel-fixture.json'),true,512,JSON_THROW_ON_ERROR);$checks=0;
function polish_check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function polish_http(array $session,string $route,?array $post=null):array{
    $c=curl_init(rtrim(getenv('SPP_TEST_BASE_URL'),'/').'/'.$route);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIE=>'PHPSESSID='.$session['id'],CURLOPT_TIMEOUT=>40]);if($post!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);$body=curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$code,$body];
}
$session=$fixture['sessions']['super_admin'];
foreach([1,2,3] as $unit){
    unit_set_context($koneksi,$unit);$item=$fixture['units'][$unit];polish_http($session,'unit_switch.php',['unit_id'=>$unit,'csrf_token'=>$session['csrf'],'next'=>'/dashboard.php']);
    $params=['no_induk'=>$item['browser_nis'],'student_id'=>$item['browser_student_id'],'unit_id'=>$unit,'scope'=>'all'];
    [$code,$json]=polish_http($session,'pembayaran/history_siswa.php?'.http_build_query($params));$data=json_decode($json,true,512,JSON_THROW_ON_ERROR);polish_check($code===200&&$data['ok']&&count($data['rows'])===3,'Unmodified original history');
    foreach($data['rows'] as $row){
        polish_check($row['unit_id']===$unit&&$row['id']>0&&is_numeric($row['total_raw'])&&$row['period_key']===202610&&str_contains($row['tanggal_raw'],'2026-'),'Raw sorting fields');
        polish_check(isset($row['tanggal'],$row['periode'],$row['komponen'],$row['metode'],$row['operator'],$row['total']),'Compatible existing fields');
        [$code,$detail]=polish_http($session,'pembayaran/'.$row['detail_url']);$detail=json_decode($detail,true,512,JSON_THROW_ON_ERROR);
        polish_check($code===200&&$detail['ok']&&!$detail['capabilities']['can_edit']&&!$detail['capabilities']['can_delete'],'Read-only detail capabilities');
        polish_check(!preg_match('/<form|open-payment-delete-request|btn-tbl-edit|open-payment-activity/',$detail['html'])&&str_contains($detail['html'],'Cetak Bukti Transaksi'),'Read-only detail has no mutation controls');
    }
    [$code,$normal]=polish_http($session,'pembayaran/detail.php?id='.$data['rows'][0]['id'].'&unit_id='.$unit);$normal=json_decode($normal,true,512,JSON_THROW_ON_ERROR);polish_check($code===200&&$normal['capabilities']['can_edit']&&str_contains($normal['html'],'btn-tbl-edit'),'Existing history retains its authorized editing controls');
    $wrong=$params;$wrong['unit_id']=$unit===3?1:3;[$code]=polish_http($session,'pembayaran/history_siswa.php?'.http_build_query($wrong));polish_check($code===409,'Changed unit is rejected');
    $wrong=$params;$wrong['student_id']=$fixture['units'][$unit===3?1:3]['browser_student_id'];[$code]=polish_http($session,'pembayaran/history_siswa.php?'.http_build_query($wrong));polish_check($code===409,'Cross-unit student identity is rejected');
    [$code]=polish_http($session,'pembayaran/detail.php?id='.$data['rows'][0]['id'].'&unit_id='.$unit.'&readonly[]=1');polish_check($code===400,'Malformed read-only flag rejected');
    if($unit!==1)continue;
    $created=[];$before=$koneksi->query('SELECT COUNT(*),SUM(total_jumlah) FROM bayar')->fetch_row();
    try{
        $columns=array_column($koneksi->query('SHOW COLUMNS FROM bayar')->fetch_all(MYSQLI_ASSOC),'Field');$columns=array_values(array_diff($columns,['id']));$names=implode(',',array_map(static fn($v)=>'`'.$v.'`',$columns));$original=(int)$data['rows'][0]['id'];
        for($i=0;$i<505;$i++){$koneksi->query('INSERT INTO bayar ('.$names.') SELECT '.$names.' FROM bayar WHERE id='.$original);$created[]=(int)$koneksi->insert_id;}
        [$code,$json]=polish_http($session,'pembayaran/history_siswa.php?'.http_build_query($params));$large=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        polish_check($code===200&&count($large['rows'])===508,'History returns all 508 transactions without a server limit');
        polish_check(count(array_unique(array_column($large['rows'],'id')))===508,'Every transaction has a unique identity');
    }finally{
        if($created)$koneksi->query('DELETE FROM bayar WHERE id IN ('.implode(',',$created).')');
        polish_check($koneksi->query('SELECT COUNT(*),SUM(total_jumlah) FROM bayar')->fetch_row()===$before,'Clone stress data removed and receipt totals restored');
    }
}
echo "PASS $checks metadata, read-only, identity, unit and uncapped history checks.\n";
