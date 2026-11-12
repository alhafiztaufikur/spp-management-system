<?php
/** Print-only layout; amounts, sections and descriptions come from the existing projection. */
function billing_history_print_font(): array {
    static $font=null;
    if($font!==null)return $font;
    $bundled=__DIR__.'/../vendor/dompdf/dompdf/lib/fonts/';
    $font=['family'=>'DejaVu Serif','normal'=>$bundled.'DejaVuSerif.ttf','bold'=>$bundled.'DejaVuSerif-Bold.ttf'];
    require_once __DIR__.'/pdf.php';require_pdf_library();
    if(PHP_OS_FAMILY!=='Windows')return $font;
    $root=realpath((getenv('WINDIR')?:'C:/Windows').'/Fonts');
    if(!$root||!is_readable($root.'/times.ttf')||!is_readable($root.'/timesbd.ttf'))return $font;
    $lock=null;
    try{
        $options=new \Dompdf\Options();$options->setIsRemoteEnabled(false);$options->setChroot([$root]);
        $pdf=new \Dompdf\Dompdf($options);$metrics=$pdf->getFontMetrics();
        $ready=static function(array $faces):bool{
            foreach(['normal','bold'] as $face){$path=$faces[$face]??'';if(!$path||!is_readable($path.'.ttf')||!is_readable($path.'.ufm'))return false;}return true;
        };
        if(!$ready($metrics->getFontFamilies()['times new roman']??[])){
            $lock=@fopen($options->getFontDir().'/spp-billing-times.lock','c');
            if(!$lock||!flock($lock,LOCK_EX))return $font;
            // Reload the family registry after acquiring the shared font-cache lock.
            $pdf=new \Dompdf\Dompdf($options);$metrics=$pdf->getFontMetrics();
            if(!$ready($metrics->getFontFamilies()['times new roman']??[])){
                $metrics->setFontFamily('Times New Roman',[]);
                foreach([400=>'times.ttf',700=>'timesbd.ttf'] as $weight=>$file){
                    $uri='file://'.str_replace('\\','/',$root.'/'.$file);
                    if(!$metrics->registerFont(['family'=>'Times New Roman','weight'=>$weight,'style'=>'normal'],$uri))return $font;
                }
            }
        }
        $font=['family'=>'Times New Roman','normal'=>$root.'/times.ttf','bold'=>$root.'/timesbd.ttf'];
    }catch(\Throwable $error){error_log('Billing print font unavailable; using the bundled serif font.');}
    finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
    return $font;
}
function billing_history_print_styles(bool $pdf=false):string {
    $font=billing_history_print_font();$css='';
    if(!$pdf)foreach(['normal'=>400,'bold'=>700] as $face=>$weight){
        $css.='@font-face{font-family:"'.$font['family'].'";font-weight:'.$weight.';font-style:normal;src:url("data:font/ttf;base64,'.base64_encode((string)file_get_contents($font[$face])).'") format("truetype")}';
    }
    $css.='.billing-print-table{font-family:"'.$font['family'].'",serif}';
    return $css.file_get_contents(__DIR__.'/../assets/css/billing_history_print.css');
}
function billing_history_print_amount(array $amount,string $component=''):string {
    $amount=billing_history_display_amount($amount,$component);
    if(empty($amount['known']))return '<span class="billing-print-unknown">'.report_e(billing_history_amount($amount)).'</span>';
    return '<span class="billing-print-money">'.report_e(substr(report_money($amount['value']),3)).'</span>'.(!empty($amount['unknown'])?'<span class="billing-print-unknown"> + belum diketahui</span>':'');
}
function billing_history_print_table(array $view,?array $visible=null,int $offset=0,bool $finalSubtotalOnly=false,bool $showGrand=true):string {
    $visible??=$view['rows'];$contexts=[];
    foreach($visible as $i=>$row){$context=json_encode([$row['unit_id'],$row['year']],JSON_THROW_ON_ERROR);$contexts[$context][]=['row'=>$row,'no'=>$offset+$i+1];}
    ob_start();
    if(!$visible)echo '<p class="billing-empty" role="status">Tidak ada data sesuai filter.</p>';
    foreach($contexts as $entries){$first=$entries[0]['row'];$count=5+count($view['columns']); ?>
    <section class="billing-print-section"><table class="billing-print-table">
    <colgroup><col style="width:3%"><col style="width:10%"><col style="width:17%"><?php foreach($view['columns'] as $_): ?><col style="width:<?= 36/max(1,count($view['columns'])) ?>%"><?php endforeach; ?><col style="width:10%"><col style="width:24%"></colgroup>
    <thead><tr class="billing-print-year"><th colspan="<?= $count ?>">Tahun Ajaran: <?= report_e($first['year']) ?><?= report_query_unit_id()===0?' · '.report_e(unit_label((int)$first['unit_id'])):'' ?></th></tr>
    <tr class="billing-print-columns"><th class="billing-print-no">NO</th><th class="billing-print-nis">NO INDUK</th><th>NAMA SISWA</th><?php foreach($view['columns'] as $columnKey=>$label): ?><th class="billing-print-numeric"><?= report_e(mb_strtoupper(billing_history_column_label($columnKey,$label))) ?></th><?php endforeach; ?><th class="billing-print-numeric billing-print-total">TOTAL</th><th class="billing-print-description">KETERANGAN TAGIHAN</th></tr></thead><tbody>
    <?php $prior=null;foreach($entries as $i=>$entry):$row=$entry['row'];$section=$view['sections'][$row['section_key']];$next=$entries[$i+1]['row']??null;$last=!$next||$next['section_key']!==$row['section_key'];
        if($prior!==$row['section_key']):$continuation=$row['key']!==$section['rows'][0]['key']; ?>
    <tr class="billing-print-group"><th colspan="<?= $count ?>">KELAS: <?= report_e($section['kelas']) ?><?= $continuation?' (lanjutan)':'' ?></th></tr>
    <?php endif;$prior=$row['section_key']; ?>
    <tr class="billing-print-student<?= $last?' billing-print-last':'' ?>"><td class="billing-print-no"><?= $entry['no'] ?></td><td class="billing-print-nis"><?= report_e($row['nis']) ?></td><td class="billing-print-name"><?= report_e($row['nama']) ?></td>
    <?php foreach($view['columns'] as $key=>$_): ?><td class="billing-print-numeric"><?= billing_history_print_amount($row['amounts'][$key]??[],$key) ?></td><?php endforeach; ?><td class="billing-print-numeric billing-print-total"><?= billing_history_print_amount($row['total']) ?></td><td class="billing-print-description"><?= report_e($row['keterangan']) ?></td></tr>
    <?php if($last&&(!$finalSubtotalOnly||$row['key']===end($section['rows'])['key'])): ?>
    <tr class="billing-print-subtotal"><th colspan="3">JUMLAH:</th><?php foreach($view['columns'] as $key=>$_): ?><td class="billing-print-numeric"><?= billing_history_print_amount($section['amounts'][$key]??[],$key) ?></td><?php endforeach; ?><td class="billing-print-numeric billing-print-total"><?= billing_history_print_amount($section['total']) ?></td><td class="billing-print-description"></td></tr>
    <?php endif;endforeach; ?></tbody></table></section>
    <?php }if($showGrand): ?><p class="billing-grand-total"><strong>Total Tunggakan Keseluruhan: <?= report_e(billing_history_grand_amount($view['grand']['total'])) ?></strong></p><?php endif;
    return ob_get_clean();
}
