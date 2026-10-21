<?php
/** Shared, typed report model. No HTML is imported into the XLSX writer. */
function spp_excel_palette(int $unit): array {
    return [1=>['168A46','0B5437','ECF8F0'],2=>['2449D8','203880','EEF2FF'],3=>['C9252D','7F2025','FFF0F1'],0=>['7228D9','472178','F4EEFF']][$unit] ?? ['168A46','0B5437','ECF8F0'];
}
function spp_excel_column(string $key,string $label,string $type='text'): array {
    if($type==='text'&&in_array($key,['jumlah_siswa','jumlah_transaksi'],true))$type='count';
    $width=$type==='datetime'?32:(in_array($type,['money','money_optional'],true)?22:($type==='date'?18:(in_array($type,['number','count'],true)?10:28)));
    if(in_array($key,['nama','NAMA','komponen','nama_biaya','keterangan'],true))$width=38;
    if($key==='no')$width=8;
    if(in_array($key,['kelas','KELAS','export_class','unit','export_unit'],true))$width=16;
    if(in_array($key,['nis','NO_INDUK'],true))$width=22;
    if(in_array($key,['nis_diknas','NO_induk_diknas','export_diknas'],true))$width=24;
    return ['key'=>$key,'label'=>$label,'type'=>$type,'width'=>$width];
}
function spp_excel_document(string $title,string $subtitle,int $unit,array $sheets): array {
    return ['title'=>$title,'subtitle'=>$subtitle,'unit'=>$unit,'school'=>unit_school_name($unit),'unit_label'=>unit_label($unit),
        'generated'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Jakarta')))->format('d/m/Y H:i:s').' WIB',
        'operator'=>(string)($_SESSION['admin_nama']??$_SESSION['admin_username']??'Pengguna'),'sheets'=>$sheets];
}
function spp_excel_section(string $title,array $columns,array $rows,array $totals=[],bool $detail=true): array {
    return compact('title','columns','rows','totals','detail');
}
function spp_excel_filter_text(mysqli $db,array $filters,string $template): string {
    $parts=[];if(in_array($template,['status','per-item','penerimaan'],true)){ $category=(string)($filters['kategori']??'');$parts[]='Kategori: '.($category==='semua'?'Semua':(report_categories($db)[$category]??$category));}$classId=report_class_filter_rombel_id($filters);$level=report_class_filter_level($filters);
    if($classId){foreach(report_classes($db) as $class)if((int)$class['id']===$classId){$parts[]='Kelas: '.class_label($class);break;}}
    elseif($level)$parts[]='Tingkat: '.$level;
    foreach(['status'=>'Status pembayaran','siswa_status'=>'Status siswa','metode'=>'Metode','mutasi'=>'Mutasi','saldo_status'=>'Saldo','q'=>'Pencarian'] as $key=>$label){
        $value=(string)($filters[$key]??'');if($value==='')continue;
        if($key!=='q')$value=['active'=>'Aktif','archived'=>'Arsip/Lulus','all'=>'Semua','belum_bayar'=>'Belum Bayar','lunas'=>'Lunas','cicilan'=>'Cicilan','saldo_nol'=>'Saldo Nol','ada_saldo'=>'Ada Saldo'][$value]??ucfirst(str_replace('_',' ',$value));
        $parts[]=$label.': '.$value;
    }
    if($filters['operator']??'')foreach(report_operators($db) as $operator)if((string)$operator['id']===(string)$filters['operator']){$parts[]='Operator: '.$operator['nama'].' (@'.$operator['username'].')';break;}
    foreach($filters['_multi']??[] as $key=>$values){$config=$GLOBALS['spp_filter_choices'][$key]??[];$labels=[];foreach($values as $value)$labels[]=$value==='*'?'Semua':($config['options'][$value]??$value);$parts[]=ucfirst(str_replace('_',' ',$key)).': '.implode(', ',$labels);}
    return implode(' | ',$parts);
}
function spp_excel_report_document(array $report,string $template,int $unit): array {
    if(isset($report['sections'])){
        $sections=[];foreach($report['sections'] as $part){$sub=spp_excel_report_document($part,$template,$unit);foreach($sub['sheets'][0]['sections'] as $section){$section['title']=($part['section_title']??$part['title']).' | '.$part['subtitle'];$sections[]=$section;}}
        return spp_excel_document($report['title'],$report['subtitle'],$unit,[['name'=>'Laporan','sections'=>$sections,'signatures'=>false]]);
    }
    $sections=[];$totals=report_money_totals($report,$template);
    if(in_array($template,['setoran','kas-tabungan'],true)){
        $savings=$template==='kas-tabungan';$components=$report['component_rows']??$report['component_summary']??[];
        foreach($components as $i=>&$row)$row['no']=$i+1;unset($row);
        $sections[]=spp_excel_section($savings?'Arus Tabungan':'Komponen Pembayaran',[spp_excel_column('no','No','number'),spp_excel_column('komponen','Komponen Pembayaran'),spp_excel_column('nominal','Nominal','money')],$components,[['label'=>$savings?'Mutasi Bersih':'Total Pembayaran','value'=>$report['component_total']??0]],false);
        $summary=$savings?($report['transaction_summary']??[]):array_map(static fn($r)=>['label'=>$r['metode'],'value'=>$r['nominal']],$report['method_summary']??[]);
        $sections[]=spp_excel_section($savings?'Jumlah Transaksi':'Metode Pembayaran',[spp_excel_column('label',$savings?'Jenis Transaksi':'Metode'),spp_excel_column('value',$savings?'Jumlah':'Nominal',$savings?'count':'money')],$summary,$totals,false);
    }elseif($template==='riwayat-tagihan'){
        $groups=report_billing_history_group_students($report['rows']);$components=report_billing_history_component_columns($groups);
        $columns=[spp_excel_column('no','No','number')];if($unit===0)$columns[]=spp_excel_column('unit','Unit');$columns=array_merge($columns,[spp_excel_column('nis','NIS'),spp_excel_column('nis_diknas','NIS Diknas'),spp_excel_column('nama','Nama Siswa'),spp_excel_column('kelas','Kelas')]);$rows=[];
        foreach($components as $i=>$column)$columns[]=spp_excel_column('component_'.$i,$column['komponen'],'money_optional');$columns[]=spp_excel_column('total_tagihan','Total Tagihan','money');
        foreach($groups as $i=>$student){$student['no']=$i+1;$student['unit']=unit_label($student['unit_id']);$map=array_column($student['components'],null,'komponen_key');foreach($components as $j=>$column)$student['component_'.$j]=isset($map[$column['komponen_key']])?$map[$column['komponen_key']]['tagihan']:null;$rows[]=$student;}
        $sections[]=spp_excel_section('Tagihan per Siswa dan Komponen',$columns,$rows,array_values(array_filter($totals,static fn($t)=>($t['key']??'')==='tagihan')));
    }else{
        $columns=[spp_excel_column('no','No','number')];$rows=$report['rows'];
        foreach($report['columns'] as $c){$columns[]=spp_excel_column($c[0],$c[1],$c[2]??'text');if($c[0]==='nis')$columns[]=spp_excel_column('export_diknas','NIS Diknas');}
        foreach($rows as $i=>&$row){$row['no']=$i+1;$row['export_diknas']=$row['diknas']??$row['nis_diknas']??$row['NO_induk_diknas']??'';}unset($row);
        $sections[]=spp_excel_section($report['title'],$columns,$rows,$totals);
    }
    return spp_excel_document($report['title'],$report['subtitle'],$unit,[['name'=>'Laporan','sections'=>$sections,'signatures'=>in_array($template,['setoran','kas-tabungan'],true)]]);
}
function spp_excel_text($value): string {
    if(is_array($value))$value=(string)($value['text']??'').(($value['sub']??'')!==''?"\n".(string)$value['sub']:'');
    // XML 1.0 cannot represent these characters. Formula-like text remains literal.
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',(string)$value)??'';
}
function spp_excel_library(): void {
    $autoload=dirname(__DIR__).'/vendor/autoload.php';if(is_file($autoload))require_once $autoload;
    if(class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class))return;
    http_response_code(503);header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="id"><meta charset="utf-8"><title>Ekspor Excel belum tersedia</title><main style="max-width:620px;margin:10vh auto;font-family:Arial;padding:24px"><h1>Ekspor Excel belum tersedia</h1><p>Komponen Excel belum terpasang. Jalankan <code>composer install</code> dari folder project, lalu coba kembali.</p></main></html>';exit;
}

