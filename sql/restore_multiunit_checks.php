<?php
/**
 * Audit and restore CHECK constraints on the physical multiunit tables.
 * Live DDL needs separate owner approval and a fresh external backup.
 */
if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../koneksi.php';
require_once __DIR__ . '/readiness_migration_guard.php';

$apply = in_array('--apply', $argv, true);
if ($apply) readiness_migration_assert_apply_allowed($argv, DB_NAME);
$table = $koneksi->query("SELECT TABLE_TYPE FROM information_schema.TABLES
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='siswa'")->fetch_assoc();
if (($table['TABLE_TYPE'] ?? '') !== 'VIEW') {
    throw new RuntimeException('Skrip ini hanya untuk skema multiunit yang sudah dimigrasi.');
}

/** Parse the limited, known CHECK grammar into a boolean tree. This keeps
 * AND/OR grouping while ignoring MySQL's redundant predicate parentheses. */
function schema_check_tokens(string $expression): array {
    preg_match_all('/`(?:``|[^`])*`|[a-z_][a-z_0-9]*|[0-9]+(?:\.[0-9]+)?|>=|<=|<>|!=|[()+,=<>-]/i',
        $expression, $matches, PREG_OFFSET_CAPTURE);
    $tokens = [];
    $offset = 0;
    foreach ($matches[0] as [$token, $position]) {
        if (trim(substr($expression, $offset, $position - $offset)) !== '') {
            throw new RuntimeException('Sintaks CHECK di luar parser audit.');
        }
        $tokens[] = strtolower(trim($token, '`'));
        $offset = $position + strlen($token);
    }
    if (trim(substr($expression, $offset)) !== '') throw new RuntimeException('Sintaks CHECK di luar parser audit.');
    return $tokens;
}

function schema_check_parse_or(array $tokens, int &$position): array {
    $terms = [schema_check_parse_and($tokens, $position)];
    while (($tokens[$position] ?? null) === 'or') {
        $position++;
        $terms[] = schema_check_parse_and($tokens, $position);
    }
    return count($terms) === 1 ? $terms[0] : ['or', $terms];
}

function schema_check_parse_and(array $tokens, int &$position): array {
    $terms = [schema_check_parse_factor($tokens, $position)];
    while (($tokens[$position] ?? null) === 'and') {
        $position++;
        $terms[] = schema_check_parse_factor($tokens, $position);
    }
    return count($terms) === 1 ? $terms[0] : ['and', $terms];
}

function schema_check_parse_factor(array $tokens, int &$position): array {
    if (($tokens[$position] ?? null) === '(') {
        $depth = 0;
        $close = null;
        for ($i = $position; $i < count($tokens); $i++) {
            if ($tokens[$i] === '(') $depth++;
            elseif ($tokens[$i] === ')') {
                $depth--;
                if ($depth === 0) { $close = $i; break; }
                if ($depth < 0) throw new RuntimeException('Kurung CHECK tidak seimbang.');
            }
        }
        if ($close === null) throw new RuntimeException('Kurung CHECK tidak seimbang.');
        $after = $tokens[$close + 1] ?? null;
        if ($after === null || in_array($after, [')', 'and', 'or'], true)) {
            $position++;
            $inside = schema_check_parse_or($tokens, $position);
            if (($tokens[$position] ?? null) !== ')') throw new RuntimeException('Kurung CHECK tidak seimbang.');
            $position++;
            return $inside;
        }
    }
    $atom = [];
    $depth = 0;
    $betweenAndPending = false;
    while ($position < count($tokens)) {
        $token = $tokens[$position];
        if ($depth === 0 && $token === ')') break;
        if ($depth === 0 && $token === 'or') break;
        if ($depth === 0 && $token === 'and' && !$betweenAndPending) break;
        if ($token === '(') $depth++;
        if ($token === ')') $depth--;
        if ($depth < 0) throw new RuntimeException('Kurung CHECK tidak seimbang.');
        if ($depth === 0 && $token === 'between') $betweenAndPending = true;
        elseif ($depth === 0 && $token === 'and' && $betweenAndPending) $betweenAndPending = false;
        if ($token !== '(' && $token !== ')') $atom[] = $token;
        $position++;
    }
    if ($depth !== 0 || $betweenAndPending || !$atom) throw new RuntimeException('Predikat CHECK tidak lengkap.');
    return ['atom', implode('|', $atom)];
}

function schema_check_definition_matches(string $expected, string $actual): bool {
    try {
        $left = schema_check_tokens($expected);
        $right = schema_check_tokens($actual);
        $leftPos = $rightPos = 0;
        $leftTree = schema_check_parse_or($left, $leftPos);
        $rightTree = schema_check_parse_or($right, $rightPos);
        return $leftPos === count($left) && $rightPos === count($right)
            && $leftTree === $rightTree;
    } catch (RuntimeException $error) {
        return false;
    }
}

function schema_grade_trigger_body(): string {
    return "BEGIN IF NOT EXISTS (SELECT 1 FROM unit_sekolah u WHERE u.id=NEW.unit_id
        AND NEW.tingkat BETWEEN u.tingkat_awal AND u.tingkat_akhir) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Tingkat kelas tidak sesuai unit';
        END IF; END";
}

