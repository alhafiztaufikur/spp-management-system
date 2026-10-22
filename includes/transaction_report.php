<?php
/** Payment-only model for Laporan Transaksi, its fragments, and both exports. */
require_once __DIR__.'/general_multiple.php';

function transaction_report_sort_options(): array {
    return ['terbaru'=>'Terbaru','nama'=>'Nama siswa','kelas'=>'Kelas',
        'nominal_terbesar'=>'Nominal terbesar','nominal_terkecil'=>'Nominal terkecil'];
}

function transaction_report_normalize_request(): string {
    // Obsolete report selectors must never expand this route into other reports.
    unset($_GET['jenis_laporan']);
    $_GET['panel']=transaction_report_panel($_GET['panel']??null);
    $sort=$_GET['urut']??'terbaru';
    return $_GET['urut']=is_string($sort)&&isset(transaction_report_sort_options()[$sort])?$sort:'terbaru';
}

function transaction_report_panel($value): string {
    return is_string($value)&&in_array($value,['ringkasan','komponen','transaksi'],true)?$value:'ringkasan';
}

/** MySQL DECIMAL sums stay exact when projected into integer cents. */
function transaction_report_cents(string $decimal): int {
    $negative=str_starts_with($decimal,'-');
    [$whole,$fraction]=array_pad(explode('.',ltrim($decimal,'-'),2),2,'');
    return ($negative?-1:1)*((int)$whole*100+(int)str_pad($fraction,2,'0'));
}

function transaction_report_section(mysqli $db,string $start,string $end,string $search='',string $sort='terbaru',?int $size=null,int $page=1): array {
    $section=general_sections($db,['semua'],$start,$end,$search,$sort,$size,0,$size===null?null:['semua'=>$page])[0];
    foreach($section['rows'] as &$row){
        if(trim((string)$row['BULAN'].' '.(string)$row['TAHUN'])==='')
            $row['BULAN']=(string)($row['evidence_period']??'');
        $row['periode']=trim($row['BULAN'].' '.$row['TAHUN']);
    }unset($row);
    $section['hide_heading']=true;
    return $section;
}

/** Numeric, snapshot-only projection shared by the aggregate and export details.
 * The caller supplies a scoped `chosen(id,unit_id)` CTE, never a name/NIS join.
 */
function transaction_report_item_sql(mysqli $db): string {
    $parts=[];
    $append=static function(string $id,string $unit,string $key,string $label,string $amount,string $order,string $tail)use(&$parts):void{
        $parts[]="SELECT $id payment_id,$unit unit_id,".lcs_sql_text($key).' component_key,'.lcs_sql_text($label)." label,CAST($amount AS DECIMAL(20,2)) amount,$order detail_order $tail";
    };
    foreach(['U_PSB'=>['psb','PSB'],'U_SPP'=>['spp','SPP'],'U_KOMITE'=>['komite','Komite'],'potong_spp'=>['potongan_spp','Potongan SPP']] as $column=>[$key,$label]){
        $amount=$column==='potong_spp'?'-n.'.$column:'n.'.$column;
        // Some native columns are DOUBLE. Round each stored item to cents before SUM.
        $append('n.id','n.unit_id',"'$key'","'$label'",$amount,'0',"FROM chosen c STRAIGHT_JOIN bayar n ON n.id=c.id AND n.unit_id=c.unit_id WHERE c.id>0 AND n.$column<>0");
    }
    $append('d.bayar_id','d.unit_id',"CASE WHEN COALESCE(d.master_biaya_lain_id,t.master_biaya_lain_id)>0 THEN CONCAT('biaya_lain:',COALESCE(d.master_biaya_lain_id,t.master_biaya_lain_id)) WHEN NULLIF(TRIM(d.legacy_key),'') IS NOT NULL THEN CONCAT('snapshot_key:',d.unit_id,':',d.legacy_key) ELSE CONCAT('snapshot_item:',d.unit_id,':',d.id) END",'d.nama_biaya_snapshot','d.nominal_snapshot','d.id',"FROM chosen c STRAIGHT_JOIN bayar_biaya_lain d ON d.bayar_id=c.id AND d.unit_id=c.unit_id LEFT JOIN tagihan_biaya_lain t ON t.id=d.tagihan_biaya_lain_id AND t.unit_id=d.unit_id WHERE c.id>0 AND d.nominal_snapshot<>0");
    $append('d.bayar_id','d.unit_id',"'daftar_ulang'","'Daftar Ulang'",'d.jumlah','d.id',"FROM chosen c STRAIGHT_JOIN bayar_du d ON d.bayar_id=c.id AND d.unit_id=c.unit_id WHERE c.id>0 AND d.jumlah<>0");
    if(lful_read_ready($db)){
        $key=lcs_cash_category_sql('i.source_column','i.category');$label=lcs_display_label_sql('i.source_column','i.category','i.label');
        $append('-i.payment_id','i.unit_id',"COALESCE(NULLIF(($key),''),CONCAT('unmapped:full:',i.unit_id,':',i.id))",$label,'i.amount','i.id',"FROM chosen c STRAIGHT_JOIN legacy_full_items i ON i.payment_id=-c.id AND i.unit_id=c.unit_id WHERE c.id<0 AND c.id>-1000000000 AND i.amount<>0");
    }
    if(lop_ready($db)){
        $key=lcs_cash_category_sql('j.source_column','j.category');$label=lcs_display_label_sql('j.source_column','j.category','j.label');
        $append('-1000000000-p.id','p.unit_id',"COALESCE(NULLIF(($key),''),CONCAT('unmapped:op:',p.unit_id,':',p.id,':',j.position))",$label,'j.amount','j.position',"FROM chosen c STRAIGHT_JOIN legacy_op_payments p ON p.id=-1000000000-c.id AND p.unit_id=c.unit_id JOIN JSON_TABLE(CAST(p.items AS JSON),'$[*]' COLUMNS(position FOR ORDINALITY,source_column VARCHAR(64) PATH '$.column' NULL ON EMPTY,category VARCHAR(255) PATH '$.category' NULL ON EMPTY,label VARCHAR(255) PATH '$.label',amount DECIMAL(15,2) PATH '$.amount')) j WHERE c.id<-1000000000 AND j.amount<>0");
    }
    return implode(' UNION ALL ',$parts);
}

