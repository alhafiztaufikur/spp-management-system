<?php
/** Destructive account cases run only on a named disposable audit clone. */
if (PHP_SAPI !== 'cli' || getenv('SPP_TEST_ALLOW_MUTATION') !== '1'
    || !preg_match('/^db_spp_audit_[a-z0-9_]+$/D', (string)getenv('SPP_DB_NAME'))) exit(1);
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/account_lifecycle.php';
unit_set_context($koneksi, 0);
function ad_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
if (($argv[1] ?? '') === '--race-writer') {
    unit_set_context($koneksi, 1);
    $id = (int)$argv[2]; $key = $argv[3];
    $lock = account_lifecycle_lock($koneksi, $id);
    echo "locked\n"; flush();
    usleep(600000);
    $stmt = $koneksi->prepare("INSERT INTO keuangan_request(request_key,unit_id,aksi,operator_id) VALUES(?,1,'pembayaran',?)");
    $stmt->bind_param('si', $key, $id); $stmt->execute(); $stmt->close();
    account_lifecycle_unlock($koneksi, $lock); exit;
}
if (($argv[1] ?? '') === '--race-session') {
    $_SESSION=['admin_id'=>(int)$argv[2],'admin_role'=>'kasir','active_unit_id'=>1];
    $_SERVER['REQUEST_METHOD']='POST';echo "waiting\n";flush();
    unit_bootstrap_context($koneksi);
    ad_check(empty($_SESSION['admin_id']),'Removed account may still write');echo "denied\n";exit;
}
$current = (int)$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 ORDER BY id LIMIT 1")->fetch_row()[0];
$prefix = 'qa.delete.'.bin2hex(random_bytes(8)); $ids = []; $keys = []; $checks = 0;
function ad_create(int $unit=1, string $role='kasir'): array {
    global $koneksi, $prefix, $ids;
    $username=$prefix.'.'.count($ids);$name='Akun Hapus QA '.bin2hex(random_bytes(6));$hash=password_hash(bin2hex(random_bytes(12)),PASSWORD_DEFAULT);
    $stmt=$koneksi->prepare('INSERT INTO admin(username,nama,password,role,unit_id) VALUES(?,?,?,?,?)');
    $storedUnit=$role==='super_admin'?null:$unit;$stmt->bind_param('ssssi',$username,$name,$hash,$role,$storedUnit);$stmt->execute();$id=(int)$stmt->insert_id;$stmt->close();$ids[]=$id;
    return ['id'=>$id,'username'=>$username,'nama'=>$name];
}
function ad_reject(int $id,int $caller,int $scope,string $reason): void {
    global $koneksi,$checks;
    try { account_delete_unused($koneksi,$id,$caller,$scope);throw new LogicException('Deletion was unexpectedly accepted'); }
    catch(RuntimeException $error){ad_check(str_contains($error->getMessage(),$reason),$error->getMessage());$checks++;}
}
try {
    $account=ad_create();$id=$account['id'];
    ad_check(!isset(account_history_usage($koneksi,[$id])[$id]),'Unused account marked as used');$checks++;
    $columns=$koneksi->query("SELECT c.TABLE_NAME,c.COLUMN_NAME,c.DATA_TYPE FROM information_schema.COLUMNS c JOIN information_schema.TABLES t USING(TABLE_SCHEMA,TABLE_NAME)
        WHERE c.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE' AND c.TABLE_NAME<>'admin'
        AND c.COLUMN_NAME IN('user_id','operator_id','actor_id','admin_id','requested_by','decided_by','created_by','actor_name','actor_username','admin_name')
        ORDER BY c.TABLE_NAME,c.COLUMN_NAME")->fetch_all(MYSQLI_ASSOC);
    foreach($columns as $field){
        $table=$field['TABLE_NAME'];$column=$field['COLUMN_NAME'];
        $first=$koneksi->query("SELECT * FROM `$table` LIMIT 1")->fetch_assoc();
        if(!$first)continue;
        unit_set_context($koneksi,(int)$first['unit_id']);
        $where=isset($first['id'])?'id='.(int)$first['id']:"request_key='".$koneksi->real_escape_string($first['request_key'])."'";
        $where.=' AND unit_id='.(int)$first['unit_id'];
        $numeric=in_array($field['DATA_TYPE'],['int','bigint','tinyint','smallint','mediumint'],true);
        $values=$numeric?[$id]:($column==='actor_username'?[$account['username']]:(in_array($column,['admin_name','actor_name'],true)?[$account['nama']]:[(string)$id,$account['username'],$account['nama']]));
        foreach($values as $value){
            $koneksi->begin_transaction();
            try {
                if ($table === 'pembayaran_aktivitas_data') {
                    // Payment activity is append-only, including on the audit clone.
                    $fields=array_column($koneksi->query("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC),'Field');
                    $fields=array_values(array_diff($fields,['id']));
                    $select=array_map(static fn($name)=>$name===$column?'?':($name==='event_key'?"'qa-delete-".bin2hex(random_bytes(12))."'":"`$name`"),$fields);
                    $stmt=$koneksi->prepare("INSERT INTO `$table` (`".implode('`,`',$fields)."`) SELECT ".implode(',',$select)." FROM `$table` WHERE $where LIMIT 1");
                } else $stmt=$koneksi->prepare("UPDATE `$table` SET `$column`=? WHERE $where LIMIT 1");
                $stmt->bind_param('s',$value);$stmt->execute();$stmt->close();
                ad_check(isset(account_history_usage($koneksi,[$id])[$id]),'Missed history '.$table.'.'.$column);$checks++;
            }finally{$koneksi->rollback();}
        }
    }
    $activity=$koneksi->query('SELECT * FROM pembayaran_aktivitas_data LIMIT 1')->fetch_assoc();
    ad_check((bool)$activity,'Archived payment fixture missing');
    $activityFields=array_values(array_diff(array_keys($activity),['id']));
    foreach([
        [['payment'=>['user_id'=>(string)$id]],true],
        [['details'=>['spp_alokasi_batch'=>[['user_id'=>$account['username']]]]],true],
        [['payment'=>['user_id'=>mb_strtoupper($account['nama'])]],true],
        [['actor_id'=>$id],true],
        [['payment'=>['NAMA'=>$account['nama'],'total_jumlah'=>$id]],false],
    ] as [$snapshot,$expected]){
        unit_set_context($koneksi,(int)$activity['unit_id']);$koneksi->begin_transaction();
        try{
            $json=json_encode($snapshot,JSON_THROW_ON_ERROR);
            $select=array_map(static fn($name)=>$name==='before_snapshot'?'?':($name==='event_key'?"'qa-delete-json-".bin2hex(random_bytes(10))."'":"`$name`"),$activityFields);
            $stmt=$koneksi->prepare('INSERT INTO pembayaran_aktivitas_data (`'.implode('`,`',$activityFields).'`) SELECT '.implode(',',$select).' FROM pembayaran_aktivitas_data WHERE id='.(int)$activity['id']);
            $stmt->bind_param('s',$json);$stmt->execute();$stmt->close();
            ad_check(isset(account_history_usage($koneksi,[$id])[$id])===$expected,'Nested snapshot identity mismatch');$checks++;
        }finally{$koneksi->rollback();}
    }
    // Same legacy name is deliberately conservative, including records in other units.
    $key=bin2hex(random_bytes(16));$keys[]=$key;
    unit_set_context($koneksi,3);
    $stmt=$koneksi->prepare("INSERT INTO keuangan_request(request_key,unit_id,aksi,operator_id) VALUES(?,3,'pembayaran',?)");$stmt->bind_param('si',$key,$id);$stmt->execute();$stmt->close();
    ad_reject($id,$current,1,'riwayat');
    $koneksi->query("DELETE FROM keuangan_request WHERE request_key='$key'");
    ad_reject($id,$id,1,'sedang dipakai');ad_reject($id,$current,2,'luar unit');ad_reject(0,$current,1,'tidak valid');
    account_delete_unused($koneksi,$id,$current,1);$checks++;
    ad_reject($id,$current,1,'tidak ditemukan');
    $inactive=ad_create();$koneksi->query('UPDATE admin SET is_active=0 WHERE id='.$inactive['id']);account_delete_unused($koneksi,$inactive['id'],$current,0);$checks++;
    // Last-Super-Admin guard: restore every original state even when the assertion fails.
    $super=ad_create(0,'super_admin');$original=$koneksi->query("SELECT id,is_active FROM admin WHERE role='super_admin'")->fetch_all(MYSQLI_ASSOC);
    try {
        $koneksi->query("UPDATE admin SET is_active=0 WHERE role='super_admin' AND id<>".$super['id']);
        ad_reject($super['id'],$current,0,'terakhir');
    }finally{foreach($original as $state)$koneksi->query('UPDATE admin SET is_active='.(int)$state['is_active'].' WHERE id='.(int)$state['id']);}
    $race=ad_create();$key=bin2hex(random_bytes(16));$keys[]=$key;
    $proc=proc_open([PHP_BINARY,__FILE__,'--race-writer',(string)$race['id'],$key],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    ad_check(is_resource($proc),'Could not start concurrent writer');fclose($pipes[0]);
    ad_check(trim((string)fgets($pipes[1]))==='locked','Concurrent writer did not lock');
    try{account_lifecycle_lock($koneksi,$race['id'],0);throw new LogicException('Concurrent lock unexpectedly acquired');}
    catch(AccountRemovalException $error){$checks++;}
    ad_reject($race['id'],$current,1,'riwayat');
    $errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);ad_check(proc_close($proc)===0,'Concurrent writer failed '.$errors);
    ad_check((bool)$koneksi->query('SELECT id FROM admin WHERE id='.$race['id'])->num_rows,'Concurrent history lost its account');$checks++;
    $late=ad_create();$lock=account_lifecycle_lock($koneksi,$late['id']);
    $proc=proc_open([PHP_BINARY,__FILE__,'--race-session',(string)$late['id']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);
    ad_check(trim((string)fgets($pipes[1]))==='waiting','Late session did not start');
    account_delete_unused($koneksi,$late['id'],$current,1);account_lifecycle_unlock($koneksi,$lock);
    ad_check(trim((string)fgets($pipes[1]))==='denied','Late authenticated write was accepted');
    $errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);ad_check(proc_close($proc)===0,'Late session failed '.$errors);$checks++;
    echo "PASS: $checks account history/removal checks; all physical references, legacy identities, unit isolation, last Super Admin and concurrent writes\n";
}finally{
    foreach($keys as $key)$koneksi->query("DELETE FROM keuangan_request WHERE request_key='$key'");
    if($ids)$koneksi->query('DELETE FROM admin WHERE id IN('.implode(',',$ids).')');
}
