<?php
/** Exercises real handlers against a disposable clone; restores every fixture. */
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
ob_start();
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';
$base=rtrim((string)getenv('SPP_HTTP_BASE'),'/');spp_test_assert_http_clone($base,DB_NAME);
function sw_assert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function sw_session(int $actor,int $unit):array{
    session_id('savingsqa'.bin2hex(random_bytes(12)));session_start();
    $_SESSION=['admin_id'=>$actor,'active_unit_id'=>$unit,'csrf_savings'=>bin2hex(random_bytes(32))];$cookie=session_id();session_write_close();return ['PHPSESSID'=>$cookie];
}
function sw_http(string $path,array $cookies,?array $post=null):array{
    global $base;$headers=['Cookie: '.http_build_query($cookies,'','; ')];if($post!==null)$headers[]='Content-Type: application/x-www-form-urlencoded';
    $body=file_get_contents($base.'/'.$path,false,stream_context_create(['http'=>['method'=>$post===null?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$post===null?'':http_build_query($post),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>30]]));
    $status=0;$location='';foreach($http_response_header??[] as $header){if(preg_match('/^HTTP\/\S+\s+(\d+)/',$header,$m))$status=(int)$m[1];if(preg_match('/^Location:\s*(.*)/i',$header,$m))$location=$m[1];}
    sw_assert($body!==false&&!preg_match('/Fatal error|Warning:|SQLSTATE/',$body),'Runtime error '.$path);return compact('body','status','location');
}
function sw_form(array $cookies,string $kind):array{
    $response=sw_http('tabungan/'.$kind.'.php',$cookies);sw_assert($response['status']===200,'Entry denied');$form=spp_test_form_scope($response['body'],'request_key');
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/',$form,$csrf);preg_match('/name="request_key" value="([a-f0-9]{32})"/',$form,$request);return ['csrf_token'=>$csrf[1],'request_key'=>$request[1],'aksi'=>$kind];
}
$super=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
$artifacts=[];$cleanup=[];$keys=[];$added=[];
try{
foreach([1,2,3] as $unit){
    unit_set_context($koneksi,$unit);
    $student=$koneksi->query('SELECT id,NO_INDUK,NAMA FROM siswa WHERE is_active=1 ORDER BY id LIMIT 1')->fetch_assoc();$nis=$student['NO_INDUK'];
    $s=$koneksi->prepare('SELECT SALDO FROM tabungan WHERE NO_INDUK=?');$s->bind_param('s',$nis);$s->execute();$old=$s->get_result()->fetch_assoc();$s->close();$cleanup[$unit]=['nis'=>$nis,'old'=>$old];
    $accounts=[];foreach(['admin','kasir','bendahara'] as $role){$s=$koneksi->prepare('SELECT id FROM admin WHERE unit_id=? AND role=? AND is_active=1 ORDER BY id LIMIT 1');$s->bind_param('is',$unit,$role);$s->execute();$accounts[$role]=(int)$s->get_result()->fetch_row()[0];$s->close();}
    $cookies=[];foreach($accounts+['super_admin'=>$super] as $role=>$id)$cookies[$role]=sw_session($id,$unit);
    foreach(['admin','kasir'] as $role){
        $fields=sw_form($cookies[$role],'masuk');$keys[]=$fields['request_key'];
        $post=$fields+['no_induk'=>$nis,'nominal'=>'200','keterangan'=>'UJI STRUK <b>& nominal'];
        sw_assert(sw_http('tabungan/proses.php',$cookies[$role],array_replace($post,['csrf_token'=>'invalid']))['status']===302,'CSRF handler');
        $result=sw_http('tabungan/proses.php',$cookies[$role],$post);sw_assert($result['status']===302&&str_contains($result['location'],'jenis=masuk'),'Successful save redirect');parse_str(parse_url($result['location'],PHP_URL_QUERY),$ref);$id=(int)$ref['id'];$added[$unit]['transaksi_m'][]=$id;
        sw_assert(sw_http('tabungan/proses.php',$cookies[$role],$post)['status']===302,'Replay response');
        $detail=sw_http('tabungan/detail.php?jenis=masuk&id='.$id,$cookies[$role]);$data=json_decode($detail['body'],true);sw_assert($detail['status']===200&&$data['transaction']['owner_id']===$accounts[$role]&&$data['transaction']['can_print'],'Owner capability');
        $struk=sw_http('tabungan/cetak_struk.php?jenis=masuk&id='.$id,$cookies[$role]);sw_assert($struk['status']===200&&str_contains($struk['body'],'Rp 200')&&str_contains($struk['body'],'&lt;b&gt;&amp; nominal'),'Receipt content escaping');
        foreach(['admin','kasir','bendahara'] as $other)if($other!==$role){sw_assert(sw_http('tabungan/cetak_struk.php?jenis=masuk&id='.$id,$cookies[$other])['status']===403,'Foreign owner print permitted');$d=json_decode(sw_http('tabungan/detail.php?jenis=masuk&id='.$id,$cookies[$other])['body'],true);sw_assert($d['ok']&&!$d['transaction']['can_print'],'Foreign owner view/capability');}
        $history=sw_http('tabungan/'.$result['location'],$cookies[$role]);sw_assert(str_contains($history['body'],'id="sw-print-modal"'),'Post-commit prompt absent');sw_assert(!str_contains(sw_http('tabungan/'.$result['location'],$cookies[$role])['body'],'id="sw-print-modal"'),'Prompt repeated');
    }
    // Treasury owns historical numeric-ID transactions; a textual legacy value cannot establish ownership.
    foreach([(string)$accounts['bendahara'],'Unknown legacy account'] as $owner){$s=$koneksi->prepare('INSERT INTO transaksi_m(NO_INDUK,TANGGAL,MASUK,KELUAR,user_id,keterangan) VALUES(?,NOW(),100,0,?,?)');$note='UJI PEMILIK';$s->bind_param('sss',$nis,$owner,$note);$s->execute();$id=(int)$koneksi->insert_id;$s->close();$added[$unit]['transaksi_m'][]=$id;
        $allowed=ctype_digit($owner);sw_assert(sw_http('tabungan/cetak_struk.php?jenis=masuk&id='.$id,$cookies['bendahara'])['status']===($allowed?200:403),'Treasury/legacy ownership');sw_assert(sw_http('tabungan/cetak_struk.php?jenis=masuk&id='.$id,$cookies['super_admin'])['status']===200,'Super print');
    }
    $s=$koneksi->prepare('UPDATE tabungan SET SALDO=SALDO+200 WHERE NO_INDUK=?');$s->bind_param('s',$nis);$s->execute();$s->close();
    $fields=sw_form($cookies['kasir'],'keluar');$keys[]=$fields['request_key'];$balance=(float)($old['SALDO']??0)+600;
    $post=$fields+['no_induk'=>$nis,'nominal'=>(string)(int)($balance+1),'keterangan'=>'UJI PENARIKAN'];
    sw_http('tabungan/proses.php',$cookies['kasir'],$post);
    $check=json_decode(sw_http('tabungan/get_saldo.php?nis='.urlencode($nis),$cookies['kasir'])['body'],true);sw_assert((float)$check['saldo']===$balance,'Overdraw changed balance');
    $result=sw_http('tabungan/proses.php',$cookies['kasir'],array_replace($post,['nominal'=>(string)(int)$balance]));sw_assert(str_contains($result['location'],'jenis=keluar'),'Equal-balance withdrawal rejected');parse_str(parse_url($result['location'],PHP_URL_QUERY),$ref);$added[$unit]['transaksi_k'][]=(int)$ref['id'];
    sw_http('tabungan/proses.php',$cookies['kasir'],array_replace($post,['nominal'=>(string)(int)$balance]));
    $check=json_decode(sw_http('tabungan/get_saldo.php?nis='.urlencode($nis),$cookies['kasir'])['body'],true);sw_assert((float)$check['saldo']===0.0,'Replay/withdrawal balance mismatch');
    sw_assert(sw_http('tabungan/detail.php?jenis=masuk&id[]=1',$cookies['admin'])['status']===400,'Array ID accepted');
    sw_assert(sw_http('tabungan/detail.php?jenis=invalid&id=1',$cookies['admin'])['status']===400,'Invalid kind accepted');
    sw_assert(sw_http('tabungan/detail.php?jenis=masuk&id=1',[])['status']===401,'Guest accepted');
    $foreign=sw_session($accounts['admin'],$unit===1?2:1); // Bootstrap must restore this account to its own unit.
    sw_assert(sw_http('tabungan/detail.php?jenis=masuk&id='.$added[$unit]['transaksi_m'][0],$foreign)['status']===200,'Account session scope not restored');
    $otherAccount=(int)$koneksi->query('SELECT id FROM admin WHERE role=\'admin\' AND unit_id<>'.$unit.' LIMIT 1')->fetch_row()[0];
    sw_assert(sw_http('tabungan/detail.php?jenis=masuk&id='.$added[$unit]['transaksi_m'][0],sw_session($otherAccount,$unit))['status']===404,'Cross-unit detail leak');
    $inactiveId=$accounts['kasir'];
    try{$koneksi->query('UPDATE admin SET is_active=0 WHERE id='.$inactiveId);sw_assert(sw_http('tabungan/cetak_struk.php?jenis=keluar&id='.$ref['id'],$cookies['kasir'])['status']===401,'Inactive print accepted');sw_assert(sw_http('tabungan/detail.php?jenis=keluar&id='.$ref['id'],$cookies['kasir'])['status']===401,'Inactive detail accepted');}finally{$koneksi->query('UPDATE admin SET is_active=1 WHERE id='.$inactiveId);}
    $pdf=sw_http('tabungan/cetak_buku.php?nis='.urlencode($nis).'&student_id='.$student['id'].'&output=pdf',$cookies['super_admin']);sw_assert($pdf['status']===200&&str_starts_with($pdf['body'],'%PDF'),'Book PDF failed');
    if($dir=getenv('SPP_QA_DIR'))file_put_contents($dir.'/book-'.$unit.'.pdf',$pdf['body']);
    echo 'OK unit '.$unit.': save, CSRF, ownership, replay, overdraw, exact withdrawal, PDF, unit isolation'.PHP_EOL;
}
}finally{
    foreach($cleanup as $unit=>$fixture){unit_set_context($koneksi,$unit);foreach($added[$unit]??[] as $table=>$ids)$koneksi->query('DELETE FROM '.$table.' WHERE id IN ('.implode(',',$ids).')');$nis=$fixture['nis'];if($fixture['old']){$balance=(float)$fixture['old']['SALDO'];$s=$koneksi->prepare('UPDATE tabungan SET SALDO=? WHERE NO_INDUK=?');$s->bind_param('ds',$balance,$nis);}else{$s=$koneksi->prepare('DELETE FROM tabungan WHERE NO_INDUK=?');$s->bind_param('s',$nis);}$s->execute();$s->close();}
    foreach($keys as $key){$s=$koneksi->prepare('DELETE FROM keuangan_request WHERE request_key=?');$s->bind_param('s',$key);$s->execute();$s->close();}
}
// Read-only browser sessions and fixture identifiers after cleanup.
foreach([1,2,3,0] as $unit){
    unit_set_context($koneksi,$unit);$c=sw_session($super,$unit);$row=$koneksi->query('SELECT id,NO_INDUK,unit_id FROM transaksi_m ORDER BY id DESC LIMIT 1')->fetch_assoc();$artifacts[$unit]=['cookies'=>$c,'row'=>$row];
    if($row){$promptCookie=sw_session($super,$unit);session_id($promptCookie['PHPSESSID']);session_start();$_SESSION['savings_print_prompt']=['jenis'=>'masuk','id'=>(int)$row['id'],'unit_id'=>(int)$row['unit_id']];session_write_close();$artifacts[$unit]['print_cookies']=$promptCookie;}
    $expected=$koneksi->query("SELECT x.id,x.jenis,x.masuk,x.keluar FROM (SELECT id,unit_id,NO_INDUK,TANGGAL,'masuk' jenis,MASUK masuk,0 keluar FROM transaksi_m UNION ALL SELECT id,unit_id,NO_INDUK,TANGGAL,'keluar',0,KELUAR FROM transaksi_k) x JOIN siswa s ON s.NO_INDUK=x.NO_INDUK AND s.unit_id=x.unit_id WHERE x.TANGGAL>='2000-01-01' AND x.TANGGAL<'2030-01-02' ORDER BY x.TANGGAL DESC,x.jenis,x.id DESC")->fetch_all(MYSQLI_ASSOC);
    $observed=[];
    for($page=1;$page<=max(1,(int)ceil(count($expected)/10));$page++){
        $r=sw_http('tabungan/riwayat.php?tanggal_awal=2000-01-01&tanggal_akhir=2030-01-01&page='.$page,$c);
        preg_match_all('/data-kind="(masuk|keluar)" data-id="([0-9]+)"/',$r['body'],$cards,PREG_SET_ORDER);foreach($cards as $card)$observed[]=$card[1].':'.$card[2];
        $dom=new DOMDocument();@$dom->loadHTML($r['body']);$xpath=new DOMXPath($dom);$stats=$xpath->query('//div[contains(@class,"sw-stat ")]//strong');
        $text=static fn($value)=>preg_replace('/[^0-9]/','',$value);
        sw_assert($stats->length===3&&(float)$text($stats[0]->textContent)===(float)array_sum(array_column($expected,'masuk'))&&(float)$text($stats[1]->textContent)===(float)array_sum(array_column($expected,'keluar'))&&(int)$text($stats[2]->textContent)===count($expected),'Filter totals differ from database');
    }
    sw_assert($observed===array_map(static fn($r)=>$r['jenis'].':'.$r['id'],$expected),'Pagination rows differ from database');
    echo 'OK scope '.$unit.': database identities, totals and all pagination pages'.PHP_EOL;
}
if($dir=getenv('SPP_QA_DIR'))file_put_contents($dir.'/sessions.json',json_encode($artifacts));
