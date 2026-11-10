<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
$o=getopt('',['database:','port:','mode:','backup-proof:','actor:','output:']);$name=$o['database']??'';$port=(int)($o['port']??3306);$mode=$o['mode']??'preview';$actor=(int)($o['actor']??1);
if(!preg_match('/^db_spp(?:_[a-z0-9_]+)?$/D',$name)||$port<1||$port>65535||!in_array($mode,['preview','apply'],true))throw new InvalidArgumentException('Gunakan --database=db_spp_test3 --port=3306 --mode=preview|apply --backup-proof=PRIVATE_JSON');
putenv('SPP_DB_NAME='.$name);putenv('SPP_DB_PORT='.$port);putenv('SPP_DB_HOST=127.0.0.1');
require __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/student_out.php';require_once __DIR__.'/../includes/legacy_migration_verification.php';require_once __DIR__.'/../includes/legacy_billing.php';
$db=$koneksi;$target=lmr_target($db);if($target['database_name']!==$name||(int)$target['port']!==$port)throw new RuntimeException('Target tidak sesuai.');
$a=sout_rows($db,"SELECT id,nama FROM admin WHERE id=? AND role='super_admin' AND is_active=1",[$actor])[0]??null;if(!$a)throw new RuntimeException('Installer memerlukan Super Admin aktif.');
$stamp=sout_now()->format('Y-m-d H:i:s');$steps=sout_schema_plan($db);$plans=[];$changes=0;$schemaChanges=0;$result=[];
foreach([1,2,3] as $unit){$_SESSION=['admin_id'=>$actor,'admin_nama'=>$a['nama'],'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($db,$unit);$plans[$unit]=sout_rollout_plan($db,$stamp);}
$pending=array_sum(array_map(static fn($p)=>$p['summary']['events']+$p['summary']['cancelled']+$p['summary']['deactivate'],$plans));
if($mode==='apply'&&($steps||$pending)){
    $lock=database_access_lock(database_access_paths($name,$port),LOCK_EX);
    try{
        lmr_backup_check($db,$o['backup-proof']??'');$before=lmr_snapshot($db);$schemaChanges=sout_schema_apply($db);
        foreach([1,2,3] as $unit){
            $_SESSION=['admin_id'=>$actor,'admin_nama'=>$a['nama'],'admin_role'=>'super_admin','active_unit_id'=>$unit];unit_set_context($db,$unit);$unitResult=['events'=>0,'cancelled'=>0,'protected'=>0,'deactivate'=>0];$db->begin_transaction();
            try{$identities=sout_rows($db,'SELECT * FROM siswa ORDER BY id FOR UPDATE');$expected=array_column($identities,null,'id');$GLOBALS['sout_batch'][$db->thread_id]=true;foreach($plans[$unit]['students'] as $s){$old=sout_rows($db,'SELECT * FROM siswa WHERE id=? FOR UPDATE',[$s['id']])[0];if(empty(sout_student($db,$old['NO_INDUK'])['is_out']))throw new RuntimeException('Status siswa berubah sejak preview.');$r=sout_transition($db,$s['id'],$old,'out',$actor,$stamp,'rollout');$unitResult['events']+=$r['event_created'];$unitResult['cancelled']+=$r['cancelled'];$unitResult['protected']+=$r['protected'];$unitResult['deactivate']+=(int)((int)$old['is_active']!==0);$expected[$s['id']]['is_active']=0;}
                $actual=sout_rows($db,'SELECT * FROM siswa ORDER BY id');foreach($actual as $row){$planned=$expected[$row['id']]??null;if(!$planned)throw new RuntimeException('Identitas bertambah saat pemasangan.');$row['is_active']=(int)$row['is_active'];$planned['is_active']=(int)$planned['is_active'];if($row!==$planned)throw new RuntimeException('Identitas berubah di luar status OUT.');}if(count($actual)!==count($expected))throw new RuntimeException('Jumlah identitas berubah saat pemasangan.');
                $after=lmr_snapshot($db);foreach($before as $key=>$value)if($key!=='identity'&&$value!==$after[$key])throw new RuntimeException('Data terlindungi berubah: '.$key);$db->commit();
            }catch(Throwable $e){$db->rollback();throw $e;}finally{unset($GLOBALS['sout_batch'][$db->thread_id]);sout_invalidate($db,true);}
            $result[$unit]=$unitResult;$changes+=$unitResult['events']+$unitResult['cancelled']+$unitResult['deactivate'];
        }
    }finally{database_access_release($lock);}
}
$summaries=array_map(static fn($p)=>$p['summary'],$plans);
$report=['target'=>$target,'mode'=>$mode,'as_of'=>$stamp,'schema_changes'=>$schemaChanges,'data_changes'=>$changes,'steps'=>$steps,'preview'=>$summaries,'applied'=>$result,'pending_schema'=>sout_schema_plan($db)];
if(isset($o['output']))file_put_contents($o['output'],json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
