<?php
session_start();
if (!isset($_SESSION['admin_id'])) { header('Location: login.php'); exit; }
require_once 'koneksi.php';
require_once 'includes/auth.php';
require_once 'includes/daftar_ulang.php';
requireRole(['admin']);

if (empty($_SESSION['csrf_master_du'])) $_SESSION['csrf_master_du'] = bin2hex(random_bytes(32));

function master_du_e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function master_du_amount($value): float { return (float)str_replace(['.', ','], ['', '.'], trim((string)$value)); }
function master_du_redirect(string $year): void { header('Location: master_daftar_ulang.php?tahun=' . urlencode($year)); exit; }
function master_du_year(mysqli $db, string $label, bool $lock = false): array {
    $stmt = $db->prepare('SELECT * FROM tahun_ajaran WHERE label = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->bind_param('s', $label); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) throw new RuntimeException('Tahun ajaran tidak ditemukan.');
    return $row;
}
function master_du_ensure_year(mysqli $db, string $label): array {
    [$start, $end] = du_year_dates($label);
    $stmt = $db->prepare("INSERT INTO tahun_ajaran (label, tanggal_mulai, tanggal_selesai, status) VALUES (?, ?, ?, 'draft') ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
    $stmt->bind_param('sss', $label, $start, $end); $stmt->execute(); $stmt->close();
    return master_du_year($db, $label);
}

$selectedYear = trim((string)($_GET['tahun'] ?? $_POST['tahun_ajaran'] ?? du_current_academic_year()));
try { $selectedYear = du_normalize_academic_year($selectedYear); }
catch (Throwable $e) { $selectedYear = du_current_academic_year(); }
master_du_ensure_year($koneksi, $selectedYear);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_master_du'], $token)) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Permintaan tidak valid atau sesi telah kedaluwarsa.'];
        master_du_redirect($selectedYear);
    }
    $action = (string)($_POST['aksi'] ?? '');
    try {
        $koneksi->begin_transaction();
        $year = master_du_year($koneksi, $selectedYear, true);
        $yearId = (int)$year['id'];

        if (in_array($action, ['simpan_tarif', 'simpan_dan_terbitkan'], true)) {
            if ($year['status'] === 'closed') throw new RuntimeException('Tahun ajaran sudah ditutup; tarif tidak dapat diubah.');
            $amounts = $_POST['jumlah'] ?? [];
            for ($class = 1; $class <= 6; $class++) {
                $amount = master_du_amount($amounts[(string)$class] ?? $amounts[$class] ?? 0);
                if ($amount <= 0) throw new RuntimeException('Nominal kelas ' . $class . ' harus lebih dari Rp 0.');
                $classText = (string)$class;
                $stmt = $koneksi->prepare('SELECT id, Jumlah FROM Daftar_ulang WHERE tahun_ajaran_id = ? AND kelas = ? LIMIT 1 FOR UPDATE');
                $stmt->bind_param('is', $yearId, $classText); $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc(); $stmt->close();

                if (!$existing) {
                    if ($year['status'] !== 'draft') throw new RuntimeException('Tarif kelas ' . $class . ' tidak dapat ditambahkan setelah tahun ajaran diterbitkan.');
                    $stmt = $koneksi->prepare('INSERT INTO Daftar_ulang (tahun_ajaran_id, th_ajaran, kelas, Jumlah) VALUES (?, ?, ?, ?)');
                    $stmt->bind_param('issd', $yearId, $selectedYear, $classText, $amount); $stmt->execute();
                    $masterId = (int)$koneksi->insert_id; $stmt->close();
                    du_write_audit($koneksi, $yearId, $masterId, 'buat_tarif', null, ['kelas'=>$classText,'jumlah'=>$amount]);
                    continue;
                }

                $oldAmount = (float)$existing['Jumlah'];
                if (abs($oldAmount - $amount) < .001) continue;
                $affected = 0;
                if ($year['status'] === 'published') {
                    $existingId = (int)$existing['id'];
                    try {
                        $affected = du_sync_open_bills_for_master_rate(
                            $koneksi,
                            $existingId,
                            $selectedYear,
                            $oldAmount,
                            $amount
                        );
                    } catch (RuntimeException $error) {
                        if (str_contains($error->getMessage(), 'lebih kecil daripada cicilan')) {
                            throw new RuntimeException('Nominal baru kelas ' . $class . ' lebih kecil daripada cicilan siswa yang sudah masuk.');
                        }
                        throw $error;
                    }
                }
                $existingId = (int)$existing['id'];
                $stmt = $koneksi->prepare('UPDATE Daftar_ulang SET Jumlah=? WHERE id=?');
                $stmt->bind_param('di', $amount, $existingId); $stmt->execute(); $stmt->close();
                du_write_audit($koneksi, $yearId, $existingId, 'ubah_tarif', ['jumlah'=>$oldAmount], ['jumlah'=>$amount], $affected);
            }
            if ($year['status'] === 'draft') {
                $created = du_publish_year_from_active_students($koneksi, $yearId, $selectedYear);
                du_write_audit($koneksi, $yearId, null, 'terbitkan_tagihan', ['status'=>'draft'], ['status'=>'published','sumber_kelas'=>'data_siswa'], $created);
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Enam tarif disimpan dan '.$created.' tagihan siswa berhasil diterbitkan.'];
            } else {
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Tarif dan tagihan yang belum lunas berhasil diperbarui.'];
            }
        } elseif ($action === 'terbitkan') {
            if ($year['status'] !== 'draft') throw new RuntimeException('Tahun ajaran ini sudah pernah diterbitkan.');
            $created = du_publish_year_from_active_students($koneksi, $yearId, $selectedYear);
            du_write_audit($koneksi, $yearId, null, 'terbitkan_tagihan', ['status'=>'draft'], ['status'=>'published','sumber_kelas'=>'data_siswa'], $created);
            $_SESSION['flash'] = ['type'=>'success','msg'=>$created.' tagihan berhasil diterbitkan berdasarkan kelas pada Data Siswa.'];
        } elseif ($action === 'tutup') {
            if ($year['status'] !== 'published') throw new RuntimeException('Hanya tahun ajaran terbit yang dapat ditutup.');
            $stmt = $koneksi->prepare("UPDATE tahun_ajaran SET status='closed',closed_at=NOW() WHERE id=?");
            $stmt->bind_param('i', $yearId); $stmt->execute(); $stmt->close();
            du_write_audit($koneksi, $yearId, null, 'tutup_tahun', ['status'=>'published'], ['status'=>'closed']);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Tahun ajaran ditutup. Tunggakan yang sudah terbit tetap dapat dilunasi.'];
        } elseif ($action === 'buka') {
            if ($year['status'] !== 'closed') throw new RuntimeException('Hanya tahun ajaran tertutup yang dapat dibuka kembali.');
            $stmt = $koneksi->prepare("UPDATE tahun_ajaran SET status='published',closed_at=NULL WHERE id=?");
            $stmt->bind_param('i', $yearId); $stmt->execute(); $stmt->close();
            du_write_audit($koneksi, $yearId, null, 'buka_tahun', ['status'=>'closed'], ['status'=>'published']);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Tahun ajaran dibuka kembali. Histori pembayaran tetap dipertahankan.'];
        } else {
            throw new RuntimeException('Aksi Master Daftar Ulang tidak dikenal.');
        }
        $koneksi->commit();
    } catch (Throwable $error) {
        $koneksi->rollback();
        $_SESSION['flash'] = ['type'=>'error','msg'=>$error->getMessage()];
    }
    master_du_redirect($selectedYear);
}