function schema_grade_trigger_definition_matches(string $actual): bool {
    $normalize = static function (string $body): ?string {
        $body = preg_replace("/MESSAGE_TEXT\s*=\s*'(?:''|[^'])*'/i", 'MESSAGE_TEXT=\'message\'', $body, 1, $replacements);
        if ($replacements !== 1) return null;
        return strtolower(preg_replace('/\s+|`/', '', $body));
    };
    return $normalize($actual) === $normalize(schema_grade_trigger_body());
}

// CHECK tingkat kelas SD lama diganti oleh trigger berbasis unit saat migrasi.
$checks = [
    'siswa_data' => [
        'chk_siswa_psb' => 'PSB >= 0 AND asal_psb IN (0,1)',
        'chk_siswa_potongan_spp_nominal' => 'potongan_spp_nominal >= 0',
    ],
    'bayar_data' => ['chk_bayar_psb' => 'U_PSB >= 0'],
    'master_spp_tarif_data' => ['chk_master_spp_nominal' => 'nominal_dasar > 0'],
    'tagihan_spp_data' => [
        'chk_tagihan_spp_bulan' => 'CAST(bulan AS UNSIGNED) BETWEEN 1 AND 12',
        'chk_tagihan_spp_nominal' => 'tarif_dasar_snapshot >= 0 AND potongan_nominal_ditetapkan_snapshot >= 0 AND potongan_nominal_snapshot >= 0 AND potongan_nominal_snapshot <= tarif_dasar_snapshot AND nominal_tagihan >= 0',
    ],
    'spp_alokasi_batch_data' => ['chk_spp_alokasi_batch_nominal' => 'uang_baru >= 0'],
    'spp_alokasi_data' => ['chk_spp_alokasi_nominal' => 'nominal_dari_bayar > 0'],
    'master_biaya_lain_data' => ['chk_master_biaya_lain_nominal' => 'nominal > 0'],
    'tagihan_biaya_lain_data' => ['chk_tagihan_biaya_lain_nominal' => 'nominal_tagihan > 0'],
    'tahun_ajaran_data' => ['chk_tahun_ajaran_dates' => 'tanggal_selesai > tanggal_mulai'],
    'siswa_tahun_ajaran_data' => ['chk_penempatan_psb_spp' => 'spp_covered_by_psb IN (0,1) AND (spp_covered_by_psb = 0 OR spp_perbulan_snapshot = 0)'],
    'tagihan_komite_data' => ['chk_tagihan_komite_nominal' => 'nominal_tagihan >= 0'],
    'bayar_komite_data' => ['chk_bayar_komite_nominal' => 'nominal > 0'],
    'tagihan_daftar_ulang_data' => ['chk_tagihan_du_nominal' => 'nominal_awal >= 0 AND nominal_tagihan >= 0'],
    'tagihan_tahunan_siswa_data' => ['chk_tagihan_tahunan_nominal' => 'nominal_awal >= 0 AND potongan >= 0 AND nominal_tagihan >= 0'],
    'bayar_tahunan_siswa_data' => ['chk_bayar_tahunan_jumlah' => 'jumlah >= 0'],
    'tabungan_data' => ['chk_tabungan_saldo_nonnegative' => 'SALDO >= 0'],
];

