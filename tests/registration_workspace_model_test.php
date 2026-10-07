<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/transaction_authorization.php';require_once __DIR__.'/../includes/registration_history.php';
function rwm_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
unit_set_context($koneksi,0);$super=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
$_SESSION=['admin_id'=>$super,'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($koneksi,1);
$koneksi->begin_transaction();
try {
    $id=random_int(700000000,799999999);$bill=random_int(800000000,899999999);
    $payment=['id'=>$id,'unit_id'=>1,'NO_INDUK'=>'0000<>&','NAMA'=>'Siswa lama <script>nama</script>','NO_induk_diknas'=>'0001234','KELAS'=>'2','master_kelas_id'=>null,'TGL_BYR'=>'2024-09-01 12:00:00','total_jumlah'=>11499,'U_LAIN'=>9999,'payment_link_version'=>1];
    $snapshot=['payment'=>$payment,'details'=>['bayar_du'=>[['tagihan_daftar_ulang_id'=>$bill,'no_induk'=>$payment['NO_INDUK'],'kelas'=>'2','th_ajaran'=>'2024/2025','jumlah'=>1000],['tagihan_daftar_ulang_id'=>$bill,'no_induk'=>$payment['NO_INDUK'],'kelas'=>'2','th_ajaran'=>'2024/2025','jumlah'=>500]]]];
    payment_activity_record($koneksi,$id,'deleted',$super,$snapshot,null,'registration-archive-model:'.$id);
    payment_activity_record($koneksi,$id,'deleted',$super,$snapshot,null,'registration-archive-model-latest:'.$id);
    $model=registration_detail($koneksi,$bill,1,'deleted');
    rwm_assert($model['deleted_amount']===1500.0&&count($model['transactions'])===1,'Archive groups explicit detail once, not U_LAIN or repeated deletion events');
    rwm_assert($model['no_induk']==='0000<>&'&&$model['NO_induk_diknas']==='0001234','Leading zeros and special characters retained');
    $f=registration_filters($koneksi,['view'=>'deleted','q'=>'Siswa lama','tahun_ajaran'=>['2024/2025'],'kelas'=>['tingkat:2']]);$page=registration_page($koneksi,$f);
    $found=array_values(array_filter($page['rows'],static fn($g)=>(int)$g['tagihan_id']===$bill));rwm_assert(count($found)===1,'Deleted student and nonexistent live bill remain visible via snapshot');
    ob_start();registration_render_detail($model,registration_filter_query($f));$html=ob_get_clean();rwm_assert(str_contains($html,'&lt;script&gt;nama&lt;/script&gt;')&&!str_contains($html,'<script>nama</script>'),'Snapshot text escaped');
    foreach($model['transactions'] as $t)rwm_assert(!$t['capabilities']['can_print']&&!$t['capabilities']['can_edit'],'Archive has no actions');
    $unknown=$id+1;$missing=['payment'=>array_merge($payment,['id'=>$unknown]),'details'=>[]];payment_activity_record($koneksi,$unknown,'deleted',$super,$missing,null,'registration-unproven:'.$unknown);
    foreach(registration_archive_groups($koneksi) as $g)foreach($g['transactions'] as $t)rwm_assert((int)$t['payment']['id']!==$unknown,'No inferred DU amount without explicit evidence');
    echo "OK: deleted identities without live student/bill, leading zeros, mixed components, duplicate deletion dedup, safe HTML, unavailable evidence, rollback.\n";
}finally{$koneksi->rollback();}
