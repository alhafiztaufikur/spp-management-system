<?php
/** Presentation only: keep each source row and value intact; never allocate payments here. */
require_once __DIR__.'/global_report_ui.php';
require_once __DIR__.'/global_report_layout.php';

function global_report_matrix(array $report,string $template,int $unit):?array {
    if(!in_array($template,['status','penerimaan','per-item','spp-tahunan'],true))return null;
    $parts=$report['sections']??[$report];
    if($template==='per-item'){
        $expanded=[];
        foreach($parts as $part){
            $years=array_values(array_unique(array_column($part['rows'],'tahun_ajaran')));
            if(count($years)<2){$expanded[]=$part;continue;}
            foreach($years as $year){$copy=$part;$copy['section_title']=($part['section_title']??$part['title']).' · '.$year;$copy['rows']=array_values(array_filter($part['rows'],static fn($row)=>($row['tahun_ajaran']??null)===$year));$expanded[]=$copy;}
        }
        $parts=$expanded;
    }
    $identities=[['no','No','number']];
    if($unit===0)$identities[]=['unit','Unit'];
    $identities=array_merge($identities,[['nis','NIS','nis'],['nama','Nama Siswa'],['kelas','Kelas','kelas']]);
    $columns=$identities;$groups=[];$rows=[];$slots=[];$subtotals=[];
    foreach($parts as $index=>$part){
        $label=$part['section_title']??$part['title'];
        $subtitle=$part['subtitle']??'';$groupColumns=[];$moved=[];
        // A constant period belongs in the group header. Variable periods remain cells.
        foreach(['periode','tahun_ajaran'] as $periodKey){
            if(!$part['rows']){$moved[$periodKey]=true;continue;}
            $values=array_values(array_unique(array_map('strval',array_column($part['rows'],$periodKey))));
            if(count($values)===1){$moved[$periodKey]=true;if(!str_contains($label.' '.$subtitle,$values[0]))$label.=' · '.$values[0];}
        }
        foreach($part['columns'] as $column){
            [$key,$caption]=$column;$type=$column[2]??'text';
            if(in_array($key,['unit','nis','nama','kelas'],true)||isset($moved[$key]))continue;
            if($template==='penerimaan'&&$key==='total_penerimaan')continue;
            $mapped='g'.$index.'_'.$key;
            $definition=[$mapped,$caption,$type,'source'=>$key,'group'=>$index];
            $columns[]=$definition;$groupColumns[]=$definition;
        }
        if(!$groupColumns)continue;
        $groups[]=['label'=>$label,'subtitle'=>$subtitle,'columns'=>$groupColumns,'source'=>$index];
        $occurrences=[];
        foreach($part['rows'] as $sourceIndex=>$source){
            // A historical class is part of identity, and ordinal slots retain duplicates.
            $identity=json_encode([(int)($source['unit_id']??$unit),(string)($source['nis']??$source['student_id']??''),(string)($source['kelas_asli']??$source['kelas']??'')],JSON_THROW_ON_ERROR);
            $ordinal=$occurrences[$identity]??0;$occurrences[$identity]=$ordinal+1;
            $slot=$identity.'#'.$ordinal;
            if(!isset($slots[$slot])){
                $slots[$slot]=count($rows);
                $rows[]=['no'=>count($rows)+1,'unit_id'=>$source['unit_id']??$unit,'unit'=>$source['unit']??unit_label($unit),'nis'=>$source['nis']??'','nis_diknas'=>$source['diknas']??$source['nis_diknas']??$source['NO_induk_diknas']??'','nama'=>$source['nama']??'','kelas'=>$source['kelas_asli']??$source['kelas']??'','_sources'=>[]];
            }
            $position=$slots[$slot];$rows[$position]['_sources'][$index]=$sourceIndex;
            foreach($groupColumns as $column)$rows[$position][$column[0]]=$source[$column['source']]??null;
            if($template==='penerimaan')$rows[$position]['total_penerimaan']=($rows[$position]['total_penerimaan']??0)+(float)($source['total_penerimaan']??0);
        }
        $totals=array_column(report_money_totals($part,$template),null,'key');
        foreach($groupColumns as $column)if(isset($totals[$column['source']]))$subtotals[$column[0]]=$totals[$column['source']];
    }
    if($template==='penerimaan'){
        $column=['total_penerimaan','Total Penerimaan','money'];$columns[]=$column;
        $groups[]=['label'=>'Total Penerimaan','subtitle'=>'','columns'=>[$column],'source'=>null];
        foreach(report_money_totals($report,$template) as $total)if(($total['key']??'')==='total_penerimaan')$subtotals['total_penerimaan']=$total;
    }
    foreach($rows as &$row)foreach($columns as $column)if(!array_key_exists($column[0],$row))$row[$column[0]]=null;
    unset($row);
    return ['columns'=>$columns,'identity_count'=>count($identities),'groups'=>$groups,'rows'=>$rows,'subtotals'=>$subtotals,'totals'=>report_money_totals($report,$template),'flat_header'=>$template==='penerimaan'&&count($parts)===1];
}

