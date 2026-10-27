<?php
/** Explicit CLI migration. Never run DDL or seed accounts from an HTTP request. */
function workflow_column_exists(mysqli $db, string $table, string $column): bool {
    $s=$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $s->bind_param('ss',$table,$column);$s->execute();$exists=(bool)$s->get_result()->fetch_row()[0];$s->close();return $exists;
}
function workflow_schema_apply(mysqli $db): void {
    if(PHP_SAPI!=='cli')throw new RuntimeException('Migrasi hanya melalui CLI.');
    foreach(['siswa','bayar','master_biaya_lain','siswa_tahun_ajaran'] as $view) {
        $table=$view.'_data';
        if(!$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND TABLE_TYPE='BASE TABLE'")->num_rows)throw new RuntimeException('Skema multiunit belum lengkap.');
    }
    foreach([
        ['siswa_data','potongan_psb_nominal','DECIMAL(15,2) NOT NULL DEFAULT 0'],
        ['master_biaya_lain_data','jenis_pembayaran',"ENUM('berulang','tidak_berulang') NOT NULL DEFAULT 'berulang'"],
        ['bayar_data','riwayat_tahun_ajaran','CHAR(9) NULL'],
        ['bayar_data','riwayat_kelas_snapshot','VARCHAR(30) NULL'],
        ['siswa_tahun_ajaran_data','du_covered_by_psb','TINYINT(1) NOT NULL DEFAULT 0'],
    ] as [$table,$column,$definition])if(!workflow_column_exists($db,$table,$column))$db->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    $db->query("ALTER TABLE siswa_tahun_ajaran_data MODIFY status ENUM('aktif','pindah','lulus','dibatalkan') NOT NULL DEFAULT 'aktif'");
    if(!$db->query("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='chk_siswa_potongan_psb'")->num_rows)$db->query('ALTER TABLE siswa_data ADD CONSTRAINT chk_siswa_potongan_psb CHECK(potongan_psb_nominal>=0 AND potongan_psb_nominal<=PSB)');
    if(!$db->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bayar_data' AND INDEX_NAME='idx_bayar_history_year'")->num_rows)$db->query('ALTER TABLE bayar_data ADD KEY idx_bayar_history_year(unit_id,NO_INDUK,riwayat_tahun_ajaran,TGL_BYR,id)');
    foreach(['siswa','bayar','master_biaya_lain','siswa_tahun_ajaran'] as $view)$db->query("CREATE OR REPLACE VIEW `$view` AS SELECT * FROM `{$view}_data` WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION");
    $db->query("CREATE TABLE IF NOT EXISTS workflow_migration_log(migration_key VARCHAR(64) PRIMARY KEY,completed_at DATETIME NOT NULL,account_before JSON NOT NULL) ENGINE=InnoDB");
    workflow_history_backfill($db);
}

/** Only a creation event and one matching historical placement establish legacy context. */
function workflow_history_backfill(mysqli $db): void {
    if($db->query("SELECT 1 FROM workflow_migration_log WHERE migration_key='history_context_20261017'")->num_rows)return;
    $placements=[];foreach($db->query("SELECT p.*,y.label FROM siswa_tahun_ajaran_data p JOIN tahun_ajaran_data y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.status<>'dibatalkan'")->fetch_all(MYSQLI_ASSOC) as $p)$placements[$p['unit_id'].'|'.$p['no_induk']][]=$p;
    $events=[];foreach($db->query("SELECT unit_id,payment_id,occurred_at,after_snapshot FROM pembayaran_aktivitas_data WHERE action='created' ORDER BY occurred_at,id")->fetch_all(MYSQLI_ASSOC) as $event)$events[$event['unit_id'].'|'.$event['payment_id']]??=$event;
    $db->begin_transaction();try{
        $updated=0;$q=$db->prepare('UPDATE bayar_data SET riwayat_tahun_ajaran=?,riwayat_kelas_snapshot=?,updated_at=? WHERE id=? AND riwayat_tahun_ajaran IS NULL');
        foreach($db->query('SELECT id,unit_id,NO_INDUK,KELAS,updated_at FROM bayar_data WHERE riwayat_tahun_ajaran IS NULL ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC) as $payment){
            $event=$events[$payment['unit_id'].'|'.$payment['id']]??null;if(!$event)continue;
            $snapshot=json_decode($event['after_snapshot']??'null',true);$original=$snapshot['payment']??null;
            if(!$original||($original['NO_INDUK']??null)!==$payment['NO_INDUK'])continue;
            $candidates=array_values(array_filter($placements[$payment['unit_id'].'|'.$payment['NO_INDUK']]??[],static fn($p)=>(string)$p['kelas']===(string)($original['KELAS']??'')&&$p['created_at']<=$event['occurred_at']));
            if(count($candidates)!==1)continue;
            $p=$candidates[0];$class=$p['kelas_rombel_snapshot']?:$p['kelas'];$q->bind_param('sssi',$p['label'],$class,$payment['updated_at'],$payment['id']);$q->execute();$updated++;
        }$q->close();$result=json_encode(['mapped'=>$updated],JSON_THROW_ON_ERROR);$q=$db->prepare("INSERT INTO workflow_migration_log VALUES('history_context_20261017',NOW(),?)");$q->bind_param('s',$result);$q->execute();$q->close();$db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
}

function workflow_accounts_finalize(mysqli $db): void {
    $db->query("ALTER TABLE admin MODIFY role ENUM('super_admin','admin') NOT NULL DEFAULT 'admin', MODIFY unit_id TINYINT UNSIGNED NULL DEFAULT NULL");
    if(!workflow_column_exists($db,'admin','active_super_guard'))$db->query("ALTER TABLE admin ADD COLUMN active_super_guard TINYINT GENERATED ALWAYS AS (CASE WHEN role='super_admin' AND is_active=1 THEN 1 ELSE NULL END) STORED, ADD UNIQUE KEY uk_one_active_super(active_super_guard)");
}

/** Explicit alternative: preserve every account ID, username, password and active status. */
function workflow_accounts_preserve_existing(mysqli $db): void {
    if(PHP_SAPI!=='cli')throw new RuntimeException('CLI only');
    if($db->query("SELECT 1 FROM workflow_migration_log WHERE migration_key='two_roles_20261017'")->num_rows){workflow_accounts_finalize($db);return;}
    $db->begin_transaction();
    try{
        $before=$db->query('SELECT id,username,nama,role,unit_id,is_active FROM admin ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
        $super=array_values(array_filter($before,static fn($a)=>$a['username']==='superadmin'&&(int)$a['is_active']===1));
        if(count($super)!==1)throw new RuntimeException('Tepat satu akun superadmin aktif wajib tersedia; akun tidak dibuat ulang.');
        $id=(int)$super[0]['id'];
        $preserveTime=workflow_column_exists($db,'admin','updated_at')?',updated_at=updated_at':'';
        $db->query("UPDATE admin SET role='admin',unit_id=NULL$preserveTime WHERE id<>$id");
        $db->query("UPDATE admin SET role='super_admin',unit_id=NULL$preserveTime WHERE id=$id");
        $json=json_encode($before,JSON_THROW_ON_ERROR);$s=$db->prepare("INSERT INTO workflow_migration_log VALUES('two_roles_20261017',NOW(),?)");$s->bind_param('s',$json);$s->execute();$s->close();
        $db->query("INSERT INTO workflow_migration_log VALUES('accounts_preserved_20261019',NOW(),JSON_OBJECT('policy','preserve_existing','super_id',$id))");
        $db->commit();
    }catch(Throwable $e){$db->rollback();throw $e;}
    workflow_accounts_finalize($db);
}

/** Initial accounts only: reruns preserve IDs/passwords and never delete historical owners. */
function workflow_accounts_apply(mysqli $db, string $credentialsFile): void {
    if(PHP_SAPI!=='cli')throw new RuntimeException('CLI only');
    $parent=realpath(dirname($credentialsFile));$workspace=realpath(__DIR__.'/..');
    if(!$parent||!$workspace||str_starts_with(strtolower(str_replace('\\','/',$parent).'/'),strtolower(str_replace('\\','/',$workspace).'/'))||str_starts_with(strtolower(str_replace('\\','/',$parent).'/'),'c:/laragon/www/'))throw new RuntimeException('Kredensial wajib berada di direktori privat di luar webroot.');
    $done=(bool)$db->query("SELECT 1 FROM workflow_migration_log WHERE migration_key='two_roles_20261017'")->num_rows;
    if($done){workflow_accounts_finalize($db);return;}
    $handle=fopen($credentialsFile,'x');if(!$handle)throw new RuntimeException('Berkas kredensial harus baru.');
    $db->begin_transaction();
    try {
        $before=$db->query('SELECT id,username,nama,role,unit_id,is_active FROM admin ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC);
        $super=array_values(array_filter($before,static fn($a)=>$a['username']==='superadmin'));
        if(!$super)$super=array_values(array_filter($before,static fn($a)=>$a['role']==='super_admin'&&(int)$a['is_active']===1));
        if(count($super)!==1)throw new RuntimeException('Akun superadmin existing harus tersedia; password tidak dibuat ulang.');
        $keep=[(int)$super[0]['id']];
        fwrite($handle,"Akun SistemSPP lintas SD/SMP/SMA. Simpan aman. Password Super Admin tidak diubah.\n");
        for($i=1;$i<=4;$i++) {
            $username='administrator'.$i;$name='Administrator '.$i;
            $s=$db->prepare('SELECT id FROM admin WHERE username=?');$s->bind_param('s',$username);$s->execute();$existing=$s->get_result()->fetch_row();$s->close();
            if($existing){$id=(int)$existing[0];fwrite($handle,$username." | password existing dipertahankan\n");}
            else {$password=bin2hex(random_bytes(12));$hash=password_hash($password,PASSWORD_DEFAULT);$s=$db->prepare("INSERT INTO admin(username,password,nama,role,unit_id,is_active) VALUES(?,?,?,'admin',NULL,1)");$s->bind_param('sss',$username,$hash,$name);$s->execute();$id=(int)$db->insert_id;$s->close();fwrite($handle,$username.' | '.$password." | Administrator | SD/SMP/SMA\n");}
            $keep[]=$id;
        }
        $db->query("UPDATE admin SET is_active=0,role='admin',unit_id=NULL WHERE id NOT IN (".implode(',',$keep).')');
        $db->query("UPDATE admin SET role='admin',unit_id=NULL,is_active=1 WHERE id IN (".implode(',',array_slice($keep,1)).')');
        $db->query("UPDATE admin SET role='super_admin',unit_id=NULL,is_active=1 WHERE id=".$keep[0]);
        $json=json_encode($before,JSON_THROW_ON_ERROR);$s=$db->prepare("INSERT INTO workflow_migration_log VALUES('two_roles_20261017',NOW(),?)");$s->bind_param('s',$json);$s->execute();$s->close();
        fflush($handle);$db->commit();fclose($handle);
    } catch(Throwable $e){$db->rollback();fclose($handle);unlink($credentialsFile);throw $e;}
    workflow_accounts_finalize($db);
}
