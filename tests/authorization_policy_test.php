<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))throw new RuntimeException('Use an audit clone.');
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/authorization_presentation.php';
$super=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
$pending=json_decode(file_get_contents(getenv('SPP_AUTH_OUTPUT').'/pending.json'),true,512,JSON_THROW_ON_ERROR);
function auth_policy_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach([1,2,3] as $unit){
    unit_set_context($koneksi,$unit);$_SESSION=['admin_role'=>'super_admin','admin_id'=>$super,'active_unit_id'=>$unit];
    $request=(int)$pending[$unit]['request_id'];$payment=(int)$pending[$unit]['payment_id'];
    $owner=(int)transaction_authorization_find($koneksi,$request)['requested_by'];
    $before=$koneksi->query('SELECT COUNT(*) FROM pembayaran_aktivitas')->fetch_row()[0];
    foreach(['approved','rejected'] as $status){
        $koneksi->begin_transaction();$denied=false;
        try{transaction_authorization_decide($koneksi,$request,$status,$owner,'Forged decision');}catch(RuntimeException $e){$denied=true;}
        auth_policy_assert($denied,'Admin decision allowed by shared helper.');$koneksi->rollback();
    }
    $denied=false;try{transaction_authorization_mark_failed($koneksi,$request,$owner,'Forged failure');}catch(RuntimeException $e){$denied=true;}
    auth_policy_assert($denied,'Admin failure decision allowed.');
    auth_policy_assert($koneksi->query('SELECT COUNT(*) FROM pembayaran_aktivitas')->fetch_row()[0]===$before,'Unauthorized request generated an event.');
    $koneksi->begin_transaction();$koneksi->query("UPDATE admin SET is_active=0 WHERE id=$super");$denied=false;
    try{transaction_authorization_decide($koneksi,$request,'rejected',$super,'Disabled Super Admin');}catch(RuntimeException $e){$denied=true;}
    auth_policy_assert($denied,'Inactive Super Admin allowed.');$koneksi->rollback();
    $koneksi->begin_transaction();transaction_authorization_decide($koneksi,$request,'rejected',$super,'Rollback test');$koneksi->rollback();
    auth_policy_assert(transaction_authorization_find($koneksi,$request)['status']==='pending','Rollback changed request.');
    auth_policy_assert($koneksi->query('SELECT COUNT(*) FROM pembayaran_aktivitas')->fetch_row()[0]===$before,'Rollback left decision journal.');
    $koneksi->begin_transaction();transaction_authorization_decide($koneksi,$request,'cancelled',$owner,'Own cancellation rollback');$koneksi->rollback();
    $_SESSION['admin_role']='admin';$_SESSION['admin_id']=$owner;
    auth_policy_assert(authorization_history_can_read($koneksi,$payment),'Admin cannot monitor own unit.');
    $denied=false;try{authorization_read_unit($koneksi,(string)($unit===1?2:1));}catch(RuntimeException $e){$denied=true;}
    auth_policy_assert($denied,'Foreign unit can widen scope.');
    echo 'OK '.unit_label($unit).": shared-helper roles/inactive account/rollback/cancellation/unit isolation.\n";
}
unit_set_context($koneksi,0);$denied=false;try{transaction_authorization_assert_super($koneksi,$super);}catch(RuntimeException $e){$denied=true;}
auth_policy_assert($denied,'All-units mutations allowed.');echo "OK: All Unit decisions denied.\n";