$yearRowsRaw = $koneksi->query('SELECT * FROM tahun_ajaran ORDER BY label DESC')->fetch_all(MYSQLI_ASSOC);
$yearRowsByLabel = [];
foreach ($yearRowsRaw as $yearRow) $yearRowsByLabel[$yearRow['label']] = $yearRow;
$currentStart = (int)substr(du_current_academic_year(), 0, 4);
for ($offset=-2; $offset<=3; $offset++) {
    $start=$currentStart+$offset; $label=$start.'/'.($start+1);
    if (!isset($yearRowsByLabel[$label])) $yearRowsByLabel[$label]=['label'=>$label,'status'=>'draft'];
}
krsort($yearRowsByLabel); $yearRows=array_values($yearRowsByLabel);
$year=master_du_year($koneksi,$selectedYear); $yearId=(int)$year['id'];

$masters=array_fill(1,6,0.0);
$stmt=$koneksi->prepare('SELECT kelas,Jumlah FROM Daftar_ulang WHERE tahun_ajaran_id=? ORDER BY CAST(kelas AS UNSIGNED)');
$stmt->bind_param('i',$yearId); $stmt->execute(); $result=$stmt->get_result();
while($row=$result->fetch_assoc()) $masters[(int)$row['kelas']]=(float)$row['Jumlah'];
$stmt->close();