/** Stable horizontal bands; an oversized month group continues with the same identity. */
function global_report_matrix_bands(array $matrix,string $orientation):array {
    $identityWidth=0;
    foreach(array_slice($matrix['columns'],0,$matrix['identity_count']) as $column)$identityWidth+=global_report_column_width($column,true,$matrix['rows'])+10;
    $capacity=($orientation==='landscape'?785:538)-$identityWidth;
    $bands=[];$current=[];$size=0;
    foreach($matrix['groups'] as $group){
        $chunks=[];$chunk=[];$chunkWidth=0;
        foreach($group['columns'] as $column){
            $width=global_report_column_width($column,true,$matrix['rows'])+10;
            if($chunk&&$chunkWidth+$width>$capacity){$chunks[]=$chunk;$chunk=[];$chunkWidth=0;}
            $chunk[]=$column;$chunkWidth+=$width;
        }
        if($chunk)$chunks[]=$chunk;
        foreach($chunks as $part=>$chunk){
            $width=array_sum(array_map(static fn($column)=>global_report_column_width($column,true,$matrix['rows'])+10,$chunk));
            if($size&&$size+$width>$capacity){$bands[]=$current;$current=[];$size=0;}
            $copy=$group;$copy['columns']=$chunk;if($part)$copy['label'].=' - lanjutan';
            $current[]=$copy;$size+=$width;
        }
    }
    if($current)$bands[]=$current;
    return $bands;
}

