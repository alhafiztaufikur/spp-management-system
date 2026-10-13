<?php
/** Scheduling and retention metadata live only in the private backup SQLite store. */
class BackupSettingsConflict extends RuntimeException {}
class BackupExpired extends RuntimeException {}

function db_backup_policy_schema(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS backup_settings (
        id INTEGER PRIMARY KEY CHECK(id=1), daily_time TEXT NOT NULL,
        retention_days INTEGER NOT NULL CHECK(retention_days BETWEEN 1 AND 365),
        version INTEGER NOT NULL, effective_from TEXT NOT NULL, updated_at TEXT NOT NULL,
        actor INTEGER NOT NULL, actor_name TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS backup_setting_changes (
        version INTEGER PRIMARY KEY, daily_time TEXT NOT NULL, retention_days INTEGER NOT NULL,
        effective_from TEXT NOT NULL, updated_at TEXT NOT NULL, actor INTEGER NOT NULL,
        actor_name TEXT NOT NULL, previous_json TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS backup_retention_barriers (
        version INTEGER PRIMARY KEY, verified_ids_json TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS backup_expirations (
        job_id TEXT PRIMARY KEY REFERENCES jobs(id), anchor_id TEXT NOT NULL REFERENCES jobs(id),
        settings_version INTEGER NOT NULL, cutoff TEXT NOT NULL, planned_at TEXT NOT NULL,
        finished_at TEXT, last_error TEXT);');
}
function db_backup_settings(PDO $db): array {
    $defaults=['daily_time'=>'18:00','retention_days'=>90,'version'=>0,'effective_from'=>null,'updated_at'=>null,'activated'=>false];
    if(!$db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='backup_settings'")->fetchColumn())return $defaults;
    $row=$db->query('SELECT daily_time,retention_days,version,effective_from,updated_at FROM backup_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if(!$row)return $defaults;
    db_backup_validate_settings($row['daily_time'],(string)$row['retention_days'],(string)$row['version']);
    return array_replace($row,['retention_days'=>(int)$row['retention_days'],'version'=>(int)$row['version'],'activated'=>true]);
}
function db_backup_validate_settings($dailyTime,$days,$version): void {
    if(!is_string($dailyTime)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$dailyTime))throw new InvalidArgumentException('Jam backup harus berformat HH:mm, antara 00:00 dan 23:59 WIB.');
    if(!is_string($days)||!preg_match('/^[1-9][0-9]{0,2}$/D',$days)||(int)$days>365)throw new InvalidArgumentException('Lama penyimpanan harus antara 1 dan 365 hari.');
    if(!is_string($version)||!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',$version))throw new InvalidArgumentException('Versi pengaturan tidak valid. Muat ulang halaman.');
}
function db_backup_save_settings(PDO $db,array $actor,$dailyTime,$days,$version,?DateTimeImmutable $now=null): array {
    db_backup_validate_settings($dailyTime,$days,$version);
    $now=($now??db_backup_now())->setTimezone(new DateTimeZone('Asia/Jakarta'));
    $db->exec('BEGIN IMMEDIATE');
    try{
        $old=db_backup_settings($db);
        if($old['version']!==(int)$version)throw new BackupSettingsConflict('Pengaturan sudah diubah oleh pengguna lain. Muat ulang halaman sebelum menyimpan kembali.');
        if($old['activated']&&$old['daily_time']===$dailyTime&&$old['retention_days']===(int)$days){$db->exec('COMMIT');return $old;}
        $effective=$old['activated']&&$old['daily_time']===$dailyTime?$old['effective_from']:db_backup_next_schedule($now,$dailyTime);
        $values=[$dailyTime,(int)$days,$old['version']+1,$effective,$now->format(DATE_ATOM),(int)$actor['id'],(string)$actor['nama']];
        $s=$db->prepare('INSERT INTO backup_settings(id,daily_time,retention_days,version,effective_from,updated_at,actor,actor_name) VALUES(1,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET daily_time=excluded.daily_time,retention_days=excluded.retention_days,version=excluded.version,effective_from=excluded.effective_from,updated_at=excluded.updated_at,actor=excluded.actor,actor_name=excluded.actor_name');
        $s->execute($values);
        $s=$db->prepare('INSERT INTO backup_setting_changes(daily_time,retention_days,version,effective_from,updated_at,actor,actor_name,previous_json) VALUES(?,?,?,?,?,?,?,?)');
        $s->execute([...$values,json_encode($old,JSON_THROW_ON_ERROR)]);
        // Wall-clock timestamps have one-second precision in old jobs. Remember completed IDs too,
        // so a success immediately before Save cannot authorize deletion under the new policy.
        $ids=$db->query("SELECT id FROM jobs WHERE type IN ('scheduled','catchup') AND state='verified'")->fetchAll(PDO::FETCH_COLUMN);
        $db->prepare('INSERT INTO backup_retention_barriers(version,verified_ids_json) VALUES(?,?)')->execute([$old['version']+1,json_encode($ids,JSON_THROW_ON_ERROR)]);
        $result=db_backup_settings($db);$db->exec('COMMIT');return $result;
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function db_backup_policy_due(PDO $db,DateTimeImmutable $now,array $settings): ?string {
    $slot=db_backup_latest_due($now,$settings['daily_time']);
    if($settings['activated']&&new DateTimeImmutable($slot)<new DateTimeImmutable($settings['effective_from']))return null;
    return $slot;
}
function db_backup_policy_next(PDO $db,DateTimeImmutable $now,array $settings): string {
    $next=db_backup_next_schedule($now,$settings['daily_time']);
    if($settings['activated']&&new DateTimeImmutable($next)<new DateTimeImmutable($settings['effective_from']))$next=$settings['effective_from'];
    // A time change must not schedule another successful/outstanding slot on the same WIB date.
    $s=$db->prepare("SELECT COUNT(*) FROM jobs WHERE type IN ('scheduled','catchup') AND substr(slot,1,10)=? AND state IN ('verified','expired','expiring','queued','dumping','verifying')");
    $s->execute([substr($next,0,10)]);
    if($s->fetchColumn())$next=(new DateTimeImmutable($next))->modify('+1 day')->format(DATE_ATOM);
    return $next;
}
/** The lock is held until the HTTP stream or CLI verification has finished. */
function db_backup_access_lock(string $id,bool $exclusive=false,bool $nonblocking=false) {
    $dir=db_backup_private_path(db_backup_job_dir($id));$path=$dir.'/access.lock';
    if(file_exists($path)||is_link($path))db_backup_private_path($path);
    $handle=fopen($path,'c');
    if(!$handle)throw new RuntimeException('Cadangan sedang tidak dapat diakses.');
    if(!flock($handle,($exclusive?LOCK_EX:LOCK_SH)|($nonblocking?LOCK_NB:0))){fclose($handle);return null;}
    return $handle;
}
function db_backup_release_lock($handle): void { if(is_resource($handle)){flock($handle,LOCK_UN);fclose($handle);} }

/** A one-off CLI backup is not proof that Windows has a recurring task installed. */
function db_backup_service_registered(array $config): bool {
    if(!empty($config['allowed_test'])&&preg_match('/^db_spp_audit_[a-z0-9_]+$/D',$config['database']))return true;
    if(!empty($config['allowed_test'])&&!empty($config['isolated_instance_manifest'])){
        $file=db_backup_private_path($config['isolated_instance_manifest']);
        $manifest=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
        if(($manifest['isolated_test']??false)===true&&!empty($manifest['source_server_uuid'])&&!empty($manifest['source_datadir'])
            &&($manifest['server_uuid']??'')===$config['server_uuid']&&$manifest['source_server_uuid']!==$config['server_uuid']
            &&(int)($manifest['port']??0)===(int)$config['port']&&(int)$config['port']!==3306
            &&db_backup_normal_path($manifest['datadir']??'')===db_backup_normal_path($config['datadir'])
            &&db_backup_normal_path($manifest['source_datadir'])!==db_backup_normal_path($config['datadir']))return true;
    }
    $path=dirname($config['_config_file']).'/scheduled-task.json';
    if(!is_file($path))return false;
    db_backup_private_path($path);$manifest=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
    return !empty($manifest['registered_at'])&&!empty($manifest['owner_sid'])
        &&($manifest['task_name']??'')===($config['task_name']??null)
        &&db_backup_normal_path($manifest['config_file']??'')===db_backup_normal_path($config['_config_file']);
}

/** Remove only known SQL payloads after an owned, verified replacement exists. Never delete directories. */
function db_backup_prune(PDO $db,?DateTimeImmutable $now=null): array {
    $now=($now??db_backup_now())->setTimezone(new DateTimeZone('Asia/Jakarta'));
    $settings=db_backup_settings($db);$result=['expired'=>0,'deferred'=>0,'errors'=>0];
    if(!$settings['activated'])return $result;
    $s=$db->prepare('SELECT verified_ids_json FROM backup_retention_barriers WHERE version=?');$s->execute([$settings['version']]);
    $barrier=$s->fetchColumn();
    if($barrier===false)throw new RuntimeException('Retention verification boundary unavailable.');
    $excluded=array_fill_keys(json_decode($barrier,true,512,JSON_THROW_ON_ERROR),true);
    $s=$db->prepare("SELECT * FROM jobs WHERE type IN ('scheduled','catchup') AND state='verified' AND julianday(finished_at)>=julianday(?) AND julianday(finished_at)<=julianday(?) ORDER BY julianday(finished_at) DESC,rowid DESC");
    $s->execute([$settings['updated_at'],$now->format(DATE_ATOM)]);$anchor=null;
    while($row=$s->fetch(PDO::FETCH_ASSOC)){if(!isset($excluded[$row['id']])){$anchor=$row;break;}}$s->closeCursor();
    if($anchor){
        // Idle ticks may resume cleanup, but cannot move the cutoff beyond the verified replacement.
        $cutoff=(new DateTimeImmutable($anchor['finished_at']))->setTimezone(new DateTimeZone('Asia/Jakarta'))->modify('-'.$settings['retention_days'].' days')->format(DATE_ATOM);
        $anchorLock=null;
        try{
            $anchorLock=db_backup_access_lock($anchor['id']);db_backup_archive($db,$anchor['id']);
            $db->exec('BEGIN IMMEDIATE');
            try{
                // Saving a policy while pruning must not apply a stale interval.
                if(db_backup_settings($db)['version']===$settings['version']){
                    $s=$db->prepare("INSERT OR IGNORE INTO backup_expirations(job_id,anchor_id,settings_version,cutoff,planned_at)
                        SELECT id,?,?,?,? FROM jobs WHERE id<>? AND type IN ('scheduled','catchup') AND state='verified' AND julianday(created_at)<julianday(?)
                        AND id NOT IN (SELECT anchor_id FROM backup_expirations WHERE finished_at IS NULL)");
                    $s->execute([$anchor['id'],$settings['version'],$cutoff,$now->format(DATE_ATOM),$anchor['id'],$cutoff]);
                }
                $db->exec('COMMIT');
            }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
        }catch(Throwable $e){$result['errors']++;error_log('Backup retention replacement validation failed ('.get_class($e).').');}
        finally{db_backup_release_lock($anchorLock);}
    }
    $pending=$db->query('SELECT * FROM backup_expirations WHERE finished_at IS NULL ORDER BY planned_at,job_id')->fetchAll(PDO::FETCH_ASSOC);
    foreach($pending as $entry){
        $lock=null;$replacementLock=null;
        try{
            $lock=db_backup_access_lock($entry['job_id'],true,true);
            if(!$lock){$result['deferred']++;continue;}
            $replacementLock=db_backup_access_lock($entry['anchor_id']);db_backup_archive($db,$entry['anchor_id']);
            $db->exec('BEGIN IMMEDIATE');
            try{
                $job=db_backup_find($db,$entry['job_id']);$current=db_backup_settings($db);
                if($job['type']==='manual'||!in_array($job['state'],['verified','expiring'],true))throw new RuntimeException('Retention job identity differs.');
                if($job['state']==='verified'&&$entry['settings_version']!==$current['version']){
                    $db->prepare('DELETE FROM backup_expirations WHERE job_id=?')->execute([$job['id']]);$db->exec('COMMIT');continue;
                }
                $dir=db_backup_private_path(db_backup_job_dir($job['id']));
                $manifestPath=db_backup_private_path($dir.'/manifest.json');
                $manifest=json_decode(file_get_contents($manifestPath),true,64,JSON_THROW_ON_ERROR);
                if(($manifest['id']??'')!==$job['id']||($manifest['state']??'')!=='verified'||!hash_equals((string)$job['archive_sha256'],(string)($manifest['archive_sha256']??'')))throw new RuntimeException('Retention manifest identity differs.');
                if($job['state']==='verified')db_backup_archive($db,$job['id']);
                $payloads=['backup.sql'=>$job['archive_name']==='backup.sql'?$job['archive_sha256']:$job['sql_sha256'],
                    'backup.sql.gz'=>$job['archive_name']==='backup.sql.gz'?$job['archive_sha256']:null,'download.sql'=>$job['sql_sha256'],'dump.sql'=>$job['sql_sha256']];
                foreach($payloads as $name=>$hash){
                    $file=$dir.'/'.$name;
                    if(!file_exists($file)&&!is_link($file))continue;
                    db_backup_private_path($file);
                    if(!is_file($file)||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)||!hash_equals($hash,hash_file('sha256',$file)))throw new RuntimeException('Retention payload checksum differs.');
                }
                $db->prepare("UPDATE jobs SET state='expiring',message='Cadangan kedaluwarsa; pembersihan berkas sedang diselesaikan.' WHERE id=?")->execute([$job['id']]);
                $db->exec('COMMIT');
            }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
            // The durable expiring state prevents downloads after a crash between unlinks.
            foreach($payloads as $name=>$hash){
                $file=$dir.'/'.$name;
                if(!file_exists($file)&&!is_link($file))continue;
                db_backup_private_path($file);
                if(!is_file($file)||!is_string($hash)||!hash_equals($hash,hash_file('sha256',$file))||!unlink($file))throw new RuntimeException('Retention cleanup incomplete.');
            }
            $db->exec('BEGIN IMMEDIATE');
            try{
                $db->prepare("UPDATE jobs SET state='expired',message='Cadangan otomatis kedaluwarsa sesuai pengaturan penyimpanan.' WHERE id=?")->execute([$job['id']]);
                $db->prepare('UPDATE backup_expirations SET finished_at=?,last_error=NULL WHERE job_id=?')->execute([$now->format(DATE_ATOM),$job['id']]);
                $db->exec('COMMIT');$result['expired']++;
            }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
        }catch(Throwable $e){
            $result['errors']++;$db->prepare('UPDATE backup_expirations SET last_error=? WHERE job_id=?')->execute(['Pembersihan cadangan belum selesai; periksa layanan dan berkas privat.',$entry['job_id']]);
            error_log('Backup retention cleanup failed ('.get_class($e).').');
        }finally{db_backup_release_lock($replacementLock);db_backup_release_lock($lock);}
    }
    return $result;
}