/** All filtered payments, independent of the current transaction page. */
function transaction_report_component_totals(mysqli $db,string $start,string $end,string $search=''): array {
    $source=mh_payment_source($db,false,null,false);
    [$where,$args]=general_history_filter('p','stored_datetime',$start,$end,$search,$db);
    $items=transaction_report_item_sql($db);
    // DISTINCT is also an optimizer barrier: reused chosen branches must not inherit
    // contradictory signed-ID conditions from other UNION branches (MySQL 8.0).
    $rows=hp_rows($db,"WITH chosen AS (SELECT DISTINCT p.id,p.unit_id FROM $source p WHERE $where), items AS ($items) SELECT component_key,MIN(label) label,SUM(amount) amount,GROUP_CONCAT(DISTINCT unit_id ORDER BY unit_id) units FROM items GROUP BY component_key ORDER BY component_key",$args);
    $labels=['psb'=>'PSB','spp'=>'SPP','komite'=>'Komite','daftar_ulang'=>'Daftar Ulang','potongan_spp'=>'Potongan SPP'];
    $counts=[];
    foreach($rows as &$row){$row['label']=$labels[$row['component_key']]??$row['label'];$row['cents']=transaction_report_cents($row['amount']);$counts[$row['label']]=($counts[$row['label']]??0)+1;}unset($row);
    foreach($rows as &$row)if($counts[$row['label']]>1){
        $units=implode(', ',array_map(static fn($u)=>unit_label((int)$u),explode(',',$row['units'])));
        $row['label'].=' · '.$units;
    }unset($row);
    usort($rows,static fn($a,$b)=>strnatcasecmp($a['label'],$b['label'])?:strcmp($a['component_key'],$b['component_key']));
    return $rows;
}

/** Bounded detail reads avoid hydrating whole archived receipts just for items. */
function transaction_report_components(mysqli $db,array $rows): array {
    $out=[];$items=transaction_report_item_sql($db);
    foreach(array_chunk($rows,500) as $chunk){
        $selected=[];foreach($chunk as $row)$selected[]='SELECT '.(int)$row['id'].' id,'.(int)$row['unit_id'].' unit_id';
        foreach(hp_rows($db,'WITH chosen AS ('.implode(' UNION ALL ',$selected).") SELECT * FROM ($items) items ORDER BY payment_id,CASE WHEN payment_id>0 THEN CASE component_key WHEN 'psb' THEN 1 WHEN 'spp' THEN 2 WHEN 'komite' THEN 3 WHEN 'potongan_spp' THEN 4 WHEN 'daftar_ulang' THEN 6 ELSE 5 END ELSE 0 END,detail_order") as $item){
            $value=round((float)$item['amount'],2);
            $out[(int)$item['payment_id']][]=$item['label'].': Rp '.number_format($value,$value===floor($value)?0:2,',','.');
        }
    }
    return array_map(static fn($items)=>implode("\n",$items),$out);
}

function transaction_report_export_section(mysqli $db,array $section): array {
    require_once __DIR__.'/excel.php';
    $parts=transaction_report_components($db,$section['rows']);
    $section['columns'][]=['komponen','Rincian Komponen'];
    foreach($section['rows'] as &$row)$row['komponen']=$parts[(int)$row['id']]??'—';unset($row);
    $section['title']='Transaksi Pembayaran';
    return general_excel_sections([$section])[0];
}
