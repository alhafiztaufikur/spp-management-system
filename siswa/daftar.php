<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: ../login.php'); exit; }
require_once '../koneksi.php';
require_once '../includes/auth.php';
require_once '../includes/daftar_ulang.php';
require_once '../includes/kelas.php';
require_once '../includes/pagination.php';
require_once '../includes/student_tariff_consistency.php';
requireRole(['admin']);

if (empty($_SESSION['csrf_student'])) {
    $_SESSION['csrf_student'] = bin2hex(random_bytes(32));
}

function student_history_count(mysqli $db, string $noInduk): int {
    $sql = "
        SELECT
          (SELECT COUNT(*) FROM bayar WHERE NO_INDUK = ?) +
          (SELECT COUNT(*) FROM bayar_du WHERE no_induk = ?) +
          (SELECT COUNT(*) FROM transaksi_m WHERE NO_INDUK = ?) +
          (SELECT COUNT(*) FROM transaksi_k WHERE NO_INDUK = ?) AS jumlah
    ";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('ssss', $noInduk, $noInduk, $noInduk, $noInduk);
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['jumlah'];
    $stmt->close();
    return $count;
}

function find_student(mysqli $db, int $id, bool $forUpdate = false): ?array {
    $stmt = $db->prepare('SELECT * FROM siswa WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if ($student) $student['history_count'] = student_history_count($db, $student['NO_INDUK']);
    return $student;
}

function student_snapshot(array $student): array {
    $keys = [
        'id', 'NO_INDUK', 'NAMA', 'KELAS', 'master_kelas_id', 'SPP_PERBULAN', 'PANGKAL', 'BANGUNAN',
        'SERAGAM', 'KEGIATAN', 'MAKAN', 'SORGA', 'INFAQ',
        'PANGKAL_BAYAR', 'BANGUNAN_BAYAR', 'SERAGAM_BAYAR',
        'KEGIATAN_BAYAR', 'POMG', 'DAFTAR_ULANG', 'NO_induk_diknas',
        'potong_pangkal', 'tot_pangkal', 'tot_du', 'potong_du', 'is_active'
    ];
    return array_intersect_key($student, array_flip($keys));
}

function write_student_audit(mysqli $db, int $studentId, string $noInduk, string $action, ?array $before, ?array $after): void {
    $beforeJson = $before ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $afterJson = $after ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $adminName = (string)($_SESSION['admin_nama'] ?? 'Administrator');
    $stmt = $db->prepare("
        INSERT INTO siswa_audit_log
          (siswa_id, no_induk_snapshot, aksi, before_data, after_data, admin_id, admin_name)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param('issssis', $studentId, $noInduk, $action, $beforeJson, $afterJson, $adminId, $adminName);
    $stmt->execute();
    $stmt->close();
}

function student_redirect(string $location = 'daftar.php'): void {
    header('Location: ' . $location);
    exit;
}

function fail_student(string $message, array $oldInput, string $location): void {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => $message];
    $_SESSION['student_old_input'] = $oldInput;
    student_redirect($location);
}

function validate_student_identity(mysqli $db, array $source): array {
    $noInduk = trim((string)($source['no_induk'] ?? ''));
    $name = trim((string)($source['nama'] ?? ''));
    $classId = (int)($source['master_kelas_id'] ?? 0);
    if (!preg_match('/^[0-9]{1,10}$/', $noInduk)) {
        throw new RuntimeException('Nomor induk wajib berupa 1 sampai 10 digit.');
    }
    if ($name === '' || mb_strlen($name) > 100) {
        throw new RuntimeException('Nama siswa wajib diisi dan maksimal 100 karakter.');
    }
    $class = class_find($db, $classId, true);
    if (!$class) throw new RuntimeException('Pilih kelas/rombel aktif dari Master Kelas.');
    return [$noInduk, $name, (string)$class['tingkat'], $classId];
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$oldInput = $_SESSION['student_old_input'] ?? [];
unset($_SESSION['student_old_input']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['aksi'] ?? '';
    $returnLocation = 'daftar.php';
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_student'], $postedToken)) {
        fail_student('Permintaan tidak valid atau sesi telah kedaluwarsa.', $_POST, $returnLocation);
    }

    try {
        if ($action === 'tambah' || $action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            if ($action === 'update') $returnLocation .= '?edit=' . $id;
            $koneksi->begin_transaction();
            $oldStudent = $action === 'update' ? find_student($koneksi, $id, true) : null;
            if ($action === 'update' && !$oldStudent) throw new RuntimeException('Data siswa tidak ditemukan.');
            [$noInduk, $name, $class, $classId] = validate_student_identity($koneksi, $_POST);

            $stmtDuplicate = $koneksi->prepare('SELECT id FROM siswa WHERE NO_INDUK = ? AND id <> ? LIMIT 1');
            $stmtDuplicate->bind_param('si', $noInduk, $id);
            $stmtDuplicate->execute();
            $duplicate = $stmtDuplicate->get_result()->fetch_assoc();
            $stmtDuplicate->close();
            if ($duplicate) throw new RuntimeException('Nomor induk sudah digunakan siswa lain.');

            $advanced = isset($_POST['advanced_enabled']) && $_POST['advanced_enabled'] === '1';
            $advancedColumns = [
                'SPP_PERBULAN', 'PANGKAL', 'BANGUNAN', 'SERAGAM', 'KEGIATAN',
                'MAKAN', 'SORGA', 'INFAQ', 'POMG', 'DAFTAR_ULANG',
                'potong_pangkal', 'potong_du'
            ];
            $postMap = [
                'SPP_PERBULAN' => 'spp_perbulan', 'PANGKAL' => 'pangkal',
                'BANGUNAN' => 'bangunan', 'SERAGAM' => 'seragam',
                'KEGIATAN' => 'kegiatan', 'MAKAN' => 'makan',
                'SORGA' => 'sorga', 'INFAQ' => 'infaq', 'POMG' => 'pomg',
                'DAFTAR_ULANG' => 'daftar_ulang', 'potong_pangkal' => 'potong_pangkal',
                'potong_du' => 'potong_du'
            ];
            $openingMap = [
                'PANGKAL_BAYAR' => 'pangkal_bayar', 'BANGUNAN_BAYAR' => 'bangunan_bayar',
                'SERAGAM_BAYAR' => 'seragam_bayar', 'KEGIATAN_BAYAR' => 'kegiatan_bayar'
            ];
            if (!$advanced) {
                $ignoredChanges = student_advanced_change_attempts($_POST, $oldStudent, $postMap, $openingMap);
                if ($ignoredChanges) {
                    throw new RuntimeException('Perubahan tarif atau data lanjutan terdeteksi. Aktifkan Advance sebelum menyimpan.');
                }
            }
            $values = [];
            foreach ($advancedColumns as $column) {
                $values[$column] = $advanced
                    ? student_amount($_POST[$postMap[$column]] ?? 0)
                    : (float)($oldStudent[$column] ?? 0);
            }
            $nisDiknas = $advanced
                ? trim((string)($_POST['no_induk_diknas'] ?? ''))
                : (string)($oldStudent['NO_induk_diknas'] ?? '');
            if ($nisDiknas !== '' && !preg_match('/^[0-9]{10}$/', $nisDiknas)) {
                throw new RuntimeException('No. Induk Diknas harus tepat 10 digit jika diisi.');
            }
            if ($nisDiknas !== '') {
                $stmtDiknas = $koneksi->prepare('SELECT id FROM siswa WHERE NO_induk_diknas = ? AND id <> ? LIMIT 1');
                $stmtDiknas->bind_param('si', $nisDiknas, $id);
                $stmtDiknas->execute();
                $duplicateDiknas = $stmtDiknas->get_result()->fetch_assoc();
                $stmtDiknas->close();
                if ($duplicateDiknas) throw new RuntimeException('No. Induk Diknas sudah digunakan siswa lain.');
            }
            if ($values['potong_pangkal'] > $values['PANGKAL']) {
                throw new RuntimeException('Potongan uang pangkal tidak boleh melebihi tagihan pangkal.');
            }
            if ($values['potong_du'] > $values['DAFTAR_ULANG']) {
                throw new RuntimeException('Potongan daftar ulang tidak boleh melebihi tagihan daftar ulang.');
            }
            $values['tot_pangkal'] = max(0, $values['PANGKAL'] - $values['potong_pangkal']);
            $values['tot_du'] = max(0, $values['DAFTAR_ULANG'] - $values['potong_du']);

            $canEditOpening = !$oldStudent || (int)$oldStudent['history_count'] === 0;
            foreach ($openingMap as $column => $postName) {
                $oldValue = (float)($oldStudent[$column] ?? 0);
                if ($advanced && $canEditOpening) {
                    $values[$column] = student_amount($_POST[$postName] ?? 0);
                } else {
                    $values[$column] = $oldValue;
                    if (!$canEditOpening && isset($_POST[$postName]) && student_amount($_POST[$postName]) !== $oldValue) {
                        throw new RuntimeException('Saldo awal tidak dapat diubah setelah siswa memiliki histori transaksi.');
                    }
                }
            }
            $openingLimits = [
                'PANGKAL_BAYAR' => $values['tot_pangkal'],
                'BANGUNAN_BAYAR' => $values['BANGUNAN'],
                'SERAGAM_BAYAR' => $values['SERAGAM'],
                'KEGIATAN_BAYAR' => $values['KEGIATAN']
            ];
            if (!$oldStudent || $canEditOpening) {
                foreach ($openingLimits as $column => $limit) {
                    if ($values[$column] > $limit) throw new RuntimeException('Saldo awal tidak boleh melebihi nilai tagihan.');
                }
            }

            $spp = $values['SPP_PERBULAN'];
            $pangkal = $values['PANGKAL'];
            $bangunan = $values['BANGUNAN'];
            $seragam = $values['SERAGAM'];
            $kegiatan = $values['KEGIATAN'];
            $makan = $values['MAKAN'];
            $sorga = $values['SORGA'];
            $infaq = $values['INFAQ'];
            $pangkalBayar = $values['PANGKAL_BAYAR'];
            $bangunanBayar = $values['BANGUNAN_BAYAR'];
            $seragamBayar = $values['SERAGAM_BAYAR'];
            $kegiatanBayar = $values['KEGIATAN_BAYAR'];
            $pomg = $values['POMG'];
            $daftarUlang = $values['DAFTAR_ULANG'];
            $potongPangkal = $values['potong_pangkal'];
            $totPangkal = $values['tot_pangkal'];
            $totDu = $values['tot_du'];
            $potongDu = $values['potong_du'];
            $active = (int)($oldStudent['is_active'] ?? 1);

            if ($action === 'tambah') {
                $stmt = $koneksi->prepare("
                    INSERT INTO siswa (
                      NO_INDUK, NAMA, KELAS, SPP_PERBULAN, PANGKAL, BANGUNAN, SERAGAM,
                      KEGIATAN, MAKAN, SORGA, INFAQ,
                      PANGKAL_BAYAR, BANGUNAN_BAYAR, SERAGAM_BAYAR, KEGIATAN_BAYAR,
                      POMG, DAFTAR_ULANG, NO_induk_diknas, potong_pangkal, tot_pangkal,
                      tot_du, potong_du, is_active
                    ) VALUES (
                      ?, ?, ?,
                      ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                      NULLIF(?, ''), ?, ?, ?, ?, ?
                    )
                ");
                $stmt->bind_param(
                    'sssddddddddddddddsddddi',
                    $noInduk, $name, $class, $spp, $pangkal, $bangunan, $seragam, $kegiatan,
                    $makan, $sorga, $infaq, $pangkalBayar, $bangunanBayar, $seragamBayar, $kegiatanBayar, $pomg,
                    $daftarUlang, $nisDiknas, $potongPangkal, $totPangkal, $totDu, $potongDu, $active
                );
                $stmt->execute();
                $id = $koneksi->insert_id;
                $stmt->close();
                $stmtClass = $koneksi->prepare('UPDATE siswa SET master_kelas_id = ? WHERE id = ?');
                $stmtClass->bind_param('ii', $classId, $id); $stmtClass->execute(); $stmtClass->close();
                $tariffSync = null;
                $placementId = class_sync_student_current_year($koneksi, $noInduk, $classId, $spp, $pomg, true, $tariffSync);
                if ($placementId) du_create_bill_for_placement($koneksi, $placementId);
                $after = find_student($koneksi, $id);
                $afterAudit = student_snapshot($after);
                $afterAudit['_tariff_sync'] = $tariffSync;
                write_student_audit($koneksi, $id, $noInduk, 'tambah', null, $afterAudit);
                $successMessage = "Siswa $name berhasil ditambahkan.";
            } else {
                $before = student_snapshot($oldStudent);
                $stmt = $koneksi->prepare("
                    UPDATE siswa SET
                      NO_INDUK=?, NAMA=?, KELAS=?, SPP_PERBULAN=?, PANGKAL=?, BANGUNAN=?,
                      SERAGAM=?, KEGIATAN=?, MAKAN=?, SORGA=?, INFAQ=?,
                      PANGKAL_BAYAR=?, BANGUNAN_BAYAR=?,
                      SERAGAM_BAYAR=?, KEGIATAN_BAYAR=?, POMG=?, DAFTAR_ULANG=?,
                      NO_induk_diknas=NULLIF(?, ''), potong_pangkal=?, tot_pangkal=?,
                      tot_du=?, potong_du=? WHERE id=?
                ");
                $stmt->bind_param(
                    'sssddddddddddddddsddddi',
                    $noInduk, $name, $class, $spp, $pangkal, $bangunan, $seragam, $kegiatan,
                    $makan, $sorga, $infaq, $pangkalBayar, $bangunanBayar, $seragamBayar, $kegiatanBayar, $pomg,
                    $daftarUlang, $nisDiknas, $potongPangkal, $totPangkal, $totDu, $potongDu, $id
                );
                $stmt->execute();
                $stmt->close();
                $stmtClass = $koneksi->prepare('UPDATE siswa SET master_kelas_id = ? WHERE id = ?');
                $stmtClass->bind_param('ii', $classId, $id); $stmtClass->execute(); $stmtClass->close();
                $tariffSync = null;
                $placementId = class_sync_student_current_year($koneksi, $noInduk, $classId, $spp, $pomg, $active === 1, $tariffSync);
                $duBillBefore = du_find_bill($koneksi, $noInduk, (int)date('n'), (int)date('Y'), true);
                $duBillId = $placementId ? du_create_bill_for_placement($koneksi, $placementId, false) : null;
                $duSync = du_reconcile_current_student_override($koneksi, $noInduk);
                if (!$duBillBefore && $duBillId) $duSync['status'] = 'synced';
                $after = find_student($koneksi, $id);
                $afterSnapshot = student_snapshot($after);
                $requestedComponents = student_tariff_component_changes($before, $afterSnapshot);
                $syncedComponents = $tariffSync['synced'] ?? [];
                $lockedComponents = $tariffSync['locked'] ?? [];
                if ($duSync['status'] === 'synced') $syncedComponents[] = 'daftar_ulang';
                if ($duSync['status'] === 'locked') $lockedComponents[] = 'daftar_ulang';
                $syncedComponents = array_values(array_unique($syncedComponents));
                $lockedComponents = array_values(array_unique($lockedComponents));
                $updatedComponents = array_values(array_intersect($syncedComponents, $requestedComponents));
                $repairedComponents = array_values(array_diff($syncedComponents, $requestedComponents));
                $lockedRequested = array_values(array_intersect($lockedComponents, $requestedComponents));
                $masterChanged = student_snapshots_differ($before, $afterSnapshot);
                $hasEffectiveChange = $masterChanged || count($syncedComponents) > 0;

                if (!$hasEffectiveChange) {
                    $koneksi->commit();
                    $noChangeMessage = 'Tidak ada perubahan yang disimpan.';
                    if ($lockedComponents) {
                        $noChangeMessage .= ' ' . student_tariff_labels($lockedComponents) . ' tahun ' . ($tariffSync['tahun_ajaran'] ?? $duSync['tahun_ajaran']) . ' tetap terkunci karena sudah memiliki pembayaran.';
                    }
                    $_SESSION['flash'] = ['type' => 'warning', 'msg' => $noChangeMessage];
                    student_redirect('daftar.php');
                }

                $syncAudit = [
                    'tahun_ajaran' => $tariffSync['tahun_ajaran'] ?? $duSync['tahun_ajaran'],
                    'diperbarui' => $updatedComponents,
                    'diperbaiki_otomatis' => $repairedComponents,
                    'terkunci' => $lockedComponents,
                    'kelas_dipertahankan' => (bool)($tariffSync['kelas_dipertahankan'] ?? false),
                ];
                $afterAudit = $afterSnapshot;
                $afterAudit['_tariff_sync'] = $syncAudit;
                $auditAction = $masterChanged ? 'update' : 'rekonsiliasi_tarif';
                write_student_audit($koneksi, $id, $noInduk, $auditAction, $before, $afterAudit);

                $messageParts = [];
                if ($masterChanged) $messageParts[] = "Data siswa $name berhasil diperbarui.";
                if ($updatedComponents) {
                    $messageParts[] = count($updatedComponents) . ' komponen tagihan tahun ' . $syncAudit['tahun_ajaran'] . ' ikut diperbarui.';
                }
                if ($repairedComponents) {
                    $messageParts[] = 'Ketidaksinkronan ' . student_tariff_labels($repairedComponents) . ' diperbaiki otomatis.';
                }
                if ($lockedRequested) {
                    $messageParts[] = student_tariff_labels($lockedRequested) . ' tahun ' . $syncAudit['tahun_ajaran'] . ' tetap karena sudah memiliki pembayaran; tarif baru berlaku untuk penerbitan tahun berikutnya.';
                }
                $lockedExisting = array_values(array_diff($lockedComponents, $lockedRequested));
                if ($lockedExisting) {
                    $messageParts[] = 'Snapshot yang tetap terkunci: ' . student_tariff_labels($lockedExisting) . '.';
                }
                $classChanged = (int)($before['master_kelas_id'] ?? 0) !== (int)($afterSnapshot['master_kelas_id'] ?? 0);
                if ($classChanged && $syncAudit['kelas_dipertahankan']) {
                    $messageParts[] = 'Kelas penempatan tahun berjalan dipertahankan untuk menjaga histori transaksi.';
                }
                $successMessage = implode(' ', $messageParts);
                $successType = ($lockedComponents || ($classChanged && $syncAudit['kelas_dipertahankan'])) ? 'warning' : 'success';
            }
            $koneksi->commit();
            $_SESSION['flash'] = ['type' => $successType ?? 'success', 'msg' => $successMessage];
            student_redirect('daftar.php');
        }

        if ($action === 'toggle_status') {
            $id = (int)($_POST['id'] ?? 0);
            $koneksi->begin_transaction();
            $student = find_student($koneksi, $id, true);
            if (!$student) throw new RuntimeException('Data siswa tidak ditemukan.');
            $before = student_snapshot($student);
            $newStatus = (int)$student['is_active'] === 1 ? 0 : 1;
            $stmt = $koneksi->prepare('UPDATE siswa SET is_active = ? WHERE id = ?');
            $stmt->bind_param('ii', $newStatus, $id);
            $stmt->execute();
            $stmt->close();
            if (!empty($student['master_kelas_id'])) {
                class_sync_student_current_year(
                    $koneksi,
                    (string)$student['NO_INDUK'],
                    (int)$student['master_kelas_id'],
                    (float)$student['SPP_PERBULAN'],
                    (float)$student['POMG'],
                    $newStatus === 1
                );
            }
            $after = find_student($koneksi, $id);
            $auditAction = $newStatus ? 'pulihkan' : 'arsipkan';
            write_student_audit($koneksi, $id, $student['NO_INDUK'], $auditAction, $before, student_snapshot($after));
            $koneksi->commit();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => $newStatus ? 'Siswa berhasil dipulihkan.' : 'Siswa berhasil diarsipkan.'];
            student_redirect('daftar.php');
        }
    } catch (Throwable $error) {
        try { $koneksi->rollback(); } catch (Throwable $ignored) {}
        fail_student($error->getMessage(), $_POST, $returnLocation);
    }
}

$editId = (int)($_GET['edit'] ?? 0);
$editStudent = $editId > 0 ? find_student($koneksi, $editId) : null;
if ($editId > 0 && !$editStudent) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Data siswa tidak ditemukan.'];
    student_redirect('daftar.php');
}

$query = trim((string)($_GET['q'] ?? ''));
$filterClass = (int)($_GET['kelas'] ?? 0);
$classOptions = class_all($koneksi, true, true);
if ($filterClass > 0 && !array_filter($classOptions, fn($row) => (int)$row['id'] === $filterClass)) $filterClass = 0;
$filterStatus = (string)($_GET['status'] ?? 'active');
if (!in_array($filterStatus, ['active', 'archived', 'all'], true)) $filterStatus = 'active';
$allowedPageSizes = [10, 25, 50];
$perPage = page_size_param('per_page', $allowedPageSizes, 10);
$page = page_int_param('page');

$listWhereSql = "
    FROM siswa s
    WHERE (? = '' OR s.NO_INDUK LIKE CONCAT('%', ?, '%') OR s.NAMA LIKE CONCAT('%', ?, '%') OR s.NO_induk_diknas LIKE CONCAT('%', ?, '%'))
      AND (? = 0 OR s.master_kelas_id = ?)
      AND (? = 'all' OR s.is_active = IF(? = 'archived', 0, 1))
";
$listTypes = 'ssssiiss';
$listParams = [$query, $query, $query, $query, $filterClass, $filterClass, $filterStatus, $filterStatus];

$stmtCount = $koneksi->prepare("SELECT COUNT(*) AS total " . $listWhereSql);
$stmtCount->bind_param($listTypes, ...$listParams);
$stmtCount->execute();
$totalStudents = (int)($stmtCount->get_result()->fetch_assoc()['total'] ?? 0);
$stmtCount->close();

$totalPages = total_pages($totalStudents, $perPage);
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmtList = $koneksi->prepare("
    SELECT s.*, mk.tingkat AS master_tingkat, mk.kode_rombel, mk.is_placeholder,
      (SELECT COUNT(*) FROM bayar p WHERE p.NO_INDUK = s.NO_INDUK) AS jml_bayar,
      ((SELECT COUNT(*) FROM bayar p WHERE p.NO_INDUK = s.NO_INDUK) +
       (SELECT COUNT(*) FROM bayar_du du WHERE du.no_induk = s.NO_INDUK) +
       (SELECT COUNT(*) FROM transaksi_m tm WHERE tm.NO_INDUK = s.NO_INDUK) +
       (SELECT COUNT(*) FROM transaksi_k tk WHERE tk.NO_INDUK = s.NO_INDUK)) AS history_count
    FROM siswa s
    LEFT JOIN master_kelas mk ON mk.id = s.master_kelas_id
    WHERE (? = '' OR s.NO_INDUK LIKE CONCAT('%', ?, '%') OR s.NAMA LIKE CONCAT('%', ?, '%') OR s.NO_induk_diknas LIKE CONCAT('%', ?, '%'))
      AND (? = 0 OR s.master_kelas_id = ?)
      AND (? = 'all' OR s.is_active = IF(? = 'archived', 0, 1))
    ORDER BY s.is_active DESC,
      CASE WHEN s.KELAS REGEXP '^[1-6]$' THEN 0 ELSE 1 END,
      CAST(s.KELAS AS UNSIGNED), s.KELAS, s.NAMA ASC
    LIMIT ? OFFSET ?
");
$pageTypes = $listTypes . 'ii';
$pageParams = array_merge($listParams, [$perPage, $offset]);
$stmtList->bind_param($pageTypes, ...$pageParams);
$stmtList->execute();
$studentRows = $stmtList->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtList->close();
$studentPaginationQuery = pagination_query(['per_page' => $perPage]);

$formStudent = $editStudent ?? [];
$fieldMap = [
    'no_induk' => 'NO_INDUK', 'nama' => 'NAMA', 'kelas' => 'KELAS',
    'master_kelas_id' => 'master_kelas_id',
    'no_induk_diknas' => 'NO_induk_diknas', 'spp_perbulan' => 'SPP_PERBULAN',
    'pangkal' => 'PANGKAL', 'bangunan' => 'BANGUNAN', 'seragam' => 'SERAGAM',
    'kegiatan' => 'KEGIATAN', 'makan' => 'MAKAN', 'sorga' => 'SORGA',
    'infaq' => 'INFAQ', 'pomg' => 'POMG', 'daftar_ulang' => 'DAFTAR_ULANG',
    'potong_pangkal' => 'potong_pangkal', 'potong_du' => 'potong_du',
    'pangkal_bayar' => 'PANGKAL_BAYAR', 'bangunan_bayar' => 'BANGUNAN_BAYAR',
    'seragam_bayar' => 'SERAGAM_BAYAR', 'kegiatan_bayar' => 'KEGIATAN_BAYAR'
];
function form_student_value(string $key, array $oldInput, array $student, array $fieldMap, $default = '') {
    if (array_key_exists($key, $oldInput)) return $oldInput[$key];
    $column = $fieldMap[$key] ?? $key;
    return $student[$column] ?? $default;
}
function rupiah_value($value): string {
    return number_format((float)$value, 0, ',', '.');
}
$advancedOpen = isset($oldInput['advanced_enabled']) && $oldInput['advanced_enabled'] === '1';
$canEditOpening = !$editStudent || (int)($editStudent['history_count'] ?? 0) === 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Data Siswa | SistemSPP</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon.png?v=2" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/style.css?v=9.6" />
  <script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>
  <div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
  <div class="layout">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">
      <div class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="topbar-title"><h2>Data Siswa</h2><span class="breadcrumb">SistemSPP / Data Siswa</span></div>
        <div class="clock-badge" id="liveClock">--:--:--</div>
      </div>

      <?php if ($flash): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>" id="flash-msg"><?= htmlspecialchars($flash['msg']) ?></div>
      <?php endif; ?>

      <section class="main-card master-modern-shell student-master-shell">
        <div class="master-modern-hero">
          <div>
            <span class="recap-class-overline">Data Master</span>
            <h1>Data Siswa</h1>
            <p>Kelola identitas siswa, rombel, tarif, saldo awal legacy, dan status aktif siswa.</p>
          </div>
          <div class="master-modern-stats">
            <div><span>Hasil Filter</span><strong><?= number_format($totalStudents) ?></strong></div>
            <div><span>Per Halaman</span><strong><?= number_format($perPage) ?></strong></div>
          </div>
        </div>
      </section>

      <div class="main-card master-modern-card master-modern-form">
        <div class="card-title-row">
          <div class="card-title"><?= $editStudent ? 'Edit Siswa' : 'Tambah Siswa Baru' ?></div>
          <?php if ($editStudent): ?><span class="master-status <?= $editStudent['is_active'] ? 'is-active' : 'is-inactive' ?>"><?= $editStudent['is_active'] ? 'Aktif' : 'Diarsipkan' ?></span><?php endif; ?>
        </div>
        <form method="POST" action="daftar.php" id="form-master-siswa" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_student']) ?>" />
          <input type="hidden" name="aksi" value="<?= $editStudent ? 'update' : 'tambah' ?>" />
          <input type="hidden" name="id" value="<?= (int)($editStudent['id'] ?? 0) ?>" />
          <div class="fields-grid">
            <div class="field-row">
              <label class="field-label" for="nis-baru">No. Induk</label>
              <input class="field-input" type="text" inputmode="numeric" maxlength="10" id="nis-baru" name="no_induk" required
                value="<?= htmlspecialchars((string)form_student_value('no_induk', $oldInput, $formStudent, $fieldMap)) ?>" />
            </div>
            <div class="field-row">
              <label class="field-label" for="nama-baru">Nama Lengkap</label>
              <input class="field-input" type="text" maxlength="100" id="nama-baru" name="nama" required
                value="<?= htmlspecialchars((string)form_student_value('nama', $oldInput, $formStudent, $fieldMap)) ?>" />
            </div>
            <div class="field-row">
              <label class="field-label" for="kelas-baru">Kelas/Rombel</label>
              <?php
                $selectedClassId = (int)form_student_value('master_kelas_id', $oldInput, $formStudent, $fieldMap, 0);
                $selectedClassLabel = '';
                foreach ($classOptions as $classOption) {
                    if ($selectedClassId === (int)$classOption['id']) {
                        $selectedClassLabel = (string)$classOption['label'];
                        break;
                    }
                }
              ?>
              <input type="hidden" id="kelas-baru" name="master_kelas_id" value="<?= $selectedClassId > 0 ? $selectedClassId : '' ?>" />
              <div class="class-picker" data-class-picker>
                <button class="field-input class-picker-button" type="button" data-class-picker-button aria-haspopup="listbox" aria-expanded="false">
                  <span data-class-picker-label><?= htmlspecialchars($selectedClassLabel !== '' ? $selectedClassLabel : '-- Pilih Kelas/Rombel --') ?></span>
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div class="class-picker-panel" data-class-picker-panel hidden role="listbox">
                  <?php foreach ($classOptions as $classOption): ?>
                  <button type="button" class="class-picker-option <?= $selectedClassId === (int)$classOption['id'] ? 'is-selected' : '' ?>"
                    data-class-picker-option
                    data-value="<?= (int)$classOption['id'] ?>"
                    data-label="<?= htmlspecialchars($classOption['label'], ENT_QUOTES, 'UTF-8') ?>"
                    role="option"
                    aria-selected="<?= $selectedClassId === (int)$classOption['id'] ? 'true' : 'false' ?>">
                    <?= htmlspecialchars($classOption['label']) ?>
                  </button>
                  <?php endforeach; ?>
                </div>
              </div>
              <small class="payment-auto-note">Kelola pilihan melalui menu Master Kelas.</small>
            </div>
          </div>

          <label class="advanced-switch" for="advanced-enabled">
            <span><strong>Advance</strong><small>Tarif siswa dan saldo awal; tagihan berbayar tetap terkunci</small></span>
            <input type="checkbox" id="advanced-enabled" name="advanced_enabled" value="1" <?= $advancedOpen ? 'checked' : '' ?> />
            <span class="advanced-switch-track"><span></span></span>
          </label>

          <div class="student-advanced-panel <?= $advancedOpen ? 'is-open' : '' ?>" id="student-advanced-panel">
            <div class="section-divider"><span>Identitas Sekolah</span></div>
            <div class="fields-grid">
              <div class="field-row">
                <label class="field-label" for="nis-diknas">No. Induk Diknas</label>
                <input class="field-input advanced-field" type="text" inputmode="numeric" maxlength="10" id="nis-diknas" name="no_induk_diknas"
                  value="<?= htmlspecialchars((string)form_student_value('no_induk_diknas', $oldInput, $formStudent, $fieldMap)) ?>" />
              </div>
            </div>

            <div class="section-divider"><span>Tarif Siswa</span></div>
            <div class="fields-grid student-money-grid">
              <?php
              $feeFields = [
                'spp_perbulan' => 'SPP per Bulan', 'pangkal' => 'Uang Pangkal',
                'bangunan' => 'Uang Bangunan', 'seragam' => 'Uang Seragam',
                'kegiatan' => 'Uang Kegiatan', 'makan' => 'Uang Makan',
                'sorga' => 'Uang Sorga', 'infaq' => 'Uang Infaq', 'pomg' => 'Uang Komite',
                'daftar_ulang' => 'Uang Daftar Ulang'
              ];
              foreach ($feeFields as $key => $label):
              ?>
              <div class="field-row">
                <label class="field-label" for="student-<?= $key ?>"><?= $label ?></label>
                <input class="field-input rupiah-input advanced-field student-fee-input" type="text" inputmode="numeric" id="student-<?= $key ?>" name="<?= $key ?>"
                  value="<?= rupiah_value(form_student_value($key, $oldInput, $formStudent, $fieldMap, 0)) ?>" />
              </div>
              <?php endforeach; ?>
            </div>

            <div class="section-divider"><span>Potongan</span></div>
            <div class="fields-grid student-money-grid">
              <div class="field-row">
                <label class="field-label" for="student-potong-pangkal">Potongan Pangkal</label>
                <input class="field-input rupiah-input advanced-field derived-source" type="text" inputmode="numeric" id="student-potong-pangkal" name="potong_pangkal"
                  value="<?= rupiah_value(form_student_value('potong_pangkal', $oldInput, $formStudent, $fieldMap, 0)) ?>" />
              </div>
              <div class="field-row">
                <label class="field-label" for="student-total-pangkal">Total Pangkal Setelah Potongan</label>
                <input class="field-input student-derived" type="text" id="student-total-pangkal" readonly value="<?= rupiah_value($editStudent['tot_pangkal'] ?? 0) ?>" />
              </div>
              <div class="field-row">
                <label class="field-label" for="student-potong-du">Potongan Daftar Ulang</label>
                <input class="field-input rupiah-input advanced-field derived-source" type="text" inputmode="numeric" id="student-potong-du" name="potong_du"
                  value="<?= rupiah_value(form_student_value('potong_du', $oldInput, $formStudent, $fieldMap, 0)) ?>" />
              </div>
              <div class="field-row">
                <label class="field-label" for="student-total-du">Total Daftar Ulang Setelah Potongan</label>
                <input class="field-input student-derived" type="text" id="student-total-du" readonly value="<?= rupiah_value($editStudent['tot_du'] ?? 0) ?>" />
              </div>
            </div>

            <div class="section-divider"><span>Migrasi Saldo Awal</span></div>
            <div class="fields-grid student-money-grid">
              <?php
              $openingFields = [
                'pangkal_bayar' => 'Pangkal Sudah Dibayar', 'bangunan_bayar' => 'Bangunan Sudah Dibayar',
                'seragam_bayar' => 'Seragam Sudah Dibayar', 'kegiatan_bayar' => 'Kegiatan Sudah Dibayar'
              ];
              foreach ($openingFields as $key => $label):
              ?>
              <div class="field-row">
                <label class="field-label" for="student-<?= $key ?>"><?= $label ?></label>
                <input class="field-input rupiah-input advanced-field opening-balance" type="text" inputmode="numeric" id="student-<?= $key ?>" name="<?= $key ?>"
                  value="<?= rupiah_value(form_student_value($key, $oldInput, $formStudent, $fieldMap, 0)) ?>" <?= $canEditOpening ? '' : 'disabled' ?> />
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="action-bar" style="margin-top:18px">
            <button type="submit" class="btn btn-primary"><?= $editStudent ? 'Simpan Perubahan' : 'Tambah Siswa' ?></button>
            <?php if ($editStudent): ?><a href="daftar.php" class="btn btn-ghost">Batal</a><?php endif; ?>
          </div>
        </form>
      </div>

      <div class="main-card master-modern-card master-modern-list" style="margin-top:0">
        <div class="card-title-row"><div class="card-title">Daftar Siswa (<?= number_format($totalStudents) ?>)</div></div>
        <form method="GET" action="daftar.php" class="filter-bar student-filter-bar">
          <div class="search-box">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="search" name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Cari nama, NIS, atau NIS Diknas..." />
          </div>
          <select class="field-input field-select filter-sel" name="kelas">
            <option value="">Semua Kelas</option>
            <?php foreach ($classOptions as $classOption): ?><option value="<?= (int)$classOption['id'] ?>" <?= $filterClass === (int)$classOption['id'] ? 'selected' : '' ?>><?= htmlspecialchars($classOption['label']) ?></option><?php endforeach; ?>
          </select>
          <select class="field-input field-select filter-sel" name="status">
            <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Aktif</option>
            <option value="archived" <?= $filterStatus === 'archived' ? 'selected' : '' ?>>Diarsipkan</option>
            <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Semua Status</option>
          </select>
          <select class="field-input field-select filter-sel" name="per_page" aria-label="Jumlah siswa per halaman">
            <?php foreach ($allowedPageSizes as $pageSize): ?>
            <option value="<?= $pageSize ?>" <?= $perPage === $pageSize ? 'selected' : '' ?>><?= $pageSize ?> / halaman</option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-primary" type="submit">Filter</button>
          <a class="btn btn-ghost" href="export_excel.php?<?= htmlspecialchars(http_build_query($_GET), ENT_QUOTES, 'UTF-8') ?>">Export Excel</a>
        </form>
        <div class="table-container">
          <table class="payment-table responsive-table">
            <thead><tr><th>No</th><th>No. Induk</th><th>Nama Siswa</th><th>Kelas</th><th>SPP/Bulan</th><th>Status</th><th class="student-history-col">Riwayat Transaksi</th><th>Aksi</th></tr></thead>
            <tbody>
              <?php if (!$studentRows): ?>
              <tr><td colspan="8"><div class="empty-state"><p>Data siswa tidak ditemukan</p></div></td></tr>
              <?php else: foreach ($studentRows as $index => $student):
                $editUrl = 'daftar.php?edit=' . (int)$student['id'];
              ?>
              <tr class="clickable-payment-row" data-edit-url="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>" tabindex="0" role="link" aria-label="Edit siswa <?= htmlspecialchars($student['NAMA'], ENT_QUOTES, 'UTF-8') ?>">
                <td data-label="No"><?= $offset + $index + 1 ?></td>
                <td data-label="No. Induk"><span class="badge-nis"><?= htmlspecialchars($student['NO_INDUK']) ?></span><?php if (!empty($student['NO_induk_diknas'])): ?><small class="du-history-nis">Diknas <?= htmlspecialchars($student['NO_induk_diknas']) ?></small><?php endif; ?></td>
                <td data-label="Nama Siswa"><?= htmlspecialchars($student['NAMA']) ?></td>
                <td data-label="Kelas"><span class="kelas-badge"><?= htmlspecialchars(class_label([
                  'tingkat' => $student['master_tingkat'] ?: $student['KELAS'],
                  'kode_rombel' => $student['kode_rombel'] ?? 'BELUM',
                  'is_placeholder' => $student['is_placeholder'] ?? 1,
                ])) ?></span></td>
                <td data-label="SPP/Bulan" class="nominal">Rp <?= number_format((float)$student['SPP_PERBULAN'], 0, ',', '.') ?></td>
                <td data-label="Status"><span class="master-status <?= $student['is_active'] ? 'is-active' : 'is-inactive' ?>"><?= $student['is_active'] ? 'Aktif' : 'Diarsipkan' ?></span></td>
                <td data-label="Riwayat Transaksi" class="student-history-col"><span class="badge-count"><?= (int)$student['history_count'] ?>x</span></td>
                <td data-label="Aksi" class="aksi-col">
                  <a class="btn-tbl btn-tbl-edit" href="<?= htmlspecialchars($editUrl) ?>">Edit</a>
                  <form method="POST" action="daftar.php" style="display:inline" onsubmit="return confirm('<?= $student['is_active'] ? 'Arsipkan' : 'Pulihkan' ?> siswa <?= htmlspecialchars(addslashes($student['NAMA'])) ?>?')">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_student']) ?>" />
                    <input type="hidden" name="aksi" value="toggle_status" /><input type="hidden" name="id" value="<?= (int)$student['id'] ?>" />
                    <button type="submit" class="btn-tbl btn-tbl-toggle"><?= $student['is_active'] ? 'Arsipkan' : 'Pulihkan' ?></button>
                  </form>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <?php render_pagination('daftar.php', $studentPaginationQuery, $page, $totalPages, $totalStudents, $perPage, 'siswa'); ?>
      </div>
    </main>
  </div>

  <script src="../assets/js/app.js?v=3.8"></script>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const toggle = document.getElementById('advanced-enabled');
      const panel = document.getElementById('student-advanced-panel');
      const advancedFields = Array.from(document.querySelectorAll('.advanced-field'));
      const initialAdvancedValues = new Map(advancedFields.map(input => [input, input.value]));
      const moneyInputs = Array.from(document.querySelectorAll('.rupiah-input:not(:disabled)'));
      const format = value => {
        const clean = String(value || '').replace(/\D/g, '');
        return clean ? Number(clean).toLocaleString('id-ID') : '0';
      };
      const number = id => Number((document.getElementById(id)?.value || '0').replace(/\./g, '')) || 0;
      const updateDerived = () => {
        document.getElementById('student-total-pangkal').value = format(Math.max(0, number('student-pangkal') - number('student-potong-pangkal')));
        document.getElementById('student-total-du').value = format(Math.max(0, number('student-daftar_ulang') - number('student-potong-du')));
      };
      const classPicker = document.querySelector('[data-class-picker]');
      const classInput = document.getElementById('kelas-baru');
      const classButton = classPicker?.querySelector('[data-class-picker-button]');
      const classPanel = classPicker?.querySelector('[data-class-picker-panel]');
      const classLabel = classPicker?.querySelector('[data-class-picker-label]');
      const closeClassPicker = () => {
        if (!classPicker || !classPanel || !classButton) return;
        classPicker.classList.remove('is-open');
        classPanel.hidden = true;
        classButton.setAttribute('aria-expanded', 'false');
      };
      classButton?.addEventListener('click', function () {
        const open = !classPicker.classList.contains('is-open');
        classPicker.classList.toggle('is-open', open);
        classPanel.hidden = !open;
        classButton.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      classPicker?.querySelectorAll('[data-class-picker-option]').forEach(option => {
        option.addEventListener('click', function () {
          classInput.value = this.dataset.value || '';
          classLabel.textContent = this.dataset.label || '-- Pilih Kelas/Rombel --';
          classPicker.querySelectorAll('[data-class-picker-option]').forEach(item => {
            const selected = item === this;
            item.classList.toggle('is-selected', selected);
            item.setAttribute('aria-selected', selected ? 'true' : 'false');
          });
          closeClassPicker();
        });
      });
      document.addEventListener('click', function (event) {
        if (classPicker && !classPicker.contains(event.target)) closeClassPicker();
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeClassPicker();
      });
      const syncPanel = () => panel.classList.toggle('is-open', toggle.checked);
      toggle.addEventListener('change', function () {
        if (!toggle.checked) {
          const dirty = advancedFields.some(input => input.value !== initialAdvancedValues.get(input));
          if (dirty && !window.confirm('Perubahan pada data Advance belum disimpan. Batalkan perubahan tersebut?')) {
            toggle.checked = true;
            syncPanel();
            return;
          }
          if (dirty) {
            advancedFields.forEach(input => { input.value = initialAdvancedValues.get(input); });
            updateDerived();
          }
        }
        syncPanel();
      });
      moneyInputs.forEach(input => input.addEventListener('input', function () { this.value = format(this.value); updateDerived(); }));
      document.getElementById('form-master-siswa').addEventListener('submit', function (event) {
        if (!classInput.value) {
          event.preventDefault();
          classButton?.focus();
          classPicker?.classList.add('has-error');
          return;
        }
        classPicker?.classList.remove('has-error');
        if (toggle.checked) moneyInputs.forEach(input => input.value = input.value.replace(/\./g, ''));
      });
      syncPanel();
      updateDerived();
      autoHideFlash();
    });
  </script>
</body>
</html>
