<?php
/** Account mutations are restricted to an explicitly selected disposable clone. */
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) exit(1);
ob_start();
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/http_form_scope.php';
$base=rtrim((string)getenv('SPP_HTTP_BASE'),'/');
spp_test_assert_http_clone($base,DB_NAME);
function au_assert(bool $condition,string $message):void {
    if (!$condition) throw new RuntimeException($message);
}
function au_http(string $path,array &$cookies,?array $post=null):array {
    global $base;
    $headers=[];
    if ($cookies) $headers[]='Cookie: '.implode('; ',array_map(static fn($k,$v)=>$k.'='.$v,array_keys($cookies),$cookies));
    if ($post!==null) $headers[]='Content-Type: application/x-www-form-urlencoded';
    $body=file_get_contents($base.'/'.$path,false,stream_context_create(['http'=>[
        'method'=>$post===null?'GET':'POST','header'=>implode("\r\n",$headers),
        'content'=>$post===null?'':http_build_query($post),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>20,
    ]]));
    $status=0;$location='';
    foreach($http_response_header??[] as $header){
        if(preg_match('/^HTTP\/\S+\s+(\d+)/',$header,$m))$status=(int)$m[1];
        if(preg_match('/^Location:\s*(.*)/i',$header,$m))$location=trim($m[1]);
        if(preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i',$header,$m))$cookies[$m[1]]=$m[2];
    }
    au_assert($body!==false&&!preg_match('/Fatal error|Warning:|SQLSTATE/',$body),'HTTP runtime failure');
    return compact('status','location','body');
}
function au_account(string $username):?array {
    global $koneksi;
    $s=$koneksi->prepare('SELECT id,role,unit_id,password,is_active FROM admin WHERE username=?');
    $s->bind_param('s',$username);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();return $row;
}
$super=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
$sid='adminunits'.bin2hex(random_bytes(12));$token=bin2hex(random_bytes(32));
$prefix='qa.admin.'.bin2hex(random_bytes(8));$usernames=[];
$password=bin2hex(random_bytes(16));$newPassword=bin2hex(random_bytes(16));
$cookies=['PHPSESSID'=>$sid];
try {
    foreach([1,2,3,0] as $scope){
        session_id($sid);session_start();$_SESSION=['admin_id'=>$super,'admin_role'=>'super_admin','active_unit_id'=>$scope,'csrf_token'=>$token];session_write_close();
        $page=au_http('role_management.php',$cookies);
        au_assert($page['status']===200&&str_contains($page['body'],'<option value="admin">Admin</option>'),'Admin option missing');
        $form=['aksi'=>'tambah','csrf_token'=>$token,'nama'=>'Admin Unit QA','role'=>'admin','password'=>$password,'password_confirmation'=>$password];
        $target=$scope?:1;$username=$prefix.'.'.$scope;$usernames[]=$username;
        $result=au_http('role_management.php',$cookies,$form+['unit_id'=>$target,'username'=>$username]);
        $account=au_account($username);
        au_assert($result['status']===302&&$account&&$account['role']==='admin'&&(int)$account['unit_id']===$target&&(int)$account['is_active']===1,'Create scoped Admin failed');
        $page=au_http('role_management.php',$cookies);
        au_assert(str_contains($page['body'],'@'.$username)&&str_contains($page['body'],'data-account-id="'.$account['id'].'"'),'Admin list/actions missing');
        $id=(int)$account['id'];
        au_http('role_management.php',$cookies,['aksi'=>'reset_password','csrf_token'=>$token,'account_id'=>$id,'new_password'=>$newPassword,'new_password_confirmation'=>$newPassword]);
        au_assert(password_verify($newPassword,au_account($username)['password']),'Admin password reset failed');
        foreach([0,1] as $active){
            au_http('role_management.php',$cookies,['aksi'=>'status','csrf_token'=>$token,'account_id'=>$id,'target_active'=>$active]);
            au_assert((int)au_account($username)['is_active']===$active,'Admin deactivation/reactivation failed');
        }
        if($scope){
            $invalid=$prefix.'.foreign.'.$scope;$usernames[]=$invalid;
            au_http('role_management.php',$cookies,$form+['unit_id'=>$scope===1?2:1,'username'=>$invalid]);
            au_assert(au_account($invalid)===null,'Account created outside selected unit');
        }
        $invalid=$prefix.'.all.'.$scope;$usernames[]=$invalid;
        au_http('role_management.php',$cookies,$form+['unit_id'=>0,'username'=>$invalid]);
        au_assert(au_account($invalid)===null,'Unit Admin allowed global unit');
        $loginCookies=[];$login=au_http('login.php',$loginCookies,['username'=>$username,'password'=>$newPassword]);
        au_assert($login['status']===302&&str_ends_with($login['location'],'dashboard.php'),'Unit Admin login failed');
        $restricted=au_http('role_management.php',$loginCookies);
        au_assert($restricted['status']===302,'Unit Admin obtained account management');
        $forbidden=$prefix.'.forbidden.'.$scope;$usernames[]=$forbidden;
        au_http('role_management.php',$loginCookies,$form+['unit_id'=>$target,'username'=>$forbidden]);
        au_assert(au_account($forbidden)===null,'Unit Admin created an account');
        $switch=au_http('unit_switch.php',$loginCookies,['unit_id'=>0,'csrf_token'=>$token]);
        au_assert($switch['status']===302,'Unit Admin obtained unit switching');
        echo 'PASS Admin account create/list/reset/status/login and access scope '.$scope.PHP_EOL;
    }
    // Super Admin in SD must not see or mutate an SMP account.
    session_id($sid);session_start();$_SESSION['active_unit_id']=1;session_write_close();
    $foreign=au_account($prefix.'.2');$page=au_http('role_management.php',$cookies);
    au_assert(!str_contains($page['body'],'@'.$prefix.'.2'),'Foreign Admin listed');
    au_http('role_management.php',$cookies,['aksi'=>'status','csrf_token'=>$token,'account_id'=>$foreign['id'],'target_active'=>0]);
    au_assert((int)au_account($prefix.'.2')['is_active']===1,'Foreign Admin mutated');
} finally {
    $delete=$koneksi->prepare('DELETE FROM admin WHERE username=?');
    foreach($usernames as $username){$delete->bind_param('s',$username);$delete->execute();}$delete->close();
    session_id($sid);session_start();$_SESSION=[];session_destroy();session_write_close();
}
ob_end_flush();
