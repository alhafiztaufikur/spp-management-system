<?php
require_once __DIR__.'/special_classes.php';
/** Handle only special-class actions; the existing master controller handles everything else. */
function special_classes_handle(mysqli $db): void {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')return;
    $action=$_POST['aksi']??'';
    if(!is_string($action))return;
    $isToggle=$action==='toggle'&&special_classes_ready($db)&&($r=class_find($db,(int)($_POST['id']??0)))&&class_kind($r)==='custom';
    if(!in_array($action,['tambah_custom','update_custom','hapus_custom'],true)&&!$isToggle)return;
    $id=filter_var($_POST['id']??0,FILTER_VALIDATE_INT);$return=$id>0?'special_edit='.$id:'special_new=1';
    try{
        if(!is_string($_POST['csrf_token']??null)||!hash_equals($_SESSION['csrf_master_kelas']??'',$_POST['csrf_token']))throw new DomainException('Permintaan tidak valid atau sesi telah kedaluwarsa.');
        if($id===false||$id<0)throw new DomainException('Identitas kelas tidak valid.');
        if($action==='hapus_custom'){
            $token=$_SESSION['special_delete']??[];
            if(($token['id']??0)!==$id||($token['expires']??0)<time()||!is_string($_POST['delete_token']??null)||!hash_equals($token['key']??'',$_POST['delete_token']))throw new DomainException('Konfirmasi hapus kedaluwarsa. Periksa ulang kelas.');
            special_class_delete($db,$id,$token['version']);unset($_SESSION['special_delete']);$message='Kelas khusus yang belum digunakan berhasil dihapus.';
        }else{
            $current=$id?class_find($db,$id):null;
            $name=$isToggle?$r['nama_custom']:($_POST['nama_custom']??null);$active=$_POST[$isToggle?'target_active':'is_active']??($current['is_active']??1);
            if(!is_string($name)||!is_scalar($active)||!in_array((string)$active,['0','1'],true))throw new DomainException('Nama atau status kelas tidak valid.');
            $version=$_POST['special_version']??null;if($version!==null&&!is_string($version))throw new DomainException('Versi kelas tidak valid.');
            $request=$_POST['special_request']??'';$digest=hash('sha256',json_encode([$action,$id,$name,(int)$active,$version]));$seen=is_string($request)?($_SESSION['special_requests'][$request]??null):null;
            if($seen&&hash_equals($seen,$digest))$message='Perubahan ini sudah tersimpan.';
            else{special_class_save($db,$id,$name,(int)$active,$version);$message=$isToggle?'Status kelas khusus diperbarui. Anggota dan histori tetap tersimpan.':'Kelas khusus berhasil disimpan.';
                if(is_string($request)&&preg_match('/^[a-f0-9]{32}$/D',$request)){$_SESSION['special_requests'][$request]=$digest;$_SESSION['special_requests']=array_slice($_SESSION['special_requests'],-20,null,true);}}
        }
        unset($_SESSION['special_old']);$_SESSION['flash']=['type'=>'success','msg'=>$message];$return='';
    }catch(Throwable $e){$_SESSION['special_old']=array_intersect_key($_POST,array_flip(['id','nama_custom','is_active']));$_SESSION['flash']=['type'=>'error','msg'=>$e instanceof DomainException?$e->getMessage():'Perubahan kelas dibatalkan. Muat ulang dan coba kembali.'];}
    $context=[];foreach(['special_q','special_kind','special_status','special_page','special_per_page','special_member_page'] as $key)if(isset($_POST[$key])&&is_string($_POST[$key]))$context[$key]=mb_substr($_POST[$key],0,100);
    $query=http_build_query($context);header('Location: master_kelas.php?'.$query.($return!==''?($query!==''?'&':'').$return:'').'#special-classes');exit;
}
