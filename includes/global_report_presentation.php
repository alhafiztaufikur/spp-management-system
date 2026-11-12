<?php
/** Copies for display/export only. Never alter the financial readers or their rows. */
function global_report_compact_totals(string $template): bool {
    return in_array($template,['penerimaan','spp-tahunan','per-item','tabungan-siswa','saldo-tabungan'],true);
}
function global_report_short_subtotal(string $template,string $surface): bool {
    return in_array($template,['riwayat-tagihan','penerimaan','spp-tahunan','per-item'],true)||($template==='status'&&$surface==='print');
}
function global_report_present_label(string $label,string $template): string {
    if($template!=='penerimaan')return $label;
    $label=preg_replace('/^(Total\s+)?Biaya Lain\s+/iu','$1',$label);
    return preg_replace('/\bTotal Penerimaan\b/iu','Total',$label);
}
function global_report_present_cell($value,string $template,string $surface) {
    if(!is_array($value))return $value;
    $labels=$template==='spp-tahunan'?['belum diketahui','lunas','belum bayar']:(($template==='per-item'&&$surface==='print')?['lunas','belum bayar']:[]);
    if(in_array(mb_strtolower(trim((string)($value['text']??''))),$labels,true)){
        $value['text']='';
        if(($value['sub']??'')==='')$value['sub']='—';
    }
    return $value;
}
function global_report_present(array $report,string $template,string $surface='screen'): array {
    if(!in_array($template,['penerimaan','spp-tahunan','per-item','tabungan-siswa','saldo-tabungan','status','riwayat-tagihan'],true))return $report;
    // Compute totals from the complete model before hiding any columns.
    $totals=report_money_totals($report,$template);
    foreach($totals as &$total)$total['label']=global_report_present_label($total['label'],$template);unset($total);
    if($template==='tabungan-siswa')$totals=array_values(array_filter($totals,static fn($total)=>($total['key']??'')!=='penyesuaian'));
    // Per-item sections can still be split by year by the matrix builder.
    if(isset($report['_read_totals'])||in_array($template,['penerimaan','tabungan-siswa'],true))$report['_read_totals']=$totals;
    $report['_presentation_template']=$template;$report['_presentation_surface']=$surface;
    foreach($report['sections']??[] as $i=>$section)$report['sections'][$i]=global_report_present($section,$template,$surface);
    foreach(['title','section_title'] as $key)if(isset($report[$key]))$report[$key]=global_report_present_label($report[$key],$template);
    $columns=[];
    foreach($report['columns']??[] as $column){
        if($template==='tabungan-siswa'&&in_array($column[0],['penyesuaian','keterangan'],true))continue;
        $column[1]=global_report_present_label($column[1],$template);$columns[]=$column;
    }
    if(isset($report['columns']))$report['columns']=$columns;
    foreach($report['rows']??[] as $i=>$row){
        foreach($columns as $column)if(($column[2]??'')==='html'&&array_key_exists($column[0],$row))$row[$column[0]]=global_report_present_cell($row[$column[0]],$template,$surface);
        if($template==='tabungan-siswa'&&preg_match('/^legacy:[0-9]+:([0-9a-f]*)$/iD',(string)($row['_operator_identity']??''),$match)&&strlen($match[1])%2===0){
            $code=hex2bin($match[1]);$row['operator']=$code!==''?$code:'—';
        }
        $report['rows'][$i]=$row;
    }
    return $report;
}
function global_report_nowrap_header(string $label): bool {
    return in_array($label,['Total Tagihan','Total Bayar','Saldo Berjalan'],true);
}
function global_report_totals_html(array $totals,string $template,bool $print=false,bool $currency=true): string {
    if(!$totals)return '';
    ob_start(); ?><section class="global-total-summary<?= $print?' is-print':'' ?>" aria-label="Total Rupiah"><h3>Total Rupiah</h3><table><colgroup><col style="width:65%"><col style="width:35%"></colgroup><tbody><?php foreach($totals as $total): ?><tr><th scope="row"><?= report_e($total['label']) ?></th><td><?= report_e($currency?report_money($total['value']):preg_replace('/^Rp /','',report_money($total['value']))) ?></td></tr><?php endforeach; ?></tbody></table></section><?php return ob_get_clean();
}
