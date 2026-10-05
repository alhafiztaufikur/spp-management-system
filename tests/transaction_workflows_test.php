<?php
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_[a-z0-9_]+$/D',(string)getenv('SPP_DB_NAME')))throw new RuntimeException('Gunakan salinan uji.');
require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/authorization_history.php';require_once __DIR__.'/../includes/parent_letter_drafts.php';
require_once __DIR__.'/../includes/pdf.php';
function workflow_assert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];
$minimal=['payment'=>['U_PSB'=>100,'U_LAIN'=>100,'total_jumlah'=>200],'details'=>['bayar_du'=>[['jumlah'=>100]],'bayar_biaya_lain'=>[]]];
workflow_assert(authorization_proposed_snapshot($minimal,['id'=>1])===$minimal,'Usulan lama tanpa rincian mengarang perubahan nominal.');
workflow_assert(authorization_proposed_snapshot($minimal,['uang_du'=>'50','total_jumlah'=>'150'])['payment']['U_LAIN']===50.0,'Nominal usulan Daftar Ulang salah.');
foreach([1,2,3] as $unit){
    unit_set_context($koneksi,$unit);$_SESSION['active_unit_id']=$unit;
    foreach(['all','pending','approved','rejected','cancelled','failed'] as $status)foreach(['all','edit','hapus'] as $kind){
        $result=authorization_history_page($koneksi,$status,$kind,'',1);
        workflow_assert(count($result['rows'])<=25,'Pagination riwayat tidak berlaku.');
        foreach($result['rows'] as $row)workflow_assert((int)$row['unit_id']===$unit,'Riwayat lintas unit bocor.');
    }
    $students=parent_letter_collect($koneksi,['mode'=>'all'])['students'];
    workflow_assert(count($students)>1,'Contoh surat diperlukan pada setiap unit.');
    $data=['students'=>array_slice($students,0,2),'today'=>report_letter_today(),'unit'=>$unit];$token=parent_letter_draft_create($data);
    $first=unit_student_key($data['students'][0]);$second=unit_student_key($data['students'][1]);
    $message="Pesan khusus <script>alert(1)</script> & orang tua\n\nParagraf kedua.";
    $draft=parent_letter_draft_update($token,[$first=>$message,$second=>'Pesan siswa kedua.']);
    $html=report_parent_letters_html($draft['students'],$draft['today'],$draft['messages']);
    workflow_assert(str_contains($html,'&lt;script&gt;')&&!str_contains($html,'<script>'),'Pesan tidak di-escape.');
    workflow_assert(substr_count($html,'Pesan siswa kedua.')===1 && substr_count($html,'Paragraf kedua.')===1,'Pesan siswa bercampur.');
    workflow_assert(strpos($html,'Jumlah tunggakan')<strpos($html,'Pesan khusus') && strpos($html,'Pesan khusus')<strpos($html,'Mohon Bapak/Ibu'),'Lokasi pesan salah.');
    workflow_assert(report_parent_letters_html($draft['students'],$draft['today'])===report_parent_letters_html($draft['students'],$draft['today'],[$first=>'']),'Pesan kosong mengubah surat.');
    $principal=report_principal_letter_html([],$draft['today']);parent_letter_draft_update($token,[$first=>str_repeat('a',2000)]);
    workflow_assert($principal===report_principal_letter_html([],$draft['today']),'Surat kepala sekolah berubah.');
    $long=implode("\n",array_fill(0,75,'Pengingat biaya sekolah.'))."\nAKHIR_PESAN";
    require_pdf_library();$pdf=new \Dompdf\Dompdf();$pdf->loadHtml(report_parent_letters_html([$draft['students'][0]],$draft['today'],[$first=>$long]),'UTF-8');$pdf->setPaper('A4','portrait');$pdf->render();
    workflow_assert($pdf->getCanvas()->get_page_count()>1,'Pesan panjang tidak berlanjut ke halaman berikutnya.');
    if($out=getenv('SPP_UI_OUTPUT'))file_put_contents($out.'/long-message-'.$unit.'.pdf',$pdf->output());unset($pdf);
    foreach([[$first=>str_repeat('a',2001)],['99|foreign'=>'Pesan']] as $invalid){$rejected=false;try{parent_letter_draft_update($token,$invalid);}catch(InvalidArgumentException $e){$rejected=true;}workflow_assert($rejected,'Draf tidak valid diterima.');}
    $_SESSION['active_unit_id']=$unit===1?2:1;$rejected=false;try{parent_letter_draft_read($token);}catch(RuntimeException $e){$rejected=true;}workflow_assert($rejected,'Draf dapat dibuka dari unit lain.');
    $_SESSION['active_unit_id']=$unit;$_SESSION['parent_letter_drafts'][$token]['expires']=time()-1;
    $rejected=false;try{parent_letter_draft_read($token);}catch(RuntimeException $e){$rejected=true;}workflow_assert($rejected,'Draf kedaluwarsa diterima.');
}
echo "OK: riwayat/status/jenis/unit, draf per siswa, escaping, batas teks, masa berlaku, dan surat kepala sekolah.\n";
