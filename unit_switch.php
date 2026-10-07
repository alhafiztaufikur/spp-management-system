<?php
session_start();
require_once 'koneksi.php';
require_once 'includes/auth.php';
requireRole(['super_admin']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || empty($_SESSION['csrf_unit_switch'])
    || !hash_equals((string)($_SESSION['csrf_unit_switch'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403); exit('Permintaan tidak valid.');
}
$unitId=filter_input(INPUT_POST,'unit_id',FILTER_VALIDATE_INT);
if (!in_array($unitId,[0,1,2,3],true)) { http_response_code(422); exit('Unit tidak valid.'); }
$next=(string)($_POST['next'] ?? 'dashboard.php');
$base=rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'])), '/');
if ($base==='.') $base='';
if (!str_starts_with($next,$base.'/') || str_starts_with($next,'//') || str_contains($next,"\r") || str_contains($next,"\n")) {
    $next=$base.'/dashboard.php';
}
$path=parse_url($next, PHP_URL_PATH);
if ($unitId === 0 && unit_transaction_route(rawurldecode((string)$path))) {
    http_response_code(422); exit('Transaksi wajib memilih SD, SMP, atau SMA.');
}
parse_str((string)(parse_url($next, PHP_URL_QUERY) ?? ''), $params);
// The account-list unit filter belongs to the previous operational scope.
if (str_ends_with((string)$path, '/role_management.php')) unset($params['account_unit']);
foreach (['unit','unit_id','kelas','master_kelas_id','tingkat_kelas','q_kelas','operator','kategori','komponen_tagihan','q','search','nis','student_id','student_unit','jenis','saldo_kelas','saldo_status','id','edit','action','aksi','page','view','detail','batch'] as $key) unset($params[$key]);
// An edit page requires a payment ID: switching returns to its safe list.
if (str_ends_with((string)$path, '/pembayaran/edit.php')) $path=$base.'/pembayaran/lihat.php';
$next=$path.($params?'?'.http_build_query($params):'');
$_SESSION['active_unit_id']=$unitId;
unit_set_context($koneksi,$unitId);
header('Location: '.$next, true, 303);