$existing = [];
$result = $koneksi->query("SELECT tc.TABLE_NAME,tc.CONSTRAINT_NAME,tc.ENFORCED,cc.CHECK_CLAUSE
    FROM information_schema.TABLE_CONSTRAINTS tc
    JOIN information_schema.CHECK_CONSTRAINTS cc
      ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME
    WHERE tc.CONSTRAINT_SCHEMA=DATABASE() AND tc.CONSTRAINT_TYPE='CHECK'");
while ($row = $result->fetch_assoc()) $existing[$row['TABLE_NAME']][$row['CONSTRAINT_NAME']] = $row;
$tariffGradeViolations = (int)$koneksi->query("SELECT COUNT(*) n FROM master_spp_tarif_data t
    LEFT JOIN unit_sekolah u ON u.id=t.unit_id
    WHERE u.id IS NULL OR t.tingkat NOT BETWEEN u.tingkat_awal AND u.tingkat_akhir")->fetch_assoc()['n'];
$triggers = [];
$result = $koneksi->query("SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,
        EVENT_MANIPULATION,ACTION_ORDER,ACTION_STATEMENT
    FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()
      AND TRIGGER_NAME IN ('master_spp_tarif_data_bi','master_spp_tarif_data_bu',
        'master_spp_tarif_data_grade_bi','master_spp_tarif_data_grade_bu')");
while ($row = $result->fetch_assoc()) $triggers[$row['TRIGGER_NAME']] = $row;
$gradeMissing = [];
$gradeMismatched = [];
foreach (['INSERT' => 'bi', 'UPDATE' => 'bu'] as $event => $suffix) {
    $name = 'master_spp_tarif_data_grade_' . $suffix;
    $base = $triggers['master_spp_tarif_data_' . $suffix] ?? null;
    $guard = $triggers[$name] ?? null;
    $baseValid = $base && $base['EVENT_OBJECT_TABLE'] === 'master_spp_tarif_data'
        && $base['ACTION_TIMING'] === 'BEFORE' && $base['EVENT_MANIPULATION'] === $event;
    $guardValid = $guard && $baseValid
        && $guard['EVENT_OBJECT_TABLE'] === 'master_spp_tarif_data'
        && $guard['ACTION_TIMING'] === 'BEFORE' && $guard['EVENT_MANIPULATION'] === $event
        && (int)$guard['ACTION_ORDER'] > (int)$base['ACTION_ORDER']
        && schema_grade_trigger_definition_matches($guard['ACTION_STATEMENT']);
    $status = $guardValid ? 'OK' : ($guard ? 'MISMATCH' : 'MISSING');
    if ($status === 'MISSING') $gradeMissing[] = [$event, $suffix];
    if ($status === 'MISMATCH' || !$baseValid) $gradeMismatched[] = [$event, $suffix];
    echo $status . '.master_spp_tarif_data.grade_guard_' . strtolower($event)
        . ' invalid_rows=' . $tariffGradeViolations
        . ' base_trigger=' . ($baseValid ? 'OK' : 'MISSING_OR_WRONG') . "\n";
}
$missing = [];
$mismatched = [];
$invalidTotal = $tariffGradeViolations;
foreach ($checks as $tableName => $tableChecks) {
    foreach ($tableChecks as $name => $expression) {
        $invalid = (int)$koneksi->query("SELECT COUNT(*) n FROM `{$tableName}` WHERE NOT ({$expression})")->fetch_assoc()['n'];
        $definition = $existing[$tableName][$name] ?? null;
        $matches = $definition && $definition['ENFORCED'] === 'YES'
            && schema_check_definition_matches($expression, $definition['CHECK_CLAUSE']);
        $status = $matches ? 'OK' : ($definition ? 'MISMATCH' : 'MISSING');
        echo $status . ".{$tableName}.{$name} invalid_rows={$invalid}";
        if ($status === 'MISMATCH') echo ' enforced=' . $definition['ENFORCED'];
        echo "\n";
        if ($status === 'MISSING') $missing[] = [$tableName, $name, $expression];
        if ($status === 'MISMATCH') $mismatched[] = [$tableName, $name];
        $invalidTotal += $invalid;
    }
}
if ($invalidTotal > 0) {
    throw new RuntimeException("Ada {$invalidTotal} pelanggaran data; tidak ada DDL yang diterapkan.");
}
if ($mismatched || $gradeMismatched) {
    throw new RuntimeException('Definisi CHECK atau trigger berbeda; tinjau manual, tidak ada DDL yang diterapkan.');
}
if (!$apply) {
    $missingCount = count($missing) + count($gradeMissing);
    echo 'SUMMARY missing=' . $missingCount . " mode=read-only\n";
    exit($missingCount ? 1 : 0);
}
foreach ($missing as [$tableName, $name, $expression]) {
    $koneksi->query("ALTER TABLE `{$tableName}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
    echo "ADDED.{$tableName}.{$name}\n";
}
$added = count($missing);
foreach ($gradeMissing as [$event, $suffix]) {
    $originalTrigger = 'master_spp_tarif_data_' . $suffix;
    $newTrigger = 'master_spp_tarif_data_grade_' . $suffix;
    $koneksi->query("CREATE TRIGGER `{$newTrigger}` BEFORE {$event} ON master_spp_tarif_data
        FOR EACH ROW FOLLOWS `{$originalTrigger}` " . schema_grade_trigger_body());
    echo "ADDED.master_spp_tarif_data.{$newTrigger}\n";
    $added++;
}
$verifiedChecks = [];
$result = $koneksi->query("SELECT tc.TABLE_NAME,tc.CONSTRAINT_NAME,tc.ENFORCED,cc.CHECK_CLAUSE
    FROM information_schema.TABLE_CONSTRAINTS tc
    JOIN information_schema.CHECK_CONSTRAINTS cc
      ON cc.CONSTRAINT_SCHEMA=tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME=tc.CONSTRAINT_NAME
    WHERE tc.CONSTRAINT_SCHEMA=DATABASE() AND tc.CONSTRAINT_TYPE='CHECK'");
while ($row = $result->fetch_assoc()) $verifiedChecks[$row['TABLE_NAME']][$row['CONSTRAINT_NAME']] = $row;
foreach ($checks as $tableName => $tableChecks) {
    foreach ($tableChecks as $name => $expression) {
        $definition = $verifiedChecks[$tableName][$name] ?? null;
        if (!$definition || $definition['ENFORCED'] !== 'YES'
            || !schema_check_definition_matches($expression, $definition['CHECK_CLAUSE'])) {
            throw new RuntimeException("Verifikasi CHECK gagal setelah DDL: {$tableName}.{$name}");
        }
    }
}
$verifiedTriggers = [];
$result = $koneksi->query("SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,
        EVENT_MANIPULATION,ACTION_ORDER,ACTION_STATEMENT
    FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()
      AND TRIGGER_NAME IN ('master_spp_tarif_data_bi','master_spp_tarif_data_bu',
        'master_spp_tarif_data_grade_bi','master_spp_tarif_data_grade_bu')");
while ($row = $result->fetch_assoc()) $verifiedTriggers[$row['TRIGGER_NAME']] = $row;
foreach (['INSERT' => 'bi', 'UPDATE' => 'bu'] as $event => $suffix) {
    $base = $verifiedTriggers['master_spp_tarif_data_' . $suffix] ?? null;
    $guard = $verifiedTriggers['master_spp_tarif_data_grade_' . $suffix] ?? null;
    if (!$base || !$guard || $base['EVENT_OBJECT_TABLE'] !== 'master_spp_tarif_data'
        || $base['ACTION_TIMING'] !== 'BEFORE' || $base['EVENT_MANIPULATION'] !== $event
        || $guard['EVENT_OBJECT_TABLE'] !== 'master_spp_tarif_data'
        || $guard['ACTION_TIMING'] !== 'BEFORE' || $guard['EVENT_MANIPULATION'] !== $event
        || (int)$guard['ACTION_ORDER'] <= (int)$base['ACTION_ORDER']
        || !schema_grade_trigger_definition_matches($guard['ACTION_STATEMENT'])) {
        throw new RuntimeException("Verifikasi trigger grade gagal setelah DDL: {$event}");
    }
}
echo 'SUMMARY added=' . $added . ' mode=apply database=' . DB_NAME . "\n";
