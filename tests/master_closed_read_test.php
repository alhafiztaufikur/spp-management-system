<?php
/** Close a restored year only inside this connection's rollback, then render the real page. */
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))throw new RuntimeException('Use an isolated audit database.');
$root=realpath($argv[1]??'');$page=$argv[2]??'';$role=$argv[3]??'';$unit=(int)($argv[4]??0);$audit=realpath((string)getenv('SPP_PERF_AUDIT_ROOT'));
if(!$root||!$audit||!in_array($page,['master_spp','master_daftar_ulang'],true)||!in_array($role,['super_admin','admin'],true)||!in_array($unit,[1,2,3],true))throw new RuntimeException('Invalid closed-year read case.');
$sessions=json_decode(file_get_contents($audit.'/sessions.json'),true,512,JSON_THROW_ON_ERROR);session_save_path($audit.'/sessions');session_id($sessions[$role.'-'.$unit]);session_start();
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['SCRIPT_NAME']='/'.$page.'.php';$_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME'];$_GET=['tahun'=>'2026/2027'];$_POST=[];
require $root.'/koneksi.php';if((int)$koneksi->query('SELECT @@port')->fetch_row()[0]===3306)throw new RuntimeException('Separate server required.');
$koneksi->begin_transaction();
try{
    $koneksi->query("UPDATE tahun_ajaran SET status='closed',closed_at='2026-11-11 10:00:00' WHERE label='2026/2027'");
    $koneksi->query("UPDATE master_spp_tahun m JOIN tahun_ajaran y ON y.id=m.tahun_ajaran_id AND y.unit_id=m.unit_id SET m.status='closed',m.closed_at='2026-11-11 10:00:00' WHERE y.label='2026/2027'");
    session_write_close();$level=ob_get_level();ob_start();chdir($root);require $root.'/'.$page.'.php';while(ob_get_level()>$level+1)ob_end_flush();$html=ob_get_clean();
    $names=$page==='master_spp'?['students','studentTariffs','stats','rates','rateVersion']:['masters','activeByClass','issuedByClass','coveredByClass','billSummary','canEdit'];$model=[];foreach($names as $name)$model[$name]=$GLOBALS[$name]??null;
    $html=preg_replace('/mtime=[0-9]+/','mtime=TIME',$html);$html=preg_replace('/([?&]v=)[0-9]{9,}/','$1TIME',$html);
    if(!str_contains($html,'Buka Kembali')||($page==='master_daftar_ulang'&&!empty($GLOBALS['canEdit'])))throw new RuntimeException('Closed year remains editable.');
    echo json_encode(['page'=>$page,'role'=>$role,'unit'=>$unit,'html_sha256'=>hash('sha256',$html),'model_sha256'=>hash('sha256',json_encode($model,JSON_THROW_ON_ERROR))],JSON_THROW_ON_ERROR),PHP_EOL;
}finally{$koneksi->rollback();}
