<?php
require_once __DIR__.'/legacy_transactions.php';
require_once __DIR__.'/payment_history.php';
$recordId=-$id-1000000000;$e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
try {lt_actor($koneksi,(int)$_SESSION['admin_id'],unit_active_id());$record=lop_record($koneksi,'payment',$recordId);$model=lt_model($koneksi,$recordId);}
catch(Throwable $error){http_response_code(403);exit($e($error->getMessage()));}
$cancel=($_GET['cancel']??'')==='1';$values=$_POST?:['date'=>$record['source_date']?str_replace(' ','T',$record['source_date']):'','period'=>$record['source_period'],'method'=>$record['method'],'note'=>$record['note'],'reason'=>'','amount'=>array_column($record['items'],'amount')];$preview=null;$error=null;
if($_SERVER['REQUEST_METHOD']==='POST')try {
    if(!hash_equals($_SESSION['csrf_payment'],(string)($_POST['csrf_token']??'')))throw new DomainException('Sesi kedaluwarsa. Muat ulang formulir.');
    $cancel=($_POST['cancelled']??'')==='1';$stage=$_POST['stage']??'preview';
    if($stage==='save'){
        $key=(string)($_POST['preview_key']??'');$pending=$_SESSION['legacy_payment_previews'][$key]??null;if($pending&&$pending['id']===$recordId&&$pending['actor']===(int)$_SESSION['admin_id']){$values=$pending['input'];$values['amount']=array_column($values['items'],'amount');$cancel=!empty($values['cancelled']);}
        if(!$pending||$pending['id']!==$recordId||$pending['actor']!==(int)$_SESSION['admin_id']||$pending['unit']!==unit_active_id()||$pending['expires']<time())throw new DomainException('Konfirmasi kedaluwarsa. Periksa kembali.');
        $result=lt_submit($koneksi,(int)$_SESSION['admin_id'],unit_active_id(),$recordId,$pending['version'],$pending['input'],$key);
        $_SESSION['flash']=['type'=>'success','msg'=>isset($result['request_id'])?'Pengajuan tersimpan dan menunggu Super Admin.':'Transaksi berhasil diperbarui.'];header('Location: '.$returnUrl);exit;
    }
    $items=$record['items'];foreach($items as $n=>&$item)$item['amount']=(string)($_POST['amount'][$n]??'');unset($item);
    $timeInput=payment_time_edit_input($_POST,$record,$model['payment']);
    $input=$timeInput+['date_day'=>$_POST['date_day']??substr((string)$model['payment']['TGL_BYR'],0,10),'date_clock'=>$_POST['date_clock']??(($model['payment']['time_precision']??'none')==='datetime'?substr((string)$model['payment']['TGL_BYR'],11,8):''),'period'=>$_POST['period']??'','method'=>$_POST['method']??$record['method'],'note'=>$_POST['note']??'','reason'=>$_POST['reason']??'','items'=>$items,'cancelled'=>$cancel,'preview_expires'=>time()+300];
    $preview=lop_correction_preview($koneksi,(int)$_SESSION['admin_id'],unit_active_id(),'payment',$recordId,(int)($_POST['version']??0),$input);
    $key=bin2hex(random_bytes(32));$_SESSION['legacy_payment_previews']=array_filter($_SESSION['legacy_payment_previews']??[],fn($v)=>$v['expires']>=time());$_SESSION['legacy_payment_previews'][$key]=['id'=>$recordId,'actor'=>(int)$_SESSION['admin_id'],'unit'=>unit_active_id(),'version'=>$record['version'],'input'=>$input,'expires'=>time()+300];
}catch(Throwable $ex){$error=$ex->getMessage();}
require __DIR__.'/legacy_payment_view.php';