/** A merged table heading must not cross a horizontal print page boundary. */
function spp_excel_print_group_headers($sheet): void {
    $breaks=[];
    foreach($sheet->getBreaks() as $cell=>$kind)if($kind===\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::BREAK_COLUMN){
        [$column]=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($cell);
        $breaks[]=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($column);
    }
    sort($breaks);
    foreach(array_keys($sheet->getMergeCells()) as $range){
        [$first,$last]=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::rangeBoundaries($range);
        if($first[1]<10 || $first[1]!==$last[1])continue;
        $cuts=array_values(array_filter($breaks,static fn($column)=>$column>$first[0]&&$column<=$last[0]));
        if(!$cuts)continue;
        $value=$sheet->getCellByColumnAndRow($first[0],$first[1])->getValue();
        // Numeric data and their cell types are never duplicated by print formatting.
        if(!is_string($value)||$value==='')continue;
        $sheet->unmergeCells($range);$starts=array_merge([$first[0]],$cuts);$ends=array_merge(array_map(static fn($c)=>$c-1,$cuts),[$last[0]]);
        foreach($starts as $index=>$start){
            $a=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($start).$first[1];
            $b=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ends[$index]).$first[1];
            if($a!==$b)$sheet->mergeCells($a.':'.$b);
            $label=$value.($index?' (lanjutan)':'');
            $sheet->setCellValueExplicit($a,$label,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $width=0;foreach(range($start,$ends[$index]) as $column)$width+=$sheet->getColumnDimensionByColumn($column)->getWidth();
            $sheet->getStyle($a.':'.$b)->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($first[1])->setRowHeight(max($sheet->getRowDimension($first[1])->getRowHeight(),16*ceil(mb_strlen($label)/max(8,$width*.85))+8));
        }
    }
}

