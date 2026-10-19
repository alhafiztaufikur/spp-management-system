<?php
/** Physical layout only; never changes ledger amounts or ordering. */
function savings_book_layout_defaults(): array {
    return ['x_top'=>8.0,'y_top'=>6.0,'x_bottom'=>8.0,'y_bottom'=>110.0,'header'=>10.0,
        'row_height'=>6.0,'rows_top'=>14,'rows_bottom'=>14,'text_offset'=>1.4,
        'w_no'=>7.0,'w_date'=>17.0,'w_in'=>34.0,'w_out'=>34.0,'w_balance'=>26.0,'w_sign'=>14.0];
}
function savings_book_print_settings(array $source, int $count): array {
    $layout=savings_book_layout_defaults();$raw=$source['layout']??[];
    if(!is_array($raw)||array_diff(array_keys($raw),array_keys($layout)))throw new InvalidArgumentException('Pengaturan kalibrasi tidak valid.');
    foreach($raw as $key=>$value){
        if(!is_scalar($value)||!preg_match('/^\d{1,3}(?:\.\d{1,3})?$/D',(string)$value))throw new InvalidArgumentException('Ukuran kalibrasi harus berupa angka milimeter.');
        $layout[$key]=(float)$value;
    }
    foreach(['rows_top','rows_bottom'] as $key){if($layout[$key]!=floor($layout[$key])||$layout[$key]<1||$layout[$key]>40)throw new InvalidArgumentException('Jumlah baris harus 1 sampai 40.');$layout[$key]=(int)$layout[$key];}
    $width=0;foreach(['w_no','w_date','w_in','w_out','w_balance','w_sign'] as $key){if($layout[$key]<3)throw new InvalidArgumentException('Lebar kolom minimal 3 mm.');$width+=$layout[$key];}
    if($layout['row_height']<3.5||$layout['row_height']>12||$layout['header']<6||$layout['header']>20||$layout['text_offset']+4>$layout['row_height'])throw new InvalidArgumentException('Tinggi baris atau posisi teks tidak mencukupi.');
    if($layout['x_top']<3||$layout['x_bottom']<3||max($layout['x_top'],$layout['x_bottom'])+$width>145||$layout['y_top']<1||$layout['y_top']+$layout['header']+$layout['rows_top']*$layout['row_height']>100.001||$layout['y_bottom']<109.7||$layout['y_bottom']+$layout['rows_bottom']*$layout['row_height']>201.001)throw new InvalidArgumentException('Area cetak melewati margin PLQ-20 atau lipatan buku. Sesuaikan posisi, jumlah, atau tinggi baris.');
    $integer=static function(string $key,int $default,int $min,int $max)use($source):int{
        $v=$source[$key]??(string)$default;if(!is_scalar($v)||!preg_match('/^\d{1,9}$/D',(string)$v)||($n=(int)$v)<$min||$n>$max)throw new InvalidArgumentException('Rentang transaksi atau baris awal tidak valid.');return $n;
    };
    $side=$source['start_side']??'top';if(!is_string($side)||!in_array($side,['top','bottom'],true))throw new InvalidArgumentException('Halaman awal tidak valid.');
    $from=$integer('entry_from',$count?1:0,$count?1:0,$count);$to=$integer('entry_to',$count,$count?1:0,$count);
    if($from>$to)throw new InvalidArgumentException('Transaksi awal harus mendahului transaksi akhir.');
    $test=$source['calibration']??'0';if(!is_scalar($test)||!in_array((string)$test,['0','1'],true))throw new InvalidArgumentException('Mode uji tidak valid.');
    return ['layout'=>$layout,'entry_from'=>$from,'entry_to'=>$to,'start_side'=>$side,'start_row'=>$integer('start_row',1,1,$layout['rows_'.$side]),'calibration'=>(string)$test==='1'];
}
function savings_book_positioned(array $book,array $settings):array {
    $layout=$settings['layout'];$entries=$settings['entry_from']?array_slice($book['entries'],$settings['entry_from']-1,$settings['entry_to']-$settings['entry_from']+1):[];
    if($settings['calibration']){
        $entries=[];for($i=1;$i<=$layout['rows_top']+$layout['rows_bottom'];$i++)$entries[]=['number'=>$i,'tanggal'=>'2026-10-18','masuk'=>10000000,'keluar'=>0,'saldo'=>$i*10000000];
    }
    $pages=[[]];$page=0;$side=$settings['calibration']?'top':$settings['start_side'];$row=$settings['calibration']?1:$settings['start_row'];
    foreach($entries as $entry){
        if($row>$layout['rows_'.$side]){if($side==='top')$side='bottom';else{$side='top';$page++;$pages[$page]=[];}$row=1;}
        $pages[$page][]=['entry'=>$entry,'side'=>$side,'row'=>$row++];
    }
    return $pages;
}
