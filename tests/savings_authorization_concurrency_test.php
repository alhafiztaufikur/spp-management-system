<?php
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/http_form_scope.php';spp_test_isolated_manifest($koneksi);
require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/savings_authorization.php';
$f=json_decode(file_get_contents(getenv('SPP_SAVINGS_QA').'/fixture.json'),true,512,JSON_THROW_ON_ERROR);$nis=$f['nis'];$id=$f['students'][1]['in'];unit_set_context($koneksi,1);
$_SESSION=['admin_role'=>'admin','admin_id'=>$f['actors']['admin']['id'],'active_unit_id'=>1];
$payload=['nominal'=>'130000','tanggal'=>'2026-10-01 08:00:00','keterangan'=>'Koreksi bersamaan','reason'=>'Pengujian konkurensi terisolasi.'];
if(($argv[1]??'')==='worker'){
    echo "START\n";flush();try{if($argv[2]==='deposit'){
        $koneksi->begin_transaction();$ledger=savings_locked_ledger($koneksi,$nis);$owner=$f['actors']['kasir']['id'];$koneksi->query("INSERT INTO transaksi_m(NO_INDUK,TANGGAL,MASUK,KELUAR,user_id,keterangan) VALUES('$nis',NOW(),5000,0,'$owner','Uji bersamaan')");$newId=(int)$koneksi->insert_id;
        $koneksi->query("UPDATE tabungan SET SALDO=SALDO+5000 WHERE NO_INDUK='$nis'");$row=['id'=>$newId,'unit_id'=>1,'NO_INDUK'=>$nis,'TANGGAL'=>date('Y-m-d H:i:s'),'MASUK'=>5000,'KELUAR'=>0,'user_id'=>(string)$owner,'keterangan'=>'Uji bersamaan','jenis'=>'masuk'];savings_event($koneksi,$row,'created',$owner,null,savings_snapshot($row,$ledger['student'])['data'],'created:masuk:'.$newId);$koneksi->commit();
    }else savings_correct($koneksi,$argv[2],'masuk',$id,$payload,$f['actors'][$argv[2]==='approve'?'super_admin':'admin']['id'],(int)($argv[3]??0));echo "OK\n";}catch(Throwable $e){echo 'DENIED: '.$e->getMessage()."\n";}exit;
}
function sc_race(array $modes):array {
    global $koneksi,$nis;$koneksi->begin_transaction();$s=$koneksi->prepare('SELECT id FROM siswa WHERE NO_INDUK=? FOR UPDATE');$s->bind_param('s',$nis);$s->execute();$s->get_result();$s->close();$workers=[];
    try{
        foreach($modes as $mode){$pipes=[];$process=proc_open(array_merge([PHP_BINARY,__FILE__,'worker'],$mode),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Worker failed to launch.');fclose($pipes[0]);stream_set_blocking($pipes[1],false);$workers[]=['p'=>$process,'pipes'=>$pipes,'out'=>''];}
        foreach($workers as &$w){stream_set_blocking($w['pipes'][1],true);stream_set_timeout($w['pipes'][1],15);$w['out']=(string)fgets($w['pipes'][1]);if(!str_contains($w['out'],'START'))throw new RuntimeException('Worker not ready.');}unset($w);$koneksi->commit();
        $outputs=[];foreach($workers as $w){stream_set_blocking($w['pipes'][1],true);$out=$w['out'].stream_get_contents($w['pipes'][1]);$error=stream_get_contents($w['pipes'][2]);fclose($w['pipes'][1]);fclose($w['pipes'][2]);proc_close($w['p']);if($error)throw new RuntimeException($error);$outputs[]=$out;}return $outputs;
    }catch(Throwable $e){$koneksi->rollback();foreach($workers as $w){proc_terminate($w['p']);proc_close($w['p']);}throw $e;}
}
function sc_check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$out=sc_race([['request_edit'],['request_edit']]);sc_check(count(array_filter($out,fn($s)=>str_contains($s,"OK\n")))===1,'Concurrent requests duplicated or both failed: '.json_encode($out));
$request=(int)$koneksi->query("SELECT id FROM tabungan_otorisasi WHERE jenis='masuk' AND transaction_id=$id AND status='pending'")->fetch_row()[0];
$out=sc_race([['approve',(string)$request],['approve',(string)$request]]);sc_check(count(array_filter($out,fn($s)=>str_contains($s,"OK\n")))===1,'Concurrent approval applied twice');
sc_check((int)$koneksi->query("SELECT COUNT(*) FROM tabungan_aktivitas WHERE event_key='apply:$request'")->fetch_row()[0]===1,'Repeated application audit');
$payload['nominal']='135000';$request=savings_correct($koneksi,'request_edit','masuk',$id,$payload,$f['actors']['admin']['id']);
$before=(float)$koneksi->query("SELECT SALDO FROM tabungan WHERE NO_INDUK='$nis'")->fetch_row()[0];$out=sc_race([['approve',(string)$request],['deposit']]);sc_check(count(array_filter($out,fn($s)=>str_contains($s,"OK\n")))===2,'Deposit and approval conflicted');
$koneksi->begin_transaction();$ledger=savings_locked_ledger($koneksi,$nis);$koneksi->commit();sc_check($ledger['balance']===(int)round(($before+10000)*100),'Concurrent operations lost balance');savings_ledger_balance($ledger['rows']);
echo "PASS concurrent request, approval replay, deposit/correction and ledger reconciliation.\n";
