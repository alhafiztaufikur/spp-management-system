<?php
/** Real controller requests, only against a verified disposable clone. */
if (PHP_SAPI!=='cli' || getenv('SPP_TEST_ALLOW_MUTATION')!=='1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME'))) exit(1);
ob_start();
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/spp_billing.php';
require_once __DIR__.'/../includes/kelas.php';
require_once __DIR__.'/http_form_scope.php';
$base=rtrim((string)getenv('SPP_TEST_BASE_URL'),'/');
if (!preg_match('#^http://(?:127\.0\.0\.1|localhost):[0-9]+$#D',$base)) throw new RuntimeException('Use loopback HTTP');
spp_test_assert_http_clone($base,DB_NAME);
function lock_http_assert(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function lock_http(string $path,string $session,?array $post=null): array {
    global $base;
    $headers=['Cookie: PHPSESSID='.$session];if ($post!==null) $headers[]='Content-Type: application/x-www-form-urlencoded';
    $body=file_get_contents($base.'/'.$path,false,stream_context_create(['http'=>[
        'method'=>$post===null?'GET':'POST','header'=>implode("\r\n",$headers),'content'=>$post===null?'':http_build_query($post),
        'ignore_errors'=>true,'follow_location'=>0,'timeout'=>20]]));
    preg_match('/^HTTP\/\S+\s+(\d+)/',$http_response_header[0]??'',$m);
    lock_http_assert($body!==false,'HTTP unavailable');return [(int)($m[1]??0),(string)$body];
}
function lock_http_snapshot(mysqli $db): array {
    $result=[];foreach (['master_spp_tahun','master_spp_tarif','tagihan_spp','siswa','siswa_tahun_ajaran','spp_audit_log'] as $table)
        $result[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    return $result;
}
unit_set_context($koneksi,0);
$actor=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 ORDER BY id LIMIT 1")->fetch_row()[0];
$sessions=[];$year='2173/2174';
try {
    foreach ([1,2,3] as $unit) {
        unit_set_context($koneksi,$unit);$_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>$unit];
        [$first,$last]=unit_level_bounds();$amount=240000.0+10000*$unit;
        $koneksi->begin_transaction();
        try {
            $master=spp_master_ensure_year($koneksi,$year,true);$id=(int)$master['id'];$yearId=(int)$master['tahun_ajaran_id'];
            lock_http_assert($master['status']==='draft','Use a fresh clone for HTTP fixture year');
            $class=$koneksi->query('SELECT id,tingkat,kode_rombel FROM master_kelas WHERE tingkat='.$first.' AND is_active=1 AND is_placeholder=0 ORDER BY id LIMIT 1')->fetch_assoc();
            $classId=(int)$class['id'];$label=class_label($class);$level=(string)$first;$students=[];
            foreach ([1,2] as $index) {
                $nis=(string)random_int(9700000000,9799999999);$students[]=$nis;$name='TEST HTTP LOCK '.$index;
                $s=$koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,SPP_PERBULAN,is_active) VALUES(?,?,?,?,?,1)');
                $s->bind_param('sssid',$nis,$name,$level,$classId,$amount);$s->execute();$s->close();
                $s=$koneksi->prepare("INSERT INTO siswa_tahun_ajaran(tahun_ajaran_id,no_induk,kelas,master_kelas_id,kelas_rombel_snapshot,spp_perbulan_snapshot,status) VALUES(?,?,?,?,?,?,'aktif')");
                $s->bind_param('issisd',$yearId,$nis,$level,$classId,$label,$amount);$s->execute();$s->close();
            }
            $koneksi->commit();
        } catch (Throwable $error) { $koneksi->rollback();throw $error; }
        $session='sppratelock'.bin2hex(random_bytes(12));$sessions[]=$session;session_id($session);session_start();
        $_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>$unit];session_write_close();
        [$status,$html]=lock_http('master_spp.php?tahun='.urlencode($year),$session);
        lock_http_assert($status===200,'Draft GET failed');$form=spp_test_form_scope($html,'jumlah['.$first.']');
        lock_http_assert(!str_contains($form,' disabled'),'Draft input is disabled');
        preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$form,$m);$token=$m[1];
        $save=['aksi'=>'simpan_tarif','tahun_ajaran'=>$year,'csrf_token'=>$token,'jumlah'=>array_fill_keys(range($first,$last),$amount),'confirm_rate_change'=>'1','expected_rate_version'=>spp_master_rate_version(spp_master_state($koneksi,$id),spp_master_rates($koneksi,$id))];
        lock_http_assert(lock_http('master_spp.php',$session,$save)[0]===302,'Draft save failed');
        $publish=['aksi'=>'terbitkan','tahun_ajaran'=>$year,'csrf_token'=>$token,'selected_students'=>[$students[0]],'confirm_previous_debt'=>'1','confirm_spp_publish'=>'1','expected_rate_version'=>spp_master_rate_version(spp_master_state($koneksi,$id),spp_master_rates($koneksi,$id))];
        lock_http_assert(lock_http('master_spp.php',$session,$publish)[0]===302,'Publication POST failed');
        lock_http_assert((int)$koneksi->query('SELECT COUNT(*) FROM tagihan_spp WHERE master_spp_tahun_id='.$id)->fetch_row()[0]===12,'Publication did not commit');
        $save['jumlah']=array_fill_keys(range($first,$last),$amount+50000);
        $before=lock_http_snapshot($koneksi);
        lock_http_assert(lock_http('master_spp.php',$session,$save)[0]===302,'Stale form did not return normally');
        lock_http_assert($before===lock_http_snapshot($koneksi),'Stale form changed published data');
        [$status,$html]=lock_http('master_spp.php?tahun='.urlencode($year),$session);$form=spp_test_form_scope($html,'jumlah['.$first.']');
        lock_http_assert(substr_count($form,' disabled')===$last-$first+1 && str_contains($form,'id="spp-rate-edit"'),'Published form is editable');
        lock_http_assert(str_contains($html,'Tarif terkunci.'),'Lock note missing');
        foreach (['tutup','buka'] as $action) {
            lock_http_assert(lock_http('master_spp.php',$session,['aksi'=>$action,'tahun_ajaran'=>$year,'csrf_token'=>$token])[0]===302,'Year transition failed');
            lock_http_assert($koneksi->query('SELECT status FROM master_spp_tahun WHERE id='.$id)->fetch_row()[0]===($action==='tutup'?'closed':'published'),'Wrong year state');
            $before=lock_http_snapshot($koneksi);lock_http('master_spp.php',$session,$save);
            lock_http_assert($before===lock_http_snapshot($koneksi),'Close/reopen unlocked a stale form');
        }
        $publish['expected_rate_version']=spp_master_rate_version(spp_master_state($koneksi,$id),spp_master_rates($koneksi,$id));$publish['selected_students']=[$students[1]];lock_http('master_spp.php',$session,$publish);
        $bills=$koneksi->query('SELECT COUNT(*) n,MIN(tarif_dasar_snapshot) lo,MAX(tarif_dasar_snapshot) hi FROM tagihan_spp WHERE master_spp_tahun_id='.$id)->fetch_assoc();
        lock_http_assert((int)$bills['n']===24 && (float)$bills['lo']===$amount && (float)$bills['hi']===$amount,'Additional publication changed base');
        $before=lock_http_snapshot($koneksi);$save['csrf_token']='invalid';
        lock_http('master_spp.php',$session,$save);lock_http_assert($before===lock_http_snapshot($koneksi),'Bad CSRF changed data');
        echo "PASS: unit $unit real draft/save/publish, stale POST, locked form, close/reopen, additional publication and CSRF\n";
    }
    $session='sppratelock'.bin2hex(random_bytes(12));$sessions[]=$session;session_id($session);session_start();
    $_SESSION=['admin_id'=>$actor,'admin_role'=>'super_admin','active_unit_id'=>0];session_write_close();unit_set_context($koneksi,0);
    $before=lock_http_snapshot($koneksi);lock_http_assert(lock_http('master_spp.php',$session,$save)[0]===409,'Combined scope accepted mutation');
    lock_http_assert($before===lock_http_snapshot($koneksi),'Combined scope changed data');
} finally {
    foreach ($sessions as $session) { session_id($session);session_start();$_SESSION=[];session_destroy();session_write_close(); }
}
ob_end_flush();