function global_report_matrix_html(array $matrix,?array $rows=null,int $start=0,?array $groups=null,bool $print=false):string {
    $rows=$rows??$matrix['rows'];$groups=$groups??$matrix['groups'];
    // One daily receipt period needs only component labels, not an empty-looking group tier.
    if(!$print&&!empty($matrix['flat_header'])){
        $flat=[];foreach($groups as $group)foreach($group['columns'] as $column)$flat[]=['label'=>$column[1],'subtitle'=>'','columns'=>[$column]];
        $groups=$flat;
    }
    $identity=array_slice($matrix['columns'],0,$matrix['identity_count']);$columns=$identity;
    $singleHeader=true;foreach($groups as $group)if(count($group['columns'])!==1||$group['subtitle']!==''||$group['label']!==$group['columns'][0][1])$singleHeader=false;
    foreach($groups as $group)$columns=array_merge($columns,$group['columns']);
    $widths=[];$printWidths=[];$left=0;$styles=[];
    foreach($columns as $column){$widths[$column[0]]=global_report_column_width($column);if($print)$printWidths[$column[0]]=global_report_column_width($column,true,$matrix['rows']);}
    foreach($identity as $column){$styles[$column[0]]='--matrix-left:'.$left.'px;--matrix-width:'.$widths[$column[0]].'px';$left+=$widths[$column[0]];}
    ob_start(); ?>
    <div class="report-standard-scroll report-matrix-scroll" tabindex="0" role="region" aria-label="Tabel laporan, geser untuk melihat seluruh komponen"><table class="data payment-table report-template-table report-horizontal-matrix<?= $print?' report-matrix-print':'' ?>" style="width:100%<?= $print?'':';min-width:'.array_sum($widths).'px' ?>">
    <colgroup><?php foreach($columns as $column): ?><col style="width:<?= $print?$printWidths[$column[0]]:$widths[$column[0]] ?><?= $print?'pt':'px' ?>"><?php endforeach; ?></colgroup>
    <thead><tr><?php foreach($identity as $column): ?><th rowspan="<?= $singleHeader?1:2 ?>" class="matrix-identity matrix-<?= $column[0] ?>" style="<?= $print?'width:'.$printWidths[$column[0]].'pt':$styles[$column[0]] ?>" scope="col"><?= report_e($column[1]) ?></th><?php endforeach; ?>
    <?php foreach($groups as $group): ?><th colspan="<?= count($group['columns']) ?>"<?= $print?' style="width:'.array_sum(array_map(static fn($col)=>$printWidths[$col[0]],$group['columns'])).'pt"':'' ?> scope="colgroup"><?= report_e($group['label']) ?><?php if($group['subtitle']!==''): ?><span class="matrix-group-period"><?= report_e($group['subtitle']) ?></span><?php endif; ?></th><?php endforeach; ?></tr><?php if(!$singleHeader): ?><tr>
    <?php foreach($groups as $group): foreach($group['columns'] as $column): ?><th<?= $print?' style="width:'.$printWidths[$column[0]].'pt"':'' ?> scope="col"><?= report_e($column[1]) ?></th><?php endforeach; endforeach; ?></tr><?php endif; ?></thead>
    <tbody><?php if(!$rows): ?><tr><td colspan="<?= count($columns) ?>">Tidak ada data sesuai filter.</td></tr><?php endif; ?>
    <?php foreach($rows as $index=>$row): ?><tr><?php foreach($columns as $i=>$column): $key=$column[0];$value=$key==='no'?$start+$index+1:($row[$key]??null);$type=$column[2]??'text';$original=$column['source']??$key; ?>
    <td class="<?= global_report_column_class([$original,$column[1],$type]) ?><?= $i<count($identity)?' matrix-identity matrix-'.$key:'' ?><?= in_array($type,['money','money_optional'],true)?' money':'' ?><?= is_numeric($value)&&$value<0?' report-negative negative':'' ?>"<?= isset($styles[$key])?' style="'.$styles[$key].'"':'' ?>><?= $value===null?'<span class="matrix-empty">—</span>':global_report_cell($value,$type,($matrix['separate_national_id']??false)&&$original==='nis'?array_diff_key($row,array_flip(['diknas','nis_diknas','NO_induk_diknas'])):$row,$original) ?></td>
    <?php endforeach; ?></tr><?php endforeach; ?></tbody>
    <?php if($matrix['subtotals']): ?><tfoot><tr>
    <?php if($print): ?><th colspan="<?= count($identity) ?>">Subtotal seluruh hasil filter</th>
    <?php else: foreach($identity as $column): ?><th class="matrix-identity matrix-<?= $column[0] ?>" style="<?= $styles[$column[0]] ?>"><?= $column[0]==='nis'?'Subtotal':($column[0]==='nama'?'Seluruh hasil filter':'') ?></th><?php endforeach; endif; ?>
    <?php foreach(array_slice($columns,count($identity)) as $column): $total=$matrix['subtotals'][$column[0]]??null; ?><td class="money"><?= $total?report_money($total['value']):'—' ?></td><?php endforeach; ?></tr></tfoot><?php endif; ?>
    </table></div>
    <?php return ob_get_clean();
}

/** Excel uses the same mapped cells; the national identifier gets its own typed text column. */
function global_report_matrix_excel(array $matrix,array $report,string $template,int $unit):array {
    require_once __DIR__.'/excel.php';$columns=[];
    $widths=['no'=>6,'unit'=>10,'nis'=>16,'nis_diknas'=>18,'nama'=>26,'kelas'=>10];
    foreach($matrix['columns'] as $column){$definition=spp_excel_column($column[0],$column[1],$column[2]??'text');$definition['width']=$widths[$column[0]]??22;$columns[]=$definition;if($column[0]==='nis'){$definition=spp_excel_column('nis_diknas','NIS Diknas');$definition['width']=18;$columns[]=$definition;}}
    $section=spp_excel_section($report['title'],$columns,$matrix['rows'],$matrix['totals']);
    $section['flat_header']=$matrix['flat_header']??false;
    $section['header_groups']=[];$section['identity_count']=$matrix['identity_count']+1;$section['column_totals']=$matrix['subtotals'];
    foreach($matrix['groups'] as $group)$section['header_groups'][]=['label'=>$group['label'].($group['subtitle']!==''?' | '.$group['subtitle']:''),'keys'=>array_column($group['columns'],0)];
    $doc=spp_excel_document($report['title'],$report['subtitle'],$unit,[['name'=>'Laporan','sections'=>[$section],'signatures'=>false]]);
    $doc['presentation']='global';
    $doc['matrix_page']=['orientation'=>global_report_print_orientation($matrix),'paper'=>'A4'];
    return $doc;
}
