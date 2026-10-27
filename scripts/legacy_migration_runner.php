<?php
/** Full pipeline entry point. No mutation of operational data without mode=apply. */
if(PHP_SAPI!=='cli')exit(1);
$memoryLimit=trim((string)ini_get('memory_limit'));
if($memoryLimit!=='-1'){
    $memoryUnits=['g'=>1073741824,'m'=>1048576,'k'=>1024];
    if((int)$memoryLimit*($memoryUnits[strtolower(substr($memoryLimit,-1))]??1)<1536*1048576)ini_set('memory_limit','1536M');
}
$options=getopt('',['source:','batch:','unit:','database:','port:','actor:','mode:','backup-proof:','output:','settings:','du-year:','components:']);
$components=$options['components']??'all';if(!in_array($components,['all','other_fees','savings'],true))throw new InvalidArgumentException('Komponen runner tidak valid.');
$mode=$options['mode']??'preview';$database=$options['database']??'';$port=(int)($options['port']??3306);$unit=(int)($options['unit']??0);
if(!in_array($mode,['preview','apply','verify'],true)||!preg_match('/^db_spp(?:_[a-z0-9_]+)?$/D',$database)||($unit&&!in_array($unit,[1,2,3],true))||$port<1||$port>65535)throw new InvalidArgumentException('Gunakan --database=db_spp_test2 --unit=1|2|3 --mode=preview|apply|verify [--port=3306].');
putenv('SPP_DB_HOST=127.0.0.1');putenv('SPP_DB_NAME='.$database);putenv('SPP_DB_PORT='.$port);
$_SESSION=['admin_id'=>(int)($options['actor']??1),'admin_role'=>'super_admin','active_unit_id'=>$unit?:1];
require __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/legacy_billing_reconstruction.php';require_once __DIR__.'/../includes/legacy_migration_verification.php';
require_once __DIR__.'/../includes/legacy_full.php';
$db=$koneksi;$actual=lmr_target($db);if($actual['database_name']!==$database||(int)$actual['port']!==$port)throw new RuntimeException('Target koneksi berbeda dari CLI.');
$duYear=$options['du-year']??ldu_target_year();if(!is_string($duYear)||$duYear!==ldu_target_year())throw new InvalidArgumentException('Tahun DU harus tahun ajaran berjalan; ulangi preview untuk tahun tersebut.');
$actor=(int)$_SESSION['admin_id'];if(!lf_one($db,"SELECT id FROM admin WHERE id=? AND role='super_admin' AND is_active=1",[$actor]))throw new RuntimeException('Super Admin aktif diperlukan.');
$closureSettings=isset($options['settings'])?json_decode(file_get_contents($options['settings']),true,512,JSON_THROW_ON_ERROR):[];
$closureRequest=$closureSettings['exception_closure']??[];
$report=['rules'=>['workflow'=>LFUL_RULES_VERSION,'operational'=>LOP_VERSION,'monthly'=>LMP_VERSION,'automation'=>LMA_VERSION,'fee_trace'=>LEGACY_FEE_TRACE_VERSION,'other_fee_billing'=>OFB_RULE_VERSION,'billing'=>LB_RULE_VERSION,'du'=>LDU_VERSION],'target'=>$actual,'mode'=>$mode,'selected_components'=>$components,'du_year'=>$duYear,'stages'=>['target_backup','source_extraction','owner_classification','psb_du','du_planning','du_application','component_mapping','other_fee_trace','spp_komite','future_period_credit','savings','simulation','application','verification','verification_du'],'units'=>[]];
if($components==='other_fees')$report['stages']=['target_backup','other_fee_billing','verification'];
// Explicit held-account resumption reuses the GUI FIX proposal and publication writer.
if($components==='savings'){
    require_once __DIR__.'/../includes/legacy_savings_resume.php';
    $request=$closureSettings['savings_fix']??[];
    if(!$unit||isset($options['source'])||($request['unit_id']??null)!==$unit||($options['batch']??'')!==($request['batch_id']??null))throw new DomainException('Saldo FIX memerlukan satu unit, batch dan settings yang cocok.');
    unit_set_context($db,$unit);$access=lful_wait_access(database_access_paths($database,$port),static fn()=>fwrite(STDERR,"Menunggu akses aplikasi...\n"));
    try{
        if($mode==='apply')$report['backup']=array_intersect_key(lmr_backup_check($db,$options['backup-proof']??''),array_flip(['sha256','verified_restore','restored_server_uuid']));
        $db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$db->begin_transaction();$db->query('SET @legacy_full_apply=1');
        try{
            $before=lsr_guard($db,$request);$result=lsr_reconcile($db,$actor,$request);
            if(lsr_guard($db,$request)!==$before)throw new RuntimeException('Data di luar rekening yang disahkan berubah; dibatalkan.');
            lop_check_result($db,$request['batch_id']);$result['unrelated_rows_preserved']=true;$report['units'][$unit]=$result;
            if($mode==='apply')$db->commit();else $db->rollback();
        }catch(Throwable $e){$db->rollback();throw $e;}finally{$db->query('SET @legacy_full_apply=0');}
    }finally{database_access_release($access);}
    $json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(isset($options['output']))file_put_contents($options['output'],$json.PHP_EOL);echo $json.PHP_EOL;
    exit($mode==='verify'&&$result['changes']?2:0);
}
// A source already archived on this target follows exactly the reconciliation engine.
if(isset($options['source'])){
    if(!$unit||!is_file($options['source']))throw new RuntimeException('Sumber baru memerlukan file dan satu unit.');
    $batch=lf_one($db,'SELECT b.id FROM legacy_full_batches b JOIN legacy_finance_sources s ON s.id=b.source_id AND s.unit_id=b.unit_id WHERE b.unit_id=? AND s.backup_hash=?',[$unit,hash_file('sha256',$options['source'])]);
    if($batch)$options['batch']=$batch['id'];
    else{
        if($components==='other_fees')throw new RuntimeException('Sumber baru memerlukan pipeline penuh; --components=other_fees hanya untuk batch existing.');
        if($mode==='verify')throw new RuntimeException('Sumber belum dimigrasikan pada target ini.');
        if($mode==='apply'){require_once __DIR__.'/../includes/database_backup.php';db_backup_assert_application(db_backup_config(),$db);}
        require_once __DIR__.'/../includes/legacy_operational_bulk.php';
        lful_empty($db,$unit);unit_set_context($db,$unit);
        $job=legacy_enqueue($options['source'],basename($options['source']),$unit,$actor,'full');$id=$job['id'];$dir=legacy_root().'/'.$id;
        legacy_update($id,['state'=>'processing','stage'=>'Ekstraksi oleh runner']);
        file_put_contents($dir.'/operational-request.json','{}');
        $pipes=[];$process=proc_open(['powershell.exe','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',__DIR__.'/legacy_extract.ps1','-JobDirectory',$dir,'-Unit',(string)$unit,'-Category','full'],[0=>['pipe','r'],1=>['file',$dir.'/runner-extract.log','a'],2=>['file',$dir.'/runner-error.log','a']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Extractor tidak tersedia.');fclose($pipes[0]);if(proc_close($process)!==0)throw new RuntimeException('Ekstraksi gagal; periksa log privat sumber.');
        lful_stage($db,$job);$draft=lful_draft($id);[$records,$manifest]=lf_extract_read($job,true,true);$catalog=lful_catalog_read($id);
        $bulk=lop_bulk_patch($records,$catalog,$draft['settings'],$unit,'all',lf_rows($db,'SELECT id,nama FROM master_biaya_lain'));
        // The engine rereads its immutable archive for simulation/apply. Do not
        // retain a second full source copy during the transaction and backups.
        unset($records,$catalog);
        $patch=$bulk['patch']??$bulk; // reviewed source defaults; exceptions remain in existing Legacy review.
        if(isset($options['settings']))$patch=array_replace_recursive($patch,json_decode(file_get_contents($options['settings']),true,512,JSON_THROW_ON_ERROR));
        $saved=lful_save($db,$actor,$unit,$id,(int)$draft['version'],$patch);unset($saved,$draft,$patch,$bulk);$simulation=lop_simulate($db,$id);
        $report['source_job']=$id;$report['simulation']=array_intersect_key($simulation,array_flip(['ready','version','digest','summary','issues','rules_version']));
        if($mode==='apply'){
            if(!$simulation['ready'])throw new RuntimeException('Simulasi belum siap. Selesaikan pengecualian Legacy pada job '.$id);
            $confirmed=lful_confirm($db,$actor,$unit,$id,(int)$simulation['version'],$simulation['digest']);
            lful_enqueue_apply($db,$actor,$unit,$id,(int)$simulation['version'],$confirmed['token']);
            unset($simulation,$confirmed);
            lful_run($db,legacy_job($id));$options['batch']=$id;$report['pipeline_backups']=lf_one($db,'SELECT before_backup,after_backup FROM legacy_full_batches WHERE id=?',[$id]);
        }else{$json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(isset($options['output']))file_put_contents($options['output'],$json);echo $json.PHP_EOL;exit;}
    }
}
if(isset($options['batch'])){
    $batch=lf_one($db,'SELECT id,unit_id,state FROM legacy_full_batches_data WHERE id=?',[$options['batch']]);
    if(!$batch||$batch['state']!=='completed'||($unit&&(int)$batch['unit_id']!==$unit))throw new RuntimeException('Batch/unit belum selesai atau tidak sesuai.');$unit=(int)$batch['unit_id'];
}
$access=lful_wait_access(database_access_paths($database,$port),static function(){fwrite(STDERR,"Menunggu akses aplikasi...\n");});
register_shutdown_function(static fn()=>database_access_release($access));
if($mode==='apply'){
    if(empty($report['pipeline_backups']))$report['backup']=array_intersect_key(lmr_backup_check($db,$options['backup-proof']??''),array_flip(['sha256','verified_restore','restored_server_uuid']));
    $report['schema_triggers_updated']=$components==='all'?legacy_monthly_schema_upgrade($db):0;
}
else $report['schema_triggers_pending']=$components==='other_fees'?0:(int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'lful_coverage_%' AND ACTION_STATEMENT LIKE '%start_period%'")->fetch_row()[0];
foreach($unit?[$unit]:[1,2,3] as $u){
    $_SESSION['active_unit_id']=$u;unit_set_context($db,$u);$db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$before=lmr_snapshot($db,$u);$db->begin_transaction();
    try{
        lop_actor($db,$actor,$u,true);
        lf_rows($db,'SELECT id FROM siswa ORDER BY id FOR UPDATE');lf_rows($db,'SELECT id FROM legacy_full_batches ORDER BY id FOR UPDATE');
        // Preview also runs against old installed guards, but rolls back all data.
        $db->query('SET @legacy_full_apply=1');
        $result=$components==='other_fees'?ofb_reconcile($db,$actor):lbr_reconcile($db,$actor);
        $result['exception_closure']=$components==='other_fees'?['changes'=>0]:lec_reconcile($db,$actor,$closureRequest);$result['changes']+=$result['exception_closure']['changes'];
        $after=lmr_snapshot($db,$u);
        if($before!==$after)throw new RuntimeException('Arsip, identitas, kas, atau tabungan berubah; unit dibatalkan.');
        foreach(lf_rows($db,"SELECT id FROM legacy_full_batches WHERE state='completed'") as $b)lop_check_result($db,$b['id']);
        $result['verification_totals']=lmr_monthly_totals($db);
        $result['verification_components']=lmr_component_totals($db);
        $result['verification_fee_trace']=lmr_fee_trace_totals($db);
        $result['verification_du']=ldu_verify($db);
        $result['preserved']=$after;$report['units'][$u]=$result;
        if($mode==='apply')$db->commit();else $db->rollback();
    }catch(Throwable $e){$db->rollback();throw $e;}finally{$db->query('SET @legacy_full_apply=0');}
}
$json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(isset($options['output']))file_put_contents($options['output'],$json.PHP_EOL);echo $json.PHP_EOL;
if($mode==='verify'&&(array_sum(array_column($report['units'],'changes'))!==0||!empty($report['schema_triggers_pending'])))exit(2);
