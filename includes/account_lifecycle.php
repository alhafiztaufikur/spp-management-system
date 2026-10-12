<?php
/** Account removal must preserve every historical identity, including legacy names. */
class AccountRemovalException extends RuntimeException {}
function account_lifecycle_lock(mysqli $db, int $accountId, int $timeout = 5): string {
    $database = (string)$db->query('SELECT DATABASE()')->fetch_row()[0];
    $name = 'spp-account:'.substr(hash('sha256', $database), 0, 16).':'.$accountId;
    $stmt = $db->prepare('SELECT GET_LOCK(?,?)');
    $stmt->bind_param('si', $name, $timeout);
    $stmt->execute(); $locked = $stmt->get_result()->fetch_row()[0]; $stmt->close();
    if ((int)$locked !== 1) throw new AccountRemovalException('Akun sedang digunakan. Silakan coba kembali setelah proses selesai.');
    return $name;
}

function account_lifecycle_unlock(mysqli $db, string $name): void {
    $stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->bind_param('s', $name); $stmt->execute(); $stmt->close();
}

/** One batch for the visible accounts; physical tables deliberately bypass unit views. */
function account_history_usage(mysqli $db, array $accountIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $accountIds), static fn($id) => $id > 0)));
    if (!$ids) return [];
    $columns = $db->query("SELECT c.TABLE_NAME,c.COLUMN_NAME,c.DATA_TYPE
        FROM information_schema.COLUMNS c JOIN information_schema.TABLES t
          ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME
        WHERE c.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE='BASE TABLE' AND c.TABLE_NAME<>'admin'
          AND (c.COLUMN_NAME IN ('user_id','operator_id','actor_id','admin_id','requested_by','decided_by','created_by','actor_name','actor_username','admin_name','before_snapshot','after_snapshot','before_data','after_data')
            OR EXISTS(SELECT 1 FROM information_schema.KEY_COLUMN_USAGE k
              WHERE k.CONSTRAINT_SCHEMA=c.TABLE_SCHEMA AND k.TABLE_NAME=c.TABLE_NAME
                AND k.COLUMN_NAME=c.COLUMN_NAME AND k.REFERENCED_TABLE_NAME='admin'))");
    $tables = [];
    foreach ($columns as $column) $tables[$column['TABLE_NAME']][] = $column;
    // Fail closed if a partial installation cannot inspect the financial journals.
    foreach (['bayar_data','transaksi_m_data','transaksi_k_data','keuangan_request','pembayaran_aktivitas_data','transaksi_otorisasi_data'] as $required) {
        if (!isset($tables[$required])) throw new AccountRemovalException('Pemeriksaan riwayat akun belum lengkap. Penghapusan tidak tersedia.');
    }
    $queries = []; $snapshots = []; $idSql = implode(',', $ids);
    foreach ($tables as $table => $fields) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new AccountRemovalException('Sumber riwayat akun tidak valid.');
        $conditions = [];
        foreach ($fields as $field) {
            $column = $field['COLUMN_NAME'];
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $column)) throw new AccountRemovalException('Identitas riwayat akun tidak valid.');
            if(in_array($column,['before_snapshot','after_snapshot','before_data','after_data'],true)){
                $snapshots[$table][]=$column;
                continue;
            }
            $ref = 'r.`'.$column.'`';
            if (in_array($field['DATA_TYPE'], ['tinyint','smallint','mediumint','int','bigint'], true)) {
                $conditions[] = "$ref=a.id";
            } else {
                // Older imports and ASCII usernames do not always share a collation.
                $ref = "CONVERT($ref USING utf8mb4) COLLATE utf8mb4_general_ci";
                $username = 'CONVERT(a.username USING utf8mb4) COLLATE utf8mb4_general_ci';
                $name = 'CONVERT(a.nama USING utf8mb4) COLLATE utf8mb4_general_ci';
                if ($column === 'actor_username') $conditions[] = "$ref=$username";
                elseif (in_array($column, ['actor_name','admin_name'], true)) $conditions[] = "$ref=$name";
                else $conditions[] = "$ref IN (CAST(a.id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci,$username,$name)";
            }
        }
        if($conditions)$queries[] = "SELECT DISTINCT a.id,'$table' source FROM admin a JOIN `$table` r ON (".implode(' OR ', $conditions).") WHERE a.id IN ($idSql)";
    }
    $usage = [];
    foreach ($db->query(implode(' UNION ALL ', $queries)) as $row) {
        $source = $row['source'];
        $label = str_contains($source, 'audit') ? 'audit'
            : (str_contains($source, 'otorisasi') ? 'pengajuan'
            : (str_starts_with($source, 'transaksi_') ? 'tabungan'
            : (str_contains($source, 'tagihan') ? 'tagihan' : 'pembayaran')));
        $usage[(int)$row['id']][$label] = true;
    }
    // Deleted/old payments may retain the only creator evidence inside a snapshot.
    // Inspect actor keys recursively, never student names or arbitrary numeric values.
    $remaining=array_values(array_diff($ids,array_keys($usage)));
    if($remaining){
        $actors=$db->query('SELECT id,username,nama FROM admin WHERE id IN ('.implode(',',$remaining).')')->fetch_all(MYSQLI_ASSOC);
        $index=[];
        foreach($actors as $actor){
            foreach(['id'=>(string)$actor['id'],'username'=>$actor['username'],'name'=>$actor['nama']] as $kind=>$value)$index[$kind][mb_strtolower($value,'UTF-8')][]=(int)$actor['id'];
        }
        $walk=static function(array $data) use (&$walk,&$usage,$index):void{
            foreach($data as $key=>$value){
                if(is_array($value)){$walk($value);continue;}
                if(!is_string($value)&&!is_int($value))continue;
                $kinds=match((string)$key){
                    'actor_username'=>['username'], 'actor_name','admin_name'=>['name'],
                    'actor_id','operator_id','admin_id','requested_by','decided_by'=>['id'],
                    'user_id','created_by'=>['id','username','name'],default=>[],
                };
                foreach($kinds as $kind)foreach($index[$kind][mb_strtolower(trim((string)$value),'UTF-8')]??[] as $id)$usage[$id]['snapshot transaksi/audit']=true;
            }
        };
        foreach($snapshots as $table=>$fields){
            $result=$db->query('SELECT `'.implode('`,`',$fields).'` FROM `'.$table.'` WHERE '.implode(' OR ',array_map(static fn($field)=>"(`$field` IS NOT NULL AND `$field`<>'')",$fields)));
            foreach($result as $row)foreach($row as $json){
                if($json===null||$json==='')continue;
                try{$data=json_decode($json,true,512,JSON_THROW_ON_ERROR);}catch(JsonException $error){throw new AccountRemovalException('Snapshot riwayat akun tidak dapat diperiksa. Penghapusan tidak tersedia.');}
                if(is_array($data))$walk($data);
            }
        }
    }
    return array_map('array_keys', $usage);
}

