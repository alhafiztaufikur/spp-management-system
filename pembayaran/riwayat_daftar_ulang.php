<?php
session_start();
if(empty($_SESSION['admin_id'])){header('Location: ../login.php');exit;}
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/transaction_authorization.php';
require_once __DIR__.'/../includes/registration_history.php';
requireRole(['admin','kasir','bendahara']);
if(empty($_SESSION['csrf_payment']))$_SESSION['csrf_payment']=bin2hex(random_bytes(32));
try {
    if(isset($_GET['q_plain'])) {
        if(!is_scalar($_GET['q_plain']))throw new InvalidArgumentException('Pencarian tidak valid.');
        if ((string)$_GET['q_plain'] !== (string)($_GET['q'] ?? '')) unset($_GET['student_id']);
        $_GET['q']=(string)$_GET['q_plain'];unset($_GET['q_plain']);
    }
    $f=registration_filters($koneksi,$_GET);$view=$f['view'];$deleted=$view==='deleted';
    $result=registration_page($koneksi,$f);extract($result);$f['page']=$page;
    $query=registration_filter_query($f);
    $selectedId=max(0,(int)($_GET['selected']??0));$selectedUnit=max(0,(int)($_GET['selected_unit']??0));
    $chosen=null;
    foreach($rows as $row)if((int)$row['tagihan_id']===$selectedId && (!$selectedUnit||(int)$row['unit_id']===$selectedUnit)){$chosen=$row;break;}
    $selectionMissing=$selectedId>0 && !$chosen;
    $chosen??=$rows[0]??null;$selectedId=(int)($chosen['tagihan_id']??0);$selectedUnit=(int)($chosen['unit_id']??unit_active_id());
    $detail=null;
    if($chosen){try{$detail=registration_detail($koneksi,$selectedId,$selectedUnit,$view);}catch(OutOfBoundsException $error){$detailError=$error->getMessage();}}
    $query['selected_unit']=$selectedUnit;
    $first=$summary['students']?$offset+1:0;$last=$summary['students']?$offset+count($rows):0;
    $students=$koneksi->query("SELECT DISTINCT s.id student_id,s.unit_id,s.NO_INDUK,s.NO_induk_diknas,s.NAMA,s.KELAS FROM siswa s JOIN tagihan_daftar_ulang t ON t.no_induk=s.NO_INDUK AND t.unit_id=s.unit_id WHERE t.status='open' ORDER BY s.NAMA")->fetch_all(MYSQLI_ASSOC);
    $studentDisplay=$f['student']['NAMA']??$f['q'];
    $flash=$_SESSION['flash']??null;unset($_SESSION['flash']);
    filter_output_start();include __DIR__.'/../includes/registration_history_page.php';
} catch(InvalidArgumentException $error) {
    http_response_code(400);echo '<!doctype html><meta charset="utf-8"><main><h1>Periksa filter</h1><p>'.htmlspecialchars($error->getMessage(),ENT_QUOTES,'UTF-8').'</p><a href="riwayat_daftar_ulang.php">Kembali ke Riwayat Daftar Ulang</a></main>';
}
