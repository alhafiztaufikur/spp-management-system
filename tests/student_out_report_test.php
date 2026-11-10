<?php
require __DIR__.'/http_form_scope.php';spp_test_assert_database_target();
require __DIR__.'/../koneksi.php';spp_test_assert_database_target($koneksi);
require_once __DIR__.'/../includes/student_out.php';require_once __DIR__.'/../includes/reports.php';
$db=$koneksi;if((int)$db->query('SELECT @@port')->fetch_row()[0]!==3320)throw new RuntimeException('Use the owned browser restore.');
$file=getenv('SPP_OUT_FIXTURE');$fixture=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$case=$fixture['cases'][0];$base=getenv('SPP_TEST_BASE_URL');spp_test_assert_http_clone($base,DB_NAME);$dir=dirname($file);$checks=0;
function sor_check(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function sor_http(string $url,?array $post=null): array {global $case,$base;$c=curl_init(str_starts_with($url,'http:')?$url:$base.$url);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>120,CURLOPT_COOKIE=>'PHPSESSID='.$case['session'],CURLOPT_HEADER=>true]);if($post!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);$raw=curl_exec($c);if($raw===false)throw new RuntimeException(curl_error($c));$size=curl_getinfo($c,CURLINFO_HEADER_SIZE);$code=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$code,substr($raw,$size),substr($raw,0,$size)];}
function sor_fields(string $html,string $xpath): array {$doc=new DOMDocument();libxml_use_internal_errors(true);$doc->loadHTML($html);$x=new DOMXPath($doc);$form=$x->query($xpath)->item(0);if(!$form)throw new RuntimeException('Form missing: '.$xpath);$out=[];foreach($x->query('.//input[@name]|.//select[@name]|.//textarea[@name]',$form) as $field){$name=$field->getAttribute('name');if($field->hasAttribute('disabled')||in_array($field->getAttribute('type'),['submit','button'],true))continue;if($field->tagName==='select'){$selected=$x->query('.//option[@selected]',$field)->item(0)??$x->query('.//option',$field)->item(0);$out[$name]=$selected?$selected->getAttribute('value'):'';}else $out[$name]=$field->tagName==='textarea'?$field->textContent:$field->getAttribute('value');}return $out;}
[$code,$page]=sor_http('/master_spp.php');$scope=sor_fields($page,"//form[contains(@class,'sidebar-unit-form')]");sor_http('/unit_switch.php',array_replace($scope,['unit_id'=>'1','next'=>'/master_spp.php']));
$_SESSION=['admin_id'=>1,'admin_role'=>'super_admin','active_unit_id'=>1];unit_set_context($db,1);
$source=['student_id'=>$case['id'],'siswa_status'=>'all','tanggal_awal'=>'2026-01-01','tanggal_akhir'=>'2027-12-31','tahun_ajaran'=>$fixture['year'],'bulan_awal'=>'07','tahun_awal'=>'2026','bulan_akhir'=>'06','tahun_akhir'=>'2027'];
$read=static function(string $template,string $category)use($db,$source){$f=report_filters($db,$source+['template'=>$template,'kategori'=>$category]);return report_build($db,$template,$f);};
$financial=static function()use($db,$case){$out=[];foreach(['bayar'=>'NO_INDUK','tabungan'=>'NO_INDUK','transaksi_m'=>'NO_INDUK','transaksi_k'=>'NO_INDUK','spp_alokasi_batch'=>'no_induk'] as $t=>$key)$out[$t]=sout_rows($db,"SELECT * FROM $t WHERE $key=? ORDER BY id",[$case['nis']]);return $out;};
$before=['cash'=>$read('penerimaan','semua'),'savings'=>$read('saldo-tabungan','semua'),'financial'=>$financial()];
[$code,$page]=sor_http('/siswa/daftar.php?edit='.$case['id']);$fields=sor_fields($page,"//form[@id='form-master-siswa']");sor_http('/siswa/daftar.php',array_replace($fields,['master_kelas_id'=>$case['out'],'_operational_unit'=>'1']));
try{
    sor_check((bool)sout_student($db,$case['nis'])['is_out'],'Official OUT saved before exports');$after=['cash'=>$read('penerimaan','semua'),'savings'=>$read('saldo-tabungan','semua'),'financial'=>$financial()];
    sor_check($before['financial']===$after['financial'],'Cash and balance rows identical');sor_check($before['cash']['rows']===$after['cash']['rows']&&count($after['cash']['rows'])>0,'Receipt report preserves exact amounts and original context');$balances=static fn($report)=>array_map(static fn($row)=>array_intersect_key($row,array_flip(['nis','nama','saldo_saat_ini'])),$report['rows']);sor_check($balances($before['savings'])===$balances($after['savings']),'Balance report preserves exact amounts');
    foreach(['spp','komite'] as $category){
        $report=$read('per-item',$category);sor_check(count($report['rows'])===1,'One monthly report row');$text=json_encode($report['rows']);sor_check(str_contains($text,'Dibatalkan'),'Monthly report distinguishes cancellation');
        $query=http_build_query($source+['template'=>'per-item','kategori'=>$category]);[$code,$html]=sor_http('/laporan/template.php?'.$query);sor_check($code===200&&str_contains($html,'Dibatalkan'),'Actual HTML report includes cancellation');
        foreach(['pdf'=>'%PDF','excel'=>'PK'] as $type=>$magic){[$code,$body]=sor_http('/laporan/export_global.php?'.$query.'&format='.$type.'&download=1');sor_check($code===200&&str_starts_with($body,$magic),'Actual '.$category.' '.$type.' export');$path=$dir.'/out-'.$category.'.'.($type==='excel'?'xlsx':'pdf');file_put_contents($path,$body);
            if($type==='excel'){$zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('Invalid Excel ZIP');$xml='';for($i=0;$i<$zip->numFiles;$i++)if(preg_match('~^xl/(sharedStrings|worksheets/sheet[0-9]+)\.xml$~D',$zip->getNameIndex($i)))$xml.=$zip->getFromIndex($i);$zip->close();sor_check(str_contains($xml,'Dibatalkan'),'Excel cancellation text survives serializer');file_put_contents($dir.'/out-'.$category.'-excel.xml',$xml);}
        }
        file_put_contents($dir.'/out-'.$category.'-model.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    }
    [$code,$body]=sor_http('/laporan/cetak_struk.php?id='.$case['payment']);sor_check($code===200,'Original receipt readable during OUT');
    file_put_contents($dir.'/out-report-result.json',json_encode(['passed'=>true,'checks'=>$checks],JSON_PRETTY_PRINT));echo 'PASS '.$checks.' report, cash, balance, receipt, PDF and Excel checks',PHP_EOL;
}finally{
    [$code,$page]=sor_http('/siswa/daftar.php?edit='.$case['id']);$fields=sor_fields($page,"//form[@id='form-master-siswa']");sor_http('/siswa/daftar.php',array_replace($fields,['master_kelas_id'=>$case['class'],'_operational_unit'=>'1']));
    if(sout_student($db,$case['nis'])['is_out'])throw new RuntimeException('Disposable fixture return failed.');
}
