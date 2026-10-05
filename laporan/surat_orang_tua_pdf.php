<?php
session_start();
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/report_letters.php';
require_once __DIR__.'/../includes/pdf.php';
requireRole(['admin','bendahara','kasir']);

require_once __DIR__.'/../includes/parent_letter_drafts.php';
$token=(string)($_GET['draft']??'');
if($token==='') { header('Location: surat_orang_tua_susun.php?'.http_build_query($_GET));exit; }
try { $draft=parent_letter_draft_read($token); }
catch(Throwable $e){http_response_code(400);header('Content-Type: text/html; charset=utf-8');exit('<!doctype html><html lang="id"><meta charset="utf-8"><p>'.report_e($e->getMessage()).'</p><a href="surat_orang_tua.php">Kembali ke daftar</a></html>');}
$students=$draft['students'];$today=$draft['today'];$messages=$draft['messages'];
$safeName='surat-orang-tua-'.str_replace('-','',$today).'-'.count($students).'-siswa';
header('Cache-Control: no-store, private');
session_write_close();
require_pdf_library();
// Satu PDF massal dapat berisi ratusan lembar; naikkan batas hanya untuk permintaan ini.
$memoryLimit=trim((string)ini_get('memory_limit'));
if(count($students)>50&&$memoryLimit!=='-1'){
    $unit=strtoupper(substr($memoryLimit,-1));
    $factor=match($unit){'G'=>1073741824,'M'=>1048576,'K'=>1024,default=>1};
    if((int)$memoryLimit*$factor<384*1048576)ini_set('memory_limit','384M');
}
$options=new \Dompdf\Options();
$options->set('isRemoteEnabled',false);
$options->set('isHtml5ParserEnabled',true);
$options->setChroot(realpath(__DIR__.'/..'));
$pdf=new \Dompdf\Dompdf($options);
$pdf->loadHtml(report_parent_letters_html($students,$today,$messages),'UTF-8');
$pdf->setPaper('A4','portrait');
$pdf->render();
header('Cache-Control: no-store, private');
$pdf->stream($safeName.'.pdf',['Attachment'=>(($_GET['download']??'')==='1')]);