/** Compact headings fit on the repeated identity side of wide workbooks. */
function spp_excel_print_heading($sheet,array $doc,array $definition,int $identity): void {
    $last=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max(3,$identity));
    foreach(array_keys($sheet->getMergeCells()) as $range)if(preg_match('/^[A-Z]+([1-9]):[A-Z]+([1-9])$/D',$range))$sheet->unmergeCells($range);
    foreach(['B1','B3','A5','A6','A7','A9'] as $cell)$sheet->setCellValue($cell,null);
    $start=$identity>=4?'C':'B';
    $sheet->mergeCells($start.'1:'.$last.'2')->setCellValueExplicit($start.'1',$doc['school'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->getStyle($start.'1')->getFont()->setSize(12)->setBold(true);
    $sheet->mergeCells('A3:'.$last.'3')->setCellValue('A3','Perum Bekasi Griya Asri II, Tambun Selatan | Telp. 021-88363466');
    $sheet->getStyle('A3')->getFont()->setSize(9);
    foreach([5=>$doc['title'],6=>'Unit: '.$doc['unit_label'].' | '.$doc['subtitle'],7=>'Dibuat: '.$doc['generated'].' | Petugas: '.$doc['operator'],9=>$definition['sections'][0]['title']] as $row=>$value){
        $sheet->mergeCells('A'.$row.':'.$last.$row)->setCellValueExplicit('A'.$row,$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }
    $sheet->getStyle('A5')->getFont()->setSize(12)->setBold(true);$sheet->getStyle('A6:A7')->getFont()->setSize(9);
    $sheet->getStyle('A1:'.$last.'9')->getAlignment()->setWrapText(true)->setVertical('center');
    $width=0;foreach(range(1,max(3,$identity)) as $i)$width+=$sheet->getColumnDimensionByColumn($i)->getWidth();
    foreach([1=>20,2=>20,3=>max(16,12*ceil(62/max(20,$width*.9))),4=>4,5=>max(24,16*ceil(mb_strlen($doc['title'])/max(20,$width*.7))),6=>max(26,12*ceil(mb_strlen('Unit: '.$doc['unit_label'].' | '.$doc['subtitle'])/max(20,$width*.9))),7=>max(18,12*ceil(mb_strlen('Dibuat: '.$doc['generated'].' | Petugas: '.$doc['operator'])/max(20,$width*.9))),8=>4,9=>max(20,16*ceil(mb_strlen($definition['sections'][0]['title'])/max(20,$width*.8)))] as $row=>$height)$sheet->getRowDimension($row)->setRowHeight($height);
    foreach($sheet->getDrawingCollection() as $drawing)$drawing->setHeight($identity<4?27:42)->setOffsetX(6)->setOffsetY(4);
}
function spp_excel_workbook(array $doc): \PhpOffice\PhpSpreadsheet\Spreadsheet {
    spp_excel_library();
    if(($doc['presentation']??'')==='global')require_once __DIR__.'/global_report_excel.php';
    $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$book->removeSheetByIndex(0);
    $book->getProperties()->setCreator('SistemSPP')->setTitle($doc['title'])->setSubject($doc['subtitle']);
    $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
    [$accent,$dark,$pale]=spp_excel_palette($doc['unit']);
    $global=($doc['presentation']??'')==='global';
    if($global)$accent=$dark=[1=>'165C44',2=>'203E80',3=>'832A35',0=>'513080'][$doc['unit']]??$dark;
    $line=$global?'D3DFE0':'D9E2EB';$used=[];
    foreach($doc['sheets'] as $definition){
        $sheet=new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($book);
        $name=mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u',' ', $definition['name']),0,31);$name=$name?:'Laporan';$base=$name;$suffix=1;
        while(isset($used[$name]))$name=mb_substr($base,0,27).' '.(++$suffix);$used[$name]=true;
        $sheet->setTitle($name);$book->addSheet($sheet);$sheet->setShowGridlines(false);$sheet->getSheetView()->setZoomScale(90);
        $n=3;$hasDetail=false;foreach($definition['sections'] as $section){$n=max($n,count($section['columns']));$hasDetail=$hasDetail||$section['detail'];}if(!$hasDetail)$n=max(6,$n);
        $letter=static fn(int $c)=>\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
        $globalIdentityLimit=$n;if($global)foreach($definition['sections'] as $candidate)if($candidate['detail']){$globalIdentityLimit=global_report_excel_identity_count($candidate);break;}
        $last=$letter($n);$sheet->getDefaultRowDimension()->setRowHeight(23);
        $sheet->getStyle('A1:'.$last.'8')->getAlignment()->setWrapText(true)->setVertical('center');
        $sheet->mergeCells('B1:'.$last.'2')->setCellValueExplicit('B1',$doc['school'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->getStyle('B1')->getFont()->setSize(18)->setBold(true)->getColor()->setRGB($dark);$sheet->getRowDimension(1)->setRowHeight(32);$sheet->getRowDimension(2)->setRowHeight(32);
        $sheet->mergeCells('B3:'.$last.'3')->setCellValue('B3','Perum Bekasi Griya Asri II, Tambun Selatan | Telp. 021-88363466');
        $sheet->getStyle('B3')->getFont()->setSize(10);$sheet->getRowDimension(3)->setRowHeight(30);
        $logo=dirname(__DIR__).'/assets/img/school-logo.png';
        if(is_file($logo)){$drawing=new \PhpOffice\PhpSpreadsheet\Worksheet\Drawing();$drawing->setName('Logo sekolah')->setPath($logo)->setHeight(54)->setCoordinates('A1')->setOffsetX(5)->setOffsetY(5)->setWorksheet($sheet);}
        $sheet->mergeCells('A5:'.$last.'5')->setCellValueExplicit('A5',$doc['title'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->getStyle('A5:'.$last.'5')->getFill()->setFillType('solid')->getStartColor()->setRGB($dark);
        $sheet->getStyle('A5')->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('FFFFFF');$sheet->getRowDimension(5)->setRowHeight(38);
        $sheet->mergeCells('A6:'.$last.'6')->setCellValueExplicit('A6','Unit: '.$doc['unit_label'].' | '.$doc['subtitle'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->mergeCells('A7:'.$last.'7')->setCellValueExplicit('A7','Dibuat: '.$doc['generated'].' | Petugas: '.$doc['operator'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->getStyle('A6:'.$last.'7')->getFill()->setFillType('solid')->getStartColor()->setRGB($pale);$sheet->getRowDimension(6)->setRowHeight(34);
        $row=9;$filterSet=false;$widths=[];
        foreach($definition['sections'] as $section){
            $sheet->mergeCells('A'.$row.':'.$last.$row)->setCellValueExplicit('A'.$row,$section['title'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->getStyle('A'.$row.':'.$last.$row)->getFill()->setFillType('solid')->getStartColor()->setRGB($pale);$sheet->getStyle('A'.$row)->getFont()->setBold(true)->getColor()->setRGB($dark);$row++;
            $groupHeader=$row;
            if(!empty($section['header_groups'])){
                $columnPositions=array_flip(array_column($section['columns'],'key'));$groupHeight=38;
                foreach($section['header_groups'] as $group){$first=$columnPositions[$group['keys'][0]]+1;$end=$columnPositions[$group['keys'][count($group['keys'])-1]]+1;$range=$letter($first).$row.':'.$letter($end).$row;if($end>$first)$sheet->mergeCells($range);$sheet->setCellValueExplicit($letter($first).$row,$group['label'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);$width=array_sum(array_column(array_slice($section['columns'],$first-1,$end-$first+1),'width'));$groupHeight=max($groupHeight,14+15*(int)ceil(mb_strlen($group['label'])/max(12,$width)));}
                $sheet->getStyle('A'.$row.':'.$last.$row)->applyFromArray(['fill'=>['fillType'=>'solid','startColor'=>['rgb'=>$accent]],'font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']],'alignment'=>['horizontal'=>'center','vertical'=>'center','wrapText'=>true]]);$sheet->getRowDimension($row)->setRowHeight(min(409,$groupHeight));$row++;
            }
            $header=$row;$headerHeight=38;$count=count($section['columns']);$positions=[];
            foreach($section['columns'] as $i=>$column){
                // Short summary tables span the same width as the main table.
                $start=1+(int)floor($i*$n/$count);$end=(int)floor(($i+1)*$n/$count);$positions[]=[$start,$end];
                $range=$letter($start).$row.':'.$letter($end).$row;if($end>$start)$sheet->mergeCells($range);$headerHeight=max($headerHeight,15*(int)ceil(mb_strlen($column['label'])/max(8,$column['width'])));
                $sheet->setCellValueExplicit($letter($start).$row,$column['label'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                if($count===$n)$widths[$start]=max($widths[$start]??0,$column['width']);
            }
            $sheet->getStyle('A'.$row.':'.$last.$row)->applyFromArray(['fill'=>['fillType'=>'solid','startColor'=>['rgb'=>$accent]],'font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']], 'alignment'=>['horizontal'=>'center','vertical'=>'center','wrapText'=>true]]);$sheet->getRowDimension($row)->setRowHeight($headerHeight);$row++;
            foreach($section['rows'] as $data){$height=$global?27:23;
                foreach($section['columns'] as $i=>$column){[$start,$end]=$positions[$i];$cell=$letter($start).$row;$range=$cell.':'.$letter($end).$row;if($end>$start)$sheet->mergeCells($range);
                    $value=array_key_exists($column['key'],$data)?$data[$column['key']]:'';$type=$column['type'];
                    if(in_array($type,['date','datetime'],true)&&($data['time_precision']??'')==='none'){$value='Tanggal dan jam tidak tercatat';$type='text';}
                    $numeric=in_array($type,['money','money_optional','number','count'],true)&&$value!==null&&$value!=='';
                    if($numeric){$sheet->setCellValueExplicit($cell,(float)$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);if(!$global)$sheet->getStyle($cell)->getNumberFormat()->setFormatCode(in_array($type,['money','money_optional'],true)?(fmod((float)$value,1.0)==0.0?'"Rp "#,##0;[Red]"Rp "-#,##0;"Rp "0':'"Rp "#,##0.00;[Red]"Rp "-#,##0.00;"Rp "0.00'):'#,##0');}
                    elseif(in_array($type,['date','datetime'],true)&&$value){try{$date=new DateTimeImmutable((string)$value,new DateTimeZone('Asia/Jakarta'));$sheet->setCellValueExplicit($cell,\PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($date),\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);$sheet->getStyle($cell)->getNumberFormat()->setFormatCode($type==='date'?'dd/mm/yyyy':(($data['time_precision']??'')==='date'?'dd/mm/yyyy "· Jam tidak tercatat"':'dd/mm/yyyy hh:mm:ss "WIB"'));}catch(Throwable $e){$sheet->setCellValueExplicit($cell,spp_excel_text($value),\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);}}
                    else{if($global&&in_array($column['key'],['nis','nis_diknas','export_diknas','NO_INDUK','NO_induk_diknas'],true))$sheet->getCell($cell)->getIgnoredErrors()->setNumberStoredAsText(true);$text=$value===null?'—':spp_excel_text($value);$sheet->setCellValueExplicit($cell,$text,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);$height=max($height,16*(substr_count($text,"\n")+1+(int)floor(mb_strlen($text)/max(12,$column['width']))));}
                    if(!$global){$align=in_array($type,['number','count'],true)?'center':($numeric?'right':(in_array($column['key'],['no','kelas','KELAS','export_class','unit','export_unit','jenis','status','periode'],true)||$type==='html'?'center':'left'));$alignment=$sheet->getStyle($range)->getAlignment();$alignment->setHorizontal($align)->setVertical('center')->setWrapText(true);if($align!=='center')$alignment->setIndent(1);}
                }
                if(($row-$header)%2===0)$sheet->getStyle('A'.$row.':'.$last.$row)->getFill()->setFillType('solid')->getStartColor()->setRGB($global?$pale:'F5F7FA');
                $sheet->getRowDimension($row)->setRowHeight(min(180,$height+5));$row++;
            }
            // Apply invariant Global Report styles once per column, rather than
            // repeatedly cloning workbook styles for every individual cell.
            if($global&&$section['rows'])global_report_excel_body_style($sheet,$section,$positions,$header+1,$row-1,$dark,$pale);
            if(!$section['rows']){$sheet->mergeCells('A'.$row.':'.$last.$row)->setCellValue('A'.$row,'Tidak ada data sesuai filter.');$sheet->getStyle('A'.$row)->getAlignment()->setHorizontal('center');$row++;}
            $endData=$row-1;$sheet->getStyle('A'.$header.':'.$last.$endData)->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setRGB($line);
            if($section['detail']&&!$filterSet){$sheet->setAutoFilter('A'.$header.':'.$last.($section['rows']?$endData:$header));$sheet->freezePane($letter(($global?global_report_excel_identity_count($section):($section['identity_count']??1))+1).($header+1));$sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($groupHeader,$header);$filterSet=true;}
            if(!empty($section['column_totals'])){
                $sheet->setCellValueExplicit('A'.$row,'Subtotal seluruh hasil filter',\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);$sheet->mergeCells('A'.$row.':'.$letter($section['identity_count']).$row);
                foreach($section['columns'] as $i=>$column)if(isset($section['column_totals'][$column['key']])){$cell=$letter($i+1).$row;$sheet->setCellValueExplicit($cell,(float)$section['column_totals'][$column['key']]['value'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);$sheet->getStyle($cell)->getNumberFormat()->setFormatCode('"Rp "#,##0;[Red]"Rp "-#,##0;"Rp "0');}
                $sheet->getStyle('A'.$row.':'.$last.$row)->getFont()->setBold(true);if($global)global_report_excel_total_style($sheet,'A'.$row.':'.$last.$row,$doc['unit']);foreach($section['columns'] as $i=>$column)if(isset($section['column_totals'][$column['key']])&&fmod((float)$section['column_totals'][$column['key']]['value'],1.0)!=0.0)$sheet->getStyle($letter($i+1).$row)->getNumberFormat()->setFormatCode('"Rp "#,##0.00;[Red]"Rp "-#,##0.00;"Rp "0.00');$row++;
            }
            foreach($section['totals'] as $total){$totalColumn=$global?min($n,max(3,min(6,$globalIdentityLimit))):($n>6?4:$n);$totalStart=($global||$n>6)?max(2,$totalColumn-1):$n;$valueCell=$letter($totalStart).$row;if($totalColumn>$totalStart)$sheet->mergeCells($valueCell.':'.$letter($totalColumn).$row);$sheet->mergeCells('A'.$row.':'.$letter($totalStart-1).$row)->setCellValueExplicit('A'.$row,$total['label'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);$sheet->setCellValueExplicit($valueCell,(float)$total['value'],\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);$sheet->getStyle($valueCell)->getNumberFormat()->setFormatCode('"Rp "#,##0;[Red]"Rp "-#,##0;"Rp "0');$sheet->getStyle('A'.$row.':'.$last.$row)->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>$dark]],'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>$pale]]]);$sheet->getStyle($valueCell)->getAlignment()->setHorizontal('right');if($global){global_report_excel_total_style($sheet,'A'.$row.':'.$letter($totalColumn).$row,$doc['unit'],true);if($totalColumn<$n)$sheet->getStyle($letter($totalColumn+1).$row.':'.$last.$row)->getFill()->setFillType('none');}if(fmod((float)$total['value'],1.0)!=0.0)$sheet->getStyle($valueCell)->getNumberFormat()->setFormatCode('"Rp "#,##0.00;[Red]"Rp "-#,##0.00;"Rp "0.00');$row++;}
            foreach($section['notes']??[] as $note){$sheet->mergeCells('A'.$row.':'.$last.$row)->setCellValueExplicit('A'.$row,$note,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);$sheet->getStyle('A'.$row)->getAlignment()->setWrapText(true)->setVertical('top');$sheet->getStyle('A'.$row)->getFont()->setSize(10)->getColor()->setRGB('64748B');$sheet->getRowDimension($row)->setRowHeight(40);$row++;}
            $row+=2;
        }
        $shortWidths=[1=>8,2=>8,3=>32,4=>32,5=>16,6=>16];foreach(range(1,$n) as $c)$sheet->getColumnDimension($letter($c))->setWidth($widths[$c]??(!$hasDetail&&$n===6?$shortWidths[$c]:($c===1?10:32)));
        if(!empty($definition['signatures'])){$mid=max(1,(int)floor($n/2));$sheet->mergeCells('A'.$row.':'.$letter($mid).$row)->setCellValue('A'.$row,'Kasir/Petugas,');$sheet->mergeCells($letter($mid+1).$row.':'.$last.$row)->setCellValue($letter($mid+1).$row,'Bagian Keuangan,');$sheet->getStyle('A'.$row.':'.$last.($row+3))->getAlignment()->setHorizontal('center');$row+=3;$sheet->mergeCells('A'.$row.':'.$letter($mid).$row)->setCellValue('A'.$row,'(________________________)');$sheet->mergeCells($letter($mid+1).$row.':'.$last.$row)->setCellValue($letter($mid+1).$row,'(________________________)');$row+=2;}
        $printEndRow=$sheet->getHighestDataRow();
        $sheet->mergeCells('A'.$row.':'.$last.$row)->setCellValue('A'.$row,'SistemSPP | Data mengikuti filter dan catatan transaksi saat laporan dibuat.');$sheet->getStyle('A'.$row)->getFont()->setSize(9)->getColor()->setRGB('64748B');
        $printWidths=[];foreach(range(1,$n) as $i)$printWidths[$i]=$sheet->getColumnDimension($letter($i))->getWidth()*6;
        $landscape=array_sum($printWidths)>538;$setup=$sheet->getPageSetup();
        $setup->setOrientation($landscape?'landscape':'portrait')->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)->setFitToPage(false)->setScale(100);
        if(!$global && array_sum($printWidths)>($landscape?785:538)){
            $repeat=min(4,$n-1);$setup->setColumnsToRepeatAtLeftByStartAndEnd('A',$letter($repeat));
            $budget=($landscape?785:538)-array_sum(array_slice($printWidths,0,$repeat));$printUsed=0;
            foreach(range($repeat+1,$n) as $i){if($printUsed&&$printUsed+$printWidths[$i]>$budget){$sheet->setBreak($letter($i).'1',\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::BREAK_COLUMN);$printUsed=0;}$printUsed+=$printWidths[$i];}
            spp_excel_print_heading($sheet,$doc,$definition,$repeat);
        }
        $setup->setPrintArea('A1:'.$last.$printEndRow);$sheet->getPageMargins()->setTop(.35)->setBottom(.35)->setLeft(.3)->setRight(.3);$sheet->getHeaderFooter()->setOddFooter('&L'.$doc['unit_label'].' | SistemSPP&RHalaman &P / &N');
        if($global)global_report_excel_finish($sheet,$doc,$definition,$n);
        $repeatColumns=$setup->getColumnsToRepeatAtLeft();
        if(!empty($repeatColumns[1])&&$repeatColumns[1]!==$last){
            $footer='A'.$row.':'.$last.$row;if(isset($sheet->getMergeCells()[$footer]))$sheet->unmergeCells($footer);
            $sheet->mergeCells('A'.$row.':'.$repeatColumns[1].$row);
            $sheet->getStyle('A'.$row)->getAlignment()->setWrapText(true);$sheet->getRowDimension($row)->setRowHeight(30);
        }
        spp_excel_print_group_headers($sheet);
    }
    $book->setActiveSheetIndex(0);return $book;
}
/** Only matrix previews freeze their identity columns; other exports keep the legacy markup. */
function spp_excel_matrix_cell_style(array $section,int $index):string {
    if(!isset($section['identity_count'])||$index>=$section['identity_count'])return '';
    $left=0;foreach(array_slice($section['columns'],0,$index) as $column)$left+=(int)$column['width']*7+24;
    return ' style="position:sticky;left:'.$left.'px;min-width:'.((int)$section['columns'][$index]['width']*7+24).'px;z-index:2"';
}
function spp_excel_preview_html(array $doc): string {
    if(($doc['presentation']??'')==='global'){require_once __DIR__.'/global_report_excel.php';return global_report_excel_preview($doc);}
    $e=static fn($v)=>htmlspecialchars(spp_excel_text($v),ENT_QUOTES,'UTF-8');[$accent,$dark,$pale]=spp_excel_palette($doc['unit']);$logo=dirname(__DIR__).'/assets/img/school-logo.png';$image=is_file($logo)?'data:image/png;base64,'.base64_encode(file_get_contents($logo)):'';
    if(($doc['presentation']??'')==='global')$accent=$dark=[1=>'165C44',2=>'203E80',3=>'832A35',0=>'513080'][$doc['unit']]??$dark;
    ob_start(); ?>
    <!doctype html><html lang="id"><meta charset="utf-8"><style>body{margin:0;padding:26px;font:14px Calibri,Arial,sans-serif;color:#1e293b;background:#fff}*{box-sizing:border-box}.head{display:flex;gap:18px;align-items:center;margin-bottom:20px}.head img{width:64px;height:64px;object-fit:contain}.head h1{font-size:23px;color:#<?= $dark ?>;margin:0 0 5px}.head p{margin:0;font-size:12px}.banner{background:#<?= $dark ?>;color:#fff;padding:15px 18px;font-size:20px;font-weight:bold}.meta{background:#<?= $pale ?>;padding:10px 18px;line-height:1.6;font-size:13px}.sheet{margin:24px 0}.sheet h2{font-size:18px;color:#<?= $dark ?>}.section-title{background:#<?= $pale ?>;color:#<?= $dark ?>;padding:9px 12px;font-size:15px;margin:20px 0 0}.scroll{overflow:auto}table{border-collapse:collapse;width:100%;margin:0 0 14px}th,td{border:1px solid #d9e2eb;padding:9px 12px;vertical-align:middle}th{background:#<?= $accent ?>;color:#fff;text-align:center}td{white-space:pre-line}tbody tr:nth-child(even){background:#f5f7fa}.matrix-excel tbody td{background:#fff}.matrix-excel tbody tr:nth-child(even) td{background:#f5f7fa}.money{text-align:right;white-space:nowrap}.center{text-align:center}.total td{background:#<?= $pale ?>;color:#<?= $dark ?>;font-weight:bold}.sign{display:flex;justify-content:space-around;text-align:center;margin:30px 0}.sign p{margin-top:55px}.foot{font-size:11px;color:#64748b;border-top:1px solid #d9e2eb;padding-top:10px}@media(max-width:600px){body{padding:14px}.head h1{font-size:18px}.banner{font-size:17px}table{min-width:600px}.meta{padding:10px}.head{gap:10px}}<?php if(($doc['presentation']??'')==='global'): ?>th{font-size:13px;text-transform:uppercase}td{font-size:14px}tbody tr:nth-child(even),.matrix-excel tbody tr:nth-child(even) td{background:#<?= $pale ?>}.matrix-excel .total td{background:#<?= $pale ?>}.excel-badge{display:inline-block;border-radius:12px;padding:3px 9px;color:#<?= $dark ?>;background:#<?= $pale ?>;font-weight:bold;font-size:12px}.excel-badge-kelas{color:#a66300;background:#fff4e4}.section-title{font-size:18px;background:transparent;padding:12px 0}<?php endif; ?></style><div class="head"><img src="<?= $image ?>" alt="Logo sekolah"><div><h1><?= $e($doc['school']) ?></h1><p>Perum Bekasi Griya Asri II, Tambun Selatan | Telp. 021-88363466</p></div></div><div class="banner"><?= $e($doc['title']) ?></div><div class="meta">Unit: <?= $e($doc['unit_label']) ?> | <?= $e($doc['subtitle']) ?><br>Dibuat: <?= $e($doc['generated']) ?> | Petugas: <?= $e($doc['operator']) ?></div>
    <?php foreach($doc['sheets'] as $sheet): ?><section class="sheet<?= isset($doc['matrix_page'])?' matrix-excel':'' ?>"><?php if(($doc['presentation']??'')!=='global'): ?><h2><?= $e($sheet['name']) ?></h2><?php endif; ?><?php foreach($sheet['sections'] as $section): ?><h3 class="section-title"><?= $e($section['title']) ?></h3><div class="scroll"><table><thead><?php if(!empty($section['header_groups'])): ?><tr><th colspan="<?= $section['identity_count'] ?>"></th><?php foreach($section['header_groups'] as $group): ?><th colspan="<?= count($group['keys']) ?>"><?= $e($group['label']) ?></th><?php endforeach; ?></tr><?php endif; ?><tr><?php foreach($section['columns'] as $ci=>$col): ?><th<?= spp_excel_matrix_cell_style($section,$ci) ?>><?= $e($col['label']) ?></th><?php endforeach; ?></tr></thead><tbody><?php if(!$section['rows']): ?><tr><td colspan="<?= count($section['columns']) ?>" class="center">Tidak ada data sesuai filter.</td></tr><?php endif; ?><?php foreach($section['rows'] as $row): ?><tr><?php foreach($section['columns'] as $ci=>$col): $v=array_key_exists($col['key'],$row)?$row[$col['key']]:'';$money=in_array($col['type'],['money','money_optional'],true); ?><td class="<?= $money?'money':(in_array($col['key'],['no','kelas','KELAS','export_class','unit','export_unit','jenis','status','periode'],true)||in_array($col['type'],['html','number','count'],true)?'center':'') ?>"<?= spp_excel_matrix_cell_style($section,$ci) ?>><?php if(($doc['presentation']??'')==='global'&&(in_array($col['key'],['nis','kelas','status'],true)||$col['type']==='status')): ?><span class="excel-badge excel-badge-<?= $e($col['key']) ?>"><?= $v===null?'—':$e($v) ?></span><?php else: ?><?= $money&&$v!==''&&$v!==null?'Rp '.number_format((float)$v,fmod((float)$v,1.0)==0.0?0:2,',','.'):($v===null?'—':(in_array($col['type'],['date','datetime'],true)&&$v?spp_date_label($v,$col['type']==='datetime',$row['time_precision']??null):$e($v))) ?><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?><?php if(!empty($section['column_totals'])): ?><tr class="total"><?php foreach($section['columns'] as $i=>$col): ?><td<?= $i>=$section['identity_count']?' class="money"':'' ?>><?= $i===0?'Subtotal seluruh hasil filter':(isset($section['column_totals'][$col['key']])?'Rp '.number_format($section['column_totals'][$col['key']]['value'],fmod((float)$section['column_totals'][$col['key']]['value'],1.0)==0.0?0:2,',','.'):'') ?></td><?php endforeach; ?></tr><?php endif; ?><?php foreach($section['totals'] as $total): ?><tr class="total"><td colspan="<?= max(1,count($section['columns'])-1) ?>"><?= $e($total['label']) ?></td><td class="money">Rp <?= number_format($total['value'],fmod((float)$total['value'],1.0)==0.0?0:2,',','.') ?></td></tr><?php endforeach; ?></tbody></table></div><?php foreach($section['notes']??[] as $note): ?><p class="meta"><?= $e($note) ?></p><?php endforeach; ?><?php endforeach; ?><?php if(!empty($sheet['signatures'])): ?><div class="sign"><div>Kasir/Petugas,<p>(________________________)</p></div><div>Bagian Keuangan,<p>(________________________)</p></div></div><?php endif; ?></section><?php endforeach; ?><p class="foot">SistemSPP | Data mengikuti filter dan catatan transaksi saat laporan dibuat.</p></html>
    <?php return ob_get_clean();
}

/** Serialize real column indices even with the installed 1.30 XLSX writer. */
function spp_excel_save(\PhpOffice\PhpSpreadsheet\Spreadsheet $book,string $path): void {
    $writer=new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);$writer->setPreCalculateFormulas(false);$writer->save($path);
    // PhpSpreadsheet 1.30.7 casts the letter part of a column break to int,
    // producing id=-1. Normalize this small XML block; financial cells are untouched.
    $zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('Berkas Excel tidak dapat diselesaikan.');
    try{
        foreach($book->getAllSheets() as $index=>$sheet){
            $breaks=[];foreach($sheet->getBreaks() as $cell=>$kind)if($kind===\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::BREAK_COLUMN){
                [$column]=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString($cell);
                $breaks[]=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($column)-1;
            }
            if(!$breaks)continue;
            $entry='xl/worksheets/sheet'.($index+1).'.xml';$xml=$zip->getFromName($entry);
            if($xml===false)throw new RuntimeException('Lembar Excel tidak lengkap.');
            $dom=new DOMDocument();if(!$dom->loadXML($xml,LIBXML_NONET))throw new RuntimeException('Lembar Excel tidak valid.');
            $xpath=new DOMXPath($dom);$xpath->registerNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $nodes=$xpath->query('/s:worksheet/s:colBreaks/s:brk');
            if($nodes->length!==count($breaks))throw new RuntimeException('Batas halaman Excel tidak sesuai.');
            foreach($nodes as $i=>$node){$node->setAttribute('id',(string)$breaks[$i]);$node->setAttribute('min','0');$node->setAttribute('max','1048575');}
            if(!$zip->addFromString($entry,$dom->saveXML()))throw new RuntimeException('Batas halaman Excel gagal disimpan.');
        }
    }finally{if(!$zip->close())throw new RuntimeException('Berkas Excel gagal diselesaikan.');}
}
function spp_excel_respond(array $doc,bool $download,string $filename,string $downloadUrl,string $backUrl,int $rowCount): void {
    if($download){
        require_once __DIR__.'/report_artifact.php';
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        $stable=$doc;unset($stable['generated']);$query=$_GET;unset($query['download'],$query['format'],$query['output']);
        $artifact=report_artifact_get(['route'=>$_SERVER['SCRIPT_NAME']??'excel','unit'=>$doc['unit'],'query'=>$query],$stable,'xlsx',static function()use($doc){
            $book=spp_excel_workbook($doc);report_perf_mark('workbook');$temp=tempnam(sys_get_temp_dir(),'spp_xlsx_');
            try{spp_excel_save($book,$temp);$bytes=file_get_contents($temp);report_perf_mark('xlsx');return $bytes;}
            finally{if(is_file($temp))unlink($temp);$book->disconnectWorksheets();}
        },($doc['presentation']??'')==='global'||$doc['title']==='Rekap Laporan Transaksi');
        report_artifact_stream($artifact,preg_replace('/[^a-zA-Z0-9_-]/','-',$filename).'.xlsx','xlsx',true);
    }
    require_once __DIR__.'/report_preview.php';render_report_export_preview(spp_excel_preview_html($doc),['presentation'=>$doc['presentation']??'','unit'=>$doc['unit'],'file_type'=>'EXCEL','show_print'=>false,'title'=>$doc['title'],'subtitle'=>$doc['subtitle'],'generated'=>$doc['generated'],'row_count'=>$rowCount,'orientation'=>'landscape','download_url'=>$downloadUrl,'back_url'=>$backUrl]);exit;
}
