<?php

function unit_schema_ready(mysqli $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    $row = $db->query("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='unit_sekolah'")->fetch_assoc();
    return $ready = (int)$row['n'] > 0;
}

function unit_set_context(mysqli $db, int $unitId): void {
    if ($unitId < 0 || $unitId > 4) throw new InvalidArgumentException('Unit tidak valid.');
    $db->query('SET @app_unit_id=' . $unitId);
    $GLOBALS['app_unit_id'] = $unitId;
}

function unit_active_id(): int {
    return (int)($_SESSION['active_unit_id'] ?? $_SESSION['admin_unit_id'] ?? 1);
}

function unit_is_super(): bool {
    return ($_SESSION['admin_role'] ?? '') === 'super_admin';
}

function unit_palette_for_view(?int $reportUnitId = null): string {
    if (unit_is_super() && ($reportUnitId === 0 || ($reportUnitId === null && unit_active_id() === 0))) return 'super';
    return match (unit_active_id()) { 2 => 'smp', 3 => 'sma', default => 'sd' };
}

function unit_label(int $unitId): string {
    return [0=>'Semua Unit',1=>'SD',2=>'SMP',3=>'SMA'][$unitId] ?? 'Unit tidak dikenal';
}

function unit_school_name(int $unitId): string {
    return match ($unitId) {
        1 => "SEKOLAH DASAR AL-QUR'AN (SDA) MUTIARA HIKMAH",
        2 => 'SEKOLAH MENENGAH PERTAMA (SMP) MUTIARA HIKMAH',
        3 => 'SEKOLAH MENENGAH ATAS (SMA) MUTIARA HIKMAH',
        default => 'MUTIARA HIKMAH · SD, SMP, SMA',
    };
}

function unit_level_bounds(?int $unitId = null): array {
    return match ($unitId ?? unit_active_id()) {
        0 => [1,12], 2 => [7,9], 3 => [10,12], default => [1,6],
    };
}

function unit_level_in_sql(): string {
    [$first,$last] = unit_level_bounds();
    return '(' . implode(',', array_map(static fn($level) => "'{$level}'", range($first,$last))) . ')';
}

function unit_level_between_sql(): string {
    [$first,$last] = unit_level_bounds();
    return "BETWEEN {$first} AND {$last}";
}

