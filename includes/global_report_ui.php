<?php
/** Cell formatting shared only by Global Report presentations. */
/** One semantic cell vocabulary for single/multiple categories and print output. */
function global_report_cell($value,string $type,array $row,string $key): string
{
    if(in_array($type,['money','money_optional'],true))return $value===null||$value===''?'—':report_e(report_money($value));
    if($type==='datetime'){
        $parts=spp_date_parts($value,$row['time_precision']??null);
        return '<span class="report-date">'.report_e($parts['date']).'</span>'.($parts['time']==='—'?'':'<span class="report-time">'.report_e($parts['time']).'</span>');
    }
    if($type==='date')return report_e(spp_date_label($value));
    if(is_array($value))return '<span class="matrix-main status-'.report_e($value['status']??'').'">'.report_e($value['text']??'').'</span>'.(($value['sub']??'')!==''?'<small class="matrix-detail">'.report_e($value['sub']).'</small>':'');
    if($type==='nis'||$key==='nis'){
        $diknas=$row['diknas']??$row['nis_diknas']??$row['NO_induk_diknas']??'';
        return '<span class="badge-nis">'.report_e($value).'</span>'.($diknas!==''?'<small class="report-secondary-id">Diknas '.report_e($diknas).'</small>':'');
    }
    if($type==='kelas'||$key==='kelas')return '<span class="kelas-badge">'.report_e($value).'</span>';
    if($type==='status'||$key==='status')return '<span class="report-status status-'.report_e(report_status_key((string)$value)).'">'.report_e($value).'</span>';
    return report_e($value);
}

function global_report_column_class(array $column): string
{
    $key=$column[0];$type=$column[2]??'text';
    if($key==='no')return 'report-col-no report-col-center';
    if($type==='status'||$key==='status')return 'report-col-status report-col-center';
    if(in_array($type,['money','money_optional'],true))return 'report-col-money';
    if($type==='html')return 'report-col-month';
    if($type==='status'||in_array($key,['kelas','unit','jenis','periode'],true))return 'report-col-center';
    if(in_array($key,['nama','NAMA'],true))return 'report-col-name report-col-center';
    if(in_array($key,['nis','nis_diknas','export_diknas','NO_INDUK','NO_induk_diknas'],true))return 'report-col-id report-col-center';
    if(in_array($type,['date','datetime'],true))return 'report-col-date';
    return 'report-col-text';
}

/** Opaque subtotal fill differs from both ordinary stripes and the final total. */
function global_report_summary_palette(int $unit): array {
    return [1=>['D8ECE1','165C44'],2=>['DDE5F7','203E80'],3=>['F3DDE2','832A35'],0=>['E8DEF3','513080']][$unit] ?? ['D8ECE1','165C44'];
}
