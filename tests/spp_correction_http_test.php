<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
ob_start();require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/spp_billing.php';require_once __DIR__.'/http_form_scope.php';
$base=rtrim((string)getenv('SPP_TEST_BASE_URL'),'/');if(!preg_match('#^http://(?:localhost|127\.0\.0\.1):[0-9]+$#D',$base))throw new RuntimeException('Use loopback');spp_test_assert_http_clone($base,DB_NAME);
function correction_http_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function correction_http(string $session,?array $post=null):array{
    global $base;$headers=['Cookie: PHPSESSID='.$session];if($post!==null)$headers[]='Content-Type: application/x-www-form-urlencoded';
    $body=file_get_contents($base.'/master_spp.php?tahun=2026%2F2027',false,stream_context_create(['http'=>['method'=>$post===null?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$post===null?'':http_build_query($post),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>20]]));preg_match('/^HTTP\/\S+\s+(\d+)/',$http_response_header[0]??'',$m);return [(int)($m[1]??0),(string)$body];
}
function correction_http_snapshot(mysqli $db):array{$result=[];foreach(['master_spp_tarif','tagihan_spp','siswa','siswa_tahun_ajaran','spp_audit_log'] as $table)$result[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY id')->fetch_all(MYSQLI_ASSOC);return $result;}
$sessions=[];
try{
 foreach([1,2,3] as $unit)foreach(['super_admin','admin','kasir'] as $role){
    unit_set_context($koneksi,0);$s=$koneksi->prepare("SELECT id FROM admin WHERE role=? AND is_active=1 AND (?='super_admin' OR unit_id=?) ORDER BY id LIMIT 1");$s->bind_param('ssi',$role,$role,$unit);$s->execute();$actor=(int)$s->get_result()->fetch_row()[0];$s->close();correction_http_assert($actor>0,'Actor fixture missing');
    $session='sppcorrection'.bin2hex(random_bytes(12));$sessions[]=$session;session_id($session);session_start();$_SESSION=['admin_id'=>$actor,'admin_role'=>$role,'active_unit_id'=>$unit];session_write_close();unit_set_context($koneksi,$unit);
    [$status,$html]=correction_http($session);correction_http_assert($status===200,'Master access failed');
    $form=spp_test_form_scope($html,'expected_rate_version');preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$form,$m);$token=$m[1];preg_match('/name="expected_rate_version" value="([a-f0-9]{64})"/',$form,$m);$version=$m[1];
    $master=$koneksi->query("SELECT mst.id FROM master_spp_tahun mst JOIN tahun_ajaran ta ON ta.id=mst.tahun_ajaran_id WHERE ta.label='2026/2027'")->fetch_assoc();$id=(int)$master['id'];$rates=spp_master_rates($koneksi,$id);[$first]=unit_level_bounds();$rates[$first]+=1;
    $post=['aksi'=>'ubah_tarif_terbit','tahun_ajaran'=>'2026/2027','csrf_token'=>$token,'expected_rate_version'=>$version,'jumlah'=>$rates];$before=correction_http_snapshot($koneksi);
    [$status,$review]=correction_http($session,$post);correction_http_assert($status===200&&str_contains($review,'Simpan perubahan tarif SPP?'),'Missing native confirmation');correction_http_assert($before===correction_http_snapshot($koneksi),'Unconfirmed POST wrote data');
    $post['confirm_rate_change']='1';$invalid=$post;$invalid['csrf_token']='invalid';correction_http($session,$invalid);correction_http_assert($before===correction_http_snapshot($koneksi),'Invalid CSRF wrote data');
    correction_http_assert(correction_http($session,$post)[0]===302,'Confirmed correction failed');correction_http_assert(spp_master_rates($koneksi,$id)===$rates,'Corrected rate not stored');
    $before=correction_http_snapshot($koneksi);$post['jumlah'][$first]+=1;correction_http($session,$post);correction_http_assert($before===correction_http_snapshot($koneksi),'Stale version wrote data');
    [$status,$html]=correction_http($session);correction_http_assert(str_contains($html,'sudah berubah'),'Stale conflict not explained');
    correction_http($session,['aksi'=>'tutup','tahun_ajaran'=>'2026/2027','csrf_token'=>$token]);$before=correction_http_snapshot($koneksi);correction_http($session,$post);correction_http_assert($before===correction_http_snapshot($koneksi),'Closed year corrected');
    correction_http($session,['aksi'=>'buka','tahun_ajaran'=>'2026/2027','csrf_token'=>$token]);
    echo "PASS: $role unit $unit access, native confirmation/no mutation, CSRF, correction, stale version and closed year\n";
 }
 unit_set_context($koneksi,0);
 foreach([['bendahara',1],['super_admin',0]] as [$role,$unit]){
    $s=$koneksi->prepare("SELECT id FROM admin WHERE role=? AND is_active=1 AND (?='super_admin' OR unit_id=?) LIMIT 1");$s->bind_param('ssi',$role,$role,$unit);$s->execute();$actor=(int)$s->get_result()->fetch_row()[0];$s->close();$session='sppcorrection'.bin2hex(random_bytes(12));$sessions[]=$session;session_id($session);session_start();$_SESSION=['admin_id'=>$actor,'admin_role'=>$role,'active_unit_id'=>$unit];session_write_close();$before=correction_http_snapshot($koneksi);$status=correction_http($session,$post)[0];correction_http_assert($status===($role==='bendahara'?302:409),'Wrong read-only access');correction_http_assert($before===correction_http_snapshot($koneksi),'Read-only actor wrote data');
 }
}finally{foreach($sessions as $session){session_id($session);session_start();$_SESSION=[];session_destroy();session_write_close();}}
ob_end_flush();
