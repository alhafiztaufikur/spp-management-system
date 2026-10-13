<?php
/** Full restore accepts exact SQL from this installation's verified backup history. */
require_once __DIR__.'/database_backup.php';
require_once __DIR__.'/database_access.php';
class DatabaseRestoreConflict extends RuntimeException {}
function db_restore_store(): PDO {
    $db=db_backup_store();
    $db->exec("CREATE TABLE IF NOT EXISTS restores(id TEXT PRIMARY KEY,actor INTEGER NOT NULL,actor_name TEXT NOT NULL,token_hash TEXT NOT NULL,backup_id TEXT NOT NULL,sql_sha256 TEXT NOT NULL,name TEXT NOT NULL,bytes INTEGER NOT NULL,state TEXT NOT NULL DEFAULT 'validating',message TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,expires_at INTEGER NOT NULL,safety_id TEXT,finished_at TEXT);
        CREATE UNIQUE INDEX IF NOT EXISTS restores_active ON restores((1)) WHERE state IN ('validating','queued','safety_backup','restoring','auditing','rolling_back','recovery_required');");
    return $db;
}
function db_restore_find(PDO $db,string $id): array {
    if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('ID pemulihan tidak valid.');
    $s=$db->prepare('SELECT * FROM restores WHERE id=?');$s->execute([$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new InvalidArgumentException('Pemulihan tidak ditemukan.');return $row;
}
function db_restore_update(PDO $db,string $id,string $state,string $message,?string $safety=null): void {
    $s=$db->prepare('UPDATE restores SET state=?,message=?,updated_at=?,safety_id=COALESCE(?,safety_id),finished_at=? WHERE id=?');
    $now=db_backup_now()->format(DATE_ATOM);$s->execute([$state,$message,$now,$safety,in_array($state,['completed','failed','rolled_back','cancelled'],true)?$now:null,$id]);
}
function db_restore_public(array $row): array {
    return array_intersect_key($row,array_flip(['id','name','bytes','state','message','created_at','updated_at','finished_at','expires_at','safety_id']));
}
function db_restore_needs_finalization(array $c,array $row): bool {
    if(!in_array($row['state'],['completed','rolled_back','failed'],true))return false;
    $paths=database_access_paths($c['database'],(int)$c['port']);
    if(!is_file($paths['maintenance']))return false;
    $owner=json_decode(file_get_contents($paths['maintenance']),true,32,JSON_THROW_ON_ERROR);
    return ($owner['id']??'')===$row['id']&&($owner['server_uuid']??'')===$c['server_uuid'];
}
function db_restore_target(array $c,string $dir): mysqli {
    $file=db_backup_private_path($dir.'/target-auth.json');$ini=json_decode(file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $db=mysqli_init();$db->options(MYSQLI_OPT_CONNECT_TIMEOUT,10);
    $db->real_connect($c['host'],$ini['user']??'', $ini['password']??'',null,(int)$c['port']);$db->set_charset('utf8mb4');
    $identity=$db->query('SELECT @@server_uuid uuid,@@datadir datadir')->fetch_assoc();
    if(!hash_equals(strtolower($c['server_uuid']),strtolower($identity['uuid']))||db_backup_normal_path($identity['datadir'])!==db_backup_normal_path($c['datadir']))throw new RuntimeException('Restore target identity differs.');
    return $db;
}
function db_restore_credentials(array $c,string $dir): void {
    db_backup_private_path($dir);if(file_exists($dir.'/target.cnf')||is_link($dir.'/target.cnf'))db_backup_private_path($dir.'/target.cnf');
    // The read-only export account is never granted restore privileges.
    $escape=static function(string $value): string {
        if(preg_match('/[\r\n\x00]/',$value))throw new RuntimeException('Restore credentials cannot be encoded.');
        return '"'.str_replace(['\\','"'],['\\\\','\\"'],$value).'"';
    };
    $text="[client]\r\nhost=".$escape($c['host'])."\r\nport=".$c['port']."\r\nprotocol=TCP\r\nuser=".$escape(DB_USER)."\r\npassword=".$escape(DB_PASS)."\r\n";
    db_backup_atomic_json($dir.'/target-auth.json',['user'=>DB_USER,'password'=>DB_PASS]);
    if(file_put_contents($dir.'/target.cnf',$text,LOCK_EX)!==strlen($text))throw new RuntimeException('Private restore credentials unavailable.');
}
function db_restore_assert_privileges(mysqli $db,string $database): void {
    $db->select_db($database);$grants=$db->query('SHOW GRANTS FOR CURRENT_USER()')->fetch_all(MYSQLI_NUM);
    $required=['SELECT','INSERT','CREATE','DROP','ALTER','INDEX','REFERENCES','CREATE VIEW','SHOW VIEW','TRIGGER','EVENT','CREATE ROUTINE','ALTER ROUTINE','EXECUTE','LOCK TABLES'];$available=[];$global=[];
    foreach($grants as [$grant]){
        if(!preg_match('/^GRANT (.+) ON (.+) TO /',$grant,$m))continue;
        if(!in_array($m[2],['*.*','`'.str_replace('`','``',$database).'`.*'],true))continue;
        if($m[1]==='ALL PRIVILEGES'){
            if($m[2]==='*.*')return;
            $available=array_merge($available,$required);continue;
        }
        if($m[2]==='*.*')$global=array_merge($global,explode(', ',$m[1]));
        $available=array_merge($available,explode(', ',$m[1]));
    }
    if(array_diff($required,$available))throw new DatabaseRestoreConflict('Akun koneksi aplikasi belum memiliki izin pemulihan MySQL. Hubungi pengelola server.');
    $identity=$db->query('SELECT CURRENT_USER()')->fetch_row()[0];
    foreach(['VIEWS'=>'TABLE_SCHEMA','ROUTINES'=>'ROUTINE_SCHEMA','TRIGGERS'=>'TRIGGER_SCHEMA','EVENTS'=>'EVENT_SCHEMA'] as $table=>$column){
        $s=$db->prepare('SELECT DISTINCT DEFINER FROM information_schema.'.$table.' WHERE '.$column.'=?');$s->bind_param('s',$database);$s->execute();$definers=$s->get_result()->fetch_all(MYSQLI_NUM);$s->close();
        foreach($definers as [$definer])if($definer!==$identity&&!array_intersect(['SUPER','SET_USER_ID','SET_ANY_DEFINER'],$global))throw new DatabaseRestoreConflict('Akun MySQL belum diizinkan memulihkan pemilik objek database. Hubungi pengelola server.');
    }
}
function db_restore_upload(int $actor,array $file): array {
    $account=db_backup_actor($actor);$c=db_backup_config();$db=db_restore_store();
    if(db_backup_dispatch_tools($c)===null)throw new DatabaseRestoreConflict('Pemicu pemulihan belum tersedia pada PC ini.');
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('Unggahan SQL tidak lengkap. Batas unggahan server juga harus mencukupi.');
    if(!is_string($file['name']??null))throw new InvalidArgumentException('Pilih satu file SQL saja.');
    $name=basename(str_replace('\\','/',$file['name']));$size=filesize($file['tmp_name']);
    if(!preg_match('/\.sql$/iD',$name)||strlen($name)>200||$size<100||$size>100*1024*1024)throw new InvalidArgumentException('Pilih satu SQL SistemSPP, maksimal 100 MiB.');
    $hash=hash_file('sha256',$file['tmp_name']);
    $s=$db->prepare("SELECT id FROM jobs WHERE sql_sha256=? AND state='verified' ORDER BY created_at DESC LIMIT 1");$s->execute([$hash]);$backup=$s->fetchColumn();
    if(!$backup)throw new InvalidArgumentException('File tidak cocok dengan cadangan terverifikasi di riwayat PC ini. Gunakan SQL hasil unduhan Backup & Restore.');
    $access=db_backup_access_lock($backup);
    try{
        db_backup_archive($db,$backup);$sourceDir=db_backup_job_dir($backup);$manifest=json_decode(file_get_contents($sourceDir.'/manifest.json'),true,64,JSON_THROW_ON_ERROR);
        if(($manifest['database']??'')!==$c['database']||($manifest['source_server_uuid']??'')!==$c['server_uuid'])throw new InvalidArgumentException('Identitas database asal cadangan berbeda.');
        db_restore_assert_privileges($GLOBALS['koneksi'],$c['database']);
        $id=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(32));$dir=db_backup_job_dir($id);
        $db->exec('BEGIN IMMEDIATE');
        try{
            if($db->query("SELECT 1 FROM restores WHERE state IN ('validating','queued','safety_backup','restoring','auditing','rolling_back','recovery_required') LIMIT 1")->fetchColumn())throw new DatabaseRestoreConflict('Pemulihan lain masih berlangsung. Selesaikan proses tersebut terlebih dahulu.');
            if(!mkdir($dir,0700))throw new RuntimeException('Private restore directory unavailable.');db_backup_private_path($dir);
            if(!move_uploaded_file($file['tmp_name'],$dir.'/dump.sql')||!hash_equals($hash,hash_file('sha256',$dir.'/dump.sql')))throw new RuntimeException('Restore upload publication failed.');
            foreach(['schema.json','verification.json'] as $evidence){db_backup_private_path($sourceDir.'/'.$evidence);if(!copy($sourceDir.'/'.$evidence,$dir.'/'.($evidence==='verification.json'?'expected.json':$evidence)))throw new RuntimeException('Backup evidence unavailable.');}
            $now=db_backup_now()->format(DATE_ATOM);
            $s=$db->prepare('INSERT INTO restores(id,actor,actor_name,token_hash,backup_id,sql_sha256,name,bytes,message,created_at,updated_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$id,$actor,$account['nama'],hash('sha256',$token),$backup,$hash,$name,$size,'Memeriksa pemulihan SQL pada database terpisah.',$now,$now,time()+3600]);$db->exec('COMMIT');
        }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
        return ['job'=>db_restore_public(db_restore_find($db,$id)),'token'=>$token];
    }finally{db_backup_release_lock($access);}
}
function db_restore_authorize(PDO $db,string $id,int $actor,string $token): array {
    $row=db_restore_find($db,$id);
    if($actor!==(int)$row['actor']||!preg_match('/^[a-f0-9]{64}$/D',$token)||!hash_equals($row['token_hash'],hash('sha256',$token)))throw new InvalidArgumentException('Akses pemulihan tidak valid.');
    return $row;
}
function db_restore_dispatch(string $id): void {
    $c=db_backup_config();$db=db_restore_store();$row=db_restore_find($db,$id);
    if(!in_array($row['state'],['validating','queued','safety_backup','restoring','auditing','rolling_back','recovery_required'],true)&&!db_restore_needs_finalization($c,$row))return;
    $tools=db_backup_dispatch_tools($c);if(!$tools)throw new DatabaseRestoreConflict('Pemicu pemulihan belum tersedia.');
    $dir=db_backup_private_path(db_backup_job_dir($id));
    foreach(['restore-worker.lock','restore-dispatch.lock','worker.out.log','worker.error.log'] as $name)if(file_exists($dir.'/'.$name)||is_link($dir.'/'.$name))db_backup_private_path($dir.'/'.$name);
    $lock=fopen($dir.'/restore-dispatch.lock','c');if(!$lock)throw new DatabaseRestoreConflict('Pemicu pemulihan belum dapat dikunci.');
    if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return;}
    try{
        if(db_backup_lock_busy($dir.'/restore-worker.lock'))return;
        $receipt=$dir.'/restore-dispatch.json';
        if(is_file($receipt)){db_backup_private_path($receipt);$sent=json_decode(file_get_contents($receipt),true,16,JSON_THROW_ON_ERROR);if(($sent['time']??0)>time()-10)return;}
        db_backup_atomic_json($receipt,['id'=>$id,'time'=>time()]);
        db_backup_process([$tools[0],'-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',$tools[1],'-ConfigFile',$c['_config_file'],'-JobId',$id,'-Mode','restore'],$dir.'/restore-dispatch',20);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function db_restore_confirm(PDO $db,array $row,int $actor,string $confirmation,bool $understood): array {
    db_backup_actor($actor);
    if(!$understood||$confirmation!=='PULIHKAN DATABASE')throw new InvalidArgumentException('Centang persetujuan dan ketik PULIHKAN DATABASE dengan tepat.');
    if(in_array($row['state'],['queued','safety_backup','restoring','auditing','completed'],true))return db_restore_public($row);
    if($row['state']!=='ready'||(int)$row['expires_at']<time())throw new DatabaseRestoreConflict('Pemeriksaan belum selesai atau sudah kedaluwarsa. Periksa kembali file SQL.');
    $db->exec('BEGIN IMMEDIATE');
    try{
        $current=db_restore_find($db,$row['id']);
        if($current['state']!=='ready'||(int)$current['expires_at']<time())throw new DatabaseRestoreConflict('Status pemulihan telah berubah atau kedaluwarsa. Periksa kembali SQL.');
        $active=$db->query("SELECT id FROM restores WHERE state IN ('validating','queued','safety_backup','restoring','auditing','rolling_back','recovery_required') LIMIT 1")->fetchColumn();
        if($active&&$active!==$row['id'])throw new DatabaseRestoreConflict('Pemulihan lain masih berlangsung.');
        db_restore_credentials(db_backup_config(),db_backup_job_dir($row['id']));
        $s=$db->prepare("UPDATE restores SET state='queued',message='Menunggu backup pengaman sebelum pemulihan.',updated_at=? WHERE id=? AND state='ready'");$s->execute([db_backup_now()->format(DATE_ATOM),$row['id']]);$db->exec('COMMIT');
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    return db_restore_public(db_restore_find($db,$row['id']));
}
