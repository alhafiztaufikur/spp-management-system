<?php
session_start();
require_once 'koneksi.php';
require_once 'includes/auth.php';
require_once 'includes/reports.php';
requireRole(['admin','kasir']);

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Metode permintaan tidak didukung.');
    $token=(string)($_POST['csrf_token']??'');
    if(empty($_SESSION['csrf_prior_debt'])||!hash_equals($_SESSION['csrf_prior_debt'],$token)){
        throw new RuntimeException('Sesi pemeriksaan telah kedaluwarsa. Muat ulang halaman dan coba lagi.');
    }
    $targetYear=du_normalize_academic_year((string)($_POST['tahun_ajaran']??''));
    $scope=(string)($_POST['scope']??'selected');
    if($scope==='all_active_regular'){
        $students=report_active_regular_student_nis($koneksi);
    }else{
        $students=is_array($_POST['selected_students']??null)?array_values(array_unique(array_map('strval',$_POST['selected_students']))):[];
    }
    if(!$students)throw new RuntimeException('Belum ada siswa yang dipilih untuk diterbitkan.');
    echo json_encode(['ok'=>true]+report_prior_debt_summary($koneksi,$targetYear,$students),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(Throwable $error) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'message'=>$error->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
