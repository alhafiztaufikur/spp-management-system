<?php
session_start();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
if(empty($_SESSION['admin_id'])){http_response_code(401);echo json_encode(['ok'=>false,'message'=>'Sesi habis. Silakan login kembali.']);exit;}
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/transaction_authorization.php';require_once __DIR__.'/../includes/registration_history.php';
if(empty($_SESSION['admin_id']) || !hasRole(['admin','kasir','bendahara'])){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'Akses tidak tersedia.']);exit;}
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');echo json_encode(['ok'=>false,'message'=>'Gunakan GET.']);exit;}
try{
    $scope=$_GET['scope']??'native';if(!is_string($scope)||!in_array($scope,['native','combined'],true))throw new InvalidArgumentException('Jenis detail tidak valid.');
    if($scope==='combined'){
        unset($_GET['undated'],$_GET['include_undated']);
        if(!in_array($_GET['view']??'active',['active','legacy'],true))throw new InvalidArgumentException('Jenis riwayat tidak valid.');
        require_once __DIR__.'/../includes/registration_combined_ui.php';
        $unit=authorization_read_unit($koneksi,$_GET['selected_unit']??$_GET['unit_id']??null);
        if(isset($_GET['unit_id'])&&(!is_scalar($_GET['unit_id'])||(int)$_GET['unit_id']!==$unit))throw new InvalidArgumentException('Unit pilihan berbeda.');
        $group=rc_selected_group($koneksi,$_GET,$unit);$source=mh_choice($_GET,'source',['all','system','legacy'],($_GET['view']??'')==='legacy'?'legacy':'all');
        if(empty($_SESSION['csrf_payment']))$_SESSION['csrf_payment']=bin2hex(random_bytes(32));
        $model=rc_detail_model($koneksi,$group,$source);ob_start();rc_render_detail($model,$_GET);$html=ob_get_clean();
        $caps=[];foreach($model['capabilities'] as $id=>$capability)$caps[]=['id'=>$id,'capabilities'=>$capability];
        echo json_encode(['ok'=>true,'html'=>$html,'capabilities'=>$caps,'selection'=>['id'=>(int)$group['id'],'unit'=>(int)$group['unit_id'],'year'=>$group['th_ajaran']]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
    }
    $raw=$_GET['tagihan_id']??'';if(!is_scalar($raw)||!preg_match('/^[1-9][0-9]*$/D',(string)$raw))throw new InvalidArgumentException('Nomor tagihan tidak valid.');
    $f=registration_filters($koneksi,$_GET);$query=registration_filter_query($f);$query['selected']=(int)$raw;
    $unit=authorization_read_unit($koneksi,$_GET['unit_id']??null);
    $query['selected_unit']=$unit;
    $model=registration_detail($koneksi,(int)$raw,$unit,$f['view'],$f['values']['operator']);
    if(empty($_SESSION['csrf_payment']))$_SESSION['csrf_payment']=bin2hex(random_bytes(32));
    ob_start();registration_render_detail($model,$query);$html=ob_get_clean();
    echo json_encode(['ok'=>true,'html'=>$html,'capabilities'=>array_map(static fn($t)=>['id'=>(int)$t['payment']['id'],'capabilities'=>$t['capabilities']],array_values($model['transactions']))],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $error){
    $expected=$error instanceof InvalidArgumentException||$error instanceof OutOfBoundsException||($error instanceof RuntimeException&&!($error instanceof mysqli_sql_exception));
    if(!$expected)error_log('Detail Daftar Ulang: '.$error->getMessage());
    http_response_code($error instanceof InvalidArgumentException?400:($expected?404:500));echo json_encode(['ok'=>false,'message'=>$expected?$error->getMessage():'Detail belum dapat dimuat. Silakan coba lagi.'],JSON_UNESCAPED_UNICODE);
}
