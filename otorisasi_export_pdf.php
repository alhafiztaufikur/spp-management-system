<?php
session_start();
require_once __DIR__.'/koneksi.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/authorization_presentation.php';
require_once __DIR__.'/includes/authorization_export_selection.php';
if (!unit_is_super()) { http_response_code(403); exit('Hanya Super Admin yang dapat mengekspor PDF Otorisasi.'); }
requireRole(['super_admin']);
header('Cache-Control: no-store, private');
if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');exit('Gunakan GET atau POST untuk membaca laporan.');}
$selection=null;$selectionToken=null;$scope=unit_active_id();$actor=(int)$_SESSION['admin_id'];
try {
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        $csrf=$_POST['csrf_token']??null;
        if (!is_string($csrf) || !isset($_SESSION['csrf_transaction_authorization']) || !hash_equals($_SESSION['csrf_transaction_authorization'],$csrf)) {
            http_response_code(403);exit('Sesi cetak tidak valid. Muat ulang halaman Otorisasi.');
        }
        if (!is_string($_POST['transactions']??null)) throw new InvalidArgumentException('Pilih transaksi yang ingin dicetak.');
        $selection=authorization_export_selection($_POST['transactions']);
        $_GET=array_intersect_key($_POST,array_flip(['menu','source','kind','status','q']));
    } elseif (array_key_exists('selection_token',$_GET)) {
        $entry=authorization_export_load_token($_GET['selection_token'],$actor,$scope);
        $selection=$entry['keys'];$selectionToken=$_GET['selection_token'];
        $_GET=$entry['query']+array_intersect_key($_GET,array_flip(['output']));
    }
    if (isset($_GET['q']) && !is_string($_GET['q'])) throw new InvalidArgumentException('Pencarian tidak valid.');
} catch (InvalidArgumentException $error) {http_response_code(400);exit(authorization_escape($error->getMessage()));}
$statuses=filter_register('status',$_GET['status']??null,array_fill_keys(['pending','approved','rejected','cancelled','failed'],''),'all','all');
$kinds=filter_register('kind',$_GET['kind']??null,['edit'=>'Edit','hapus'=>'Hapus'],'all','all');
foreach($statuses as $choice)if($choice!=='*'&&!in_array($choice,['pending','approved','rejected','cancelled','failed'],true))filter_choice_error('status');
foreach($kinds as $choice)if($choice!=='*'&&!in_array($choice,['edit','hapus'],true))filter_choice_error('jenis perubahan');
$status=filter_scalar($statuses,'all');$kind=filter_scalar($kinds,'all');
try{$source=authorization_source($_GET['source']??'all',true);$menu=authorization_menu($_GET['menu']??'all');}catch(InvalidArgumentException $e){http_response_code(400);exit(authorization_escape($e->getMessage()));}
require_once __DIR__.'/includes/report_artifact.php';
if($_SERVER['REQUEST_METHOD']==='GET'&&session_status()===PHP_SESSION_ACTIVE)session_write_close();
report_perf_mark('validate');
$q=trim((string)($_GET['q']??''));$models=[];$unmatched=$selection??[];
try {
    // All pages and event details belong to the same consistent read snapshot.
    $koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    $first=authorization_combined_page($koneksi,true,$source,$kind,$q,1,$statuses,$menu);
    for($page=1;$page<=$first['pages'];$page++) {
        $result=$page===1?$first:authorization_combined_page($koneksi,true,$source,$kind,$q,$page,$statuses,$menu);
        foreach($result['rows'] as $row) {
            $key=($row['source']==='pembayaran'?'':$row['source'].'|').(int)$row['unit_id'].'|'.(int)$row['payment_id'];
            if ($selection!==null && !isset($selection[$key])) continue;
            unset($unmatched[$key]);
            authorization_read_unit($koneksi,(string)$row['unit_id']);
            $model=authorization_detail_model($koneksi,(int)$row['payment_id'],false,$row['source']);$model['menu']=$row['menu'];$models[]=$model;
            unit_set_context($koneksi,$scope);
        }
    }
    if ($unmatched) throw new InvalidArgumentException('Sebagian pilihan tidak termasuk hasil filter atau unit yang diizinkan. Pilih kembali transaksi.');
    $koneksi->commit();
}catch(InvalidArgumentException $e){$koneksi->rollback();unit_set_context($koneksi,$scope);http_response_code(400);exit(authorization_escape($e->getMessage()));}
catch(Throwable $e){try{$koneksi->rollback();}catch(Throwable $ignored){}unit_set_context($koneksi,$scope);error_log('PDF otorisasi: '.$e->getMessage());http_response_code(503);exit('Riwayat belum dapat diekspor. Silakan coba kembali.');}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $selectionToken=authorization_export_save_token($selection,['menu'=>$menu,'source'=>$source,'kind'=>$kinds,'status'=>$statuses,'q'=>$q],$actor,$scope);
    header('Location: otorisasi_export_pdf.php?'.http_build_query(['selection_token'=>$selectionToken]),true,303);exit;
}
report_perf_mark('query_model');
$selectionLabel=$selection!==null?'transaksi terpilih':'transaksi hasil filter (seluruh halaman)';
$palette=[0=>'6d28d9',1=>'12844b',2=>'244bb5',3=>'b62735'];$color=$palette[$scope]??$palette[1];
$generated=spp_date_label(new DateTimeImmutable('now'),true);
require_once __DIR__.'/includes/authorization_pdf.php';
$logo=__DIR__.'/assets/img/favicon.png';
$html=authorization_pdf_html($models,['color'=>$color,'school'=>unit_school_name($scope),'unit'=>unit_label($scope),'generated'=>$generated,
    'operator'=>$_SESSION['admin_nama']??'Tidak tercatat','menu'=>authorization_menu_label($menu),'logo'=>is_file($logo)?'data:image/png;base64,'.base64_encode(file_get_contents($logo)):'']);
