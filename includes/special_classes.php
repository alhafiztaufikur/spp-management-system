<?php
require_once __DIR__.'/kelas.php';

function special_class_query(mysqli $db,string $sql,array $args=[]): mysqli_result|bool {
    $s=$db->prepare($sql);if($args)$s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();$r=$s->get_result();$s->close();return $r;
}
function special_classes_ready(mysqli $db): bool {
    $row=$db->query("SELECT COUNT(*) n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='master_kelas' AND COLUMN_NAME IN ('jenis','nama_custom')")->fetch_assoc();
    if((int)$row['n']!==2)return false;
    $nullable=(int)$db->query("SELECT COUNT(*) n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='master_kelas_data' AND COLUMN_NAME='tingkat' AND IS_NULLABLE='YES'")->fetch_assoc()['n'];
    $index=(int)$db->query("SELECT COUNT(*) n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='master_kelas_data' AND INDEX_NAME='uk_rombel_custom_name'")->fetch_assoc()['n'];
    return $nullable===1&&$index===2;
}
function special_class_version(array $row): string {
    return hash('sha256',json_encode(array_intersect_key($row,array_flip(['id','unit_id','jenis','nama_custom','is_active','updated_at'])),JSON_THROW_ON_ERROR));
}
function special_class_actor(mysqli $db): void {
    if(!in_array(unit_active_id(),[1,2,3],true))throw new DomainException('Pilih satu unit untuk mengelola kelas khusus.');
    promotion_actor_lock($db);
    if(!special_classes_ready($db))throw new DomainException('Kelas Khusus belum siap. Administrator perlu menjalankan migrasi CLI kelas khusus.');
}
/** Check all physical foreign keys and class-id columns, including Legacy references. */
function special_class_usage(mysqli $db,int $id): array {
    $refs=$db->query("SELECT DISTINCT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='master_kelas_data' AND REFERENCED_COLUMN_NAME='id' UNION SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '%\\_data' AND COLUMN_NAME='master_kelas_id'")->fetch_all(MYSQLI_ASSOC);
    $used=[];
    foreach($refs as $ref){$t=$ref['TABLE_NAME'];$c=$ref['COLUMN_NAME'];if(!preg_match('/^[a-zA-Z0-9_]+$/D',$t.$c))throw new LogicException('Invalid class reference');
        $hasUnit=(int)special_class_query($db,"SELECT COUNT(*) n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='unit_id'",[$t])->fetch_assoc()['n'];
        $args=[$id];$where="`$c`=?";if($hasUnit){$where.=' AND unit_id=?';$args[]=unit_active_id();}
        $n=(int)special_class_query($db,"SELECT COUNT(*) n FROM `$t` WHERE $where",$args)->fetch_assoc()['n'];if($n)$used[$t]=$n;
    }
    // A nonacademic member has no academic placement; its audit still proves prior use.
    $audit=$db->query("SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa_audit_log'")->fetch_assoc();
    if((int)$audit['n']){$n=(int)special_class_query($db,"SELECT COUNT(*) n FROM siswa_audit_log WHERE JSON_UNQUOTE(JSON_EXTRACT(before_data,'$.master_kelas_id'))=? OR JSON_UNQUOTE(JSON_EXTRACT(after_data,'$.master_kelas_id'))=?",[$id,$id])->fetch_assoc()['n'];if($n)$used['riwayat_anggota']=$n;}
    return $used;
}
function special_class_save(mysqli $db,int $id,string $name,int $active,?string $version=null): int {
    $name=trim($name);
    if($name===''||mb_strlen($name)>100||preg_match('/[\x00-\x1F\x7F]/u',$name))throw new DomainException('Nama kelas wajib diisi, maksimal 100 karakter, tanpa karakter kontrol.');
    if(in_array(mb_strtolower($name),['psb','out'],true)||preg_match('/^alumni\s*20[0-9]{2}$/iu',$name))throw new DomainException('PSB, OUT, dan kelas Alumni dikelola melalui mekanisme sistem.');
    if(!in_array($active,[0,1],true))throw new DomainException('Pilih status Aktif atau Nonaktif.');
    $db->begin_transaction();try{
        special_class_actor($db);
        if($id){$row=class_find($db,$id,false,true);if(!$row||class_kind($row)!=='custom')throw new DomainException('Kelas khusus tidak ditemukan pada unit ini.');if($version!==null&&!hash_equals(special_class_version($row),$version))throw new DomainException('Kelas telah berubah. Muat ulang sebelum menyimpan.');}
        $duplicate=special_class_query($db,'SELECT id FROM master_kelas WHERE nama_custom=? AND id<>? LIMIT 1',[$name,$id])->fetch_assoc();if($duplicate)throw new DomainException('Nama kelas khusus sudah tersedia pada unit ini.');
        if($id)special_class_query($db,'UPDATE master_kelas SET nama_custom=?,is_active=? WHERE id=?',[$name,$active,$id]);
        else{$code='C'.strtoupper(bin2hex(random_bytes(4)));special_class_query($db,"INSERT INTO master_kelas(tingkat,kode_rombel,jenis,nama_custom,is_placeholder,is_active) VALUES(NULL,?,'custom',?,0,?)",[$code,$name,$active]);$id=(int)$db->insert_id;}
        $db->commit();return $id;
    }catch(Throwable $e){$db->rollback();if($e instanceof mysqli_sql_exception&&$e->getCode()===1062)throw new DomainException('Nama kelas khusus sudah tersedia pada unit ini.');throw $e;}
}
function special_class_delete(mysqli $db,int $id,string $version): void {
    $db->begin_transaction();try{special_class_actor($db);$row=class_find($db,$id,false,true);
        if(!$row||class_kind($row)!=='custom')throw new DomainException('Kelas khusus tidak ditemukan pada unit ini.');
        if(!hash_equals(special_class_version($row),$version))throw new DomainException('Kelas telah berubah. Periksa kembali sebelum menghapus.');
        if(special_class_usage($db,$id))throw new DomainException('Kelas pernah digunakan oleh anggota, histori, atau data Legacy. Gunakan Nonaktifkan agar data tetap tersimpan.');
        special_class_query($db,'DELETE FROM master_kelas WHERE id=?',[$id]);$db->commit();
    }catch(Throwable $e){$db->rollback();if($e instanceof mysqli_sql_exception&&$e->getCode()===1451)throw new DomainException('Kelas sedang digunakan. Gunakan Nonaktifkan.');throw $e;}
}
