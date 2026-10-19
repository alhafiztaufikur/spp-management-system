<?php
session_start();require_once __DIR__.'/koneksi.php';require_once __DIR__.'/includes/legacy_finance_http.php';
header('Content-Type: application/json; charset=utf-8');
try{
    lf_http_bootstrap($koneksi);
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)){http_response_code(405);throw new DomainException('Metode tidak diizinkan.');}
    lf_require_schema($koneksi);$write=$_SERVER['REQUEST_METHOD']==='POST';$action=$write?($_POST['action']??''):($_GET['action']??'reviews');
    if(!is_string($action))throw new DomainException('Tindakan tidak valid.');
    if($write){echo lf_json(lf_http_mutate($koneksi,$action,$_POST));exit;}
    if($action==='student_search'){echo lf_json(lf_student_search($koneksi,$_GET));exit;}
    if($action==='archive'||$action==='csv'){
        $result=lf_archive_search($koneksi,$_GET,$action==='csv');
        if($action==='csv'){
            header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="arsip-keuangan-legacy.csv"');
            $out=fopen('php://output','w');fputcsv($out,['Unit','Hash Backup','Tabel','Kunci Baris','Kemunculan','NIS Sumber','Tanggal Sumber','Operator Sumber','Nominal Asli','Data Asli','Referensi Saja']);
            while($r=$result['result']->fetch_assoc())fputcsv($out,array_map('lf_csv_cell',[$r['unit_id'],$r['backup_hash'],$r['source_table'],$r['row_key'],$r['occurrence'],$r['source_nis'],$r['source_date'],$r['source_operator'],$r['amounts'],$r['raw_data'],$r['reference_only']]));exit;
        }echo lf_json($result);exit;
    }
    if($action==='review'){
        if(!is_string($_GET['id']??null)||!ctype_digit($_GET['id']))throw new DomainException('ID tidak valid.');
        $r=lf_review_load($koneksi,(int)$_GET['id']);$r['position']=$r['student']?lf_existing_position($koneksi,$r['student']):null;echo lf_json(['review'=>$r]);exit;
    }
    if($action==='reviews'){
        echo lf_json(['reviews'=>lf_rows($koneksi,'SELECT r.id,r.version,r.status,r.savings_result,m.source_id,m.source_nis,m.source_name,m.student_id,m.match_status,m.reason,s.unit_id,s.source_date FROM legacy_finance_reviews r JOIN legacy_finance_maps m ON m.id=r.map_id AND m.unit_id=r.unit_id JOIN legacy_finance_sources s ON s.id=m.source_id AND s.unit_id=m.unit_id WHERE s.published=1 ORDER BY r.id LIMIT 100')]);exit;
    }
    throw new DomainException('Tindakan tidak tersedia.');
}catch(DomainException $e){if(http_response_code()<400)http_response_code(in_array($e->getCode(),[400,403,404,409,503],true)?$e->getCode():409);echo lf_json(['error'=>$e->getMessage(),'code'=>'request_failed','action'=>legacy_ui_problem('request_failed')]);}
catch(Throwable $e){error_log('Legacy finance: '.$e->getMessage());http_response_code(500);echo lf_json(['error'=>'Permintaan gagal. Periksa status sebelum mencoba kembali.']);}
