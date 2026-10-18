<?php
require_once __DIR__.'/savings_book.php';
require_once __DIR__.'/savings_book_layout.php';

/** Same physical coordinates for guide, production and calibration. */
function savings_book_pdf(array $book,array $settings,bool $guide=false): \Dompdf\Dompdf {
    require_once __DIR__.'/pdf.php';require_pdf_library();
    $pages=savings_book_positioned($book,$settings);$layout=$settings['layout'];
    $options=new \Dompdf\Options();$options->setIsRemoteEnabled(false);
    $pdf=new \Dompdf\Dompdf($options);
    $pdf->loadHtml('<html><head><style>@page{margin:0}body{margin:0}.sheet{page-break-after:always}.sheet:last-child{page-break-after:auto}</style></head><body>'.str_repeat('<div class="sheet">&nbsp;</div>',count($pages)).'</body></html>');
    $pdf->setPaper([0,0,148*72/25.4,210*72/25.4]);$pdf->render();
    $pdf->getCanvas()->page_script(static function($number,$count,$canvas,$fonts)use($pages,$layout,$guide,$settings){
        $mm=72/25.4;$font=$fonts->getFont('DejaVu Sans','bold');$black=[0,0,0];
        $widths=array_map(static fn($key)=>$layout[$key],['w_no','w_date','w_in','w_out','w_balance','w_sign']);$width=array_sum($widths);
        $text=static function(float $x,float $y,float $width,string $value,float $size=8.5,bool $right=false)use($canvas,$fonts,$font,$mm,$black){
            while($size>6.5&&$fonts->getTextWidth($value,$font,$size)>($width-1.6)*$mm)$size-=.25;
            if($fonts->getTextWidth($value,$font,$size)>($width-1.6)*$mm)throw new InvalidArgumentException('Isi tidak muat pada lebar kolom. Perbesar kolom sebelum mencetak.');
            $left=($x+$width/2)*$mm-$fonts->getTextWidth($value,$font,$size)/2;
            $canvas->text($left,$y*$mm,$value,$font,$size,$black);
        };
        if($guide||$settings['calibration']){
            foreach(['top','bottom'] as $side){
                $x=$layout['x_'.$side];$y=$side==='top'?$layout['y_top']+$layout['header']:$layout['y_bottom'];$rows=$layout['rows_'.$side];$bottom=$y+$rows*$layout['row_height'];
                for($r=0;$r<=$rows;$r++)$canvas->line($x*$mm,($y+$r*$layout['row_height'])*$mm,($x+$width)*$mm,($y+$r*$layout['row_height'])*$mm,[.5,.5,.5],.35);
                $cx=$x;foreach(array_merge([0],$widths) as $cw){$cx+=$cw;$canvas->line($cx*$mm,$y*$mm,$cx*$mm,$bottom*$mm,[.5,.5,.5],.35);}
                if($side==='top'){
                    $top=$layout['y_top'];$canvas->rectangle($x*$mm,$top*$mm,$width*$mm,$layout['header']*$mm,[.3,.3,.3],.4);
                    $cx=$x;foreach($widths as $i=>$cw){if($i)$canvas->line($cx*$mm,($i===3?$top+$layout['header']/2:$top)*$mm,$cx*$mm,$y*$mm,[.3,.3,.3],.4);$cx+=$cw;}
                    $tx=$x;foreach(['No','Tanggal','Masuk','Keluar','Jumlah','Paraf'] as $i=>$label){$text($tx,$top+($i===2||$i===3?$layout['header']*.75-1.3:$layout['header']/2-1.3),$widths[$i],$label,7);$tx+=$widths[$i];}
                    $gx=$x+$widths[0]+$widths[1];$text($gx,$top+$layout['header']*.25-1.3,$widths[2]+$widths[3],'Tabungan',7.5);
                    $canvas->line($gx*$mm,($top+$layout['header']/2)*$mm,($gx+$widths[2]+$widths[3])*$mm,($top+$layout['header']/2)*$mm,[.3,.3,.3],.4);
                }
            }
            $canvas->line(3*$mm,105*$mm,145*$mm,105*$mm,[.7,.7,.7],.25);
        }
        foreach($pages[$number-1]??[] as $position){
            $entry=$position['entry'];$side=$position['side'];$x=$layout['x_'.$side];$y=($side==='top'?$layout['y_top']+$layout['header']:$layout['y_bottom'])+($position['row']-1)*$layout['row_height']+$layout['text_offset'];
            $values=[(string)$entry['number'],date('d/m/Y',strtotime($entry['tanggal'])),$entry['masuk']?savings_book_money($entry['masuk']):'',$entry['keluar']?savings_book_money($entry['keluar']):'',savings_book_money($entry['saldo']),''];
            foreach($values as $i=>$value){if($value!=='')$text($x,$y,$widths[$i],$value,$i===1?7.5:8.5,$i>=2);$x+=$widths[$i];}
        }
    });
    return $pdf;
}
