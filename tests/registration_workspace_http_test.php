<?php
/** Real handler tests. Fixtures commit only on an explicit audit clone. Financial rows are removed in finally; immutable journals remain on the disposable clone. */
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/transaction_authorization.php';require_once __DIR__.'/../includes/registration_history.php';
$base=(string)getenv('SPP_HTTP_BASE');if(!in_array(parse_url($base,PHP_URL_HOST),['localhost','127.0.0.1'],true))throw new RuntimeException('Local test server required.');
function rw_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rw_http(string $cookie,string $path,array $post=[]):array{
    global $base;$options=['method'=>$post?'POST':'GET','ignore_errors'=>true,'follow_location'=>0,'header'=>'Cookie: PHPSESSID='.$cookie."\r\n"];
    if($post){$options['header'].="Content-Type: application/x-www-form-urlencoded\r\n";$options['content']=http_build_query($post);}
    $body=file_get_contents($base.$path,false,stream_context_create(['http'=>$options]));$headers=$http_response_header??[];$status=preg_match('/\s(\d{3})\s/',$headers[0]??'',$m)?(int)$m[1]:0;$location='';
    foreach($headers as $h)if(str_starts_with(strtolower($h),'location:'))$location=trim(substr($h,9));return [$status,$body,$location];
}
function rw_session(int $id,int $unit,string $role):string{
    session_id('registrationhttp'.bin2hex(random_bytes(12)));session_start();$_SESSION=['admin_id'=>$id,'admin_role'=>$role,'active_unit_id'=>$unit,'csrf_payment'=>'registration-payment-token','csrf_transaction_authorization'=>'registration-approval-token'];$cookie=session_id();session_write_close();return $cookie;
}
function rw_context(string $cookie,int $id,int $unit,array $query):string{
    session_id($cookie);session_start();$token=payment_return_create($id,$unit,$query);session_write_close();return $token;
}
function rw_close_session():void{if(session_status()===PHP_SESSION_ACTIVE)session_write_close();}
$ids=[];$data=[];$inactiveRestore=[];
[$guardStatus,$guardBody]=rw_http('','/tests/browser_clone_identity.php');
rw_assert($guardStatus===200 && (json_decode($guardBody,true)['database']??null)===DB_NAME,'HTTP server must use the same disposable clone before any mutation.');
try{
    unit_set_context($koneksi,0);$super=(int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_row()[0];
    foreach([1,2,3] as $unit){
        unit_set_context($koneksi,$unit);$actors=[];
        foreach(['admin','kasir','bendahara'] as $role){$actors[$role]=(int)$koneksi->query("SELECT id FROM admin WHERE role='$role' AND unit_id=$unit AND is_active=1 LIMIT 1")->fetch_row()[0];}
        $actors['super_admin']=$super;$actors['unknown']=null;
        $bill=$koneksi->query("SELECT t.*,s.id student_id,s.NAMA,s.NO_induk_diknas,s.master_kelas_id,s.KELAS current_class FROM tagihan_daftar_ulang t JOIN siswa s ON s.NO_INDUK=t.no_induk AND s.unit_id=t.unit_id WHERE t.status='open' AND NOT EXISTS(SELECT 1 FROM bayar existing WHERE existing.NO_INDUK=s.NO_INDUK AND existing.unit_id=s.unit_id AND existing.U_SPP>0) AND t.nominal_tagihan-COALESCE((SELECT SUM(d.jumlah) FROM bayar_du d WHERE d.tagihan_daftar_ulang_id=t.id),0)>10000 ORDER BY t.id LIMIT 1")->fetch_assoc();
        rw_assert((bool)$bill,'Selectable fixture bill required.');
        $cookies=[];$payments=[];
        foreach($actors as $role=>$actor){
            if($actor)$cookies[$role]=rw_session($actor,$unit,$role);
            $operator=(string)($actor??$actors['kasir']);$class=(int)$bill['master_kelas_id'];
            $s=$koneksi->prepare("INSERT INTO bayar(NO_INDUK,KELAS,master_kelas_id,kelas_rombel_snapshot,TGL_BYR,BULAN,TAHUN,user_id,sistem_pembayaran,total_jumlah,payment_link_version) VALUES(?,?,?,?,'2026-10-07 12:00:00','10','2026',?,'Tunai',1000,1)");
            $s->bind_param('ssiss',$bill['no_induk'],$bill['current_class'],$class,$bill['kelas_snapshot'],$operator);$s->execute();$id=(int)$koneksi->insert_id;$s->close();$ids[]=$id;$payments[$role]=$id;
            $s=$koneksi->prepare('INSERT INTO bayar_du(bayar_id,tagihan_daftar_ulang_id,no_induk,kelas,th_ajaran,jumlah) VALUES(?,?,?,?,?,1000)');$s->bind_param('iisss',$id,$bill['id'],$bill['no_induk'],$bill['kelas_snapshot'],$bill['tahun_ajaran_snapshot']);$s->execute();$s->close();
            $koneksi->begin_transaction();payment_activity_record($koneksi,$id,$actor?'created':'baseline',$actor,null,transaction_authorization_snapshot($koneksi,$id)['data'],'registration-fixture:'.$id);$koneksi->commit();
        }
        $data[$unit]=compact('bill','actors','cookies','payments');
    }
    foreach($data as $unit=>$d){
        unit_set_context($koneksi,$unit);$bill=$d['bill'];$query=['view'=>'active','tahun_ajaran'=>[$bill['tahun_ajaran_snapshot']],'kelas'=>['rombel:'.$bill['master_kelas_id']],'status'=>['cicilan'],'page'=>1,'per_page'=>25,'selected'=>(int)$bill['id'],'selected_unit'=>$unit];
        foreach(['admin','kasir','bendahara','super_admin'] as $role){
            [$status,$json]=rw_http($d['cookies'][$role],'/pembayaran/detail_daftar_ulang.php?'.http_build_query(array_merge($query,['tagihan_id'=>$bill['id'],'unit_id'=>$unit])));rw_assert($status===200,'Detail access '.$role.' unit '.$unit.' status '.$status);
            $detail=json_decode($json,true,512,JSON_THROW_ON_ERROR);$caps=[];foreach($detail['capabilities'] as $cap)$caps[$cap['id']]=$cap['capabilities'];
            foreach($d['payments'] as $owner=>$id){$allowed=$role==='super_admin'||$role===$owner;rw_assert($caps[$id]['can_print']===$allowed,'Print capability '.$role.'/'.$owner);rw_assert($caps[$id]['can_edit']===($allowed&&$role!=='bendahara'),'Edit capability '.$role.'/'.$owner);}
            $own=$d['payments'][$role];rw_assert(rw_http($d['cookies'][$role],'/laporan/cetak_struk.php?id='.$own)[0]===200,'Own receipt');
            if($role!=='super_admin'){
                $foreign=$d['payments']['super_admin'];rw_assert(rw_http($d['cookies'][$role],'/laporan/cetak_struk.php?id='.$foreign)[0]===403,'Foreign receipt denied');
                if($role!=='bendahara')rw_assert(rw_http($d['cookies'][$role],'/pembayaran/edit.php?id='.$foreign)[0]===403,'Foreign edit denied');
                rw_assert(rw_http($d['cookies'][$role],'/pembayaran/proses.php',['aksi'=>'hapus','id'=>$foreign,'csrf_token'=>'registration-payment-token','authorization_reason'=>'Uji transaksi asing'])[0]===403||$role==='bendahara','Foreign delete denied');
            }
        }
        $cashId=$d['payments']['kasir'];$cookie=$d['cookies']['kasir'];$token=rw_context($cookie,$cashId,$unit,$query);
        $otherToken=rw_context($cookie,$cashId,$unit,array_merge($query,['page'=>2]));
        $sameQueryToken=rw_context($cookie,$cashId,$unit,$query);
        session_id($cookie);session_start();rw_assert($token!==$otherToken,'Two-tab contexts must be distinct');
        rw_assert($sameQueryToken!==$token,'Two tabs with identical filters still require independent contexts');
        rw_assert(str_contains(payment_return_url($otherToken,$cashId),'page=2')&&str_contains(payment_return_url($token,$cashId),'page=1'),'Independent tab return routes');
        rw_assert(payment_return_entry($token,$d['payments']['admin'])===null,'Return context cannot change payment');
        $_SESSION['payment_returns'][$otherToken]['expires']=time()-1;rw_assert(payment_return_url($otherToken,$cashId)==='riwayat_daftar_ulang.php','Expired context fallback');session_write_close();
        $payload=['aksi'=>'update','id'=>$cashId,'csrf_token'=>'registration-payment-token','return_context'=>$token,'authorization_reason'=>'Perbaiki catatan Daftar Ulang','no_induk'=>$bill['no_induk'],'tanggal_bayar'=>'2026-10-07 12:00:00','bulan_bayar'=>'10','tahun_bayar'=>'2026','sistem_pembayaran'=>'Tunai','uang_psb'=>'0','uang_spp'=>'0','uang_komite'=>'0','uang_du'=>'1200','tagihan_daftar_ulang_id'=>$bill['id'],'catatan'=>'Uji & <catatan>'];
        [$status,,$location]=rw_http($cookie,'/pembayaran/proses.php',$payload);rw_assert($status===302&&str_starts_with($location,'riwayat_daftar_ulang.php?'),'Cashier proposal returns to DU');
        rw_assert((float)$koneksi->query('SELECT total_jumlah FROM bayar WHERE id='.$cashId)->fetch_row()[0]===1000.0,'Proposal must not change payment');
        $request=$koneksi->query("SELECT id,status FROM transaksi_otorisasi WHERE bayar_id=$cashId ORDER BY id DESC LIMIT 1")->fetch_assoc();rw_assert($request&&$request['status']==='pending','Proposal queued');
        $decision=['aksi'=>'otorisasi_setujui','request_id'=>$request['id'],'csrf_token'=>'registration-approval-token','decision_note'=>'Uji persetujuan pada salinan'];
        [$status,,$location]=rw_http($d['cookies']['super_admin'],'/pembayaran/proses.php',$decision);rw_assert($status===302&&$location==='../otorisasi_transaksi.php','Decision returns to Otorisasi');
        $state=$koneksi->query('SELECT total_jumlah FROM bayar WHERE id='.$cashId)->fetch_row()[0];rw_assert((float)$state===1200.0,'Proposal applied unit '.$unit.'; '.($koneksi->query('SELECT note FROM pembayaran_aktivitas WHERE payment_id='.$cashId.' ORDER BY id DESC LIMIT 1')->fetch_row()[0]??''));
        $_SESSION=['admin_id'=>$d['actors']['kasir'],'admin_role'=>'kasir','active_unit_id'=>$unit];rw_assert(payment_activity_summary(payment_activity_for_payments($koneksi,[$cashId])[$cashId])['creator']['actor_id']==$d['actors']['kasir'],'Approval must not transfer owner');
        $superId=$d['payments']['super_admin'];$superToken=rw_context($d['cookies']['super_admin'],$superId,$unit,$query);$payload['id']=$superId;$payload['return_context']=$superToken;unset($payload['authorization_reason']);$payload['activity_request_key']=bin2hex(random_bytes(16));
        [$status,,$location]=rw_http($d['cookies']['super_admin'],'/pembayaran/proses.php',$payload);rw_assert($status===302&&str_starts_with($location,'riwayat_daftar_ulang.php?'),'Direct edit returns to DU');rw_assert((float)$koneksi->query('SELECT total_jumlah FROM bayar WHERE id='.$superId)->fetch_row()[0]===1200.0,'Direct edit applied');
        rw_assert(rw_http($d['cookies']['super_admin'],'/pembayaran/proses.php',$payload)[2]===$location,'Replay returns to original context');
        $payload['uang_du']='999999999';$payload['activity_request_key']=bin2hex(random_bytes(16));$payload['catatan']='Draf dipertahankan & <aman>';
        [$status,,$errorUrl]=rw_http($d['cookies']['super_admin'],'/pembayaran/proses.php',$payload);rw_assert($status===302&&str_starts_with($errorUrl,'edit.php?'),'Failed edit stays in form');
        [$status,$html]=rw_http($d['cookies']['super_admin'],'/pembayaran/'.$errorUrl);rw_assert($status===200&&str_contains($html,'Draf dipertahankan &amp; &lt;aman&gt;'),'Failed edit draft restored safely');
        rw_assert(str_contains($html,'riwayat_daftar_ulang.php?'),'Cancel carries origin context');
        $delete=['aksi'=>'hapus','id'=>$superId,'csrf_token'=>'registration-payment-token','return_context'=>$superToken];
        rw_assert(str_starts_with(rw_http($d['cookies']['super_admin'],'/pembayaran/proses.php',$delete)[2],'riwayat_daftar_ulang.php?'),'Delete return');
        rw_assert((int)$koneksi->query('SELECT COUNT(*) FROM bayar WHERE id='.$superId)->fetch_row()[0]===0,'Direct deletion applied');
        [$status,$json]=rw_http($cookie,'/pembayaran/detail_daftar_ulang.php?view=deleted&tagihan_id='.$bill['id'].'&unit_id='.$unit);rw_assert($status===200,'Archive detail');$archive=json_decode($json,true,512,JSON_THROW_ON_ERROR);rw_assert(str_contains($archive['html'],'Rp 1.200'),'Archive last snapshot');foreach($archive['capabilities'] as $cap)rw_assert(!$cap['capabilities']['can_print']&&!$cap['capabilities']['can_edit']&&!$cap['capabilities']['can_delete'],'Archive is read-only');
        rw_assert(rw_http($cookie,'/pembayaran/detail_daftar_ulang.php?tagihan_id='.$data[$unit===1?2:1]['bill']['id'].'&unit_id='.($unit===1?2:1))[0]===404,'Foreign unit detail');
        rw_assert(rw_http($cookie,'/pembayaran/proses.php',['aksi'=>'hapus','id'=>$cashId,'csrf_token'=>'invalid'])[0]===302,'CSRF rejected');
        $adminId=$d['payments']['admin'];$adminToken=rw_context($d['cookies']['admin'],$adminId,$unit,$query);
        [$status,,$location]=rw_http($d['cookies']['admin'],'/pembayaran/proses.php',['aksi'=>'hapus','id'=>$adminId,'csrf_token'=>'registration-payment-token','return_context'=>$adminToken,'authorization_reason'=>'Uji pengajuan hapus Admin']);
        rw_assert($status===302&&str_starts_with($location,'riwayat_daftar_ulang.php?'),'Admin delete proposal return');rw_assert((int)$koneksi->query('SELECT COUNT(*) FROM bayar WHERE id='.$adminId)->fetch_row()[0]===1,'Admin proposal must retain payment');
        $superCookie=$d['cookies']['super_admin'];$ownToken=rw_context($superCookie,$d['payments']['unknown'],$unit,$query);
        $ordinaryPayload=$payload;$ordinaryPayload['id']=$d['payments']['unknown'];unset($ordinaryPayload['return_context']);$ordinaryPayload['uang_du']='1100';$ordinaryPayload['activity_request_key']=bin2hex(random_bytes(16));
        rw_assert(rw_http($superCookie,'/pembayaran/proses.php',$ordinaryPayload)[2]==='lihat.php','Ordinary history edit return unaffected');
        $inactiveId=$d['actors']['bendahara'];$inactiveRestore[$inactiveId]=1;
        $koneksi->query('UPDATE admin SET is_active=0 WHERE id='.$inactiveId);
        rw_assert(rw_http($d['cookies']['bendahara'],'/pembayaran/detail_daftar_ulang.php?tagihan_id='.$bill['id'].'&unit_id='.$unit)[0]===403,'Inactive account detail denied');
        rw_assert(rw_http($d['cookies']['bendahara'],'/laporan/cetak_struk.php?id='.$d['payments']['bendahara'])[0]!==200,'Inactive account receipt denied');
        $koneksi->query('UPDATE admin SET is_active=1 WHERE id='.$inactiveId);unset($inactiveRestore[$inactiveId]);
    }
    echo "OK: three units, four roles, creator ownership, receipts, actual proposal/approval/direct edit/replay/rollback/archive, safe draft and return routes.\n";
}finally{
    rw_close_session();unit_set_context($koneksi,0);
    foreach($inactiveRestore as $actor=>$active)$koneksi->query('UPDATE admin SET is_active='.(int)$active.' WHERE id='.(int)$actor);
    foreach($ids as $id){
        $koneksi->query("DELETE FROM transaksi_otorisasi WHERE bayar_id=".(int)$id." OR JSON_UNQUOTE(JSON_EXTRACT(before_snapshot,'$.payment.id'))=".(int)$id);
        $koneksi->query('DELETE FROM bayar WHERE id='.(int)$id);
    }
}
