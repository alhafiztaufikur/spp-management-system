<?php
/** Read-only HTTP/model comparisons on the verified clone only. */
if(PHP_SAPI!=='cli')exit(1);
ini_set('session.save_path',getenv('SPP_QA_SESSION_PATH'));session_id('plq20-readonly-'.bin2hex(random_bytes(8)));session_start();
require __DIR__.'/../koneksi.php';require __DIR__.'/http_form_scope.php';spp_test_isolated_manifest($koneksi);spp_test_assert_http_clone(getenv('SPP_TEST_BASE_URL'),DB_NAME);
require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/savings_book_print.php';require_once __DIR__.'/../includes/authorization_presentation.php';
$root=getenv('SPP_QA_DIR');$fixture=json_decode(file_get_contents(getenv('SPP_SAVINGS_QA').'/rombel-fixture.json'),true);$checks=0;$expected=[];
function ph_check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function ph_http(string $route,?array $form=null,string $role='super_admin'):array{global $fixture;$c=curl_init(getenv('SPP_TEST_BASE_URL').'/'.$route);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIE=>'PHPSESSID='.$fixture['sessions'][$role]['id'],CURLOPT_TIMEOUT=>90]);if($form!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($form)]);$body=curl_exec($c);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);$type=curl_getinfo($c,CURLINFO_CONTENT_TYPE);$redirect=curl_getinfo($c,CURLINFO_REDIRECT_URL);curl_close($c);return [$code,$body,$type,$redirect];}
function ph_document():array{global $koneksi;$sppGeneralPdf=true;$cwd=getcwd();chdir(__DIR__.'/../laporan');try{require __DIR__.'/../laporan/export_excel.php';return $doc;}finally{chdir($cwd);}}
foreach([1,2,3] as $unit){
    $_SESSION=['admin_id'=>$fixture['sessions']['super_admin']['actor'],'admin_role'=>'super_admin','active_unit_id'=>$unit,'admin_nama'=>'Super Admin'];unit_set_context($koneksi,$unit);$info=$fixture['units'][$unit];
    [$status]=ph_http('unit_switch.php',['unit_id'=>$unit,'csrf_token'=>$fixture['sessions']['super_admin']['csrf'],'next'=>'/dashboard.php']);ph_check($status===303,'Switch unit');
    [$student,$book]=savings_book_load($koneksi,$info['nis'],$info['student_id']);$expected[$unit]['book']=$book['entries'];
    $bookQuery=['nis'=>$info['nis'],'student_id'=>$info['student_id'],'output'=>'pdf'];
    [$status,$bytes,$type]=ph_http('tabungan/cetak_buku.php?'.http_build_query($bookQuery));ph_check($status===200&&str_contains($type,'application/pdf'),'Book PDF');file_put_contents($root.'/book-http-'.$unit.'-pdf.pdf',$bytes);
    foreach([['entry_from'=>1,'entry_to'=>1,'start_side'=>'bottom','start_row'=>14],['calibration'=>1],['output'=>'guide']] as $case=>$extra){[$status,$bytes,$type]=ph_http('tabungan/cetak_buku.php?'.http_build_query(array_replace($bookQuery,$extra)));ph_check($status===200&&str_contains($type,'application/pdf'),'Positioned book '.$case);file_put_contents($root.'/book-extra-'.$unit.'-'.$case.'.pdf',$bytes);}
    [$status]=ph_http('tabungan/cetak_buku.php?'.http_build_query($bookQuery),['entry_from'=>1]);ph_check($status===405,'Reject POST book');
    $_GET=['jenis_laporan'=>['*'],'unit'=>'active','tanggal_awal'=>'2026-10-01','tanggal_akhir'=>'2026-10-18'];$doc=ph_document();$expected[$unit]['doc']=$doc;
    [$status,$bytes,$type]=ph_http('laporan/export_pdf.php?'.http_build_query($_GET+['output'=>'inline']));ph_check($status===200&&str_contains($type,'application/pdf'),'Inline recap PDF');file_put_contents($root.'/recap-http-'.$unit.'.pdf',$bytes);
    [$status,$bytes,$type]=ph_http('laporan/export_excel.php?'.http_build_query($_GET+['download'=>'1']));ph_check($status===200&&str_starts_with($bytes,'PK'),'Existing Excel export');file_put_contents($root.'/recap-http-'.$unit.'.xlsx',$bytes);
    $first=authorization_combined_page($koneksi,true,'all','all',$info['nis'],1,['*'],'all');$models=[];
    for($p=1;$p<=$first['pages'];$p++){$result=$p===1?$first:authorization_combined_page($koneksi,true,'all','all',$info['nis'],$p,['*'],'all');foreach($result['rows'] as $r)$models[]=authorization_detail_model($koneksi,(int)$r['payment_id'],false,$r['source']);}
    $expected[$unit]['audit']=$models;ph_check(count($models)>0,'Real audit history exercised');
    [$status,$bytes,$type]=ph_http('otorisasi_export_pdf.php?'.http_build_query(['q'=>$info['nis'],'output'=>'inline']));ph_check($status===200&&str_contains($type,'application/pdf'),'Audit PDF');file_put_contents($root.'/audit-http-'.$unit.'.pdf',$bytes);
}
[$status,$html]=ph_http('otorisasi_transaksi.php?view=history');$dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);$csrf=$xp->query('//form[@data-export-form]//input[@name="csrf_token"]')->item(0)->getAttribute('value');
$selected=$expected[3]['audit'][0];$selection=['unit_id'=>3,'payment_id'=>(int)$selected['id'],'source'=>$selected['source']];
[$status,$body,$type,$location]=ph_http('otorisasi_export_pdf.php',['csrf_token'=>$csrf,'transactions'=>json_encode([$selection]),'q'=>$fixture['units'][3]['nis']]);ph_check($status===303&&str_contains($location,'selection_token='),'Selection creates scoped preview token');
$tokenQuery=parse_url($location,PHP_URL_QUERY);
[$status,$bytes,$type]=ph_http('otorisasi_export_pdf.php?'.$tokenQuery.'&output=inline');ph_check($status===200&&str_contains($type,'application/pdf'),'Selection token opens native PDF');file_put_contents($root.'/audit-selected.pdf',$bytes);$expected['selected_reference']=$selected['reference'];
ph_http('unit_switch.php',['unit_id'=>1,'csrf_token'=>$fixture['sessions']['super_admin']['csrf'],'next'=>'/dashboard.php']);
[$status]=ph_http('otorisasi_export_pdf.php?'.$tokenQuery.'&output=inline');ph_check($status===400,'Token rejects changed unit');
ph_http('unit_switch.php',['unit_id'=>3,'csrf_token'=>$fixture['sessions']['super_admin']['csrf'],'next'=>'/dashboard.php']);
$selection['unit_id']=1;$selection['payment_id']=$fixture['units'][1]['payment_id'];
[$status]=ph_http('otorisasi_export_pdf.php',['csrf_token'=>$csrf,'transactions'=>json_encode([$selection])]);ph_check($status===400,'Reject foreign transaction selection');
[$status,$html]=ph_http('otorisasi_export_pdf.php?'.http_build_query(['kind'=>['edit','hapus'],'status'=>['approved','rejected']]));ph_check($status===200&&str_contains($html,'kind%5B1%5D=hapus')&&str_contains($html,'status%5B1%5D=rejected'),'Preview retains multiple audit filters');
[$status]=ph_http('otorisasi_export_pdf.php?output=inline',null,'admin');ph_check($status===403,'Audit export remains super-only');
[$status]=ph_http('otorisasi_export_pdf.php',['transactions'=>'[]','csrf_token'=>'invalid']);ph_check($status===403,'Selection CSRF remains enforced');
file_put_contents($root.'/output-expected.json',json_encode($expected,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));file_put_contents($root.'/http-verification.json',json_encode(['checks'=>$checks],JSON_PRETTY_PRINT));echo "PASS $checks read-only HTTP/model checks\n";
