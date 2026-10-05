<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
require __DIR__.'/../koneksi.php';require __DIR__.'/http_form_scope.php';
$base=rtrim((string)getenv('SPP_HTTP_BASE'),'/');spp_test_assert_http_clone($base,DB_NAME);
function scope_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function scope_request(string $base,string $path,string $sid,?array $post=null):array{
    $options=['method'=>$post===null?'GET':'POST','follow_location'=>0,'ignore_errors'=>true,'timeout'=>40,'header'=>'Cookie: '.session_name().'='.$sid."\r\n"];
    if($post!==null){$options['header'].="Content-Type: application/x-www-form-urlencoded\r\n";$options['content']=http_build_query($post);}
    $body=file_get_contents($base.'/'.$path,false,stream_context_create(['http'=>$options]));
    return [(int)explode(' ',$http_response_header[0]??'')[1],(string)$body,$http_response_header??[]];
}
function scope_fingerprints(mysqli $db):array{
    $hashes=[];
    foreach($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME<>'admin' ORDER BY TABLE_NAME")->fetch_all(MYSQLI_ASSOC) as $table){
        $name=$table['TABLE_NAME'];$rows=$db->query("SELECT * FROM `$name`")->fetch_all(MYSQLI_ASSOC);
        $encoded=array_map(static fn($row)=>json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$rows);sort($encoded,SORT_STRING);
        $hashes[$name]=hash('sha256',implode("\n",$encoded));
    }return $hashes;
}
$before=scope_fingerprints($koneksi);
$account=$koneksi->query("SELECT * FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_assoc();
$sid='allunits'.bin2hex(random_bytes(12));session_id($sid);session_start();
$_SESSION=['admin_id'=>(int)$account['id'],'admin_role'=>'super_admin','active_unit_id'=>0,'csrf_unit_switch'=>bin2hex(random_bytes(32))];$token=$_SESSION['csrf_unit_switch'];session_write_close();
try{
    foreach(['pembayaran/form.php','pembayaran/edit.php?id=1','tabungan/masuk.php','tabungan/keluar.php','otorisasi_transaksi.php'] as $path){
        [$status,$body]=scope_request($base,$path,$sid);scope_assert($status===200&&str_contains($body,'Pilih unit untuk transaksi'),$path.' missing gate');
        preg_match('/<select id="sidebar-unit-select".*?<\/select>/s',$body,$select);scope_assert(!str_contains($select[0]??'','value="0"'),$path.' exposes all');
        scope_assert(!str_contains($body,'id="form-bayar"')&&!str_contains($body,'id="form-tabungan"'),$path.' loaded transaction form');
    }
    $targets=['pembayaran/proses.php','tabungan/proses.php','otorisasi_transaksi.php','siswa/daftar.php','master_kelas.php','master_spp.php','master_daftar_ulang.php','master_biaya_lain.php','tagihan_tunggakan_check.php'];
    foreach($targets as $path){[$status]=scope_request($base,$path,$sid,['aksi'=>'hapus','action'=>'approve','id'=>1,'unit_id'=>1,'csrf_token'=>$token]);scope_assert($status===409,$path.' allowed all-unit write');}
    [$status,$body]=scope_request($base,'role_management.php',$sid);scope_assert($status===200&&str_contains($body,'id="form-tambah-akun"'),'Super Admin cannot manage accounts from Semua Unit');
    [$status]=scope_request($base,'role_management.php',$sid,['aksi'=>'tambah','unit_id'=>2,'csrf_token'=>'invalid']);scope_assert($status===302,'Role Management did not enforce CSRF from Semua Unit');
    [$status]=scope_request($base,'pembayaran/proses.php?aksi=hapus&id=1',$sid);scope_assert($status===409,'GET write endpoint escaped gate');
    [$status]=scope_request($base,'unit_switch.php',$sid,['unit_id'=>1]);scope_assert($status===403,'CSRF bypass');
    [$status]=scope_request($base,'unit_switch.php',$sid,['unit_id'=>0,'next'=>'/pembayaran/form.php','csrf_token'=>$token]);scope_assert($status===422,'All accepted for transaction target');
    [$status,$body,$headers]=scope_request($base,'unit_switch.php',$sid,['unit_id'=>2,'next'=>'/laporan/template.php?template=per-item&unit=all&kelas=rombel:1&operator=1&tanggal_awal=2026-09-01&edit=1','csrf_token'=>$token]);
    scope_assert($status===303,'Explicit unit switch failed');$location=implode("\n",$headers);scope_assert(!str_contains($location,'unit=all')&&!str_contains($location,'rombel')&&!str_contains($location,'operator=')&&str_contains($location,'tanggal_awal=2026-09-01'),'Stale filters retained');
    foreach(['pembayaran/form.php?unit=all','tabungan/masuk.php?unit_id=0'] as $path){[$status]=scope_request($base,$path,$sid);scope_assert($status===422,'All-unit transaction query accepted');}
    [$status]=scope_request($base,'pembayaran/proses.php',$sid,['unit_id'=>0]);scope_assert($status===422,'All-unit transaction POST accepted');
    [$status,$body]=scope_request($base,'pembayaran/form.php',$sid);scope_assert($status===200&&str_contains($body,'id="form-bayar"'),'Concrete form did not load');preg_match('/<select id="sidebar-unit-select".*?<\/select>/s',$body,$select);scope_assert(!str_contains($select[0]??'','value="0"'),'Concrete transaction exposes all');
    // Revalidate the account, never trust a previously issued Super Admin session.
    $id=(int)$account['id'];$koneksi->query("UPDATE admin SET role='admin',unit_id=2 WHERE id=$id");
    [$status]=scope_request($base,'unit_switch.php',$sid,['unit_id'=>0,'next'=>'/dashboard.php','csrf_token'=>$token]);scope_assert($status!==303,'Demoted account selected all');
    $koneksi->query("UPDATE admin SET is_active=0 WHERE id=$id");[$status]=scope_request($base,'dashboard.php',$sid);scope_assert($status===302,'Inactive account retained access');
}finally{
    $id=(int)$account['id'];$role=$koneksi->real_escape_string($account['role']);$unit=$account['unit_id']===null?'NULL':(int)$account['unit_id'];$active=(int)$account['is_active'];
    $koneksi->query("UPDATE admin SET role='$role',unit_id=$unit,is_active=$active WHERE id=$id");
    session_id($sid);session_start();$_SESSION=[];session_destroy();session_write_close();
}
scope_assert($before===scope_fingerprints($koneksi),'Read-only/rejected requests changed business data');
echo "OK: transaction gates, all write paths, CSRF, stale filters, role refresh and inactive sessions\n";
