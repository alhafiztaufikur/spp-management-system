<?php
/** Reconcile and reverify a restored/blocked cutover without reimporting source data. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/legacy_full.php';
$id='';$actor=0;foreach($argv as $arg){if(str_starts_with($arg,'--batch='))$id=substr($arg,8);if(str_starts_with($arg,'--actor='))$actor=filter_var(substr($arg,8),FILTER_VALIDATE_INT)?:0;}
if(!preg_match('/^[a-f0-9]{32}$/D',$id)||$actor<=0){fwrite(STDERR,"Gunakan --batch=<ID batch> --actor=<ID Super Admin aktif>; tambah --verify --confirm-recovery untuk verifikasi dan backup ulang.\n");exit(1);}
lful_require($koneksi);lf_actor($koneksi,$actor,0);$b=lf_one($koneksi,'SELECT b.*,s.backup_hash,s.source_name FROM legacy_full_batches b JOIN legacy_finance_sources s ON s.id=b.source_id AND s.unit_id=b.unit_id WHERE b.id=?',[$id]);if(!$b)throw new RuntimeException('Batch tidak ditemukan.');lf_actor($koneksi,$actor,(int)$b['unit_id']);
if(!in_array('--verify',$argv,true)){echo 'Status migrasi: '.$b['state'].". Tidak ada perubahan.\n";exit;}
if(!in_array('--confirm-recovery',$argv,true))throw new RuntimeException('Periksa server, backup, akun, dan batch; --confirm-recovery diperlukan.');
if($b['state']==='completed'){lful_check_result($koneksi,$id);echo "Hasil migrasi konsisten; tidak ada impor ulang.\n";exit;}
$manifest=json_decode($b['manifest'],true,512,JSON_THROW_ON_ERROR);$settings=$manifest['mapping']??null;if(!is_array($settings))throw new RuntimeException('Manifest pemetaan tidak lengkap; pulihkan penyimpanan privat importer dan tinjau secara administratif.');
$q=lful_store();$q->exec('BEGIN IMMEDIATE');try{
    $s=$q->prepare('SELECT * FROM jobs WHERE id=?');$s->execute([$id]);$job=$s->fetch(PDO::FETCH_ASSOC);
    if($job&&((int)$job['unit']!==(int)$b['unit_id']||$job['hash']!==$b['backup_hash']||$job['category']!=='full'))throw new RuntimeException('Antrean privat tidak cocok dengan batch database.');
    if(!$job)$q->prepare("INSERT INTO jobs(id,unit,actor,name,hash,state,stage,created,updated,category) VALUES(?,?,?,?,?,'apply_queued','Verifikasi Pemulihan',?,?,'full')")->execute([$id,$b['unit_id'],$actor,$b['source_name'],$b['backup_hash'],time(),time()]);
    $q->prepare('INSERT OR IGNORE INTO full_drafts(job,version,settings) VALUES(?,?,?)')->execute([$id,$b['version'],lf_json($settings)]);$q->exec('COMMIT');
}catch(Throwable $e){$q->exec('ROLLBACK');throw $e;}
lful_run($koneksi,['id'=>$id,'unit'=>(int)$b['unit_id'],'actor'=>$actor]);echo "Hasil dan pemulihan backup terverifikasi; unit dibuka tanpa impor ulang.\n";
