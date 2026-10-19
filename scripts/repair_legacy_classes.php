<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/legacy_class_links.php';
$options=getopt('',['apply','confirm-target:','backup-file:','actor:','unit:']);$apply=isset($options['apply']);
if(!special_classes_ready($koneksi)||!lop_ready($koneksi))throw new RuntimeException('Pasang skema Kelas Khusus/Legacy melalui CLI sebelum perbaikan.');
$actor=(int)($options['actor']??0);
if($apply){
    if(($options['confirm-target']??'')!==DB_NAME)throw new RuntimeException('Nama database tujuan harus dikonfirmasi persis.');
    if(DB_NAME==='db_spp')throw new RuntimeException('Perintah ini tidak menerapkan perubahan pada database utama db_spp.');
    $backup=realpath($options['backup-file']??'');$root=strtolower(str_replace('\\','/',realpath(__DIR__.'/..'))).'/';
    if(!$backup||!is_file($backup)||filesize($backup)<1000||str_starts_with(strtolower(str_replace('\\','/',$backup)),$root))throw new RuntimeException('Backup SQL di luar folder aplikasi wajib tersedia.');
    $f=fopen($backup,'rb');$header=fread($f,1024);fclose($f);if(!str_contains($header,'MySQL dump'))throw new RuntimeException('Backup SQL MySQL tidak dikenali.');
    $who=special_class_query($koneksi,"SELECT id FROM admin WHERE id=? AND role='super_admin' AND is_active=1",[$actor])->fetch_assoc();if(!$who)throw new RuntimeException('ID Super Admin aktif wajib diberikan melalui --actor.');
}
if(isset($options['unit'])&&!preg_match('/^[1-3]$/D',(string)$options['unit']))throw new RuntimeException('Unit harus 1, 2, atau 3.');
$units=isset($options['unit'])?[(int)$options['unit']]:[1,2,3];$results=[];
foreach($units as $unit){
    if(!in_array($unit,[1,2,3],true))throw new RuntimeException('Unit harus 1, 2, atau 3.');
    $_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($koneksi,$unit);
    if($apply)$koneksi->begin_transaction();
    try{
        if($apply){special_class_query($koneksi,"SELECT id FROM admin WHERE id=? AND role='super_admin' AND is_active=1 FOR UPDATE",[$actor])->fetch_assoc()?:throw new RuntimeException('Hak Super Admin berubah.');special_class_query($koneksi,'SELECT id FROM master_kelas FOR UPDATE');special_class_query($koneksi,'SELECT id FROM legacy_op_groups FOR UPDATE');}
        $results[$unit]=legacy_class_repair($koneksi,$actor,$apply);
        if($apply)$koneksi->commit();
    }catch(Throwable $e){if($apply)$koneksi->rollback();throw $e;}
}
echo json_encode(['database'=>DB_NAME,'mode'=>$apply?'applied':'check','changes'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
