<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$memoryLimit=trim((string)ini_get('memory_limit'));
if($memoryLimit!=='-1'){
    $memoryUnits=['g'=>1073741824,'m'=>1048576,'k'=>1024];
    if((int)$memoryLimit*($memoryUnits[strtolower(substr($memoryLimit,-1))]??1)<1536*1048576)ini_set('memory_limit','1536M');
}
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/legacy_import.php';
require_once __DIR__.'/../includes/legacy_finance.php';
require_once __DIR__.'/../includes/legacy_full.php';
require_once __DIR__.'/../includes/legacy_monthly_planner.php';
require_once __DIR__.'/../includes/legacy_du.php';
if(DB_NAME!=='db_spp' && (!preg_match('/^db_spp_(?:(?:audit|test)_[a-z0-9_]+|test[0-9]+)$/D',DB_NAME)||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'))throw new RuntimeException('Worker target not permitted');
$root=legacy_root();
$runPowerShell=static function(array $args):array{
    $pipes=[];$proc=proc_open(array_merge(['powershell.exe','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',__DIR__.'/legacy_extract.ps1'],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('PowerShell unavailable');fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($proc),$out,$err];
};
if(in_array('--setup',$argv,true)){$r=$runPowerShell(['-JobDirectory',$root,'-Unit','1','-Setup']);if($r[0]){file_put_contents($root.'/setup-error.log',$r[2]);throw new RuntimeException('LocalDB setup failed; see private log');}echo "Importer instance prepared\n";exit;}
$lock=fopen($root.'/worker.lock','c');if(!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Worker already running');
$lastHealth=0;$sqlReady=false;
$heartbeat=static function()use($root,$runPowerShell,&$lastHealth,&$sqlReady){
    if(time()-$lastHealth>30){$check=$runPowerShell(['-JobDirectory',$root,'-Unit','1','-Health']);$sqlReady=$check[0]===0;$lastHealth=time();}
    file_put_contents($root.'/heartbeat.json',json_encode(['time'=>time(),'pid'=>getmypid(),'database'=>DB_NAME,'sql_ready'=>$sqlReady,'categories'=>['students','finance','full'],'workflow_version'=>LFUL_RULES_VERSION,'source_reader_version'=>LEGACY_SOURCE_READER_VERSION,'operational_version'=>LOP_VERSION,'automation_version'=>LMA_VERSION,'monthly_version'=>LMP_VERSION,'fee_trace_version'=>LEGACY_FEE_TRACE_VERSION,'du_version'=>LDU_VERSION,'billing_version'=>LB_RULE_VERSION]));
};
$q=legacy_queue();
$ruleFiles=[__FILE__,__DIR__.'/../includes/legacy_full.php',__DIR__.'/../includes/legacy_full_components.php',__DIR__.'/../includes/legacy_full_workflow.php',__DIR__.'/../includes/legacy_operational.php',__DIR__.'/../includes/legacy_operational_setup.php',__DIR__.'/../includes/legacy_operational_resolution.php',__DIR__.'/../includes/legacy_operational_bulk.php',__DIR__.'/../includes/legacy_migration_automation.php',__DIR__.'/../includes/legacy_monthly_planner.php',__DIR__.'/../includes/legacy_billing_reconstruction.php',__DIR__.'/../includes/legacy_fee_trace.php',__DIR__.'/../includes/legacy_fee_reconstruction.php'];
$ruleFiles[]=__DIR__.'/../includes/legacy_billing.php';
$ruleFiles[]=__DIR__.'/../includes/other_fee_usage.php';
$ruleFiles[]=__DIR__.'/../includes/legacy_operational_corrections.php';
$ruleFiles[]=__DIR__.'/../includes/legacy_operational_obligations.php';
$ruleFiles[]=__DIR__.'/../includes/komite_billing.php';
$ruleFiles[]=__DIR__.'/../includes/legacy_du.php';
$ruleFiles[]=__DIR__.'/../includes/legacy_du_planner.php';
$ruleFiles[]=__DIR__.'/../includes/legacy_du_reconstruction.php';
$ruleFiles[]=__DIR__.'/../includes/legacy_billing_digest.php';
$ruleFiles[]=__DIR__.'/../includes/du_master_service.php';
$ruleFiles[]=__DIR__.'/../includes/du_psb.php';
$ruleFiles[]=__DIR__.'/../includes/du_advance.php';
$ruleFiles[]=__DIR__.'/../includes/daftar_ulang.php';
$ruleFiles[]=__DIR__.'/../includes/kelas.php';
$ruleFiles[]=__DIR__.'/../includes/promotion.php';
$ruleFiles[]=__DIR__.'/../includes/daftar_ulang.php';
$ruleSignature=static fn()=>hash('sha256',implode('|',array_map(static fn($file)=>hash_file('sha256',$file),$ruleFiles)));
$loadedRules=$ruleSignature();
$restorePaths=database_access_paths(DB_NAME,(int)$dbPort);
$restoreGeneration=is_file($restorePaths['generation'])?trim(file_get_contents($restorePaths['generation'])):'0';
// Interrupted pre-commit jobs are held for explicit retry; application is idempotent via manifest.
foreach($q->query("SELECT id FROM jobs WHERE state='processing'")->fetchAll(PDO::FETCH_COLUMN) as $interrupted){$recovery=$runPowerShell(['-JobDirectory',$root.'/'.$interrupted,'-Unit','1','-Recover']);if($recovery[0]){file_put_contents($root.'/'.$interrupted.'/recovery-error.log',$recovery[2]);throw new RuntimeException('Interrupted source cleanup requires review; private log retained');}}
$q->exec("UPDATE jobs SET state='failed',message='Worker berhenti. Tinjau hasil dan unggah ulang untuk pemulihan aman.' WHERE state='processing'");
$q->exec("UPDATE jobs SET state='apply_queued' WHERE state='applying'");
$q->exec("UPDATE jobs SET state='simulate_queued' WHERE state='simulating'");
do {
    if(!hash_equals($loadedRules,$ruleSignature())){fwrite(STDERR,"Aturan migrasi berubah. Mulai ulang worker dan jalankan simulasi ulang.\n");break;}
    $heartbeat();
    $currentGeneration=is_file($restorePaths['generation'])?trim(file_get_contents($restorePaths['generation'])):'0';
    if($currentGeneration!==$restoreGeneration){fwrite(STDERR,"Database dipulihkan. Mulai ulang worker setelah meninjau antrean Legacy.\n");break;}
    // The same maintenance gate used by HTTP also pauses queued application during DDL/restore.
    if(is_file(__DIR__.'/../tmp/financial_migration.lock')||is_file($restorePaths['maintenance'])){if(in_array('--once',$argv,true))break;usleep(500000);continue;}
    $job=$q->query("SELECT * FROM jobs WHERE state IN ('queued','apply_queued','simulate_queued') ORDER BY created,id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if(!$job){if(in_array('--once',$argv,true))break;usleep(500000);continue;}
    $dir=$root.'/'.$job['id'];
    $restoreLock=database_access_lock($restorePaths,LOCK_SH|LOCK_NB);
    if(!$restoreLock){if(in_array('--once',$argv,true))break;usleep(500000);continue;}
    try {
        if(is_file($restorePaths['maintenance']))continue;
        legacy_actor($koneksi,(int)$job['actor'],(int)$job['unit']);
        if($job['cancel']){legacy_update($job['id'],['state'=>'cancelled','message'=>'Dibatalkan sebelum penerapan.']);continue;}
        if($job['state']==='simulate_queued'){
            $claim=$q->prepare("UPDATE jobs SET state='simulating',updated=? WHERE id=? AND state='simulate_queued' AND cancel=0");$claim->execute([time(),$job['id']]);if($claim->rowCount()!==1)continue;
            legacy_update($job['id'],['stage'=>'Memeriksa Hasil','message'=>'Menghitung histori, saldo dan kewajiban.']);lful_simulate($koneksi,$job['id']);
            if(legacy_job($job['id'])['cancel'])legacy_update($job['id'],['state'=>'cancelled','stage'=>'Dibatalkan','message'=>'Simulasi dibatalkan; data operasional tidak berubah.']);
            else legacy_update($job['id'],['state'=>'ready','stage'=>'Periksa Hasil','message'=>'Simulasi selesai. Periksa ringkasan dan pengecualian.']);continue;
        }
        if($job['state']==='apply_queued'){
            $claim=$q->prepare("UPDATE jobs SET state='applying',updated=? WHERE id=? AND state='apply_queued' AND cancel=0");$claim->execute([time(),$job['id']]);if($claim->rowCount()!==1)continue;
            legacy_update($job['id'],['state'=>'applying','stage'=>'Impor Legacy','done'=>0,'total'=>0]);
            if(($job['category']??'students')==='full'){database_access_release($restoreLock);$restoreLock=null;lful_run($koneksi,$job);}
            elseif(($job['category']??'students')==='finance')lf_publish($koneksi,$job);else legacy_apply($koneksi,$job);continue;
        }
        if(hash_file('sha256',$dir.'/source.dat')!==$job['hash'])throw new RuntimeException('Hash sumber berubah.');
        $claim=$q->prepare("UPDATE jobs SET state='processing',updated=? WHERE id=? AND state='queued' AND cancel=0");$claim->execute([time(),$job['id']]);if($claim->rowCount()!==1)continue;
        legacy_update($job['id'],['stage'=>'Periksa Backup']);
        $pipes=[];$proc=proc_open(['powershell.exe','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',__DIR__.'/legacy_extract.ps1','-JobDirectory',$dir,'-Unit',(string)$job['unit'],'-Category',$job['category']??'students'],[0=>['pipe','r'],1=>['file',$dir.'/extract-output.log','a'],2=>['file',$dir.'/extract-error.log','a']],$pipes);
        if(!is_resource($proc))throw new RuntimeException('SQL Server worker tidak tersedia.');fclose($pipes[0]);
        $started=time();do{$heartbeat();if(time()-$started>900){proc_terminate($proc);$runPowerShell(['-JobDirectory',$dir,'-Unit',(string)$job['unit'],'-Recover']);throw new RuntimeException('Batas waktu pekerjaan terlampaui; tidak ada siswa diterapkan.');}$status=proc_get_status($proc);$latest=legacy_job($job['id']);if($latest['cancel'])file_put_contents($dir.'/cancel','1');
            if(is_file($dir.'/progress.json')){$p=json_decode(ltrim(file_get_contents($dir.'/progress.json'),"\xEF\xBB\xBF"),true);if(is_array($p))legacy_update($job['id'],array_intersect_key($p,array_flip(['stage','done','total'])));}
            if($status['running'])usleep(500000);
        }while($status['running']);proc_close($proc);
        if(legacy_job($job['id'])['cancel']){legacy_update($job['id'],['state'=>'cancelled','message'=>'Dibatalkan; tidak ada siswa yang diterapkan.']);continue;}
        if($status['exitcode']!==0){
            if(is_file($dir.'/source-error.json')){$sourceError=json_decode(ltrim(file_get_contents($dir.'/source-error.json'),"\xEF\xBB\xBF"),true);if(($sourceError['code']??'')==='source_schema_unsupported')throw new DomainException('Struktur backup belum didukung. Periksa sumber sebelum mengunggah ulang.');}
            throw new RuntimeException('Backup tidak dapat dibuka: periksa profil unit, integritas, versi, atau enkripsi. Detail tersimpan pada log privat.');
        }
        if(($job['category']??'students')==='full'){lful_stage($koneksi,$job);continue;}
        if(($job['category']??'students')==='finance'){lf_stage($koneksi,$job);continue;}
        $sourceManifest=json_decode(ltrim(file_get_contents($dir.'/source_manifest.json'),"\xEF\xBB\xBF"),true,512,JSON_THROW_ON_ERROR);legacy_source_manifest_check($sourceManifest,(int)$job['unit']);
        $rows=[];$file=fopen($dir.'/rows.jsonl','r');while(($line=fgets($file))!==false)$rows[]=json_decode($line,true,512,JSON_THROW_ON_ERROR);fclose($file);
        legacy_update($job['id'],['stage'=>'Validasi','done'=>0,'total'=>count($rows)]);legacy_stage($koneksi,$job,$rows);
    }catch(Throwable $e){
        if($e instanceof LegacyApplicationBusy){legacy_update($job['id'],['state'=>'apply_queued','stage'=>'Menunggu Akses Aplikasi','message'=>'Halaman lain masih berjalan. Worker akan mencoba otomatis; tidak perlu kirim ulang.']);usleep(500000);}
        else{file_put_contents($dir.'/worker-error.log',$e->getMessage()."\n",FILE_APPEND);legacy_update($job['id'],['state'=>'failed','message'=>$e instanceof mysqli_sql_exception?'Penerapan dibatalkan. Identitas atau relasi berubah; tidak ada siswa parsial.':$e->getMessage()]);}
    }
    finally{database_access_release($restoreLock);}
}while(!in_array('--once',$argv,true));
