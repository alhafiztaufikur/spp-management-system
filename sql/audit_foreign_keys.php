<?php

/** Read-only comparison of canonical schema relationships with physical multiunit tables. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/schema_source.php';

function fk_audit_identifier(string $value): string {
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $value)) {
        throw new RuntimeException('Identifier skema tidak valid.');
    }
    return $value;
}

function fk_audit_expected(): array {
    $schema = spp_schema_source();
    preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`([^`]+)`\s*\((.*?)\)\s*ENGINE=InnoDB;/is', $schema, $tables, PREG_SET_ORDER);
    $expected = [];
    foreach ($tables as $table) {
        $child = fk_audit_identifier($table[1]);
        preg_match_all('/FOREIGN KEY\s*\(\s*`?([A-Za-z0-9_]+)`?\s*\)\s*REFERENCES\s*`?([A-Za-z0-9_]+)`?\s*\(\s*`?([A-Za-z0-9_]+)`?\s*\)([^\r\n,]*)/i', $table[2], $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            preg_match('/ON DELETE\s+(RESTRICT|CASCADE|SET NULL|NO ACTION)/i', $match[4], $delete);
            preg_match('/ON UPDATE\s+(RESTRICT|CASCADE|SET NULL|NO ACTION)/i', $match[4], $update);
            $expected[] = [
                'child' => $child,
                'column' => fk_audit_identifier($match[1]),
                'parent' => fk_audit_identifier($match[2]),
                'parent_column' => fk_audit_identifier($match[3]),
                // MySQL reports an omitted rule as NO ACTION. We retain the
                // declaration here; comparison treats it as equivalent to
                // RESTRICT only because InnoDB enforces both immediately.
                'on_delete' => strtoupper($delete[1] ?? 'NO ACTION'),
                'on_update' => strtoupper($update[1] ?? 'NO ACTION'),
            ];
        }
    }
    if (count($tables) < 30 || count($expected) < 40) {
        throw new RuntimeException('Parser schema.sql menghasilkan terlalu sedikit tabel atau relasi.');
    }
    return $expected;
}

function fk_audit_physical_tables(mysqli $db): array {
    $stmt = $db->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'");
    $stmt->execute();
    $tables = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $tables[strtolower((string)$row['TABLE_NAME'])] = (string)$row['TABLE_NAME'];
    }
    $stmt->close();
    return $tables;
}

function fk_audit_resolve_table(string $name, array $physicalTables): ?string {
    return $physicalTables[strtolower($name . '_data')]
        ?? $physicalTables[strtolower($name)]
        ?? null;
}

function fk_audit_actual(mysqli $db): array {
    $stmt = $db->prepare("SELECT k.TABLE_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,
            k.REFERENCED_COLUMN_NAME,k.CONSTRAINT_NAME,r.DELETE_RULE,r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
         AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
        WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL");
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $result = ['by_relation' => [], 'by_child_column' => []];
    $constraintSizes = [];
    foreach ($rows as $row) {
        $constraintKey = strtolower($row['TABLE_NAME'] . '.' . $row['CONSTRAINT_NAME']);
        $constraintSizes[$constraintKey] = ($constraintSizes[$constraintKey] ?? 0) + 1;
    }
    foreach ($rows as $row) {
        $constraintKey = strtolower($row['TABLE_NAME'] . '.' . $row['CONSTRAINT_NAME']);
        $row['column_count'] = $constraintSizes[$constraintKey];
        $row['unit_pair']=count(array_filter($rows,static fn($other)=>$other['TABLE_NAME']===$row['TABLE_NAME'] && $other['CONSTRAINT_NAME']===$row['CONSTRAINT_NAME'] && $other['COLUMN_NAME']==='unit_id' && $other['REFERENCED_COLUMN_NAME']==='unit_id'))===1;
        $key = strtolower($row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'] . '>'
            . $row['REFERENCED_TABLE_SCHEMA'] . '.' . $row['REFERENCED_TABLE_NAME']
            . '.' . $row['REFERENCED_COLUMN_NAME']);
        $childKey = strtolower($row['TABLE_NAME'] . '.' . $row['COLUMN_NAME']);
        $result['by_relation'][$key][] = $row;
        $result['by_child_column'][$childKey][] = $row;
    }
    return $result;
}

function fk_audit_rule_equivalent(string $expected, string $actual): bool {
    // InnoDB does not defer constraints: NO ACTION and RESTRICT have the same
    // effect. CASCADE and SET NULL must still match exactly.
    $normalize = static fn(string $rule): string => strtoupper($rule) === 'NO ACTION'
        ? 'RESTRICT' : strtoupper($rule);
    return $normalize($expected) === $normalize($actual);
}

function fk_audit_columns(mysqli $db): array {
    $stmt = $db->prepare("SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,DATA_TYPE,CHARACTER_SET_NAME,COLLATION_NAME,IS_NULLABLE
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()");
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $result = [];
    foreach ($rows as $row) {
        $result[strtolower($row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'])] = $row;
    }
    return $result;
}

function fk_audit_first_indexes(mysqli $db): array {
    $stmt = $db->prepare("SELECT TABLE_NAME,COLUMN_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()");
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $result = [];
    $byIndex = [];
    foreach ($rows as $row) {
        $byIndex[strtolower($row['TABLE_NAME'] . '.' . $row['INDEX_NAME'])][] = $row;
    }
    foreach ($byIndex as $indexColumns) {
        usort($indexColumns, static fn($a, $b) => (int)$a['SEQ_IN_INDEX'] <=> (int)$b['SEQ_IN_INDEX']);
        $first = $indexColumns[0];
        $result[strtolower($first['TABLE_NAME'] . '.' . $first['COLUMN_NAME'])][] = [
            'index' => $first['INDEX_NAME'],
            'unique_columns'=>(int)$first['NON_UNIQUE']===0?array_column($indexColumns,'COLUMN_NAME'):[],
            'unique_single_column' => (int)$first['NON_UNIQUE'] === 0 && count($indexColumns) === 1,
        ];
    }
    return $result;
}

function fk_audit_engines(mysqli $db): array {
    $stmt = $db->prepare("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'");
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $result = [];
    foreach ($rows as $row) $result[strtolower($row['TABLE_NAME'])] = $row['ENGINE'];
    return $result;
}

function fk_audit_preflight(array $relation, array $columns, array $indexes, array $engines): array {
    $childKey = strtolower($relation['physical_child'] . '.' . $relation['column']);
    $parentKey = strtolower($relation['physical_parent'] . '.' . $relation['parent_column']);
    $child = $columns[$childKey] ?? null;
    $parent = $columns[$parentKey] ?? null;
    $issues = [];
    if (!$child) $issues[] = 'child_column_missing';
    if (!$parent) $issues[] = 'parent_column_missing';
    if ($child && $parent) {
        $sameDataType = strcasecmp($child['DATA_TYPE'], $parent['DATA_TYPE']) === 0;
        $stringLengthMayDiffer = $sameDataType && in_array(strtolower($child['DATA_TYPE']),
            ['char', 'varchar', 'binary', 'varbinary'], true);
        if (!$sameDataType || (!$stringLengthMayDiffer
            && strcasecmp($child['COLUMN_TYPE'], $parent['COLUMN_TYPE']) !== 0)) {
            $issues[] = 'column_type_mismatch:' . $child['COLUMN_TYPE'] . '>' . $parent['COLUMN_TYPE'];
        }
        if ($child['CHARACTER_SET_NAME'] !== $parent['CHARACTER_SET_NAME']
            || $child['COLLATION_NAME'] !== $parent['COLLATION_NAME']) {
            $issues[] = 'charset_collation_mismatch';
        }
        if ($relation['on_delete'] === 'SET NULL' && $child['IS_NULLABLE'] !== 'YES') {
            $issues[] = 'set_null_on_nonnullable_child';
        }
    }
    if(!empty($relation['unit_pair']))$parentKey=strtolower($relation['physical_parent'].'.unit_id');
    if (empty($indexes[$parentKey])) $issues[] = 'parent_leading_index_missing';
    if (!array_filter($indexes[$parentKey] ?? [], static fn($index) => !empty($relation['unit_pair'])?$index['unique_columns']===['unit_id','NO_INDUK']:$index['unique_single_column'])) {
        $issues[] = 'parent_unique_single_column_index_missing';
    }
    foreach (['physical_child', 'physical_parent'] as $tableKey) {
        if (strcasecmp((string)($engines[strtolower($relation[$tableKey])] ?? ''), 'InnoDB') !== 0) {
            $issues[] = $tableKey . '_not_innodb';
        }
    }
    return $issues;
}

function fk_audit_orphans(mysqli $db, array $relation): int {
    $child = fk_audit_identifier($relation['physical_child']);
    $column = fk_audit_identifier($relation['column']);
    $parent = fk_audit_identifier($relation['physical_parent']);
    $parentColumn = fk_audit_identifier($relation['parent_column']);
    $sql = "SELECT COUNT(*) AS n FROM `{$child}` c LEFT JOIN `{$parent}` p ON p.`{$parentColumn}`=c.`{$column}` ".(!empty($relation['unit_pair'])?' AND p.unit_id=c.unit_id':'')." WHERE c.`{$column}` IS NOT NULL AND p.`{$parentColumn}` IS NULL";
    return (int)$db->query($sql)->fetch_assoc()['n'];
}

function fk_audit_report(mysqli $db): array {
    $physicalTables = fk_audit_physical_tables($db);
    $actual = fk_audit_actual($db);
    $columns = fk_audit_columns($db);
    $indexes = fk_audit_first_indexes($db);
    $engines = fk_audit_engines($db);
    $expected = [];
    $missing = [];
    $mismatched = [];
    $equivalentRuleSpellings = [];
    $unresolved = [];
    $deferredUpgrades = [];
    foreach (fk_audit_expected() as $relation) {
        $child = fk_audit_resolve_table($relation['child'], $physicalTables);
        $parent = fk_audit_resolve_table($relation['parent'], $physicalTables);
        if (!$child || !$parent) {
            $unresolved[] = $relation + ['physical_child' => $child, 'physical_parent' => $parent];
            continue;
        }
        $relation['physical_child'] = $child;
        $relation['physical_parent'] = $parent;
        // A verified pre-upgrade backup legitimately lacks this additive column.
        // Once installed, its FK is checked exactly like every other relation.
        if ($relation['column'] === 'penempatan_asal_id'
            && !isset($columns[strtolower($child.'.penempatan_asal_id')])) {
            $deferredUpgrades[] = $relation;
            continue;
        }
        $relation['unit_pair']=$parent==='siswa_data' && $relation['parent_column']==='NO_INDUK' && isset($columns['siswa_data.legacy_pending']);
        $key = strtolower($child . '.' . $relation['column'] . '>' . DB_NAME . '.'
            . $parent . '.' . $relation['parent_column']);
        $childKey = strtolower($child . '.' . $relation['column']);
        $matches = $actual['by_relation'][$key] ?? [];
        $existingOnColumn = $actual['by_child_column'][$childKey] ?? [];
        $relation['constraint'] = count($matches) === 1 ? $matches[0]['CONSTRAINT_NAME'] : null;
        $expected[] = $relation;
        if ($matches || $existingOnColumn) {
            $sameDefinition = count($matches) === 1
                && count($existingOnColumn) === 1
                && (int)$matches[0]['column_count'] === (!empty($relation['unit_pair'])?2:1)
                && (empty($relation['unit_pair']) || $matches[0]['unit_pair'])
                && fk_audit_rule_equivalent($matches[0]['DELETE_RULE'], $relation['on_delete'])
                && fk_audit_rule_equivalent($matches[0]['UPDATE_RULE'], $relation['on_update']);
            if (!$sameDefinition) {
                $relation['orphans'] = fk_audit_orphans($db, $relation);
                $relation['preflight_issues'] = fk_audit_preflight($relation, $columns, $indexes, $engines);
                $mismatched[] = $relation + ['actual' => $existingOnColumn];
            } elseif (strcasecmp($matches[0]['DELETE_RULE'], $relation['on_delete']) !== 0
                || strcasecmp($matches[0]['UPDATE_RULE'], $relation['on_update']) !== 0) {
                $equivalentRuleSpellings[] = $relation + ['actual' => $matches[0]];
            }
        } else {
            $relation['orphans'] = fk_audit_orphans($db, $relation);
            $relation['preflight_issues'] = fk_audit_preflight($relation, $columns, $indexes, $engines);
            $missing[] = $relation;
        }
    }
    return [
        'database' => DB_NAME,
        'expected' => count($expected),
        'present' => count($expected) - count($missing) - count($mismatched),
        'missing' => $missing,
        'mismatched' => $mismatched,
        'equivalent_rule_spellings' => $equivalentRuleSpellings,
        'unresolved' => $unresolved,
        'deferred_upgrades' => $deferredUpgrades,
    ];
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    echo json_encode(fk_audit_report($koneksi), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
}