$activeByClass=array_fill(1,6,0);
$result=$koneksi->query("SELECT KELAS,COUNT(*) total FROM siswa WHERE is_active=1 AND KELAS IN ('1','2','3','4','5','6') GROUP BY KELAS");
while($row=$result->fetch_assoc()) $activeByClass[(int)$row['KELAS']]=(int)$row['total'];
$activeTotal=array_sum($activeByClass);

$stmt=$koneksi->prepare("SELECT COUNT(*) bills,COALESCE(SUM(tdu.nominal_tagihan),0) total,COALESCE(SUM(p.paid),0) paid
    FROM tagihan_daftar_ulang tdu
    LEFT JOIN (SELECT tagihan_daftar_ulang_id,SUM(jumlah) paid FROM bayar_du GROUP BY tagihan_daftar_ulang_id) p ON p.tagihan_daftar_ulang_id=tdu.id
    WHERE tdu.tahun_ajaran_id=? AND tdu.status='open'");
$stmt->bind_param('i',$yearId); $stmt->execute(); $billSummary=$stmt->get_result()->fetch_assoc(); $stmt->close();
?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Master Daftar Ulang | SistemSPP</title><link rel="icon" type="image/png" href="assets/img/favicon.png?v=2"><link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/css/style.css?v=8.7"><script>(function(){var t=localStorage.getItem('spp_theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script></head><body>
<div class="bg-orbs"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div><div class="layout"><?php include 'includes/sidebar.php'; ?><main class="main-content">
<div class="topbar"><button class="sidebar-toggle" onclick="toggleSidebar()" id="btn-sidebar-toggle" aria-label="Buka navigasi"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button><div class="topbar-title"><h2>Master Daftar Ulang</h2><span class="breadcrumb">SistemSPP / Tahun Ajaran / Daftar Ulang</span></div><div class="clock-badge" id="liveClock">--:--:--</div></div>
<?php if($flash): ?><div class="alert alert-<?= master_du_e($flash['type']) ?>" id="flash-msg"><?= master_du_e($flash['msg']) ?></div><?php endif; ?>
<div class="main-card du-master-hero master-modern-shell"><div class="master-modern-hero"><div><span class="recap-class-overline">Konfigurasi Tahun Ajaran</span><h1><?= master_du_e($selectedYear) ?></h1><p>Periode 1 Juli <?= substr($selectedYear,0,4) ?> sampai 30 Juni <?= substr($selectedYear,5,4) ?>.</p></div><div class="master-modern-stats"><div><span>Siswa Aktif</span><strong><?= number_format($activeTotal) ?></strong></div><div><span>Tagihan Terbit</span><strong><?= number_format((int)$billSummary['bills']) ?></strong></div><div><span>Terbayar</span><strong>Rp <?= number_format((float)$billSummary['paid'],0,',','.') ?></strong></div></div><div class="du-master-year-tools"><form method="get"><select class="field-input field-select" name="tahun" onchange="this.form.submit()"><?php foreach($yearRows as $yr): ?><option value="<?= master_du_e($yr['label']) ?>" <?= $yr['label']===$selectedYear?'selected':'' ?>><?= master_du_e($yr['label']) ?> · <?= master_du_e(strtoupper($yr['status'])) ?></option><?php endforeach; ?></select></form><span class="recap-status <?= $year['status']==='draft'?'is-partial':($year['status']==='published'?'is-paid':'is-unpaid') ?>"><?= master_du_e(strtoupper($year['status'])) ?></span></div></div></div>

<div class="main-card master-modern-card master-modern-form"><div class="card-title-row"><div><div class="card-title">Tarif Kelas 1–6</div><p class="payment-auto-note">Jumlah siswa dibaca langsung dari Data Siswa aktif. Pastikan kelas siswa sudah benar sebelum menerbitkan tagihan.</p></div></div><form method="post" id="du-rate-form"><input type="hidden" name="csrf_token" value="<?= master_du_e($_SESSION['csrf_master_du']) ?>"><input type="hidden" name="aksi" value="<?= $year['status']==='draft'?'simpan_dan_terbitkan':'simpan_tarif' ?>"><input type="hidden" name="tahun_ajaran" value="<?= master_du_e($selectedYear) ?>"><div class="du-rate-grid"><?php for($c=1;$c<=6;$c++): ?><label class="field-row"><span class="field-label">Kelas <?= $c ?></span><input class="field-input rupiah-input" name="jumlah[<?= $c ?>]" inputmode="numeric" value="<?= $masters[$c]>0?number_format($masters[$c],0,',','.') : '' ?>" placeholder="Rp 0" <?= $year['status']==='closed'?'disabled':'' ?>><small><?= $activeByClass[$c] ?> siswa aktif · estimasi Rp <?= number_format($activeByClass[$c]*$masters[$c],0,',','.') ?></small></label><?php endfor; ?></div><?php if($year['status']!=='closed'): ?><div class="action-bar"><button class="btn btn-primary" type="submit"><?= $year['status']==='draft'?'Simpan & Terbitkan Tagihan':'Simpan Perubahan Tarif' ?></button></div><?php endif; ?></form></div>

<div class="main-card master-modern-card"><div class="card-title-row"><div><div class="card-title">Penerbitan Tagihan</div><p class="payment-auto-note"><?= $activeTotal ?> siswa aktif · <?= (int)$billSummary['bills'] ?> tagihan terbit · Total Rp <?= number_format((float)$billSummary['total'],0,',','.') ?> · Terbayar Rp <?= number_format((float)$billSummary['paid'],0,',','.') ?></p><?php if($year['status']==='draft'): ?><p class="payment-auto-note">Gunakan tombol Simpan & Terbitkan Tagihan di atas. Tarif, penempatan internal, dan tagihan siswa dibuat dalam satu proses.</p><?php endif; ?></div><div class="action-bar"><?php if($year['status']==='published'): ?><form method="post" onsubmit="return confirm('Tutup tahun ajaran? Tunggakan tetap dapat dibayar, tetapi tarif tidak dapat diubah lagi.')"><input type="hidden" name="csrf_token" value="<?= master_du_e($_SESSION['csrf_master_du']) ?>"><input type="hidden" name="aksi" value="tutup"><input type="hidden" name="tahun_ajaran" value="<?= master_du_e($selectedYear) ?>"><button class="btn btn-warning" type="submit">Tutup Tahun Ajaran</button></form><?php elseif($year['status']==='closed'): ?><form method="post" onsubmit="return confirm('Buka kembali tahun ajaran ini? Histori pembayaran tetap aman dan status kembali menjadi terbit.')"><input type="hidden" name="csrf_token" value="<?= master_du_e($_SESSION['csrf_master_du']) ?>"><input type="hidden" name="aksi" value="buka"><input type="hidden" name="tahun_ajaran" value="<?= master_du_e($selectedYear) ?>"><button class="btn btn-primary" type="submit">Buka Kembali</button></form><?php endif; ?></div></div></div>
</main></div><script src="assets/js/app.js?v=4.6"></script><script>document.querySelectorAll('.rupiah-input').forEach(function(el){el.addEventListener('input',function(){var n=this.value.replace(/\D/g,'');this.value=n?Number(n).toLocaleString('id-ID'):'';});});document.getElementById('du-rate-form')?.addEventListener('submit',function(){this.querySelectorAll('.rupiah-input').forEach(function(el){el.value=el.value.replace(/\./g,'');});});autoHideFlash();</script></body></html>
