<?php
/** Read-only regression on a disposable, explicitly selected database. */
if(PHP_SAPI!=='cli'||getenv('SPP_TEST_ALLOW_MUTATION')!=='1'||!preg_match('/^db_spp_audit_/D',(string)getenv('SPP_DB_NAME')))exit(1);
ob_start();require_once __DIR__.'/../koneksi.php';require_once __DIR__.'/../includes/reports.php';require_once __DIR__.'/../includes/excel.php';require_once __DIR__.'/excel_test_helpers.php';require_once __DIR__.'/http_form_scope.php';
$base=rtrim(getenv('SPP_HTTP_BASE'),'/');spp_test_assert_http_clone($base,DB_NAME);
$sid='multi'.bin2hex(random_bytes(12));$admin=$koneksi->query("SELECT id FROM admin WHERE role='super_admin' AND is_active=1 LIMIT 1")->fetch_assoc();
function multi_get(string $path,array $query=[],int $expected=200):string{
    global $base,$sid;$body=file_get_contents($base.'/'.$path.'?'.http_build_query($query),false,stream_context_create(['http'=>['header'=>'Cookie: PHPSESSID='.$sid,'ignore_errors'=>true,'timeout'=>120,'follow_location'=>0]]));
    if(!str_contains($http_response_header[0]??'',' '.$expected.' '))throw new RuntimeException('HTTP '.$path.' '.$http_response_header[0].' '.substr(strip_tags($body),0,250));
    if(preg_match('/Fatal error|Warning:|Gagal memuat laporan|SQLSTATE|Unknown column|Array to string/',$body))throw new RuntimeException('Runtime error: '.$path.' '.substr(strip_tags($body),0,400));return $body;
}
function multi_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function multi_readback($book,array $doc):void{
    foreach($doc['sheets'] as $definition){$sheet=$book->getSheetByName($definition['name']);multi_assert($sheet!==null,'Missing worksheet');$cursor=9;$n=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        foreach($definition['sections'] as $section){multi_assert($sheet->getCellByColumnAndRow(1,$cursor)->getValue()===$section['title'],'Section title');$cursor++;$count=count($section['columns']);
            foreach($section['columns'] as $i=>$column)multi_assert($sheet->getCellByColumnAndRow(1+(int)floor($i*$n/$count),$cursor)->getValue()===$column['label'],'Section header');$cursor++;
            foreach($section['rows'] as $row){foreach($section['columns'] as $i=>$column){$cell=$sheet->getCellByColumnAndRow(1+(int)floor($i*$n/$count),$cursor);$expected=$row[$column['key']]??null;
                    if(in_array($column['type'],['money','money_optional','number','count'],true)&&$expected!==null&&$expected!=='')multi_assert($cell->getDataType()==='n'&&abs((float)$cell->getValue()-(float)$expected)<.001,'Numeric value '.$column['key']);
                    elseif(in_array($column['type'],['date','datetime'],true)&&$expected){$serial=\PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTimeImmutable($expected,new DateTimeZone('Asia/Jakarta')));multi_assert(abs((float)$cell->getValue()-$serial)<.00001,'Date value');}
                    else multi_assert((string)$cell->getValue()===($expected===null&&array_key_exists($column['key'],$row)?'—':spp_excel_text($expected??'')),'Text value '.$column['key']);
                }$cursor++;}
            if(!$section['rows'])$cursor++;foreach($section['totals'] as $total){multi_assert(abs((float)$sheet->getCellByColumnAndRow($n,$cursor)->getValue()-(float)$total['value'])<.001,'Subtotal '.$total['label']);$cursor++;}$cursor+=2;
        }
    }
}
$cases=0;
require_once __DIR__.'/../includes/general_multiple.php';
try{
    multi_assert(filter_choices(['b','a','b'],['a'=>'A','b'=>'B'])===['a','b'],'Deduplication/canonical order');
    foreach([[],[['a']],['*','a'],['not-available']] as $invalid){$rejected=false;try{filter_choices($invalid,['a'=>'A']);}catch(InvalidArgumentException $expected){$rejected=true;}multi_assert($rejected,'Invalid array accepted');}
    foreach([1,2,3,0] as $unit){
        session_id($sid);session_start();$_SESSION=['admin_id'=>(int)$admin['id'],'admin_role'=>'super_admin','active_unit_id'=>$unit];session_write_close();unit_set_context($koneksi,$unit);
        $years=array_column(report_years($koneksi),'label');$levels=$unit===0?[1,12]:unit_level_bounds();$classes=report_classes($koneksi);$regular=array_values(array_filter($classes,static fn($c)=>(int)$c['tingkat']>0));$rombel=$regular[0]??null;
        $categories=array_keys(report_categories($koneksi));$other=array_values(array_filter($categories,static fn($v)=>str_starts_with($v,'biaya_lain:')));
        $selected=array_values(array_unique(array_merge(['spp','komite','daftar_ulang','psb'],array_slice($other,0,1))));$selected=array_values(array_intersect($categories,$selected));
        $common=['unit'=>'active','tanggal_awal'=>'2026-09-01','tanggal_akhir'=>'2026-10-06','tahun_ajaran'=>array_slice($years,0,2),'siswa_status'=>['*'],'kelas'=>['tingkat:'.$levels[0],'tingkat:'.$levels[1]],'q'=>''];
        foreach(array_keys(report_registry()) as $template){
            $query=$common+['template'=>$template];
            if(in_array($template,['status','per-item','penerimaan'],true))$query['kategori']=$selected;
            if($template==='status')$query['bulan_awal']=['09','10'];
            if($template==='per-item')$query+=['bulan_awal'=>'07','bulan_akhir'=>'12','tahun_awal'=>2026,'tahun_akhir'=>2026];
            $report=report_build($koneksi,$template,report_filters($koneksi,$query));
            $html=multi_get('laporan/template.php',$query);multi_assert(!str_contains($html,'report-alert-error'),'Report error: '.$template);
            $excel=multi_get('laporan/export_global.php',$query+['format'=>'excel','download'=>1]);$book=test_excel_read($excel);$values=[];foreach($book->getActiveSheet()->getCellCollection()->getCoordinates() as $coord)$values[]=$book->getActiveSheet()->getCell($coord)->getValue();
            foreach($report['sections']??[$report] as $part)foreach($part['rows'] as $row)if(isset($row['nis']))multi_assert(in_array($row['nis'],$values,true),'Missing source NIS: '.$template);
            // Principal detail has a specialized document; remaining exports use the common model.
            multi_readback($book,spp_excel_report_document($report,$template,$unit));$book->disconnectWorksheets();
            multi_get('laporan/export_global.php',$query+['format'=>'preview']);$cases+=3;
        }
        foreach(['siswa/daftar.php','siswa/export_excel.php'] as $path)multi_get($path,['kelas'=>array_map(static fn($c)=>(string)$c['id'],array_slice($classes,0,2)),'status'=>['active','archived']]);
        multi_get('pembayaran/riwayat_daftar_ulang.php',['kelas'=>['tingkat:'.$levels[0]],'tahun_ajaran'=>['*'],'status'=>['lunas','cicilan']]);
        multi_get('otorisasi_transaksi.php',['view'=>'history','status'=>['approved','rejected'],'kind'=>['edit','hapus']]);
        multi_get('pembayaran/riwayat_daftar_ulang.php',['kelas'=>'tingkat:'.$levels[0]]);multi_get('tabungan/riwayat.php');multi_get('tabungan/cetak.php');multi_get('laporan/surat_orang_tua.php',['kelas'=>['tingkat:'.$levels[0]],'siswa_status'=>['active','archived']]);
        $ops=array_map('strval',array_slice(array_column(report_operator_options($koneksi),'id'),0,2));
        foreach(['penerimaan','setoran','kas-tabungan','tabungan-siswa'] as $template)if($ops){$query=['template'=>$template,'operator'=>$ops,'tanggal_awal'=>'2026-09-01','tanggal_akhir'=>'2026-10-06','metode'=>['Tunai','VA']];multi_get('laporan/template.php',$query);multi_get('laporan/export_global.php',$query+['format'=>'excel']);}
        $receiptQuery=['template'=>'penerimaan','tanggal_awal'=>'2026-09-01','tanggal_akhir'=>'2026-10-06','siswa_status'=>'all'];
        $legacyReceipt=report_build($koneksi,'penerimaan',report_filters($koneksi,$receiptQuery+['kategori'=>'semua']));
        $multiReceipt=report_build($koneksi,'penerimaan',report_filters($koneksi,$receiptQuery+['kategori'=>['*'],'tahun_ajaran'=>array_slice($years,0,2)]));
        multi_assert(abs(array_sum(array_column($legacyReceipt['rows'],'total_penerimaan'))-array_sum(array_column($multiReceipt['rows'],'total_penerimaan')))<.001,'Select All duplicated or omitted receipt cash');
        $statusReport=report_build($koneksi,'status',report_filters($koneksi,['template'=>'status','kategori'=>$selected,'tahun_ajaran'=>array_slice($years,0,2),'bulan_awal'=>['09','10']]));
        multi_assert(count(array_filter($statusReport['sections']??[],static fn($s)=>str_starts_with($s['section_title'],'Uang PSB')))===1,'Once fee repeated by year/month');
        $pdf=multi_get('laporan/export_global.php',['template'=>'status','kategori'=>['spp','komite'],'tahun_ajaran'=>[$years[0]],'bulan_awal'=>['09','10'],'q'=>'QA_NIS_TIDAK_ADA','format'=>'pdf']);multi_assert(str_starts_with($pdf,'%PDF'),'Invalid grouped PDF');
        $pdf=multi_get('laporan/export_pdf.php',['jenis_laporan'=>['belum_spp','belum_du'],'tanggal_awal'=>'2026-09-01','tanggal_akhir'=>'2026-10-06','output'=>'pdf','q'=>'QA_NIS_TIDAK_ADA']);multi_assert(str_starts_with($pdf,'%PDF'),'Invalid general PDF');
        if($unit)multi_get('master_kelas.php',['tingkat_kelas'=>array_map('strval',range($levels[0],$levels[1])),'status_kelas'=>['aktif','nonaktif']]);
        foreach([['sudah_bayar','belum_spp','belum_komite','belum_du','belum_biaya_lain'],['*']] as $choices){
            $query=['jenis_laporan'=>$choices,'tanggal_awal'=>'2026-09-01','tanggal_akhir'=>'2026-10-06','unit'=>'active','urut'=>'nama'];
            $screen=multi_get('laporan/index.php',$query);multi_assert(str_contains($screen,'urut=nama'),'Export order parameter missing');
            $book=test_excel_read(multi_get('laporan/export_excel.php',$query+['download'=>1]));
            $sections=general_sections($koneksi,general_choices($query),'2026-09-01 00:00:00','2026-10-07 00:00:00','','nama');
            multi_readback($book,['sheets'=>[['name'=>'Pembayaran','sections'=>general_excel_sections($sections)]]]);
            multi_assert($book->getSheetCount()===3,'General report worksheets');$book->disconnectWorksheets();
            multi_get('laporan/export_pdf.php',$query);$cases+=3;
        }
        multi_get('laporan/template.php',['template'=>'status','kategori'=>['']],400);multi_get('laporan/template.php',['template'=>'status','kategori'=>['invalid']],400);multi_get('laporan/template.php',['template'=>'status','tahun_ajaran'=>['1900/1901']],400);
        if($unit){unit_set_context($koneksi,0);$foreign=$koneksi->query('SELECT id FROM master_kelas WHERE unit_id<>'.$unit.' LIMIT 1')->fetch_assoc();unit_set_context($koneksi,$unit);if($foreign)multi_get('laporan/template.php',['template'=>'status','kelas'=>['rombel:'.$foreign['id']]],400);}
        if($rombel){$baseQuery=['template'=>'status','tahun_ajaran'=>$years[0],'bulan_awal'=>'09','kategori'=>'spp'];$a=report_build($koneksi,'status',report_filters($koneksi,$baseQuery+['kelas'=>['tingkat:'.$rombel['tingkat']]]));$b=report_build($koneksi,'status',report_filters($koneksi,$baseQuery+['kelas'=>['tingkat:'.$rombel['tingkat'],'rombel:'.$rombel['id']]]));multi_assert(count($a['rows'])===count($b['rows']),'Overlapping class filters duplicated rows');}
        echo 'PASS unit '.$unit.' multi filters, reports/exports, legacy and unit boundaries'.PHP_EOL;
    }
    ob_end_flush();echo 'PASS '.$cases.' report HTTP cases'.PHP_EOL;
}catch(Throwable $error){ob_end_flush();fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1);}
