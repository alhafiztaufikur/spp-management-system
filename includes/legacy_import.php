<?php
/** Private job storage. SQL Server backups are never executed by an HTTP request. */
require_once __DIR__.'/legacy_schema.php';
require_once __DIR__.'/legacy_source.php';
require_once __DIR__.'/legacy_component_semantics.php';
function legacy_root(): string {
    $base=getenv('SPP_LEGACY_STORAGE')?:'C:/laragon/data/spp-legacy-import';
    $root=rtrim(str_replace('\\','/',$base),'/').'/'.DB_NAME;
    $web=str_replace('\\','/',realpath(__DIR__.'/..'));
    if(!preg_match('/^[a-zA-Z]:\//',$root)||preg_match('~(^|/)\.\.?(/|$)~',$root)||str_starts_with(strtolower($root),strtolower($web))||str_starts_with(strtolower($root),'c:/laragon/www/'))throw new RuntimeException('Private storage configuration required');
    if(!is_dir($root)&&!mkdir($root,0700,true))throw new RuntimeException('Private storage unavailable');
    return $root;
}
function legacy_queue(): PDO {
    $db=new PDO('sqlite:'.legacy_root().'/queue.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=10000; PRAGMA journal_mode=WAL; CREATE TABLE IF NOT EXISTS jobs(id TEXT PRIMARY KEY,unit INTEGER NOT NULL,actor INTEGER NOT NULL,name TEXT NOT NULL,hash TEXT NOT NULL,state TEXT NOT NULL,stage TEXT NOT NULL,done INTEGER NOT NULL DEFAULT 0,total INTEGER NOT NULL DEFAULT 0,message TEXT NOT NULL DEFAULT "",created INTEGER NOT NULL,updated INTEGER NOT NULL,cancel INTEGER NOT NULL DEFAULT 0); CREATE TABLE IF NOT EXISTS rows(job TEXT NOT NULL,ordinal INTEGER NOT NULL,nis TEXT,name TEXT,class TEXT,raw TEXT NOT NULL,result TEXT NOT NULL,reason TEXT NOT NULL,PRIMARY KEY(job,ordinal));');
    if(!in_array('category',array_column($db->query('PRAGMA table_info(jobs)')->fetchAll(PDO::FETCH_ASSOC),'name'),true)){
        $db->exec('BEGIN IMMEDIATE');
        try{if(!in_array('category',array_column($db->query('PRAGMA table_info(jobs)')->fetchAll(PDO::FETCH_ASSOC),'name'),true))$db->exec("ALTER TABLE jobs ADD COLUMN category TEXT NOT NULL DEFAULT 'students'");$db->exec('COMMIT');}
        catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
    }
    return $db;
}
function legacy_job(string $id): array {
    if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Pekerjaan tidak ditemukan.');
    $s=legacy_queue()->prepare('SELECT * FROM jobs WHERE id=?');$s->execute([$id]);$job=$s->fetch(PDO::FETCH_ASSOC);
    if(!$job)throw new InvalidArgumentException('Pekerjaan tidak ditemukan.');return $job;
}
function legacy_update(string $id,array $fields):void {
    $fields['updated']=time();$sql=[];$args=[];
    foreach($fields as $key=>$value){if(!in_array($key,['state','stage','done','total','message','updated','cancel'],true))throw new LogicException('Invalid checkpoint');$sql[]=$key.'=?';$args[]=$value;}
    $args[]=$id;$s=legacy_queue()->prepare('UPDATE jobs SET '.implode(',',$sql).' WHERE id=?');$s->execute($args);
}
function legacy_public(array $job):array {
    $public=array_intersect_key($job,array_flip(['id','unit','name','hash','state','stage','done','total','message','created','updated','cancel','category']));
    $dir=legacy_root().'/'.$job['id'];
    foreach(['source_manifest.json','finance_manifest.json'] as $file)if(is_file($dir.'/'.$file)){$m=json_decode(ltrim(file_get_contents($dir.'/'.$file),"\xEF\xBB\xBF"),true);if(is_array($m))$public+=array_intersect_key($m,array_flip(['source_profile','source_profile_version','source_schema_signature','reader_version']));}
    if($job['state']==='failed'&&is_file($dir.'/source-error.json')){$e=json_decode(ltrim(file_get_contents($dir.'/source-error.json'),"\xEF\xBB\xBF"),true);if(($e['code']??'')==='source_schema_unsupported'){$public['code']=$e['code'];$public['action']=legacy_ui_problem($e['code']);}}
    return $public;
}
function legacy_actor(mysqli $db,int $actor,int $unit):void {
    $s=$db->prepare("SELECT id FROM admin WHERE id=? AND role IN ('super_admin','admin','kasir') AND is_active=1");$s->bind_param('i',$actor);$s->execute();$ok=$s->get_result()->fetch_assoc();$s->close();
    if(!$ok||!in_array($unit,[1,2,3],true))throw new RuntimeException('Akun atau unit impor tidak lagi diizinkan.');
}
function legacy_readiness(mysqli $db):array {
    $reasons=[];$root=legacy_root();
    if(!legacy_schema_ready($db))$reasons[]='Migrasi identitas Legacy belum terpasang.';
    $heart=is_file($root.'/heartbeat.json')?json_decode(file_get_contents($root.'/heartbeat.json'),true):[];
    if(!is_array($heart)||time()-($heart['time']??0)>15)$reasons[]='Worker importer belum aktif.';
    if(($heart['source_reader_version']??0)!==LEGACY_SOURCE_READER_VERSION)$reasons[]='Mulai ulang worker importer untuk memakai pembaca backup terbaru.';
    if(empty($heart['sql_ready']))$reasons[]='SQL Server importer belum tersedia.';
    if(!is_writable($root))$reasons[]='Penyimpanan privat tidak dapat ditulis.';
    if(!is_file($root.'/instance.json'))$reasons[]='Instance importer belum disiapkan.';
    if((float)disk_free_space($root)<1073741824)$reasons[]='Ruang penyimpanan kurang dari 1 GiB.';
    return ['ready'=>!$reasons,'reasons'=>$reasons,'finance_worker'=>in_array('finance',$heart['categories']??[],true),'full_worker'=>in_array('full',$heart['categories']??[],true),'operational_worker'=>($heart['operational_version']??0)===(defined('LOP_VERSION')?LOP_VERSION:1)];
}
function legacy_upload_error(string $name,int $size):?array {
    if($size>104857600)return [413,'Ukuran backup maksimal 100 MiB.'];
    if($size<1||$name===''||!mb_check_encoding($name,'UTF-8')||mb_strlen($name)>255||str_starts_with($name,'.')||$name!==basename(str_replace('\\','/',$name))||preg_match('/[\x00-\x1f\x7f<>:"|?*]/u',$name)||preg_match('/[. ]$/D',$name)
        ||!in_array(strtolower(pathinfo($name,PATHINFO_EXTENSION)),['','dat','bak'],true))return [400,'Pilih backup SQL Server .dat, .bak, atau tanpa ekstensi yang tidak kosong.'];
    return null;
}
function legacy_enqueue(string $file,string $name,int $unit,int $actor,string $category='students'):array {
    if(!in_array($category,['students','finance','full'],true))throw new InvalidArgumentException('Kategori tidak tersedia.');
    if(!is_file($file))throw new RuntimeException('Backup tidak ditemukan.');
    if($invalid=legacy_upload_error($name,(int)filesize($file)))throw new RuntimeException($invalid[1]);
    $id=bin2hex(random_bytes(16));$dir=legacy_root().'/'.$id;mkdir($dir,0700);
    $hash=hash_file('sha256',$file);if(!copy($file,$dir.'/source.dat'))throw new RuntimeException('Penyimpanan unggahan gagal.');
    if(hash_file('sha256',$dir.'/source.dat')!==$hash)throw new RuntimeException('Hash unggahan tidak cocok.');
    if($category==='full'&&file_put_contents($dir.'/migration-rules.json',json_encode(['job'=>$id,'unit'=>$unit,'source_hash'=>$hash,'komite_mapping_version'=>LCS_KOMITE_MAPPING_VERSION],JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Aturan pekerjaan migrasi belum dapat disimpan.');
    $name=mb_substr(basename(str_replace('\\','/',$name)),0,255);
    $s=legacy_queue()->prepare('INSERT INTO jobs(id,unit,actor,name,hash,state,stage,created,updated,category) VALUES(?,?,?,?,?,"queued","Periksa Backup",?,?,?)');$s->execute([$id,$unit,$actor,$name,$hash,time(),time(),$category]);
    return legacy_job($id);
}
function legacy_stage(mysqli $db,array $job,array $rows):void {
    unit_set_context($db,(int)$job['unit']);$q=legacy_queue();$counts=[];
    $diknasCounts=[];
    foreach($rows as $raw){$nis=legacy_source_identity($raw);$counts[$nis]=($counts[$nis]??0)+1;$d=(string)($raw['NO_induk_diknas']??'');$diknasCounts[$d]=($diknasCounts[$d]??0)+1;}
    $q->beginTransaction();
    try {
        $q->prepare('DELETE FROM rows WHERE job=?')->execute([$job['id']]);
        $write=$q->prepare('INSERT INTO rows(job,ordinal,nis,name,class,raw,result,reason) VALUES(?,?,?,?,?,?,?,?)');
        $find=$db->prepare('SELECT s.id,m.source_hash,m.source_row FROM siswa s LEFT JOIN legacy_student_import m ON m.student_id=s.id WHERE s.NO_INDUK=?');
        foreach($rows as $i=>$raw){
            $nis=legacy_source_identity($raw);$name=(string)($raw['NAMA']??'');$class=(string)($raw['KELAS']??'');$result='accepted';$reason='Identitas layak; belum aktif dan belum ditempatkan.';
            if($identityIssues=legacy_source_identity_issues($raw)){$result='held';$reason=implode(' ',$identityIssues);}
            elseif(strtoupper(trim($class))==='GK'){$result='non_student';$reason='GK terbukti Guru & Karyawan pada master legacy, bukan siswa.';}
            elseif(in_array(strtoupper(trim($class)),['GURU','KARYAWAN','ADMIN','TAB'],true)){$result='held';$reason='Kode sumber diduga akun non-siswa; diperlukan pemeriksaan identitas manual.';}
            elseif(!preg_match('/^[0-9]{1,10}$/D',$nis)||$name===''||trim($name)===''||mb_strlen($name)>100||preg_match('/[\x00-\x1f\x7f]/u',$name)){$result='held';$reason='NIS/nama tidak memenuhi struktur target; nilai asli dipertahankan.';}
            elseif($counts[$nis]>1){$result='held';$reason='NIS ganda dalam unit sumber.';}
            else {$find->bind_param('s',$nis);$find->execute();$old=$find->get_result()->fetch_assoc();if($old){$same=$old['source_hash']===$job['hash']&&(int)$old['source_row']===$i+1;$result=$same?'already_imported':'held';$reason=$same?'Baris sumber telah diimpor; tidak digandakan.':'Pasangan unit/NIS sudah ada; tidak ditimpa atau digabung.';}}
            $diknas=(string)($raw['NO_induk_diknas']??'');
            $d=$db->prepare('SELECT id FROM siswa WHERE NO_induk_diknas=?');$d->bind_param('s',$diknas);$d->execute();$used=(bool)$d->get_result()->fetch_assoc();$d->close();
            $raw['_spp_target_diknas']=preg_match('/^[0-9]{1,10}$/D',$diknas)&&$diknasCounts[$diknas]===1&&!$used?$diknas:null;
            if($diknas!==''&&$raw['_spp_target_diknas']===null)$reason.=' NIS Diknas tidak cocok/unik; hanya dipertahankan pada metadata sumber.';
            $write->execute([$job['id'],$i+1,$nis,$name,$class,json_encode($raw,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$result,$reason]);
            if(($i+1)%25===0){$q->commit();legacy_update($job['id'],['done'=>$i+1,'total'=>count($rows)]);if(legacy_job($job['id'])['cancel']){legacy_update($job['id'],['state'=>'cancelled','message'=>'Dibatalkan sebelum penerapan.']);$find->close();return;}$q->beginTransaction();}
        }
        $find->close();$q->commit();
    }catch(Throwable $e){$q->rollBack();throw $e;}
    legacy_update($job['id'],['state'=>legacy_job($job['id'])['cancel']?'cancelled':'ready','stage'=>'Siap Ditinjau','done'=>count($rows),'total'=>count($rows),'message'=>'Tinjau identitas dan baris yang ditahan sebelum konfirmasi.']);
}
require_once __DIR__.'/legacy_ui.php';
function legacy_preview(string $id):array {
    $q=legacy_queue();$s=$q->prepare('SELECT result,COUNT(*) AS count FROM rows WHERE job=? GROUP BY result');$s->execute([$id]);$counts=$s->fetchAll(PDO::FETCH_KEY_PAIR);
    $s=$q->prepare('SELECT ordinal,nis,name,class,result,reason FROM rows WHERE job=? ORDER BY ordinal LIMIT 100');$s->execute([$id]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$row){$row['status_label']=legacy_ui_state($row['result']);if($row['result']==='held'){$row['code']='source_identity';$row['action']=legacy_ui_problem('source_identity');}}unset($row);return ['counts'=>$counts,'rows'=>$rows,'limit'=>100];
}
function legacy_apply(mysqli $db,array $job):void {
    legacy_actor($db,(int)$job['actor'],(int)$job['unit']);unit_set_context($db,(int)$job['unit']);
    $q=legacy_queue();$s=$q->prepare('SELECT * FROM rows WHERE job=? AND result="accepted" ORDER BY ordinal');$s->execute([$job['id']]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
    $db->begin_transaction();
    try {
        // Lock authorizing account during commit; revocation cannot race application.
        $a=$db->prepare("SELECT id FROM admin WHERE id=? AND role IN ('super_admin','admin','kasir') AND is_active=1 FOR UPDATE");$actor=(int)$job['actor'];$a->bind_param('i',$actor);$a->execute();if(!$a->get_result()->fetch_assoc())throw new RuntimeException('Hak impor telah berubah.');$a->close();
        $insert=$db->prepare("INSERT INTO siswa(NO_INDUK,NAMA,KELAS,is_active,legacy_pending,NO_induk_diknas) VALUES(?,?,'LEGACY',0,1,?)");
        $meta=$db->prepare('INSERT INTO legacy_student_import(student_id,unit_id,source_hash,source_row,source_name,source_class,raw_data,imported_by) VALUES(?,?,?,?,?,?,?,?)');
        $find=$db->prepare('SELECT s.id,m.source_hash,m.source_row FROM siswa s LEFT JOIN legacy_student_import m ON m.student_id=s.id WHERE s.NO_INDUK=? FOR UPDATE');
        foreach($rows as $row){
            $nis=$row['nis'];$find->bind_param('s',$nis);$find->execute();$old=$find->get_result()->fetch_assoc();
            if($old){if($old['source_hash']===$job['hash']&&(int)$old['source_row']===(int)$row['ordinal'])continue;throw new RuntimeException('Identitas berubah setelah pratinjau; tidak ada siswa yang diterapkan.');}
            $raw=json_decode($row['raw'],true,512,JSON_THROW_ON_ERROR);$diknas=$raw['_spp_target_diknas']??null;
            $insert->bind_param('sss',$nis,$row['name'],$diknas);$insert->execute();$student=(int)$db->insert_id;$unit=(int)$job['unit'];$ord=(int)$row['ordinal'];
            $meta->bind_param('iisisssi',$student,$unit,$job['hash'],$ord,$job['name'],$row['class'],$row['raw'],$actor);$meta->execute();
        }
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
    legacy_update($job['id'],['state'=>'imported','stage'=>'Impor Legacy','done'=>count($rows),'total'=>count($rows),'message'=>'Identitas Legacy disimpan. Aktivasi dan penempatan dilakukan manual melalui Data Siswa.']);
}
