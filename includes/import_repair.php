<?php
/** Additive import repair. HTTP never invokes this module or performs DDL. */
function ir_identifier(string $name): string {
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name)) throw new RuntimeException('Invalid schema identifier.');
    return '`'.$name.'`';
}
function ir_contract(): array {
    $contract = json_decode(file_get_contents(__DIR__.'/../sql/import_repair_contract.json'), true, 64, JSON_THROW_ON_ERROR);
    if (($contract['version'] ?? 0) !== 1 || count($contract['checks']) < 40 || count($contract['foreign_keys']) < 100) throw new RuntimeException('Incomplete repair contract.');
    return $contract;
}
function ir_foreign_keys(mysqli $db): array {
    $rows = $db->query("SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
        ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
        WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC);
    $result = [];
    foreach ($rows as $r) {
        $key = $r['TABLE_NAME'].'.'.$r['CONSTRAINT_NAME'];
        $result[$key] ??= ['table_name'=>$r['TABLE_NAME'], 'name'=>$r['CONSTRAINT_NAME'], 'columns'=>[], 'parent'=>$r['REFERENCED_TABLE_NAME'], 'parent_columns'=>[], 'delete'=>$r['DELETE_RULE'], 'update'=>$r['UPDATE_RULE']];
        $result[$key]['columns'][] = $r['COLUMN_NAME']; $result[$key]['parent_columns'][] = $r['REFERENCED_COLUMN_NAME'];
    }
    return array_values($result);
}
function ir_fk_signature(array $fk): string {
    $rule = static fn($v) => $v === 'NO ACTION' ? 'RESTRICT' : $v;
    return json_encode([$fk['table_name'],$fk['columns'],$fk['parent'],$fk['parent_columns'],$rule($fk['delete']),$rule($fk['update'])], JSON_THROW_ON_ERROR);
}
function ir_checks(mysqli $db): array {
    $rows = $db->query("SELECT t.TABLE_NAME table_name,t.CONSTRAINT_NAME name,c.CHECK_CLAUSE clause,t.ENFORCED enforced
        FROM information_schema.TABLE_CONSTRAINTS t JOIN information_schema.CHECK_CONSTRAINTS c
        ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME
        WHERE t.TABLE_SCHEMA=DATABASE() AND t.CONSTRAINT_TYPE='CHECK'")->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$r) $r['clause'] = stripslashes($r['clause']);
    return $rows;
}
function ir_check_signature(string $clause): string {
    return preg_replace('/[`\s]/', '', str_replace('_utf8mb4', '', strtolower($clause)));
}
/** Validate every expected relation, including existing constraints, before changing any schema. */
function ir_plan(mysqli $db): array {
    $contract = ir_contract(); $missing = []; $violations = []; $mismatches = [];
    $tables=array_flip(array_column($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetch_all(MYSQLI_ASSOC),'TABLE_NAME'));
    $savingsTables=['tabungan_otorisasi_data','tabungan_aktivitas_data'];
    $savingsReady=count(array_intersect(array_keys($tables),$savingsTables))===2
        && (int)$db->query("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('tabungan_otorisasi','tabungan_aktivitas')")->fetch_row()[0]===2
        && (int)$db->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ('tabungan_otorisasi_data_bi','tabungan_otorisasi_data_bu','tabungan_otorisasi_data_bd','tabungan_aktivitas_data_bi','tabungan_aktivitas_data_bu','tabungan_aktivitas_data_bd')")->fetch_row()[0]===6;
    $actual = ir_foreign_keys($db); $signatures = array_flip(array_map('ir_fk_signature', $actual));
    $names = []; foreach ($actual as $fk) $names[$fk['table_name'].'.'.$fk['name']] = $fk;
    foreach ($contract['foreign_keys'] as $fk) {
        if((!isset($tables[$fk['table_name']])&&in_array($fk['table_name'],$savingsTables,true))||(!isset($tables[$fk['parent']])&&in_array($fk['parent'],$savingsTables,true)))continue;
        $signature = ir_fk_signature($fk);
        if (!isset($signatures[$signature])) {
            if (isset($names[$fk['table_name'].'.'.$fk['name']])) $mismatches[] = $fk['table_name'].'.'.$fk['name'];
            else { $missing[] = ['kind'=>'foreign_key'] + $fk; $signatures[$signature] = true; }
        }
        $nonnull = []; $join = [];
        foreach ($fk['columns'] as $n => $column) {
            $nonnull[] = 'c.'.ir_identifier($column).' IS NOT NULL';
            $join[] = 'p.'.ir_identifier($fk['parent_columns'][$n]).'=c.'.ir_identifier($column);
        }
        $sql = 'SELECT COUNT(*) FROM '.ir_identifier($fk['table_name']).' c WHERE '.implode(' AND ', $nonnull)
            .' AND NOT EXISTS (SELECT 1 FROM '.ir_identifier($fk['parent']).' p WHERE '.implode(' AND ', $join).')';
        if ($count = (int)$db->query($sql)->fetch_row()[0]) $violations[] = ['constraint'=>$fk['table_name'].'.'.$fk['name'], 'rows'=>$count];
    }
    $checks = []; foreach (ir_checks($db) as $check) $checks[$check['table_name'].'.'.$check['name']] = $check;
    foreach ($contract['checks'] as $check) {
        $key = $check['table_name'].'.'.$check['name'];
        if (!isset($checks[$key])) $missing[] = ['kind'=>'check'] + $check;
        elseif ($checks[$key]['enforced'] !== 'YES' || ir_check_signature($checks[$key]['clause']) !== ir_check_signature($check['clause'])) $mismatches[] = $key;
        if ($count = (int)$db->query('SELECT COUNT(*) FROM '.ir_identifier($check['table_name']).' WHERE NOT ('.$check['clause'].')')->fetch_row()[0]) $violations[] = ['constraint'=>$key, 'rows'=>$count];
    }
    return ['missing'=>$missing, 'violations'=>$violations, 'mismatches'=>$mismatches,'missing_modules'=>$savingsReady?[]:['savings_authorization']];
}
function ir_apply_schema(mysqli $db, array $plan, callable $progress): void {
    if (PHP_SAPI !== 'cli' || $plan['violations'] || $plan['mismatches']) throw new RuntimeException('Repair preflight failed; no DDL permitted.');
    $tables = [];
    foreach ($plan['missing'] as $item) {
        $definition = 'ADD CONSTRAINT '.ir_identifier($item['name']);
        if ($item['kind'] === 'check') $definition .= ' CHECK ('.$item['clause'].')';
        else {
            foreach (['delete','update'] as $rule) if (!in_array($item[$rule], ['RESTRICT','NO ACTION','CASCADE','SET NULL'], true)) throw new RuntimeException('Invalid foreign key rule.');
            $definition .= ' FOREIGN KEY ('.implode(',', array_map('ir_identifier', $item['columns'])).') REFERENCES '
                .ir_identifier($item['parent']).' ('.implode(',', array_map('ir_identifier', $item['parent_columns'])).') ON DELETE '.$item['delete'].' ON UPDATE '.$item['update'];
        }
        $tables[$item['table_name']][] = $definition;
    }
    foreach ($tables as $table => $definitions) {
        $start = microtime(true);
        $db->query('ALTER TABLE '.ir_identifier($table).' '.implode(', ', $definitions));
        $progress(['table'=>$table,'constraints'=>count($definitions),'ms'=>round((microtime(true)-$start)*1000)]);
    }
}
/** Check the immutable raw payload and the original occurrence-aware row key, not only its stored key list. */
function ir_verify_archive(mysqli $db, int $unit, array $source): void {
    $rows = $db->query('SELECT source_table,row_hash,row_key,occurrence,raw_data FROM legacy_finance_rows_data WHERE unit_id='.$unit.' AND source_id='.(int)$source['id'], MYSQLI_USE_RESULT);
    try {
        while ($row = $rows->fetch_assoc()) {
            $raw = json_decode($row['raw_data'], true, 512, JSON_THROW_ON_ERROR);
            $hash = hash('sha256',lf_canonical($raw));
            $key = hash('sha256',implode('|',[$unit,strtolower($source['backup_hash']),$row['source_table'],$hash,$row['occurrence']]));
            if (!hash_equals($hash,$row['row_hash']) || !hash_equals($key,$row['row_key'])) throw new RuntimeException('Immutable archive payload differs from its original hash.');
        }
    } finally { $rows->free(); }
}
/** Only the known empty digest defect is repairable automatically; every other mismatch is rejected. */
function ir_repair_manifests(mysqli $db): array {
    require_once __DIR__.'/units.php';
    require_once __DIR__.'/legacy_operational.php';
    if (!lop_ready($db, true)) throw new RuntimeException('Legacy schema is not ready.');
    unit_set_context($db, 0);
    $batches = lf_rows($db, "SELECT id,unit_id FROM legacy_full_batches WHERE state='completed' ORDER BY unit_id");
    $result = [];
    $db->begin_transaction();
    try {
        foreach ($batches as $batch) {
            unit_set_context($db, (int)$batch['unit_id']);
            $current = lf_one($db, 'SELECT * FROM legacy_full_batches WHERE id=? FOR UPDATE', [$batch['id']]);
            $manifest = json_decode($current['manifest'], true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['mode'] ?? '') !== 'operational') continue;
            $digest = lop_working_digest($db, $batch['id']);
            $before = $manifest['working_digest'] ?? '';
            if (!hash_equals($before, $digest)) {
                if ($before !== hash('sha256','')) throw new RuntimeException('Unrecognized working digest mismatch: '.$batch['id']);
                $source = lf_source_fingerprint($db, (int)$batch['unit_id'], (int)$current['source_id']);
                $record = lf_one($db, 'SELECT id,backup_hash,fingerprint,row_count FROM legacy_finance_sources WHERE id=?', [$current['source_id']]);
                if ($source['count'] !== (int)$record['row_count'] || !hash_equals($source['fingerprint'], $record['fingerprint']) || !hash_equals($source['fingerprint'], $manifest['source_fingerprint'])) throw new RuntimeException('Source archive differs.');
                ir_verify_archive($db,(int)$batch['unit_id'],$record);
                $manifest['working_digest'] = $digest;
                lf_query($db, 'UPDATE legacy_full_batches SET manifest=? WHERE id=?', [lf_json($manifest),$batch['id']]);
                // Full payment, ledger and obligation checks run inside this transaction;
                // any failure rolls back ALL repaired manifests and their audit entries.
                lop_check_result($db, $batch['id']);
                $actor = lf_one($db, "SELECT id FROM admin WHERE role='super_admin' AND is_active=1 ORDER BY id LIMIT 1");
                if (!$actor) throw new RuntimeException('Active Super Admin required.');
                $evidence = ['batch_id'=>$batch['id'],'source_fingerprint'=>$source['fingerprint'],'working_digest'=>$digest,'policy'=>'empty_digest_import_repair_v1'];
                lf_query($db, "INSERT INTO legacy_op_audit(unit_id,actor_id,entity_type,entity_id,request_key,reason,before_data,after_data) VALUES(?,?,'integrity_repair',0,?,'Pemulihan metadata hash kosong setelah audit impor SQL',?,?)",
                    [$batch['unit_id'],$actor['id'],hash('sha256','import-repair:'.$batch['id'].':'.$digest),lf_json(['working_digest'=>$before]),lf_json($evidence)]);
                $result[] = ['unit_id'=>(int)$batch['unit_id'],'before'=>$before,'after'=>$digest];
            } else lop_check_result($db, $batch['id']);
        }
        $db->commit();
    } catch (Throwable $error) { $db->rollback(); throw $error; }
    finally { unit_set_context($db, 0); }
    return $result;
}
