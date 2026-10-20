<?php
/** Operational capabilities do not change an account's recorded role. */
function operational_role(?string $role = null): bool {
    return in_array($role ?? ($_SESSION['admin_role'] ?? ''), ['super_admin', 'admin'], true);
}

function operational_unit_bound_route(string $path): bool {
    $path = str_replace('\\', '/', (string)(parse_url($path, PHP_URL_PATH) ?? ''));
    return preg_match('~/(master_(kelas|spp|biaya_lain|daftar_ulang)\.php|siswa/(daftar|aktivasi_legacy|koreksi_psb)\.php|pembayaran/(proses|edit)\.php|tabungan/(proses|koreksi)\.php|legacy_import_api\.php)$~D', $path) === 1;
}

/** A form from another tab must never silently write to the newly selected unit. */
function operational_request_guard(): void {
    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || !operational_role() || !operational_unit_bound_route($_SERVER['SCRIPT_NAME'] ?? '')) return;
    $unit = $_POST['_operational_unit'] ?? null;
    if (!is_string($unit) || !in_array($unit, ['1','2','3'], true) || (int)$unit !== unit_active_id()) {
        http_response_code(409);
        $message = 'Unit formulir sudah berubah atau formulir kedaluwarsa. Muat ulang halaman sebelum menyimpan.';
        if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'legacy_import_api.php') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error'=>$message], JSON_UNESCAPED_UNICODE);
        } else {
            if (str_ends_with($_SERVER['SCRIPT_NAME'] ?? '', '/pembayaran/proses.php')) {
                require_once __DIR__.'/payment_form_feedback.php';
                $draftUnit=is_string($unit)&&in_array($unit,['1','2','3'],true)?(int)$unit:unit_active_id();
                if(in_array($draftUnit,[1,2,3],true))$_SESSION['payment_drafts_by_unit'][$draftUnit]=payment_capture_draft($_POST);
                header('Content-Type: text/html; charset=utf-8');
                echo '<!doctype html><html lang="id" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Periksa unit pembayaran</title><link rel="stylesheet" href="../assets/css/style.css"></head><body><main class="main-card" style="max-width:680px;margin:8vh auto;padding:24px"><h1>Pembayaran belum disimpan</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p><p>Isian disimpan untuk unit asal. Pilih kembali unit tersebut, lalu periksa formulir sebelum menyimpan.</p><a class="btn btn-primary" href="form.php">Kembali ke Input Pembayaran</a></main></body></html>';
            } else {header('Content-Type: text/plain; charset=utf-8');echo $message;}
        }
        exit;
    }
}

/** Server-rendered markers also protect native forms when JavaScript is unavailable. */
function operational_form_scope(): void {
    if (PHP_SAPI === 'cli' || !operational_role()) return;
    $path = str_replace('\\','/',$_SERVER['SCRIPT_NAME']??'');
    if (!preg_match('~/(master_(kelas|spp|biaya_lain|daftar_ulang)\.php|siswa/(daftar|aktivasi_legacy|koreksi_psb)\.php|pembayaran/(form|edit|lihat)\.php|tabungan/(masuk|keluar|pemilik|koreksi)\.php|otorisasi_transaksi\.php)$~D', $path)) return;
    $unit = unit_active_id();
    ob_start(static function (string $html) use ($unit): string {
        if (!preg_match('/<html\b/i', $html)) return $html;
        return preg_replace_callback('/<form\b[^>]*>.*?<\/form>/is', static function (array $match) use ($unit): string {
            if (!preg_match('/\bmethod\s*=\s*(["\x27]?)post\1(?:\s|>)/i', $match[0])) return $match[0];
            if (str_contains($match[0],'name="_operational_unit"')) return $match[0];
            return preg_replace('/(<form\b[^>]*>)/i','$1<input type="hidden" name="_operational_unit" value="'.$unit.'">',$match[0],1);
        }, $html) ?? $html;
    });
}