$query=['menu'=>$menu,'source'=>$source,'kind'=>$kinds,'status'=>$statuses,'q'=>$q];
$downloadQuery=$selectionToken!==null?['selection_token'=>$selectionToken]:$query;
if(($_GET['output']??'preview')==='preview') {
    require_once __DIR__.'/includes/report_preview.php';
    render_report_pdf_preview($html,['title'=>'Riwayat Otorisasi Transaksi','subtitle'=>unit_label($scope).' · '.count($models).' '.$selectionLabel,
        'generated'=>$generated,'row_count'=>count($models),'orientation'=>'landscape','unit'=>$scope,
        'pdf_url'=>'otorisasi_export_pdf.php?'.filter_build_query($downloadQuery+['output'=>'inline']),
        'download_url'=>'otorisasi_export_pdf.php?'.filter_build_query($downloadQuery+['output'=>'pdf']),
        'back_url'=>'otorisasi_transaksi.php?'.filter_build_query($query+['view'=>'history'])]);
}
require_once __DIR__.'/includes/pdf.php';require_pdf_library();
$artifact=report_artifact_get(['route'=>'authorization','unit'=>$scope,'query'=>$query,'selection'=>$selection],['models'=>$models,'operator'=>$_SESSION['admin_nama']??'Tidak tercatat','school'=>unit_school_name($scope)],'pdf',static function()use($html){
    $options=new \Dompdf\Options();$options->setIsRemoteEnabled(false);$options->setChroot(__DIR__);
    $pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4','landscape');$pdf->render();
    $pdf->getCanvas()->page_text(705,571,'Halaman {PAGE_NUM} / {PAGE_COUNT}',$pdf->getFontMetrics()->getFont('DejaVu Sans'),8,[.3,.4,.45]);
    report_perf_mark('pdf');return $pdf->output();
});
report_artifact_stream($artifact,'riwayat-otorisasi.pdf','pdf',($_GET['output']??'pdf')!=='inline');
