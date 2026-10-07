<?php
require_once __DIR__.'/excel_test_helpers.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../koneksi.php';
require_once __DIR__.'/../includes/reports.php';

function letter_http_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function letter_http_get(string $url,string $cookie=''):array{
    $options=['http'=>['method'=>'GET','ignore_errors'=>true,'follow_location'=>0,'timeout'=>20,'header'=>$cookie!==''?'Cookie: '.session_name().'='.$cookie."\r\n":'']];
    $body=file_get_contents($url,false,stream_context_create($options));
    $headers=$http_response_header??[];
    return [$headers,$body===false?'':$body];
}
function letter_http_header(array $headers,string $name):string{
    foreach($headers as $header)if(stripos($header,$name.':')===0)return trim(substr($header,strlen($name)+1));
    return '';
}
function letter_http_end_session(string $id):void{session_id($id);session_start();$_SESSION=[];session_destroy();session_write_close();}

try{
    $base=rtrim((string)(getenv('SPP_HTTP_BASE')?:'http://localhost/spp-management-system'),'/').'/';
    $students=report_student_debt_groups($koneksi,report_filters($koneksi,['siswa_status'=>'active']),'',[],report_letter_today());
    letter_http_assert((bool)$students,'Butuh satu siswa dengan tunggakan untuk uji HTTP.');
    $debtorIds=array_fill_keys(array_column($students,'nis'),true);
    $paidOrNotDue=null;
    foreach($koneksi->query('SELECT NO_INDUK FROM siswa WHERE is_active=1') as $studentRow){
        if(!isset($debtorIds[$studentRow['NO_INDUK']])){$paidOrNotDue=$studentRow['NO_INDUK'];break;}
    }
    [$headers]=letter_http_get($base.'laporan/surat_laporan.php');
    letter_http_assert(str_contains($headers[0]??'','302'),'Katalog surat tanpa login tidak ditolak.');
    [$headers]=letter_http_get($base.'laporan/surat_orang_tua.php');
    letter_http_assert(str_contains($headers[0]??'','302'),'Halaman surat tanpa login tidak ditolak.');
    $accountIds=[];
    foreach($koneksi->query("SELECT id,role FROM admin WHERE unit_id=1 AND is_active=1 AND role IN ('admin','bendahara','kasir') ORDER BY id") as $account){
        $accountIds[$account['role']]??=(int)$account['id'];
    }
    foreach(['admin','bendahara','kasir'] as $role){
        letter_http_assert(isset($accountIds[$role]),"Akun aktif SD untuk peran $role tidak tersedia.");
        $id='letterhttp'.bin2hex(random_bytes(8));
        session_id($id);session_start();
        $_SESSION=['admin_id'=>$accountIds[$role],'admin_role'=>$role,'admin_nama'=>'Uji Surat'];
        session_write_close();
        [$headers,$body]=letter_http_get($base.'laporan/surat_laporan.php',$id);
        letter_http_assert(str_contains($headers[0]??'','200')&&str_contains($body,'href="surat_orang_tua.php"')&&str_contains($body,'href="template.php?template=tunggakan-siswa"')&&str_contains($body,'href="../laporan/surat_laporan.php" class="nav-item active"'),"Katalog surat gagal untuk $role.");
        [$headers,$body]=letter_http_get($base.'laporan/surat_orang_tua.php',$id);
        letter_http_assert(str_contains($headers[0]??'','200')&&str_contains($body,'Cetak Surat ke Orang Tua')&&str_contains($body,'Kembali ke pilihan surat')&&str_contains($body,'href="../laporan/surat_laporan.php" class="nav-item active"'),"Halaman surat gagal untuk $role.");
        [$headers,$body]=letter_http_get($base.'laporan/template.php?template=tunggakan-siswa',$id);
        letter_http_assert(str_contains($headers[0]??'','200')&&str_contains($body,'Kembali ke pilihan surat')&&str_contains($body,'href="../laporan/surat_laporan.php" class="nav-item active"'),'URL rekap lama tidak mengarah ke Surat Laporan.');
        letter_http_assert(str_contains($body,'Seluruh Rombel Kelas')&&str_contains($body,'Rekap Tunggakan per Kelas/Rombel')&&!str_contains($body,$students[0]['nama']),'Pilihan surat kepala sekolah masih menampilkan rincian siswa.');
        if($role!=='kasir'){letter_http_end_session($id);continue;}
        preg_match('/href="([^"]*mode=filtered[^"]*)"[^>]*>Pratinjau Surat Pilihan<\/a>/', $body, $principalLink);
        letter_http_assert(isset($principalLink[1]),'Tautan pratinjau surat pilihan hilang.');
        [$headers,$preview]=letter_http_get($base.'laporan/'.html_entity_decode($principalLink[1],ENT_QUOTES|ENT_HTML5,'UTF-8'),$id);
        letter_http_assert(str_contains($headers[0]??'','200')&&str_contains($preview,'<iframe'),'Pratinjau surat pilihan gagal dibuka.');
        $classId=(int)$students[0]['master_kelas_id'];
        $classFilters=report_filters($koneksi,['kelas'=>'rombel:'.$classId,'siswa_status'=>'active']);
        $classRows=report_principal_debt_data($koneksi,$classFilters)['rows'];
        [$headers,$classPage]=letter_http_get($base.'laporan/template.php?template=tunggakan-siswa&kelas=rombel:'.$classId.'&siswa_status=active',$id);
        letter_http_assert(str_contains($headers[0]??'','200')&&str_contains($classPage,report_money(array_sum(array_column($classRows,'total_tunggakan'))))&&!str_contains($classPage,$students[0]['nama']),'Total pilihan satu rombel tidak sesuai atau rincian siswa masih muncul.');
        [$headers,$detail]=letter_http_get($base.'laporan/template.php?template=tunggakan-siswa&kelas=rombel:'.$classId.'&siswa_status=active&view=detail&detail=rombel%3A'.$classId,$id);
        letter_http_assert(str_contains($headers[0]??'','200')&&str_contains($detail,'Daftar Siswa Menunggak')&&str_contains($detail,htmlspecialchars($students[0]['nama'],ENT_QUOTES,'UTF-8')),'Detail rombel tidak memuat siswa sesuai pilihan.');
        [$headers,$legacyDetail]=letter_http_get($base.'laporan/template.php?template=tunggakan-siswa&kelas=rombel:'.$classId.'&siswa_status=active&q=nis-lama-tidak-ada&view=detail&detail=rombel%3A'.$classId,$id);
        letter_http_assert(str_contains($headers[0]??'','200')&&str_contains($legacyDetail,htmlspecialchars($students[0]['nama'],ENT_QUOTES,'UTF-8')),'Parameter pencarian lama menghilangkan detail tunggakan.');
        preg_match('/href="([^"]*view=detail[^"]*)"[^>]*>Export Excel<\/a>/', $detail, $detailExcelLink);
        letter_http_assert(isset($detailExcelLink[1]),'Tautan Excel detail rombel tidak ditemukan.');
        [$headers,$detailExcel]=letter_http_get($base.'laporan/'.html_entity_decode($detailExcelLink[1],ENT_QUOTES|ENT_HTML5,'UTF-8').'&download=1',$id);
        $detailExcel=test_excel_html($detailExcel);
        letter_http_assert(str_contains(strtolower(letter_http_header($headers,'Content-Disposition')),'attachment')
            && str_contains($detailExcel,htmlspecialchars($students[0]['nama'],ENT_QUOTES,'UTF-8')),
            'Excel detail rombel tidak sesuai.');
        [$headers]=letter_http_get($base.'laporan/export_global.php?template=tunggakan-siswa&format=preview&mode=single&nis='.rawurlencode($students[0]['nis']),$id);
        letter_http_assert(str_contains($headers[0]??'','400'),'Tautan surat per siswa lama masih diterima untuk kepala sekolah.');
        $single=$base.'laporan/surat_orang_tua_pdf.php?'.http_build_query(['mode'=>'single','nis'=>$students[0]['nis']]);
        [$headers,$body]=letter_http_get($single,$id);
        letter_http_assert(str_contains($headers[0]??'','200')&&str_starts_with($body,'%PDF-'),'PDF orang tua gagal.');
        letter_http_assert(str_contains(strtolower(letter_http_header($headers,'Content-Type')),'application/pdf'),'Tipe PDF orang tua salah.');
        letter_http_assert(str_contains(strtolower(letter_http_header($headers,'Content-Disposition')),'inline'),'PDF orang tua tidak inline.');
        if(count($students)>1){
            $selectedIds=[$students[0]['nis'],$students[count($students)-1]['nis']];
            [$headers,$body]=letter_http_get($base.'laporan/surat_orang_tua_pdf.php?'.http_build_query(['mode'=>'selected','nis'=>implode(',',$selectedIds)]),$id);
            letter_http_assert(str_starts_with($body,'%PDF-')&&str_contains(letter_http_header($headers,'Content-Disposition'),'dipilih-2-siswa'),'PDF pilihan lintas halaman gagal.');
        }
        [$headers,$body]=letter_http_get($base.'laporan/surat_orang_tua_pdf.php?'.http_build_query(['mode'=>'class','kelas'=>'rombel:'.$students[0]['master_kelas_id'],'q'=>$students[0]['nis']]),$id);
        letter_http_assert(str_starts_with($body,'%PDF-')&&str_contains(letter_http_header($headers,'Content-Disposition'),'rombel-'),'PDF rombel sesuai filter gagal.');
        [$headers,$body]=letter_http_get($base.'laporan/surat_orang_tua_pdf.php?mode=all',$id);
        letter_http_assert(str_starts_with($body,'%PDF-')&&str_contains(letter_http_header($headers,'Content-Disposition'),'semua-rombel'),'PDF semua rombel gagal.');
        if($paidOrNotDue!==null){
            [$headers]=letter_http_get($base.'laporan/surat_orang_tua_pdf.php?'.http_build_query(['mode'=>'single','nis'=>$paidOrNotDue]),$id);
            letter_http_assert(str_contains($headers[0]??'','404'),'Siswa tanpa tunggakan tetap mendapat surat.');
        }
        [$headers,$body]=letter_http_get($base.'laporan/export_global.php?template=tunggakan-siswa&format=pdf&siswa_status=active',$id);
        letter_http_assert(str_contains($headers[0]??'','200')&&str_starts_with($body,'%PDF-'),'PDF kepala sekolah gagal.');
        letter_http_assert(str_contains(strtolower(letter_http_header($headers,'Content-Disposition')),'inline'),'PDF kepala sekolah tidak inline.');
        [$headers,$body]=letter_http_get($base.'laporan/export_global.php?template=tunggakan-siswa&format=excel&download=1&siswa_status=active',$id);
        letter_http_assert(str_contains(strtolower(letter_http_header($headers,'Content-Disposition')),'attachment'),'Excel tidak langsung diunduh.');
        $body=test_excel_html($body);
        letter_http_assert(str_contains($body,'Siswa Menunggak')&&str_contains($body,'Total Tunggakan')&&!str_contains($body,htmlspecialchars($students[0]['nama'],ENT_QUOTES,'UTF-8')),'Excel kepala sekolah masih merinci siswa.');
        letter_http_end_session($id);
    }
    echo "OK: akses tiga peran, PDF inline, Excel lampiran, dan akses tanpa login.\n";
}catch(Throwable $error){fwrite(STDERR,'FAILED: '.$error->getMessage().PHP_EOL);exit(1);}