function unit_bootstrap_context(mysqli $db): void {
    if (!unit_schema_ready($db)) {
        unit_set_context($db, 1);
        return;
    }
    if (empty($_SESSION['admin_id'])) {
        unit_set_context($db, PHP_SAPI === 'cli' ? 1 : 4);
        return;
    }
    $id = (int)$_SESSION['admin_id'];
    $stmt = $db->prepare('SELECT id,nama,role,unit_id,is_active FROM admin WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $id); $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc(); $stmt->close();
    // A non-super account without a valid unit must never inherit unit 0:
    // current_unit_id()=0 intentionally exposes every unit for global reports.
    if (!$account || (int)$account['is_active'] !== 1
        || ($account['role'] !== 'super_admin' && !in_array((int)$account['unit_id'], [1, 2, 3], true))) {
        unset($_SESSION['admin_id'], $_SESSION['admin_nama'], $_SESSION['admin_role'],
            $_SESSION['admin_unit_id'], $_SESSION['active_unit_id']);
        unit_set_context($db, 4);
        return;
    }
    $_SESSION['admin_role'] = $account['role'];
    $_SESSION['admin_nama'] = $account['nama'];
    $_SESSION['admin_unit_id'] = $account['unit_id'] === null ? null : (int)$account['unit_id'];
    if ($account['role'] === 'super_admin') {
        $selected = (int)($_SESSION['active_unit_id'] ?? 1);
        if ($selected < 0 || $selected > 3) $selected = 1;
    } else {
        $selected = (int)$account['unit_id'];
    }
    $_SESSION['active_unit_id'] = $selected;
    unit_set_context($db, $selected);
}

function unit_report_scope(mysqli $db, string $choice): int {
    $unitId = unit_active_id();
    if (unit_is_super() && $choice === 'all') $unitId = 0;
    unit_set_context($db, $unitId);
    return $unitId;
}

function unit_report_selector(int $reportUnitId): string {
    if (!unit_is_super()) return '';
    if (unit_active_id() === 0) return '<span class="unit-report-picker">Cakupan rekap: <strong>Semua Unit</strong></span>';
    $selected = $reportUnitId === 0 ? 'all' : 'active';
    return '<label class="unit-report-picker">Cakupan rekap <select class="field-input field-select" name="unit" onchange="unitSwitchReportScope(this)">'
        . '<option value="active"' . ($selected === 'active' ? ' selected' : '') . '>Unit aktif: ' . unit_label(unit_active_id()) . '</option>'
        . '<option value="all"' . ($selected === 'all' ? ' selected' : '') . '>Semua Unit</option>'
        . '</select></label>';
}

// Route classification is shared by the sidebar, entry gate and switch endpoint.
function unit_transaction_route(string $path): bool {
    $path = str_replace('\\', '/', (string)(parse_url($path, PHP_URL_PATH) ?? ''));
    return preg_match('#/(pembayaran/(form|edit|proses)\.php|tabungan/(masuk|keluar|proses)\.php)$#D', $path) === 1;
}

function unit_all_readonly(): bool {
    return unit_is_super() && unit_active_id() === 0;
}

function unit_guard_request(): void {
    if (PHP_SAPI === 'cli') return;
    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if (unit_transaction_route($path)
        && (($_GET['unit'] ?? '') === 'all' || ($_POST['unit'] ?? '') === 'all'
            || (isset($_GET['unit_id']) && (string)$_GET['unit_id'] === '0')
            || (isset($_POST['unit_id']) && (string)$_POST['unit_id'] === '0'))) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Transaksi wajib memilih SD, SMP, atau SMA.');
    }
    if (!unit_all_readonly()) return;
    $koneksi = $GLOBALS['koneksi'];
    $allowed = ['unit_switch.php', 'logout.php', 'role_management.php'];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
        && !in_array(basename($path), $allowed, true)) {
        http_response_code(409);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Pilih SD, SMP, atau SMA untuk melakukan perubahan. Semua Unit hanya untuk melihat data.');
    }
    if (unit_transaction_route($path)) {
        if (basename($path) === 'proses.php') {
            http_response_code(409);
            exit('Pilih unit untuk transaksi.');
        }
        require __DIR__ . '/unit_transaction_gate.php';
        exit;
    }
    // Annual master screens otherwise resolve one arbitrary year with LIMIT 1.
    if (in_array(basename($path), ['master_spp.php', 'master_daftar_ulang.php'], true)) {
        require __DIR__ . '/unit_master_overview.php';
        exit;
    }
}

function unit_record_badge(array $row): string {
    if (($GLOBALS['app_unit_id']??1) !== 0) return '';
    $unitId=(int)($row['unit_id']??0);
    if (!$unitId) {
        $nis=(string)($row['NO_INDUK']??$row['no_induk']??$row['nis']??'');
        $stmt=$GLOBALS['koneksi']->prepare('SELECT unit_id FROM siswa WHERE NO_INDUK=?');
        $stmt->bind_param('s',$nis);$stmt->execute();$owners=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
        $unitId=count($owners)===1?(int)$owners[0]['unit_id']:0;
    }
    return $unitId>0?'<span class="unit-record-pill" data-unit="'.$unitId.'">'.unit_label($unitId).'</span> ':'';
}

/** Stable identity key for combined read views; a student number alone is not an identity. */
function unit_student_key(array $row): string { return (int)($row['unit_id']??unit_active_id()).'|'.(string)($row['NO_INDUK']??$row['no_induk']??$row['nis']??''); }

/** Legacy NIS URLs require a unique visible identity or an explicit student ID. */
function unit_resolve_student(mysqli $db,string $nis,int $id=0):array {
    $s=$db->prepare('SELECT id,unit_id,NO_INDUK,NAMA,legacy_pending FROM siswa WHERE NO_INDUK=? AND (?=0 OR id=?)');$s->bind_param('sii',$nis,$id,$id);$s->execute();$rows=$s->get_result()->fetch_all(MYSQLI_ASSOC);$s->close();
    if(count($rows)!==1)throw new RuntimeException(count($rows)>1?'NIS ambigu; pilih siswa dan unit secara eksplisit.':'Siswa tidak ditemukan pada unit ini.');
    unit_set_context($db,(int)$rows[0]['unit_id']);return $rows[0];
}

function unit_student_selection_where(string $alias='s'):string {
    if(!preg_match('/^[a-z][a-z0-9_]*$/D',$alias))throw new LogicException('Invalid alias');
    $id=max(0,(int)($_GET['student_id']??0));return $id?' AND '.$alias.'.id='.$id:'';
}
