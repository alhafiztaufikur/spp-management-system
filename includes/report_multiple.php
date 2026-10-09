<?php
require_once __DIR__.'/filter_choices.php';
function report_multiple_normalize(mysqli $db,array &$source):array{
    $classes=[];$bounds=($GLOBALS['app_unit_id']??1)===0?[1,12]:report_query_level_bounds();foreach(range(...$bounds) as $level)$classes['tingkat:'.$level]='Tingkat '.$level;
    foreach(report_classes($db) as $class)$classes['rombel:'.$class['id']]=$class['label'];
    $years=array_column(report_years($db),'label','label');$operators=array_column(report_operator_options($db),'nama','id');
    $spec=[
        'kategori'=>[report_categories($db),($source['template']??'')==='penerimaan'?'semua':'spp','semua'],
        'kelas'=>[$classes,'',''], 'tahun_ajaran'=>[$years,du_current_academic_year(),array_key_first($years)??du_current_academic_year()],
        'bulan_awal'=>[report_months(),date('m'),date('m')],
        'status'=>[array_fill_keys(['tidak_ditagihkan','belum_bayar','cicilan','lunas','rekonsiliasi','ada_pembayaran','tunggakan','dibatalkan'],''),'',''],
        'siswa_status'=>[['active'=>'Aktif','archived'=>'Arsip/Lulus'],($source['template']??'')==='riwayat-tagihan'?'all':'active','all'],
        'metode'=>[['Tunai'=>'Tunai','VA'=>'VA','Qris'=>'QRIS'],'',''], 'mutasi'=>[['masuk'=>'Masuk','keluar'=>'Keluar'],'',''],
        'saldo_status'=>[['ada_saldo'=>'Ada Saldo','saldo_nol'=>'Saldo Nol'],'',''], 'operator'=>[$operators,'',''],
        'komponen_tagihan'=>[report_billing_categories($db),'',''],
    ];$multi=[];
    foreach($spec as $key=>[$options,$default,$all]){
        // Range boundary months are scalar; only Status has a month selection filter.
        if($key==='bulan_awal'&&($source['template']??'')!=='status')continue;
        $values=filter_register($key,$source[$key]??null,$options,$default,$all);
        if(is_array($source[$key]??null)||($source[$key]??null)==='*'){$multi[$key]=$values;$source[$key]=filter_scalar($values,$all);}
    }
    return $multi;
}
function report_multiple_values(array $filters,string $name):array{return $filters['_multi'][$name]??[(string)($filters[$name]??'')];}
function report_multi_operator_sql(array $filters,string $expression):string{
    $values=$filters['_multi']['operator']??['*'];if(filter_is_all($values))return '';
    return ' AND EXISTS (SELECT 1 FROM admin mop WHERE ('.report_sql_ci_eq('CAST(mop.id AS CHAR)','CAST('.$expression.' AS CHAR)').' OR '.report_sql_ci_eq('mop.username','CAST('.$expression.' AS CHAR)').' OR '.report_sql_ci_eq('mop.nama','CAST('.$expression.' AS CHAR)').')'.filter_sql_values($values,'mop.id').')';
}
function report_multiple_sections(mysqli $db,string $template,array $filters):?array{
    if(!empty($filters['_multiple_leaf'])||!array_intersect(['kategori','tahun_ajaran','bulan_awal'],array_keys($filters['_multi']??[])))return null;
    if(!in_array($template,['status','per-item','penerimaan','spp-tahunan'],true))return null;
    $categories=in_array($template,['status','per-item','penerimaan'],true)?report_multiple_values($filters,'kategori'):['spp'];
    if(filter_is_all($categories)||$categories===['semua']){$categories=array_keys(report_categories($db));if($template==='penerimaan')$categories[]='potongan';}
    $years=report_multiple_values($filters,'tahun_ajaran');if(filter_is_all($years))$years=array_column(report_years($db),'label');
    $months=report_multiple_values($filters,'bulan_awal');if(filter_is_all($months))$months=array_keys(report_months());
    $parts=[];
    foreach($categories as $category){
        $yearBased=$template!=='penerimaan'&&($template==='spp-tahunan'||($template==='status'&&in_array($category,['spp','komite'],true))||report_item_is_annual_category($category));
        foreach($yearBased?$years:[''] as $year)foreach($template==='status'&&in_array($category,['spp','komite'],true)?$months:[''] as $month){
            $leaf=$filters;$leaf['_multiple_leaf']=true;$leaf['kategori']=$category;if($year!=='')$leaf['tahun_ajaran']=$year;if($month!=='')$leaf['bulan_awal']=$month;
            unset($leaf['_multi']['kategori'],$leaf['_multi']['tahun_ajaran'],$leaf['_multi']['bulan_awal']);
            $part=report_build($db,$template,$leaf);$part['section_title']=($category==='potongan'?'Potongan SPP':(report_categories($db)[$category]??$part['title'])).($year!==''?' — '.$year:'');
            if($template==='per-item'&&report_item_is_monthly_category($category)){
                $matrixYears=array_values(array_unique(array_column($part['rows'],'tahun_ajaran')));sort($matrixYears);
                if(count($matrixYears)>1){foreach($matrixYears as $matrixYear){$sub=$part;$sub['section_title'].=' | '.$matrixYear;$sub['rows']=array_values(array_filter($part['rows'],static fn($r)=>$r['tahun_ajaran']===$matrixYear));$parts[]=$sub;}continue;}
            }
            $parts[]=$part;
        }
    }
    if(count($parts)===1)return $parts[0];
    $report=['title'=>report_registry()[$template]['label'],'subtitle'=>'Beberapa pilihan · '.count($parts).' bagian laporan','columns'=>[],'rows'=>[],'sections'=>$parts];$keys=[];
    foreach($parts as $index=>$part){foreach($part['columns'] as $column)if(!isset($keys[$column[0]])){$keys[$column[0]]=true;$report['columns'][]=$column;}
        foreach($part['rows'] as $row){$row['_section']=$index;$report['rows'][]=$row;}}
    $identities=[];foreach($report['rows'] as $row)$identities[unit_student_key($row)]=true;$report['student_count']=count($identities);
    return $report;
}
function report_multiple_query(array $filters):array{
    $query=$filters;unset($query['_multi'],$query['_multiple_leaf']);foreach($filters['_multi']??[] as $key=>$values)$query[$key]=$values;return $query;
}
function report_section_html(array $section,int $start=0):string{
    ob_start();?><section class="report-multiple-section"><h3><?= report_e($section['section_title']??$section['title']) ?></h3><p><?= report_e($section['subtitle']) ?></p><div class="table-container report-standard-scroll"><table class="payment-table data"><thead><tr><th>No</th><?php foreach($section['columns'] as $column):?><th><?= report_e($column[1]) ?></th><?php endforeach;?></tr></thead><tbody><?php if(!$section['rows']):?><tr><td colspan="<?= count($section['columns'])+1 ?>">Tidak ada data sesuai filter.</td></tr><?php endif;?><?php foreach($section['rows'] as $index=>$row):?><tr><td><?= $start+$index+1 ?></td><?php foreach($section['columns'] as $column):$value=$row[$column[0]]??'';$type=$column[2]??'text';?><td class="<?= $type==='money'||$type==='money_optional'?'money nominal report-col-money':(in_array($column[0],['kelas','unit','status'],true)?'report-col-center':'') ?>"><?php if(in_array($type,['money','money_optional'],true)):?><?= $value===null||$value===''?'—':report_money($value) ?><?php elseif(is_array($value)):?><?= report_e($value['text']??'') ?><br><small><?= report_e($value['sub']??'') ?></small><?php elseif(in_array($type,['date','datetime'],true)):?><?= report_e(spp_date_label($value,$type==='datetime')) ?><?php else:?><?= report_e($value) ?><?php endif;?><?php if($column[0]==='nis'&&($row['nis_diknas']??$row['diknas']??'')!==''):?><small class="report-secondary-id">Diknas <?= report_e($row['nis_diknas']??$row['diknas']) ?></small><?php endif;?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></div><div class="report-multiple-subtotals"><?php foreach($section['section_totals']??report_money_totals($section) as $total):?><span><?= report_e($total['label']) ?>: <strong><?= report_money($total['value']) ?></strong></span><?php endforeach;?></div></section><?php return ob_get_clean();
}
