<?php
session_start();
require_once __DIR__.'/koneksi.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/authorization_presentation.php';
if (!unit_is_super()) { http_response_code(403); exit('Hanya Super Admin yang dapat mengekspor PDF Otorisasi.'); }
requireRole(['super_admin']);
header('Cache-Control: no-store, private');
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');exit('Gunakan GET untuk membaca laporan.');}
$statuses=filter_register('status',$_GET['status']??null,array_fill_keys(['pending','approved','rejected','cancelled','failed'],''),'all','all');
$kinds=filter_register('kind',$_GET['kind']??null,['edit'=>'Edit','hapus'=>'Hapus'],'all','all');
foreach($statuses as $choice)if($choice!=='*'&&!in_array($choice,['pending','approved','rejected','cancelled','failed'],true))filter_choice_error('status');
foreach($kinds as $choice)if($choice!=='*'&&!in_array($choice,['edit','hapus'],true))filter_choice_error('jenis perubahan');
$status=filter_scalar($statuses,'all');$kind=filter_scalar($kinds,'all');
$q=trim((string)($_GET['q']??''));$scope=unit_active_id();$models=[];
try {
    // All pages and event details belong to the same consistent read snapshot.
    $koneksi->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    $first=authorization_history_page($koneksi,$status,$kind,$q,1,$statuses);
    for($page=1;$page<=$first['pages'];$page++) {
        $result=$page===1?$first:authorization_history_page($koneksi,$status,$kind,$q,$page,$statuses);
        foreach($result['rows'] as $row) {
            authorization_read_unit($koneksi,(string)$row['unit_id']);
            $models[]=authorization_detail_model($koneksi,(int)$row['payment_id'],false);
            unit_set_context($koneksi,$scope);
        }
    }
    $koneksi->commit();
}catch(Throwable $e){try{$koneksi->rollback();}catch(Throwable $ignored){}unit_set_context($koneksi,$scope);error_log('PDF otorisasi: '.$e->getMessage());http_response_code(503);exit('Riwayat belum dapat diekspor. Silakan coba kembali.');}
$palette=[0=>'6d28d9',1=>'12844b',2=>'244bb5',3=>'b62735'];$color=$palette[$scope]??$palette[1];
$kindLabel=filter_is_all($kinds)?'Semua perubahan':implode(', ',array_map(static fn($v)=>$v==='edit'?'Edit':'Hapus',$kinds));
$statusLabel=filter_is_all($statuses)?'Semua status':implode(', ',array_map('transaction_authorization_status_label',$statuses));
$generated=spp_date_label(new DateTimeImmutable('now'),true);
ob_start(); ?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><style>
@page { margin:22mm 13mm 18mm; } body { font-family:DejaVu Sans,sans-serif;font-size:10px;color:#233642;line-height:1.5; }
h1 { color:#<?= $color ?>;font-size:21px;margin:8px 0; } h2 { font-size:15px;color:#<?= $color ?>;margin:20px 0 8px; } h3 { font-size:12px;margin:12px 0 4px; }
.identity { border-bottom:2px solid #<?= $color ?>;padding-bottom:10px; } .logo { width:42px;float:left;margin-right:14px; }
.meta { color:#5a6c78;margin:10px 0 16px; } table { width:100%;border-collapse:collapse;table-layout:fixed;margin:7px 0 14px; } thead { display:table-header-group; }
th { background:#<?= $color ?>;color:white;text-align:center; } td,th { border:1px solid #dbe3e8;padding:7px;overflow-wrap:break-word;word-wrap:break-word;vertical-align:top; } tr:nth-child(even) td { background:#f4f7f9; }
.muted { color:#5d6d78; } .event { border-left:3px solid #<?= $color ?>;padding:8px 12px;margin:10px 0;background:#f4f7f9; } .note { white-space:pre-wrap;word-wrap:break-word; }
.detail-title { page-break-after:avoid; } .empty { padding:25px;background:#f4f7f9; } .reference { width:19%; }.actor { width:22%; }.time { width:19%; }
</style></head><body><header class="identity">
<?php $logo=__DIR__.'/assets/img/favicon.png';if(is_file($logo)): ?><img class="logo" src="data:image/png;base64,<?= base64_encode(file_get_contents($logo)) ?>"><?php endif; ?>
<strong><?= authorization_escape(unit_school_name($scope)) ?></strong><br>Unit: <?= authorization_escape(unit_label($scope)) ?><h1>Riwayat Otorisasi Transaksi</h1></header>
<div class="meta">Dibuat: <?= authorization_escape($generated) ?> · Petugas: <?= authorization_escape($_SESSION['admin_nama']??'Tidak tercatat') ?><br>Jenis: <?= authorization_escape($kindLabel) ?> · Status: <?= authorization_escape($statusLabel) ?><br>Pencarian: <?= authorization_escape($q?:'Tidak dibatasi') ?> · Total: <?= count($models) ?> transaksi hasil filter (seluruh halaman).</div>
<p class="muted">Filter mencocokkan aktivitas dalam riwayat. Ringkasan menunjukkan aktivitas terakhir setiap transaksi; kronologi di bawah mencakup seluruh bukti aktivitas yang tersedia.</p>
<h2>Ringkasan Transaksi</h2><table><thead><tr><th style="width:4%">No.</th><th class="reference">Transaksi / Siswa</th><th style="width:7%">Unit</th><th>Aktivitas terakhir</th><th class="actor">Operator</th><th class="time">Waktu WIB</th></tr></thead><tbody>
<?php foreach($models as $index=>$model): $event=$model['latest']; ?><tr><td><?= $index+1 ?></td><td><strong><?= authorization_escape($model['reference']) ?></strong><br><?= authorization_escape($model['student']) ?><br>NIS <?= authorization_escape($model['nis']) ?></td><td><?= authorization_escape($model['unit']) ?></td><td><?= authorization_escape($event['label']) ?><br><?= $event['authorization_id']?'Pengajuan #'.(int)$event['authorization_id']:'Perubahan langsung' ?></td><td><?= authorization_escape($event['name']) ?><br><?= authorization_escape($event['username']?'@'.$event['username']:'Tidak tercatat') ?><br><?= authorization_escape(authorization_role_label($event['role'])) ?></td><td><?= authorization_escape($event['time']) ?></td></tr><?php endforeach; ?>
<?php if(!$models): ?><tr><td colspan="6">Tidak ada riwayat yang cocok dengan filter.</td></tr><?php endif; ?></tbody></table>
<?php foreach($models as $model): ?><h2 class="detail-title"><?= authorization_escape($model['reference'].' · '.$model['student'].' · '.$model['unit']) ?></h2><p>NIS <?= authorization_escape($model['nis']) ?></p>
<?php foreach($model['events'] as $event): ?><div class="event"><strong><?= authorization_escape($event['label']) ?></strong> · <?= authorization_escape($event['time']) ?><br><?= $event['proposed']?'Pemohon':'Pelaksana / pemberi keputusan' ?>: <?= authorization_escape($event['name']) ?> · <?= authorization_escape($event['username']?'@'.$event['username']:'Tidak tercatat') ?> · <?= authorization_escape(authorization_role_label($event['role'])) ?>
<?php if($event['authorization_id']): ?><br>Pengajuan #<?= (int)$event['authorization_id'] ?><?php endif; ?>
<?php if($event['proposed']): ?><br><em>Usulan pemohon; belum berarti perubahan diterapkan.</em><?php endif; ?>
<?php if($event['reconstructed']): ?><br><em>Direkonstruksi dari bukti data lama.</em><?php endif; ?>
<?php if($event['note']!==''): ?><p class="note">Alasan / catatan: <?= authorization_escape($event['note']) ?></p><?php endif; ?></div>
<?php if($event['changes']): foreach($event['changes'] as $change): ?><h3 class="detail-title"><?= authorization_escape($change['label']) ?></h3><p class="note"><strong>Sebelum:</strong><br><?= authorization_escape($change['before']) ?></p><p class="note"><strong><?= $event['proposed']?'Usulan:':'Sesudah:' ?></strong><br><?= authorization_escape($change['after']) ?></p><?php endforeach; ?><?php elseif(in_array($event['action'],['edited','request_edit'],true)): ?><p class="muted">Rincian perubahan tidak tercatat.</p><?php endif; ?>
<?php endforeach; endforeach; ?></body></html>
<?php $html=ob_get_clean();
$query=['kind'=>$kind,'status'=>$status,'q'=>$q];
if(($_GET['output']??'preview')==='preview') {
    require_once __DIR__.'/includes/report_preview.php';
    render_report_pdf_preview($html,['title'=>'Riwayat Otorisasi Transaksi','subtitle'=>unit_label($scope).' · '.count($models).' transaksi hasil filter',
        'generated'=>$generated,'row_count'=>count($models),'orientation'=>'landscape',
        'download_url'=>'otorisasi_export_pdf.php?'.filter_build_query($query+['output'=>'pdf']),
        'back_url'=>'otorisasi_transaksi.php?'.filter_build_query($query+['view'=>'history'])]);
}
require_once __DIR__.'/includes/pdf.php';require_pdf_library();
$options=new \Dompdf\Options();$options->setIsRemoteEnabled(false);$options->setChroot(__DIR__);
$pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4','landscape');$pdf->render();
$pdf->getCanvas()->page_text(740,570,'Halaman {PAGE_NUM} / {PAGE_COUNT}',$pdf->getFontMetrics()->getFont('DejaVu Sans'),8,[.3,.4,.45]);
$pdf->stream('riwayat-otorisasi-'.date('Ymd-His').'.pdf',['Attachment'=>($_GET['output']??'pdf')!=='inline']);exit;
