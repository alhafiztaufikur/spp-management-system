<?php
/** Mutation tests must use an owned isolated MySQL instance and private sessions. */
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/http_form_scope.php';require_once __DIR__.'/../koneksi.php';
spp_test_isolated_manifest($koneksi);spp_test_assert_http_clone(getenv('SPP_TEST_BASE_URL'),DB_NAME);
require_once __DIR__.'/../includes/transaction_authorization.php';
require_once __DIR__.'/../includes/database_backup.php';
require_once __DIR__.'/../includes/legacy_import.php';
$root=getenv('SPP_OPERATIONS_QA');if(!$root||str_starts_with(strtolower(str_replace('\\','/',$root)),strtolower(str_replace('\\','/',realpath(__DIR__.'/..')))))throw new RuntimeException('Private QA directory required.');
session_save_path(getenv('SPP_QA_SESSION_PATH'));$checks=0;
function op_assert(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
function op_http(string $sid,string $path,?array $post=null):array{
    $curl=curl_init(rtrim(getenv('SPP_TEST_BASE_URL'),'/').'/'.$path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>45,CURLOPT_COOKIE=>'PHPSESSID='.$sid]);
    if($post!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post),CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
    $body=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);curl_close($curl);
    if($body===false)throw new RuntimeException($error);return [$code,$body];
}
function op_session(array $actor,int $unit):array{
    $sid='operations'.bin2hex(random_bytes(12));session_id($sid);session_start();
    $paths=database_access_paths(DB_NAME,(int)getenv('SPP_DB_PORT'));
    $generation=is_file($paths['generation'])?trim(file_get_contents($paths['generation'])):'0';
    $csrf=bin2hex(random_bytes(32));$_SESSION=['database_generation'=>$generation,'admin_id'=>$actor['id'],'admin_role'=>$actor['role'],'admin_nama'=>$actor['name'],'admin_unit_id'=>$actor['unit'],'active_unit_id'=>$unit,
        'csrf_unit_switch'=>$csrf,'csrf_backup'=>$csrf,'csrf_payment'=>$csrf,'csrf_legacy_import'=>$csrf];session_write_close();
    return ['id'=>$sid,'csrf'=>$csrf];
}
unit_set_context($koneksi,0);$actors=[];$suffix=bin2hex(random_bytes(3));
foreach(['super_admin','admin','kasir','bendahara'] as $role){
    $name='Petugas Uji '.$role;$username='qa.operations.'.$role.'.'.$suffix;$home=$role==='super_admin'?null:1;
    $s=$koneksi->prepare("INSERT INTO admin(username,password,nama,role,unit_id,is_active) VALUES(?,'!disabled-qa!',?,?,?,1)");$s->bind_param('sssi',$username,$name,$role,$home);$s->execute();$actors[$role]=['id'=>(int)$koneksi->insert_id,'role'=>$role,'name'=>$name,'unit'=>$home];$s->close();
}
$payments=[];$nis='QAOP'.$suffix;
foreach([1,2,3] as $unit){
    unit_set_context($koneksi,$unit);$class=$koneksi->query('SELECT id,tingkat FROM master_kelas WHERE is_active=1 AND is_placeholder=0 ORDER BY tingkat,id LIMIT 1')->fetch_assoc();
    $name='Siswa Uji Operasional '.$unit;$level=(string)$class['tingkat'];$cid=(int)$class['id'];
    $s=$koneksi->prepare('INSERT INTO siswa(NO_INDUK,NAMA,KELAS,master_kelas_id,PSB) VALUES(?,?,?,?,100000)');$s->bind_param('sssi',$nis,$name,$level,$cid);$s->execute();$s->close();
    $s=$koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,U_PSB,TGL_BYR,BULAN,TAHUN,sistem_pembayaran,total_jumlah,payment_link_version) VALUES(?,?,?,100,NOW(),'10','2026','Tunai',100,1)");$s->bind_param('ssi',$nis,$level,$cid);$s->execute();$pid=(int)$koneksi->insert_id;$s->close();$payments[$unit]=$pid;
    $koneksi->begin_transaction();payment_activity_record($koneksi,$pid,'created',$actors['super_admin']['id'],null,transaction_authorization_snapshot($koneksi,$pid)['data'],'created:'.$pid);$koneksi->commit();
}
db_backup_store(true);$sessions=[];
$menus=['dashboard.php','pembayaran/form.php','pembayaran/lihat.php','pembayaran/riwayat_daftar_ulang.php','siswa/daftar.php','master_kelas.php','master_spp.php','master_biaya_lain.php','master_daftar_ulang.php','tabungan/masuk.php','tabungan/keluar.php','tabungan/riwayat.php','tabungan/cetak.php','laporan/index.php','laporan/global.php','laporan/surat_laporan.php','backup_restore.php'];
foreach($actors as $role=>$actor){
    $sessions[$role]=op_session($actor,1);$session=$sessions[$role];
    foreach([0,1,2,3] as $unit){
        if($role==='bendahara')continue;
        [$code]=op_http($session['id'],'unit_switch.php',['unit_id'=>(string)$unit,'csrf_token'=>$session['csrf'],'next'=>'/dashboard.php']);op_assert($code===303,"Switch $role/$unit: $code");
        foreach($menus as $menu){[$code,$body]=op_http($session['id'],$menu);op_assert($code===200,"Menu $role/$unit/$menu: $code");op_assert(!str_contains($body,'Fatal error'),'PHP error '.$menu);}
        foreach(['role_management.php','otorisasi_transaksi.php','otorisasi_detail.php?id=1','otorisasi_aktivitas.php?id=1','otorisasi_export_pdf.php'] as $menu){[$code]=op_http($session['id'],$menu);if($role!=='super_admin')op_assert($code===403,"Restricted $role/$menu: $code");}
        if($unit>0){
            [$code,$body]=op_http($session['id'],'pembayaran/edit.php?id='.$payments[$unit]);op_assert($code===200,'Edit form petugas lain '.$role);op_assert(str_contains($body,'name="_operational_unit" value="'.$unit.'"'),'Form missing scope marker');
            [$code,$body]=op_http($session['id'],'laporan/cetak_struk.php?id='.$payments[$unit]);op_assert($code===200&&str_contains($body,'receipt_print.css'),'Cross-unit receipt');
            [$code]=op_http($session['id'],'pembayaran/proses.php',['aksi'=>'hapus','id'=>$payments[$unit],'csrf_token'=>$session['csrf'],'_operational_unit'=>(string)($unit===1?2:1)]);op_assert($code===409,'Stale form accepted');
        }else{
            [$code,$body]=op_http($session['id'],'pembayaran/form.php');op_assert(str_contains($body,'Pilih unit untuk transaksi'),'All-unit gate missing');
            [$code]=op_http($session['id'],'master_kelas.php',['aksi'=>'toggle','_operational_unit'=>'0']);op_assert($code===409,'All-unit write accepted');
        }
    }
    if($role==='bendahara'){
        foreach(['role_management.php','otorisasi_transaksi.php','otorisasi_detail.php?id=1','otorisasi_aktivitas.php?id=1','backup_api.php','restore_api.php?action=status','legacy_import_api.php'] as $menu){[$code]=op_http($session['id'],$menu);op_assert($code===403,'Bendahara privilege expansion '.$menu);}
        [$code]=op_http($session['id'],'unit_switch.php',['unit_id'=>'2','csrf_token'=>$session['csrf']]);op_assert($code===302,'Bendahara unit switch accepted');
    }
}
$admin=$sessions['admin'];$cashier=$sessions['kasir'];$pid=$payments[2];
foreach([$admin,$cashier] as $session)op_http($session['id'],'unit_switch.php',['unit_id'=>'2','csrf_token'=>$session['csrf'],'next'=>'/pembayaran/lihat.php']);
unit_set_context($koneksi,2);$before=$koneksi->query('SELECT * FROM bayar WHERE id='.$pid)->fetch_assoc();
foreach([$admin,$cashier] as $session){
    [$code]=op_http($session['id'],'pembayaran/proses.php',['aksi'=>'hapus','id'=>$pid,'csrf_token'=>$session['csrf'],'_operational_unit'=>'2','authorization_reason'=>'Pengujian koreksi petugas lain melalui riwayat pembayaran.']);op_assert($code===302,'Request not queued');
    op_assert($koneksi->query('SELECT * FROM bayar WHERE id='.$pid)->fetch_assoc()===$before,'Proposal changed payment');
    $request=$koneksi->query('SELECT * FROM transaksi_otorisasi WHERE bayar_id='.$pid.' ORDER BY id DESC LIMIT 1')->fetch_assoc();op_assert($request['status']==='pending','Pending proposal missing');
    [$code,$body]=op_http($session['id'],'pembayaran/lihat.php?selected='.$pid.'&tanggal_awal=2026-01-01&tanggal_akhir=2026-12-31');op_assert($code===200&&str_contains($body,'Batalkan Pengajuan Saya'),'Own cancel unavailable');
    [$code]=op_http($session['id'],'pembayaran/proses.php',['aksi'=>'batal_pengajuan','request_id'=>$request['id'],'id'=>$pid,'csrf_token'=>$session['csrf'],'_operational_unit'=>'2']);op_assert($code===303,'Cancel failed');
    op_assert($koneksi->query('SELECT status FROM transaksi_otorisasi WHERE id='.(int)$request['id'])->fetch_row()[0]==='cancelled','Cancel state not recorded');
}
$koneksi->query("INSERT INTO bayar(NO_INDUK,KELAS,U_PSB,TGL_BYR,BULAN,TAHUN,sistem_pembayaran,total_jumlah,payment_link_version) SELECT NO_INDUK,KELAS,100,NOW(),'10','2026','Tunai',100,1 FROM bayar WHERE id=$pid");$pid=(int)$koneksi->insert_id;
$koneksi->begin_transaction();payment_activity_record($koneksi,$pid,'created',$actors['super_admin']['id'],null,transaction_authorization_snapshot($koneksi,$pid)['data'],'created:'.$pid);$koneksi->commit();$before=$koneksi->query('SELECT * FROM bayar WHERE id='.$pid)->fetch_assoc();
$super=$sessions['super_admin'];op_http($super['id'],'unit_switch.php',['unit_id'=>'2','csrf_token'=>$super['csrf'],'next'=>'/otorisasi_transaksi.php']);
op_http($admin['id'],'pembayaran/proses.php',['aksi'=>'hapus','id'=>$pid,'csrf_token'=>$admin['csrf'],'_operational_unit'=>'2','authorization_reason'=>'Siapkan antrean keputusan pada fixture pengujian.']);
[$code,$body]=op_http($super['id'],'otorisasi_transaksi.php');$decisionForm=spp_test_form_scope($body,'request_id');preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$decisionForm,$decisionToken);op_assert(!empty($decisionToken[1]),'Decision CSRF missing');
foreach(['rejected'=>$admin,'approved'=>$cashier] as $decision=>$session){
    op_http($session['id'],'pembayaran/proses.php',['aksi'=>'hapus','id'=>$pid,'csrf_token'=>$session['csrf'],'_operational_unit'=>'2','authorization_reason'=>'Pengajuan petugas lain untuk pengujian keputusan.']);
    $request=$koneksi->query('SELECT * FROM transaksi_otorisasi WHERE bayar_id='.$pid.' ORDER BY id DESC LIMIT 1')->fetch_assoc();
    [$forged]=op_http($admin['id'],'pembayaran/proses.php',['aksi'=>'otorisasi_setujui','request_id'=>$request['id'],'csrf_token'=>$admin['csrf'],'_operational_unit'=>'2']);op_assert($forged===403,'Admin approved a request');
    if($decision==='rejected'){
        [$code]=op_http($super['id'],'otorisasi_transaksi.php',['action'=>'reject','request_id'=>$request['id'],'csrf_token'=>$decisionToken[1],'decision_note'=>'Ditolak untuk pengujian; transaksi tetap.']);op_assert($code===302,'Super rejection failed');
        op_assert($koneksi->query('SELECT * FROM bayar WHERE id='.$pid)->fetch_assoc()===$before,'Rejection changed payment');
        [$code,$body]=op_http($session['id'],'pembayaran/lihat.php?selected='.$pid.'&search='.$nis.'&tanggal_awal=2026-01-01&tanggal_akhir=2026-12-31');op_assert(str_contains($body,'Ditolak untuk pengujian'),'Decision note missing from payment history');
    }else{
        [$code]=op_http($super['id'],'pembayaran/proses.php',['aksi'=>'otorisasi_setujui','request_id'=>$request['id'],'csrf_token'=>$decisionToken[1],'_operational_unit'=>'2']);op_assert($code===302,'Super application failed');
        op_assert((int)$koneksi->query('SELECT COUNT(*) FROM bayar WHERE id='.$pid)->fetch_row()[0]===0,'Approved deletion not applied');
        op_assert($koneksi->query('SELECT status FROM transaksi_otorisasi WHERE id='.(int)$request['id'])->fetch_row()[0]==='approved','Approval audit missing');
    }
}
foreach(['admin','kasir'] as $role){
    $session=$sessions[$role];[$code]=op_http($session['id'],'backup_api.php');op_assert($code===200,'Backup status '.$role);
    [$code]=op_http($session['id'],'backup_api.php',['action'=>'settings','daily_time'=>'18:00','retention_days'=>'90','version'=>'0','csrf_token'=>'wrong']);op_assert($code===403,'Backup CSRF bypass');
    $_SESSION=['admin_id'=>$actors[$role]['id'],'admin_role'=>$role,'active_unit_id'=>2];op_assert(db_backup_actor($actors[$role]['id'])['id']===$actors[$role]['id'],'Worker backup actor '.$role);legacy_actor($koneksi,$actors[$role]['id'],2);$checks++;
}
foreach(['admin','kasir'] as $role)foreach([1,2,3] as $unit){
    $session=$sessions[$role];op_http($session['id'],'unit_switch.php',['unit_id'=>(string)$unit,'csrf_token'=>$session['csrf'],'next'=>'/pembayaran/form.php']);unit_set_context($koneksi,$unit);
    [$code,$body]=op_http($session['id'],'pembayaran/form.php');$form=spp_test_form_scope($body,'no_induk');
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$form,$csrf);preg_match('/name="request_key" value="([a-f0-9]+)"/',$form,$key);
    op_assert($code===200&&!empty($csrf[1])&&!empty($key[1]),'Payment tokens missing');
    $count=(int)$koneksi->query("SELECT COUNT(*) FROM bayar WHERE NO_INDUK='$nis'")->fetch_row()[0];
    [$code,$body]=op_http($session['id'],'pembayaran/proses.php',['aksi'=>'input','payment_plan'=>'monthly','no_induk'=>$nis,'bulan_bayar'=>'10','tahun_bayar'=>'2026','sistem_pembayaran'=>'Tunai','uang_psb'=>'200','uang_spp'=>'0','uang_komite'=>'0','csrf_token'=>$csrf[1],'request_key'=>$key[1],'_operational_unit'=>(string)$unit]);
    op_assert($code===302,'Payment handler response');op_assert((int)$koneksi->query("SELECT COUNT(*) FROM bayar WHERE NO_INDUK='$nis'")->fetch_row()[0]===$count+1,'Cross-unit payment not created: '.$role.'/'.$unit);
    $created=$koneksi->query("SELECT id,unit_id,user_id,total_jumlah FROM bayar WHERE NO_INDUK='$nis' ORDER BY id DESC LIMIT 1")->fetch_assoc();op_assert((int)$created['unit_id']===$unit&&(int)$created['user_id']===$actors[$role]['id']&&(float)$created['total_jumlah']===200.0,'Wrong payment identity/amount');
    foreach(['masuk'=>5000,'keluar'=>1000] as $kind=>$amount){
        [$code,$body]=op_http($session['id'],'tabungan/'.$kind.'.php');$form=spp_test_form_scope($body,'no_induk');preg_match('/name="csrf_token" value="([a-f0-9]+)"/',$form,$csrf);preg_match('/name="request_key" value="([a-f0-9]+)"/',$form,$key);
        $balance=(float)($koneksi->query("SELECT SALDO FROM tabungan WHERE NO_INDUK='$nis'")->fetch_row()[0]??0);
        [$code]=op_http($session['id'],'tabungan/proses.php',['aksi'=>$kind,'no_induk'=>$nis,'nominal'=>(string)$amount,'tanggal'=>date('Y-m-d'),'csrf_token'=>$csrf[1],'request_key'=>$key[1],'_operational_unit'=>(string)$unit]);op_assert($code===302,'Savings handler response');
        $after=(float)$koneksi->query("SELECT SALDO FROM tabungan WHERE NO_INDUK='$nis'")->fetch_row()[0];op_assert($after===$balance+($kind==='masuk'?$amount:-$amount),'Savings balance wrong '.$role.'/'.$unit.'/'.$kind);
    }
}
$inactive=$actors['kasir']['id'];$koneksi->query('UPDATE admin SET is_active=0 WHERE id='.$inactive);[$code]=op_http($cashier['id'],'dashboard.php');op_assert($code===302,'Inactive session accepted');$koneksi->query('UPDATE admin SET is_active=1 WHERE id='.$inactive);
$sessions['kasir']=op_session($actors['kasir'],2);
file_put_contents($root.'/access-fixture.json',json_encode(['actors'=>$actors,'sessions'=>$sessions,'payments'=>$payments,'nis'=>$nis],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo 'PASS '.$checks.' operational access checks; only the isolated instance was changed.'.PHP_EOL;
