<?php
/** Additive, CLI-only subset of the existing custom-rombel schema; no account migration. */
function special_classes_schema_apply(mysqli $db): void {
    if(PHP_SAPI!=='cli')throw new LogicException('Pemasangan hanya melalui CLI.');
    $column=static function(string $table,string $col)use($db):?array{$s=$db->prepare('SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$s->bind_param('ss',$table,$col);$s->execute();$r=$s->get_result()->fetch_assoc();$s->close();return $r;};
    // Preflight trigger definitions before any DDL. Preserve their Legacy and unit guards.
    $triggers=[];foreach(['master_kelas_data','siswa_data'] as $t)foreach(['bi','bu'] as $suffix){$name=$t.'_'.$suffix;$sql=$db->query("SHOW CREATE TRIGGER `$name`")->fetch_assoc()['SQL Original Statement'];
        if(!str_contains($sql,'current_unit_id()'))throw new RuntimeException('Struktur pengaman unit belum dikenali.');
        if($t==='master_kelas_data'&&!str_contains($sql,"NEW.jenis IN ('custom','out')")){
            if(!str_contains($sql,'IF NEW.`tingkat`<>0'))throw new RuntimeException('Struktur pengaman kelas belum dikenali.');
            $sql=str_replace('IF NEW.`tingkat`<>0',"IF NEW.jenis IN ('custom','out') AND NEW.tingkat IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Kelas nonakademik tidak memiliki tingkat'; END IF; IF NEW.jenis IN ('reguler','psb') AND NEW.tingkat IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tingkat rombel wajib diisi'; END IF; IF NEW.`tingkat`<>0",$sql);
        }
        if($t==='siswa_data'&&!str_contains($sql,"'CUSTOM'")){
            if(!str_contains($sql,"NEW.`KELAS` NOT IN ('0','PSB')"))throw new RuntimeException('Struktur identitas kelas belum dikenali.');
            $sql=str_replace("NEW.`KELAS` NOT IN ('0','PSB')","NEW.`KELAS` NOT IN ('0','PSB','CUSTOM','OUT')",$sql);
        }
        $original=$db->query("SHOW CREATE TRIGGER `$name`")->fetch_assoc()['SQL Original Statement'];if($sql!==$original)$triggers[$name]=$sql;
    }
    if(!$column('master_kelas_data','jenis'))$db->query("ALTER TABLE master_kelas_data ADD jenis ENUM('reguler','psb','out','custom') NOT NULL DEFAULT 'reguler'");
    if(!$column('master_kelas_data','nama_custom'))$db->query('ALTER TABLE master_kelas_data ADD nama_custom VARCHAR(100) NULL');
    if($column('master_kelas_data','tingkat')['IS_NULLABLE']!=='YES')$db->query('ALTER TABLE master_kelas_data MODIFY tingkat TINYINT UNSIGNED NULL');
    foreach($triggers as $name=>$sql){$db->query("DROP TRIGGER `$name`");$db->query($sql);}
    $context=(int)$db->query('SELECT current_unit_id()')->fetch_row()[0];
    try{for($unit=1;$unit<=3;$unit++){$db->query('SET @app_unit_id='.$unit);$db->query("UPDATE master_kelas_data SET jenis='psb',updated_at=updated_at WHERE unit_id=$unit AND tingkat=0 AND kode_rombel='PSB' AND jenis='reguler'");}}
    finally{$db->query('SET @app_unit_id='.$context);}
    if(!$db->query("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='master_kelas_data' AND INDEX_NAME='uk_rombel_custom_name'")->num_rows)$db->query('ALTER TABLE master_kelas_data ADD UNIQUE KEY uk_rombel_custom_name(unit_id,nama_custom)');
    $views=['master_kelas'];
    $cols=$db->query("SELECT TABLE_NAME,COLUMN_NAME,CHARACTER_MAXIMUM_LENGTH,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '%\\_data' AND COLUMN_NAME IN ('kelas_rombel_snapshot','riwayat_kelas_snapshot')")->fetch_all(MYSQLI_ASSOC);
    foreach($cols as $c)if((int)$c['CHARACTER_MAXIMUM_LENGTH']<100){$t=$c['TABLE_NAME'];$n=$c['COLUMN_NAME'];if(!preg_match('/^[a-zA-Z0-9_]+$/D',$t.$n))throw new LogicException('Invalid schema column');$default=$c['COLUMN_DEFAULT']!==null?" DEFAULT '".$db->real_escape_string($c['COLUMN_DEFAULT'])."'":($c['IS_NULLABLE']==='YES'?' DEFAULT NULL':'');$db->query("ALTER TABLE `$t` MODIFY `$n` VARCHAR(100) ".($c['IS_NULLABLE']==='YES'?'NULL':'NOT NULL').$default);$views[]=substr($t,0,-5);}
    foreach(array_unique($views) as $v)$db->query("CREATE OR REPLACE VIEW `$v` AS SELECT * FROM `{$v}_data` WHERE current_unit_id()=0 OR unit_id=current_unit_id() WITH CASCADED CHECK OPTION");
}
