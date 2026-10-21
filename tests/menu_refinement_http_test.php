<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require_once __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/savings_owners.php';
$base=(string)getenv('SPP_TEST_BASE_URL');spp_test_assert_http_clone($base,DB_NAME);
$qa=(string)getenv('SPP_QA_DIR');$sessions=json_decode(file_get_contents($qa.'/sessions.json'),true);$cookie=$sessions[1];$checks=0;
function mr_check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function mr_http(string $path,?array $data=null):array{
    global $base,$cookie;$c=curl_init($base.'/'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$cookie,CURLOPT_TIMEOUT=>90]);
    if($data!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);
    $raw=curl_exec($c);if($raw===false)throw new RuntimeException(curl_error($c));$length=curl_getinfo($c,CURLINFO_HEADER_SIZE);$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
    $headers=[];foreach(explode("\r\n",substr($raw,0,$length)) as $line)if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$headers[strtolower($k)]=trim($v);}
    $body=substr($raw,$length);mr_check(!preg_match('/Fatal error|Warning:|Uncaught/',$body),'No runtime errors '.$path);return [$status,$body,$headers];
}
function mr_fields(array $identity,string $kind):array{
    [$status,$body]=mr_http('tabungan/'.$kind.'.php?'.http_build_query($identity));mr_check($status===200,'Entry rendered');
    $dom=new DOMDocument();@$dom->loadHTML($body);$xp=new DOMXPath($dom);$form=$xp->query('//form[@data-savings-entry]')->item(0);mr_check((bool)$form,'Owner-aware entry form');$fields=[];
    foreach($form->getElementsByTagName('input') as $input)if($input->getAttribute('name')!=='')$fields[$input->getAttribute('name')]=$input->getAttribute('value');
    $fields['keterangan']='QA menu refinement on disposable clone';return $fields;
}
function mr_balance(array $identity):int{[$status,$body]=mr_http('tabungan/get_saldo.php?'.http_build_query($identity));$data=json_decode($body,true);mr_check($status===200&&isset($data['raw_saldo']),'Authoritative balance');return (int)round((float)$data['raw_saldo']*100);}
$_SESSION=['admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($koneksi,1);
foreach(['student','account'] as $type){
    $page=so_page($koneksi,['owner_type'=>$type]);$target=null;
    foreach($page['rows'] as $row){$identity=$row['student_id']?['student_id'=>$row['student_id']]:['owner_id'=>$row['owner_id']];try{so_resolve($koneksi,$identity,true);$target=$identity;break;}catch(Throwable $ignored){}}
    mr_check($target!==null,'Ready '.$type.' account');$before=mr_balance($target);
    $fields=mr_fields($target,'masuk');$fields['nominal']='100.000,13';
    [$status,,$headers]=mr_http('tabungan/proses.php',$fields);mr_check(in_array($status,[302,303],true)&&str_contains($headers['location']??'','riwayat.php'),'Formatted deposit saved');mr_check(mr_balance($target)===$before+10000013,'Exact grouped decimal amount stored');
    mr_http('tabungan/proses.php',$fields);mr_check(mr_balance($target)===$before+10000013,'Replay never duplicates deposit');
    $withdraw=mr_fields($target,'keluar');$withdraw['nominal']=number_format(($before+10000013)/100+1000,2,',','.');
    [$status,,$headers]=mr_http('tabungan/proses.php',$withdraw);mr_check(str_contains($headers['location']??'','keluar.php'),'Excess withdrawal returned to form');mr_check(mr_balance($target)===$before+10000013,'Rejected withdrawal leaves balance intact');
    [$status,$html]=mr_http('tabungan/'.$headers['location']);mr_check(str_contains($html,'data-error="1"')&&str_contains($html,'savings-entry-dialog'),'Server rejection wired to popup');mr_check(str_contains($html,htmlspecialchars($withdraw['nominal'],ENT_QUOTES)),'Rejected amount preserved');
    $withdraw=mr_fields($target,'keluar');$withdraw['nominal']='100.000,13';
    $stale=mr_fields($target,'keluar');$stale['nominal']=number_format(($before+10000013)/100,2,',','.');
    mr_http('tabungan/proses.php',$withdraw);mr_check(mr_balance($target)===$before,'Deposit and withdrawal restore original balance');
    [$status,,$headers]=mr_http('tabungan/proses.php',$stale);
    mr_check(in_array($status,[302,303],true)&&str_contains($headers['location']??'','keluar.php'),'Stale form rejected after another withdrawal');
    mr_check(mr_balance($target)===$before,'Concurrent balance change remains authoritative');
    $bad=mr_fields($target,'masuk');$bad['nominal']='0,13';$bad['csrf_token']='invalid';mr_http('tabungan/proses.php',$bad);mr_check(mr_balance($target)===$before,'Invalid CSRF cannot change balance');
    $bookIdentity=$target;if(isset($target['student_id']))$bookIdentity['nis']=so_resolve($koneksi,$target)['identity'];
    [$status,$pdf]=mr_http('tabungan/cetak_buku.php?'.http_build_query($bookIdentity+['output'=>'pdf']));mr_check($status===200&&str_starts_with($pdf,'%PDF-'),'Whole-account savings book PDF');
}
echo "PASS $checks HTTP checks: students/non-students, grouped decimals, replay, insufficient balance, preserved draft, CSRF and book PDFs.\n";
