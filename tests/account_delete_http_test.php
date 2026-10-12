<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
ob_start();
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';
$base=(string)getenv('SPP_HTTP_BASE');spp_test_assert_http_clone($base,DB_NAME);
$sessions=json_decode(file_get_contents(getenv('SPP_QA_DIR').'/sessions.json'),true,512,JSON_THROW_ON_ERROR);
function adh_check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function adh_request(string $path,string $sid,?array $post=null):array{
    global $base;
    $body=file_get_contents($base.'/'.$path,false,stream_context_create(['http'=>['method'=>$post===null?'GET':'POST','header'=>'Cookie: PHPSESSID='.$sid."\r\nContent-Type: application/x-www-form-urlencoded",'content'=>$post===null?'':http_build_query($post),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>20]]));
    adh_check($body!==false&&!preg_match('/Fatal error|Warning:|SQLSTATE/',$body),'HTTP runtime error');
    preg_match('/HTTP\/\S+ (\d+)/',$http_response_header[0],$match);$location='';foreach($http_response_header as $header)if(str_starts_with($header,'Location: '))$location=substr($header,10);
    return ['body'=>$body,'status'=>(int)$match[1],'location'=>$location];
}
$prefix='qa.delete.http.'.bin2hex(random_bytes(6));$ids=[];$key=bin2hex(random_bytes(16));$staleSid='deleteqa'.bin2hex(random_bytes(12));$checks=0;
try{
    foreach([1,1,2] as $unit){$name='Hapus HTTP '.bin2hex(random_bytes(6));$username=$prefix.'.'.count($ids);$hash=password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT);$stmt=$koneksi->prepare("INSERT INTO admin(username,nama,password,role,unit_id) VALUES(?,?,?,'kasir',?)");$stmt->bind_param('sssi',$username,$name,$hash,$unit);$stmt->execute();$ids[]=(int)$stmt->insert_id;$stmt->close();}
    unit_set_context($koneksi,3);$stmt=$koneksi->prepare("INSERT INTO keuangan_request(request_key,unit_id,aksi,operator_id) VALUES(?,3,'pembayaran',?)");$stmt->bind_param('si',$key,$ids[1]);$stmt->execute();$stmt->close();
    $path='role_management.php?q='.urlencode($prefix).'&account_status%5B0%5D=*';
    $page=adh_request($path,$sessions['super_admin']);adh_check($page['status']===200,'Account list inaccessible');
    $form=spp_test_form_scope($page['body'],'aksi');
    preg_match('/name="csrf_token" value="([^"]+)"/',$form,$token);adh_check(isset($token[1]),'Account CSRF missing');$token=$token[1];
    adh_check(str_contains($page['body'],'Belum memiliki riwayat')&&str_contains($page['body'],'Memiliki riwayat'),'History markers missing');$checks++;
    foreach(['Kelola akun petugas, ubah password, dan atur status akun.','Akun baru mengikuti unit yang dipilih pada formulir.'] as $text){adh_check(!str_contains($page['body'],$text),'Removed copy still present');$checks++;}
    $exists=static fn($id)=>(bool)$koneksi->query('SELECT id FROM admin WHERE id='.(int)$id)->num_rows;
    foreach([['super_admin',$ids[0],'wrong'],['super_admin',$ids[1],$token],['super_admin',$ids[2],$token],['admin',$ids[0],$token],['kasir',$ids[0],$token],['bendahara',$ids[0],$token]] as [$role,$id,$csrf]){
        $result=adh_request($path,$sessions[$role],['aksi'=>'hapus','account_id'=>$id,'csrf_token'=>$csrf]);adh_check($result['status']===302&&$exists($id),'Invalid deletion accepted '.$role);$checks++;
    }
    session_id($staleSid);session_start();$_SESSION=['admin_id'=>$ids[0],'admin_role'=>'kasir','active_unit_id'=>1];session_write_close();
    $result=adh_request($path,$sessions['super_admin'],['aksi'=>'hapus','account_id'=>$ids[0],'csrf_token'=>$token]);adh_check($result['status']===302&&!$exists($ids[0]),'Unused account not deleted');adh_check(str_contains($result['location'],urlencode($prefix)),'Return filter lost');$checks+=2;
    $again=adh_request($path,$sessions['super_admin'],['aksi'=>'hapus','account_id'=>$ids[0],'csrf_token'=>$token]);adh_check($again['status']===302&&!$exists($ids[0]),'Replay deletion failed');$checks++;
    $stale=adh_request('tabungan/masuk.php',$staleSid);adh_check($stale['status']===302&&str_contains($stale['location'],'login.php'),'Deleted account retained access');$checks++;
    $allSid='deleteallqa'.bin2hex(random_bytes(12));session_id($allSid);session_start();
    $super=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
    $_SESSION=['admin_id'=>$super,'admin_role'=>'super_admin','active_unit_id'=>0,'csrf_token'=>$token];session_write_close();
    $all=adh_request($path,$allSid,['aksi'=>'hapus','account_id'=>$ids[2],'csrf_token'=>$token]);adh_check($all['status']===302&&!$exists($ids[2]),'All-unit Super Admin deletion rejected');$checks++;
    echo "PASS: $checks HTTP account deletion checks; CSRF, four roles, scopes, history markers, replay, filters and stale session\n";
}finally{
    $koneksi->query("DELETE FROM keuangan_request WHERE request_key='$key'");
    if($ids)$koneksi->query('DELETE FROM admin WHERE id IN('.implode(',',$ids).')');
    session_id($staleSid);session_start();$_SESSION=[];session_destroy();
    if(isset($allSid)){session_id($allSid);session_start();$_SESSION=[];session_destroy();}
}