function account_delete_unused(mysqli $db, int $accountId, int $currentId, int $scope): array {
    if ($accountId <= 0) throw new AccountRemovalException('Akun yang dipilih tidak valid.');
    if ($accountId === $currentId) throw new AccountRemovalException('Anda tidak dapat menghapus akun yang sedang dipakai.');
    $lock = account_lifecycle_lock($db, $accountId);
    try {
        $db->begin_transaction();
        $stmt = $db->prepare('SELECT id,nama,username,role,unit_id,is_active FROM admin WHERE id=? FOR UPDATE');
        $stmt->bind_param('i', $accountId); $stmt->execute(); $account = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$account) throw new AccountRemovalException('Akun tidak ditemukan atau sudah dihapus.');
        if ($scope !== 0 && (int)$account['unit_id'] !== $scope) throw new AccountRemovalException('Akun ini berada di luar unit yang dipilih.');
        if ($account['role'] === 'super_admin' && (int)$account['is_active'] === 1) {
            $active = $db->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 FOR UPDATE");
            if ($active->num_rows <= 1) throw new AccountRemovalException('Super Admin aktif terakhir tidak dapat dihapus.');
        }
        $usage = account_history_usage($db, [$accountId])[$accountId] ?? [];
        if ($usage) throw new AccountRemovalException('Akun memiliki riwayat '.implode(', ', $usage).'. Gunakan Nonaktifkan.');
        $stmt = $db->prepare('DELETE FROM admin WHERE id=?');
        $stmt->bind_param('i', $accountId); $stmt->execute(); $stmt->close();
        $db->commit();
        return $account;
    } catch (Throwable $error) {
        $db->rollback(); throw $error;
    } finally {
        account_lifecycle_unlock($db, $lock);
    }
}
