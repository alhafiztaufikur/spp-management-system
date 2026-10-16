<?php
/** Private, all-unit snapshot queue. Confirmed restore has a separate endpoint/worker. */
require_once __DIR__.'/operational_access.php';
require_once __DIR__.'/database_backup_policy.php';
require_once __DIR__.'/database_backup_dispatch.php';
function db_backup_now(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')); }
function db_backup_latest_due(DateTimeImmutable $now,string $dailyTime='18:00'): string {
    db_backup_validate_settings($dailyTime,'90','0');[$hour,$minute]=array_map('intval',explode(':',$dailyTime));
    $local=$now->setTimezone(new DateTimeZone('Asia/Jakarta')); $due=$local->setTime($hour,$minute);
    return ($local<$due?$due->modify('-1 day'):$due)->format(DATE_ATOM);
}
function db_backup_next_schedule(?DateTimeImmutable $now=null,string $dailyTime='18:00'): string {
    db_backup_validate_settings($dailyTime,'90','0');[$hour,$minute]=array_map('intval',explode(':',$dailyTime));
    $local=($now??db_backup_now())->setTimezone(new DateTimeZone('Asia/Jakarta')); $next=$local->setTime($hour,$minute);
    return ($next<=$local?$next->modify('+1 day'):$next)->format(DATE_ATOM);
}
function db_backup_normal_path(string $path): string { return strtolower(rtrim(str_replace('\\','/', $path),'/')); }
function db_backup_private_path(string $path): string {
    if(!preg_match('~^[a-zA-Z]:[\\\\/]~',$path)||preg_match('~(^|[\\\\/])\.\.?([\\\\/]|$)~',$path)||str_contains($path,"\0"))throw new RuntimeException('Private backup path required.');
    $resolved=realpath($path); if($resolved===false||is_link($path))throw new RuntimeException('Private backup path unavailable.');
    $normal=db_backup_normal_path($resolved); $web=db_backup_normal_path(realpath(__DIR__.'/..'));
    if($normal!==db_backup_normal_path($path))throw new RuntimeException('Backup paths cannot use aliases or junctions.');
    if($normal===$web||str_starts_with($normal,$web.'/')||str_starts_with($normal,'c:/laragon/www/'))throw new RuntimeException('Backup storage must be outside the web root.');
    return $resolved;
}
function db_backup_config_path(): string { return getenv('SPP_BACKUP_CONFIG')?:'C:/laragon/data/spp-backup/db_spp/config.json'; }
function db_backup_config(): array {
    $path=db_backup_private_path(db_backup_config_path());
    $c=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    foreach(['app_root','mysql_bin','php_executable','storage_root','database','host','port','server_uuid','datadir','source_defaults_file','installed_at'] as $key)if(!isset($c[$key])||!is_scalar($c[$key]))throw new RuntimeException('Backup configuration incomplete.');
    if(($c['version']??0)!==1||!preg_match('/^db_spp(?:_audit_[a-z0-9_]+)?$/D',(string)$c['database'])||!in_array($c['host'],['127.0.0.1','localhost','::1'],true)||filter_var($c['port'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]])===false)throw new RuntimeException('Backup target invalid.');
    if(db_backup_normal_path(realpath($c['app_root'])?:'')!==db_backup_normal_path(realpath(__DIR__.'/..')))throw new RuntimeException('Backup application identity differs.');
    $c['storage_root']=db_backup_private_path($c['storage_root']); $c['source_defaults_file']=db_backup_private_path($c['source_defaults_file']);
    if(!is_dir($c['storage_root'].'/jobs'))throw new RuntimeException('Backup storage is not installed.');
    $c['_config_file']=$path;
    return $c;
}
function db_backup_store(bool $initialize=false): PDO {
    $c=db_backup_config(); $file=$c['storage_root'].'/queue.sqlite';
    if(!$initialize&&!is_file($file))throw new RuntimeException('Backup queue is not installed.');
    $db=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=10000; PRAGMA foreign_keys=ON;');
    if($initialize){
        $db->exec('PRAGMA journal_mode=WAL; CREATE TABLE IF NOT EXISTS jobs(id TEXT PRIMARY KEY,manual_key TEXT UNIQUE,slot TEXT,type TEXT NOT NULL,actor INTEGER NOT NULL DEFAULT 0,actor_name TEXT NOT NULL DEFAULT "Sistem",state TEXT NOT NULL DEFAULT "queued",message TEXT NOT NULL DEFAULT "Menunggu layanan pencadangan.",created_at TEXT NOT NULL,started_at TEXT,finished_at TEXT,next_attempt_at INTEGER NOT NULL DEFAULT 0,retryable INTEGER NOT NULL DEFAULT 0,archive_name TEXT,archive_sha256 TEXT,sql_sha256 TEXT,bytes INTEGER NOT NULL DEFAULT 0); CREATE TABLE IF NOT EXISTS schedule_slots(slot TEXT PRIMARY KEY,last_job_id TEXT NOT NULL REFERENCES jobs(id)); CREATE INDEX IF NOT EXISTS jobs_state ON jobs(state,created_at);');
        $db->exec('CREATE TABLE IF NOT EXISTS request_keys(request_key TEXT PRIMARY KEY,job_id TEXT NOT NULL REFERENCES jobs(id));');
    }
    db_backup_policy_schema($db);
    return $db;
}
function db_backup_atomic_json(string $file,array $data): void {
    $tmp=$file.'.'.bin2hex(random_bytes(6)).'.partial';
    if(file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Backup metadata could not be saved.');
    if(!rename($tmp,$file)){@unlink($tmp);throw new RuntimeException('Backup metadata could not be published.');}
}
function db_backup_job_dir(string $id): string {
    if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('ID cadangan tidak valid.');
    return db_backup_config()['storage_root'].'/jobs/'.$id;
}
function db_backup_public_job(array $job): array {
    $date=new DateTimeImmutable($job['started_at']??$job['created_at']);
    $out=['id'=>$job['id'],'name'=>'SistemSPP_'.$date->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('Ymd_His').'_'.substr($job['id'],0,8).'.sql',
        'type'=>$job['type'],'created_at'=>$job['created_at'],'scheduled_for'=>$job['slot']??null,'started_at'=>$job['started_at']??null,'finished_at'=>$job['finished_at']??null,
        'state'=>$job['state']==='expiring'?'expired':$job['state'],'message'=>$job['message'],'bytes'=>(int)($job['bytes']??0),'actor_name'=>$job['actor_name']];
    if($job['state']==='verified')$out['download_url']='backup_api.php?action=download&id='.$job['id'];
    return $out;
}
function db_backup_find(PDO $db,string $id): array {
    if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('ID cadangan tidak valid.');
    $s=$db->prepare('SELECT * FROM jobs WHERE id=?');$s->execute([$id]);$job=$s->fetch(PDO::FETCH_ASSOC);
    if(!$job)throw new InvalidArgumentException('Cadangan tidak ditemukan.'); return $job;
}
function db_backup_assert_application(array $c,mysqli $db): void {
    $identity=$db->query('SELECT DATABASE() db,@@server_uuid uuid,@@datadir datadir')->fetch_assoc();
    if($identity['db']!==$c['database']||!hash_equals(strtolower($c['server_uuid']),strtolower($identity['uuid']))||db_backup_normal_path($c['datadir'])!==db_backup_normal_path($identity['datadir']))throw new RuntimeException('Backup application target differs.');
}
function db_backup_actor(int $actor): array {
    $db=$GLOBALS['koneksi']??null;
    if(!$db instanceof mysqli)throw new RuntimeException('Active application account required.');
    db_backup_assert_application(db_backup_config(),$db);
    $s=$db->prepare("SELECT id,nama FROM admin WHERE id=? AND role IN ('super_admin','admin','kasir') AND is_active=1");$s->bind_param('i',$actor);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
    if(!$row)throw new RuntimeException('Akun pengelola operasional aktif diperlukan.');return $row;
}
function db_backup_enqueue(int $actor,string $requestKey): array {
    if(!preg_match('/^[a-f0-9]{32}$/D',$requestKey))throw new InvalidArgumentException('Kunci permintaan tidak valid.');
    $account=db_backup_actor($actor);$db=db_backup_store();$db->exec('BEGIN IMMEDIATE');
    try{
        $s=$db->prepare('SELECT j.* FROM jobs j LEFT JOIN request_keys r ON r.job_id=j.id WHERE j.manual_key=? OR r.request_key=? LIMIT 1');$s->execute([$actor.':'.$requestKey,$actor.':'.$requestKey]);$job=$s->fetch(PDO::FETCH_ASSOC);
        if(!$job)$job=$db->query("SELECT * FROM jobs WHERE state IN ('queued','dumping','verifying') ORDER BY created_at LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if(!$job){$id=bin2hex(random_bytes(16));$s=$db->prepare('INSERT INTO jobs(id,manual_key,type,actor,actor_name,created_at) VALUES(?,?,?,?,?,?)');$s->execute([$id,$actor.':'.$requestKey,'manual',$actor,$account['nama'],db_backup_now()->format(DATE_ATOM)]);$job=db_backup_find($db,$id);}
        $s=$db->prepare('INSERT OR IGNORE INTO request_keys(request_key,job_id) VALUES(?,?)');$s->execute([$actor.':'.$requestKey,$job['id']]);
        $db->exec('COMMIT');return db_backup_public_job($job);
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** One slot represents the latest missed daily time, not fabricated historical snapshots. */
function db_backup_schedule_job(PDO $db,DateTimeImmutable $now): ?array {
    $db->exec('BEGIN IMMEDIATE');
    try{
        $settings=db_backup_settings($db);$slot=db_backup_policy_due($db,$now,$settings);
        if($settings['activated']){
            // Keep already queued jobs; changing the clock never cancels accepted work.
            if($db->query("SELECT 1 FROM jobs WHERE type IN ('scheduled','catchup') AND state IN ('queued','dumping','verifying') LIMIT 1")->fetchColumn()){$db->exec('COMMIT');return null;}
            // A pending retry from the previous clock remains valid until a newer daily slot is due.
            if($slot===null){
                $s=$db->prepare("SELECT * FROM jobs WHERE type IN ('scheduled','catchup') AND state='failed' AND retryable=1 AND next_attempt_at<=? ORDER BY slot DESC,rowid DESC LIMIT 1");
                $s->execute([$now->getTimestamp()]);$retry=$s->fetch(PDO::FETCH_ASSOC);
                if($retry)$slot=$retry['slot'];
            }
            if($slot!==null){
                $s=$db->prepare("SELECT * FROM jobs WHERE type IN ('scheduled','catchup') AND substr(slot,1,10)=? ORDER BY CASE WHEN state IN ('verified','expired','expiring') THEN 0 ELSE 1 END,rowid DESC LIMIT 1");
                $s->execute([substr($slot,0,10)]);$day=$s->fetch(PDO::FETCH_ASSOC);
                if($day){
                    if($day['state']!=='failed'||!(int)$day['retryable']||(int)$day['next_attempt_at']>$now->getTimestamp()){$db->exec('COMMIT');return null;}
                    $slot=$day['slot'];
                }
            }
        }
        if($slot===null){$db->exec('COMMIT');return null;}
        $s=$db->prepare('SELECT j.* FROM schedule_slots s JOIN jobs j ON j.id=s.last_job_id WHERE s.slot=?');$s->execute([$slot]);$old=$s->fetch(PDO::FETCH_ASSOC);
        if($old&&($old['state']!=='failed'||!(int)$old['retryable']||(int)$old['next_attempt_at']>$now->getTimestamp())){$db->exec('COMMIT');return null;}
        if(!$settings['activated']){$s=$db->prepare("UPDATE jobs SET state='failed',retryable=0,finished_at=?,message='Jadwal terlewat digantikan pencadangan susulan terbaru.' WHERE slot IS NOT NULL AND slot<>? AND state='queued'");$s->execute([$now->format(DATE_ATOM),$slot]);}
        $id=bin2hex(random_bytes(16));$type=$now->getTimestamp()-(new DateTimeImmutable($slot))->getTimestamp()>60?'catchup':'scheduled';
        $s=$db->prepare('INSERT INTO jobs(id,slot,type,created_at) VALUES(?,?,?,?)');$s->execute([$id,$slot,$type,$now->setTimezone(new DateTimeZone('Asia/Jakarta'))->format(DATE_ATOM)]);
        $s=$db->prepare('INSERT INTO schedule_slots(slot,last_job_id) VALUES(?,?) ON CONFLICT(slot) DO UPDATE SET last_job_id=excluded.last_job_id');$s->execute([$slot,$id]);
        $job=db_backup_find($db,$id);$db->exec('COMMIT');return $job;
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function db_backup_disk_budget(int $estimatedBytes,int $freeBytes): bool { return $freeBytes>=max(2147483648,$estimatedBytes*4); }
/** Record the missed daily window without pretending historical snapshots exist. */
function db_backup_schedule_window(PDO $db,array $job,string $installedAt): ?array {
    if(empty($job['slot']))return null;
    $last=new DateTimeImmutable($job['slot']);
    $s=$db->prepare("SELECT MAX(slot) FROM jobs WHERE slot<? AND state='verified'");$s->execute([$job['slot']]);$previous=$s->fetchColumn();
    $settings=db_backup_settings($db);$clock=$last->format('H:i');
    $first=$previous?(new DateTimeImmutable($previous))->modify('+1 day')->setTime((int)$last->format('H'),(int)$last->format('i')):new DateTimeImmutable(db_backup_next_schedule(new DateTimeImmutable($installedAt),$clock));
    if($settings['activated']&&$settings['effective_from']<=$job['slot']&&new DateTimeImmutable($settings['effective_from'])>$first)$first=new DateTimeImmutable($settings['effective_from']);
    if($first>$last)$first=$last;
    return ['first_due'=>$first->format(DATE_ATOM),'last_due'=>$last->format(DATE_ATOM),'count'=>(int)$first->diff($last)->days+1];
}
function db_backup_summary(): array {
    $out=['configured'=>false,'ready'=>false,'service_registered'=>false,'manual_available'=>false,'can_create'=>false,'active'=>null,'last_success'=>null,'count'=>0,'total_bytes'=>0,'next_schedule'=>db_backup_next_schedule(),'settings'=>['daily_time'=>'18:00','retention_days'=>90,'version'=>0,'activated'=>false,'effective_from'=>null,'updated_at'=>null],'alerts'=>[],'jobs'=>[],'free_bytes'=>null];
    if(!extension_loaded('pdo_sqlite')){$out['alerts'][]='PDO SQLite belum aktif pada PHP aplikasi. Aktifkan ekstensi tersebut lalu muat ulang server web.';return $out;}
    if(!is_file(db_backup_config_path())){$out['alerts'][]='Layanan backup belum dipasang pada PC ini. Jalankan pemasang backup terlebih dahulu.';return $out;}
    try{
        $c=db_backup_config();if(($GLOBALS['koneksi']??null) instanceof mysqli)db_backup_assert_application($c,$GLOBALS['koneksi']);
        $db=db_backup_store();$out['configured']=true;$out['settings']=db_backup_settings($db);$out['next_schedule']=db_backup_policy_next($db,db_backup_now(),$out['settings']);
        $heartbeat=is_file($c['storage_root'].'/heartbeat.json')?json_decode(file_get_contents($c['storage_root'].'/heartbeat.json'),true):[];
        $out['service_registered']=db_backup_service_registered($c);
        $out['ready']=$out['service_registered']&&(int)($heartbeat['time']??0)>time()-180;
        $jobs=$db->query('SELECT * FROM jobs ORDER BY created_at DESC,id DESC')->fetchAll(PDO::FETCH_ASSOC);
        foreach($jobs as $job){$public=db_backup_public_job($job);$out['jobs'][]=$public;
            if($job['state']==='verified'){$out['count']++;$out['total_bytes']+=(int)$job['bytes'];if(!$out['last_success'])$out['last_success']=$public;}
            if(!$out['active']&&in_array($job['state'],['queued','dumping','verifying'],true))$out['active']=$public;
        }
        $out['manual_available']=db_backup_dispatch_tools($c)!==null;
        $out['can_create']=$out['manual_available']&&(!$out['active']||!db_backup_job_running($c,$out['active']));
        $out['free_bytes']=(int)disk_free_space($c['storage_root']);
        if(!$out['service_registered'])$out['alerts'][]='Backup otomatis belum aktif. Jadwal Windows belum terpasang.';
        elseif(!$out['ready'])$out['alerts'][]='Layanan pencadangan belum aktif. Periksa jadwal Windows.';
        if($out['free_bytes']<10737418240)$out['alerts'][]='Ruang penyimpanan cadangan hampir penuh. Periksa ruang disk dan pengaturan penyimpanan.';
        if($db->query('SELECT COUNT(*) FROM backup_expirations WHERE finished_at IS NULL')->fetchColumn())$out['alerts'][]='Pembersihan cadangan kedaluwarsa belum selesai. Berkas yang sedang digunakan akan dicoba kembali.';
        elseif(is_file($c['storage_root'].'/retention-warning.json'))$out['alerts'][]='Pembersihan cadangan kedaluwarsa belum selesai. Periksa layanan dan berkas privat.';
        $latest=$jobs[0]??null;if($latest&&$latest['state']==='failed')$out['alerts'][]='Cadangan terakhir gagal. '.$latest['message'];
        $slot=db_backup_policy_due($db,db_backup_now(),$out['settings']);
        if($slot!==null){$s=$db->prepare("SELECT COUNT(*) FROM jobs WHERE substr(slot,1,10)=? AND state IN ('verified','expired','expiring')");$s->execute([substr($slot,0,10)]);
        if(!$s->fetchColumn()&&time()-(new DateTimeImmutable($slot))->getTimestamp()>1800)$out['alerts'][]='Cadangan harian belum selesai. Sistem akan menjalankan pencadangan susulan.';}
    }catch(Throwable $e){$out['alerts'][]='Pencadangan otomatis belum tersedia. Hubungi pengelola komputer.';}
    return $out;
}
function db_backup_source(array $c): mysqli {
    $ini=parse_ini_file($c['source_defaults_file'],true,INI_SCANNER_RAW);$client=$ini['client']??[];
    if(empty($client['user'])||!array_key_exists('password',$client))throw new RuntimeException('Backup credentials unavailable.');
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $db=mysqli_init();$db->options(MYSQLI_OPT_CONNECT_TIMEOUT,10);$db->options(MYSQLI_OPT_READ_TIMEOUT,30);
    $db->real_connect($c['host'],$client['user'],$client['password'],$c['database'],(int)$c['port']);$db->set_charset('utf8mb4');
    $identity=$db->query('SELECT @@server_uuid uuid,@@datadir datadir')->fetch_assoc();
    if(!hash_equals(strtolower($c['server_uuid']),strtolower($identity['uuid']))||db_backup_normal_path($c['datadir'])!==db_backup_normal_path($identity['datadir']))throw new RuntimeException('Backup source identity differs.');
    return $db;
}
/** Canonicalize SHOW CREATE formatting without rewriting SQL literals or identifiers. */
function db_backup_schema_ddl(string $sql,bool $table=false): string {
    // Keep quoted tokens and comments opaque. In particular, executable MySQL
    // comments and line-comment terminators must never become ordinary spaces.
    $protected=<<<'REGEX'
~('(?:\\.|''|[^'\\])*'|"(?:\\.|""|[^"\\])*"|`(?:``|[^`])*`|/\*[\s\S]*?\*/|--(?=[\x00-\x20])[^\r\n]*(?:\r\n|\r|\n|$)|\#[^\r\n]*(?:\r\n|\r|\n|$))~
REGEX;
    $parts=preg_split($protected,$sql,-1,PREG_SPLIT_DELIM_CAPTURE);
    if($parts===false)throw new RuntimeException('Schema SQL could not be tokenized.');
    foreach($parts as $index=>$part){
        if($index%2)continue;
        $part=preg_replace('/\s+/',' ',$part);
        // A changing table sequence is data, not a schema change. Column
        // AUTO_INCREMENT attributes and every quoted occurrence remain intact.
        if($table)$part=preg_replace('/\bAUTO_INCREMENT\s*=\s*\d+\b\s*/i','',$part);
        $part=preg_replace_callback('/\bCHARACTER SET ([a-z0-9_]+) COLLATE ([a-z0-9_]+)/i',static function(array $match):string{
            $charset=strtolower($match[1]);$collation=strtolower($match[2]);
            // An explicit collation already selects its character set. Remove
            // only the matching redundant declaration SHOW CREATE may add after
            // restore; retain differing or standalone charset declarations.
            return ($collation===$charset||str_starts_with($collation,$charset.'_'))?'COLLATE '.$match[2]:$match[0];
        },$part);
        $parts[$index]=$part;
    }
    return trim(implode('',$parts));
}
function db_backup_schema(mysqli $db): array {
    $objects=['tables'=>[],'views'=>[],'routines'=>[],'triggers'=>[],'events'=>[]];$estimate=0;
    $quote=static fn(string $s):string=>'`'.str_replace('`','``',$s).'`';
    $ddl=static function(array $row):string{foreach($row as $k=>$v)if(str_starts_with(strtolower($k),'create '))return db_backup_schema_ddl((string)$v,strtolower($k)==='create table');throw new RuntimeException('Schema definition unavailable.');};
    foreach($db->query('SELECT TABLE_NAME,TABLE_TYPE,ENGINE,DATA_LENGTH,INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetch_all(MYSQLI_ASSOC) as $t){
        $name=$t['TABLE_NAME'];$definition=$ddl($db->query('SHOW CREATE '.($t['TABLE_TYPE']==='VIEW'?'VIEW ':'TABLE ').$quote($name))->fetch_assoc());
        if($t['TABLE_TYPE']==='VIEW')$objects['views'][$name]=$definition;
        else{if($t['ENGINE']!=='InnoDB')throw new RuntimeException('Nontransactional table requires an administrative backup.');$objects['tables'][$name]=['engine'=>$t['ENGINE'],'ddl'=>$definition];$estimate+=(int)$t['DATA_LENGTH']+(int)$t['INDEX_LENGTH'];}
    }
    foreach($db->query('SELECT ROUTINE_NAME,ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() ORDER BY ROUTINE_NAME')->fetch_all(MYSQLI_ASSOC) as $r)$objects['routines'][$r['ROUTINE_NAME']]=$ddl($db->query('SHOW CREATE '.$r['ROUTINE_TYPE'].' '.$quote($r['ROUTINE_NAME']))->fetch_assoc());
    foreach($db->query('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetch_all(MYSQLI_NUM) as [$name]){
        $row=$db->query('SHOW CREATE TRIGGER '.$quote($name))->fetch_assoc();$objects['triggers'][$name]=db_backup_schema_ddl((string)$row['SQL Original Statement']);
    }
    foreach($db->query('SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE() ORDER BY EVENT_NAME')->fetch_all(MYSQLI_NUM) as [$name])$objects['events'][$name]=$ddl($db->query('SHOW CREATE EVENT '.$quote($name))->fetch_assoc());
    if(!$objects['tables']||!$objects['views']||!$objects['routines'])throw new RuntimeException('Backup schema inventory incomplete.');
    return ['signature'=>hash('sha256',json_encode($objects,JSON_THROW_ON_ERROR)),'objects'=>$objects,'estimated_bytes'=>$estimate];
}
/** Process argv is structured; credentials never appear in command lines. */
function db_backup_process(array $args,string $log,int $seconds=1800,?string $input=null): void {
    $pipes=[];$p=proc_open($args,[0=>$input?['file',$input,'r']:['pipe','r'],1=>['file',$log.'.out.log','w'],2=>['file',$log.'.error.log','w']],$pipes);
    if(!is_resource($p))throw new RuntimeException('Backup process could not start.');if(isset($pipes[0]))fclose($pipes[0]);$start=time();
    $lastHeartbeat=0;
    do{$status=proc_get_status($p);if(!$status['running'])break;if(time()-$lastHeartbeat>10&&isset($GLOBALS['db_backup_heartbeat_file'])){db_backup_atomic_json($GLOBALS['db_backup_heartbeat_file'],['time'=>time(),'pid'=>getmypid()]);$lastHeartbeat=time();}if(time()-$start>$seconds){proc_terminate($p);proc_close($p);throw new RuntimeException('Backup process timed out.');}usleep(200000);}while(true);
    $code=$status['exitcode'];$closed=proc_close($p);if(($code<0?$closed:$code)!==0)throw new RuntimeException('Backup process failed; private diagnostics retained.');
}
function db_backup_archive(PDO $db,string $id): array {
    $job=db_backup_find($db,$id);if(in_array($job['state'],['expired','expiring'],true))throw new BackupExpired('Cadangan sudah kedaluwarsa sesuai pengaturan penyimpanan dan tidak dapat diunduh.');
    if($job['state']!=='verified')throw new InvalidArgumentException('Cadangan belum terverifikasi.');
    $expected=db_backup_job_dir($id);$dir=db_backup_private_path($expected);
    if(db_backup_normal_path($dir)!==db_backup_normal_path($expected))throw new RuntimeException('Backup file location differs.');
    $archiveName=$job['archive_name']?:'backup.sql.gz';
    if(!in_array($archiveName,['backup.sql','backup.sql.gz'],true))throw new RuntimeException('Backup format is not supported.');
    $file=$dir.'/'.$archiveName;$manifestPath=$dir.'/manifest.json';
    if(!is_file($file)||is_link($file)||!is_file($manifestPath)||is_link($manifestPath))throw new RuntimeException('Berkas cadangan tidak tersedia.');
    $manifest=json_decode(file_get_contents($manifestPath),true,32,JSON_THROW_ON_ERROR);
    if(($manifest['id']??'')!==$id||($manifest['state']??'')!=='verified'||(isset($manifest['archive_name'])&&$manifest['archive_name']!==$archiveName)||!hash_equals((string)$job['archive_sha256'],(string)($manifest['archive_sha256']??''))||!hash_equals((string)$job['archive_sha256'],hash_file('sha256',$file))||(int)$job['bytes']!==filesize($file)||($archiveName==='backup.sql'&&!hash_equals((string)$job['archive_sha256'],(string)$job['sql_sha256'])))throw new RuntimeException('Pemeriksaan cadangan gagal. Unduhan dihentikan.');
    return ['file'=>$file,'name'=>db_backup_public_job($job)['name'],'bytes'=>filesize($file),'compressed'=>$archiveName==='backup.sql.gz'];
}
/** Existing compressed backups stay intact; all web downloads deliver verified SQL. */
function db_backup_download_archive(PDO $db,string $id): array {
    $archive=db_backup_archive($db,$id);
    if(!$archive['compressed'])return $archive;
    $job=db_backup_find($db,$id);$sql=db_backup_job_dir($id).'/download.sql';
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)$job['sql_sha256']))throw new RuntimeException('SQL checksum unavailable.');
    $lock=fopen(db_backup_job_dir($id).'/download.lock','c');
    if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('SQL download unavailable.');
    try{
        if(is_file($sql)){
            if(is_link($sql)||!hash_equals($job['sql_sha256'],hash_file('sha256',$sql)))throw new RuntimeException('SQL download checksum differs.');
        }else db_backup_expand_archive($archive['file'],$sql,$job['sql_sha256']);
        return ['file'=>$sql,'name'=>$archive['name'],'bytes'=>filesize($sql),'compressed'=>false];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
/** Copy only checksum-matching SQL to the private verification workspace. */
function db_backup_copy_sql(string $source,string $target,string $expectedHash): void {
    if(is_link($source)||!is_file($source)||!hash_equals($expectedHash,hash_file('sha256',$source))||db_backup_normal_path($source)===db_backup_normal_path($target))throw new RuntimeException('SQL verification source differs.');
    if(is_file($target)){
        if(is_link($target)||!hash_equals($expectedHash,hash_file('sha256',$target)))throw new RuntimeException('Existing SQL file requires administrative review.');
        return;
    }
    $partial=$target.'.copy-'.bin2hex(random_bytes(6)).'.partial';
    if(!copy($source,$partial)||!hash_equals($expectedHash,hash_file('sha256',$partial))||!rename($partial,$target))throw new RuntimeException('SQL verification copy failed.');
}
function db_backup_expand_archive(string $archive,string $dump,string $expectedHash): void {
    if(is_file($dump)){
        if(is_link($dump)||!hash_equals($expectedHash,hash_file('sha256',$dump)))throw new RuntimeException('Existing SQL file requires administrative review.');
        unlink($dump);
    }
    $partial=$dump.'.decode-'.bin2hex(random_bytes(6)).'.partial';$input=gzopen($archive,'rb');$output=fopen($partial,'xb');
    if(!$input||!$output)throw new RuntimeException('Compressed backup could not be opened.');
    try{while(!gzeof($input)){$chunk=gzread($input,1048576);if($chunk===false||fwrite($output,$chunk)!==strlen($chunk))throw new RuntimeException('Compressed backup is incomplete.');}}
    finally{gzclose($input);fclose($output);}
    if(!hash_equals($expectedHash,hash_file('sha256',$partial)))throw new RuntimeException('Compressed SQL checksum differs.');
    if(!rename($partial,$dump))throw new RuntimeException('Decoded SQL could not be published.');
}
