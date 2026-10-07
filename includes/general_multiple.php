<?php
require_once __DIR__.'/filter_choices.php';
require_once __DIR__.'/spp_billing.php';
require_once __DIR__.'/tagihan_tahunan.php';
function general_types():array{return ['semua'=>'Semua transaksi','sudah_bayar'=>'Yang sudah bayar','belum_spp'=>'SPP belum lunas','belum_komite'=>'Komite belum lunas','belum_du'=>'Daftar ulang belum lunas','belum_biaya_lain'=>'Biaya lain belum lunas'];}
function general_choices(array $source):array{return filter_register('jenis_laporan',$source['jenis_laporan']??null,general_types(),'semua','semua');}
function general_bind(mysqli_stmt $stmt,string $types,array $params):void{if($params)$stmt->bind_param($types,...$params);}
function general_sections(mysqli $db,array $choices,string $start,string $end,string $search='',string $sort='terbaru'):array {
    $where=unit_student_selection_where();$params=[];
    if($search!==''){$where.=' AND (s.NO_INDUK LIKE ? OR s.NAMA LIKE ? OR s.NO_induk_diknas LIKE ?)';$params=array_fill(0,3,'%'.$search.'%');}
    $stamp=strtotime($end.' -1 second');$month=date('m',$stamp);$year=date('Y',$stamp);
    $names=['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    $types=filter_is_all($choices)?['semua','belum_spp','belum_komite','belum_du','belum_biaya_lain']:$choices;
    $sections=[];
    foreach($types as $type){
        $columns=[['NO_INDUK','NIS'],['NO_induk_diknas','NIS Diknas'],['NAMA','Nama Siswa'],['KELAS','Kelas']];
        if(in_array($type,['semua','sudah_bayar'],true)){
            $order=match($sort){'nama'=>'s.NAMA,b.TGL_BYR DESC','kelas'=>'b.KELAS,s.NAMA,b.TGL_BYR DESC','nominal_terbesar'=>'b.total_jumlah DESC,b.id DESC',default=>'b.TGL_BYR DESC,b.id DESC'};
            $stmt=$db->prepare("SELECT b.unit_id,b.id,s.NO_INDUK,s.NO_induk_diknas,s.NAMA,COALESCE(NULLIF(b.kelas_rombel_snapshot,''),NULLIF(b.KELAS,''),s.KELAS) KELAS,b.BULAN,b.TAHUN,b.sistem_pembayaran,b.total_jumlah,b.TGL_BYR FROM bayar b JOIN siswa s ON s.NO_INDUK=b.NO_INDUK AND s.unit_id=b.unit_id WHERE b.TGL_BYR>=? AND b.TGL_BYR<?".($type==='sudah_bayar'?' AND b.total_jumlah>0':'').$where.' ORDER BY '.$order);
            general_bind($stmt,'ss'.str_repeat('s',count($params)),array_merge([$start,$end],$params));$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            foreach($rows as &$row)$row['periode']=$row['BULAN'].' '.$row['TAHUN'];unset($row);
            $columns=array_merge($columns,[['periode','Bulan Tagihan'],['sistem_pembayaran','Sistem Pembayaran'],['total_jumlah','Total Bayar','money'],['TGL_BYR','Tanggal Bayar','datetime']]);
            $totals=[['label'=>'Total Pembayaran','value'=>array_sum(array_column($rows,'total_jumlah'))]];
        }else{
            $rows=general_unpaid($db,$type,$month,$year,$names[$month],du_academic_year_label((int)$month,(int)$year),$where,$params,$sort);
            if($type==='belum_biaya_lain')$columns[]=['komponen','Komponen'];
            $columns=array_merge($columns,[['tagihan','Tagihan','money'],['sudah_bayar','Sudah Dibayar','money'],['sisa','Sisa','money']]);
            $totals=[];foreach(['tagihan'=>'Total Tagihan','sudah_bayar'=>'Sudah Dibayar','sisa'=>'Total Tunggakan'] as $key=>$label)$totals[]=['label'=>$label,'value'=>array_sum(array_column($rows,$key))];
        }
        if(unit_all_readonly()){$columns=array_merge([['unit','Unit']],$columns);foreach($rows as &$row)$row['unit']=unit_label((int)$row['unit_id']);unset($row);}
        $sections[]=['title'=>general_types()[$type],'columns'=>$columns,'rows'=>$rows,'totals'=>$totals];
    }
    return $sections;
}
function general_section_html(array $section,int $start=0):string{
    $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');ob_start();
    echo '<section class="report-multiple-section"><h3>'.$e($section['title']).'</h3><div class="table-container"><table class="payment-table"><thead><tr><th>No</th>';
    foreach($section['columns'] as $column)echo '<th>'.$e($column[1]).'</th>';echo '</tr></thead><tbody>';
    if(!$section['rows'])echo '<tr><td colspan="'.(count($section['columns'])+1).'">Tidak ada data sesuai filter.</td></tr>';
    foreach($section['rows'] as $i=>$row){echo '<tr><td>'.($start+$i+1).'</td>';foreach($section['columns'] as $column){$value=$row[$column[0]]??'';$type=$column[2]??'text';echo '<td'.($type==='money'?' class="nominal"':'').'>';echo $type==='money'?'Rp '.number_format((float)$value,0,',','.'):(in_array($type,['date','datetime'],true)?$e(spp_date_label($value,$type==='datetime')):$e($value));echo '</td>';}echo '</tr>';}
    echo '</tbody></table></div><div class="report-multiple-subtotals">';foreach($section['totals'] as $total)echo '<span>'.$e($total['label']).': <strong>Rp '.number_format($total['value'],0,',','.').'</strong></span>';echo '</div></section>';return ob_get_clean();
}
function general_excel_sections(array $sections):array{
    $result=[];foreach($sections as $section){$columns=[spp_excel_column('no','No','number')];foreach($section['columns'] as $column)$columns[]=spp_excel_column($column[0],$column[1],$column[2]??'text');foreach($section['rows'] as $i=>&$row)$row['no']=$i+1;unset($row);$result[]=spp_excel_section($section['title'],$columns,$section['rows'],$section['totals']);}return $result;
}
function general_document_html(array $doc):string{
    $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    [$accent,$dark,$pale]=spp_excel_palette($doc['unit']);
    $html='<!doctype html><html><meta charset="utf-8"><style>@page{margin:14mm}body{font:11px DejaVu Sans;color:#233344}h1{font-size:20px;color:#'.$dark.'}h2{margin-top:22px}h3{padding:8px;background:#'.$pale.'}table{width:100%;border-collapse:collapse;table-layout:fixed}th{background:#'.$dark.';color:white;text-align:center}th,td{border:1px solid #dde4ea;padding:6px;overflow-wrap:break-word}tbody tr:nth-child(even){background:#'.$pale.'}.nominal{text-align:right}.report-multiple-subtotals{margin:10px 0 20px}.report-multiple-subtotals span{display:block;margin:4px}thead{display:table-header-group}tr{page-break-inside:avoid}</style><body><h1>'.$e($doc['school']).'</h1><h2>'.$e($doc['title']).'</h2><p>'.$e($doc['subtitle']).'</p><p>Unit: '.$e($doc['unit_label']).' | '.$e($doc['generated']).' | Petugas: '.$e($doc['operator']).'</p>';
    foreach($doc['sheets'] as $sheet){$html.='<h2>'.$e($sheet['name']).'</h2>';foreach($sheet['sections'] as $section){$section['columns']=array_map(static fn($c)=>[$c['key'],$c['label'],$c['type']],$section['columns']);$section['columns']=array_values(array_filter($section['columns'],static fn($c)=>$c[0]!=='no'));$html.=general_section_html($section);}}
    return $html.'</body></html>';
}
function general_unpaid(mysqli $koneksi,string $report_type,string $filter_bulan,string $filter_tahun,string $bulan_label,string $academicYear,string $studentSearchSql,array $studentSearchParams,string $sort):array{
    $periodMonthCode = $filter_bulan;
    $periodMonthName = $bulan_label;
    $periodMonthLegacy = (string)(int)$filter_bulan;
    $orderUnpaid = match ($sort) {
        'nama' => 'NAMA ASC',
        'kelas' => 'CAST(KELAS AS UNSIGNED) ASC, NAMA ASC',
        'nominal_terbesar', 'sisa_terbesar' => 'sisa DESC, NAMA ASC',
        default => 'CAST(KELAS AS UNSIGNED) ASC, NAMA ASC',
    };

    if ($report_type === 'belum_spp') {
        $stmtUnpaid = $koneksi->prepare("
            SELECT *
            FROM (
                SELECT s.id AS student_id,s.unit_id,s.NO_INDUK, s.NO_induk_diknas, s.NAMA, ts.kelas_rombel_snapshot AS KELAS,
                       ts.nominal_tagihan AS tagihan,
                       COALESCE(SUM(CASE WHEN ab.status='active' THEN a.nominal_dari_bayar ELSE 0 END),0) AS sudah_bayar,
                       GREATEST(ts.nominal_tagihan-COALESCE(SUM(CASE WHEN ab.status='active' THEN a.nominal_dari_bayar ELSE 0 END),0),0) AS sisa
                FROM tagihan_spp ts JOIN siswa s ON s.NO_INDUK=ts.no_induk AND s.unit_id=ts.unit_id
                LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=ts.id
                LEFT JOIN spp_alokasi_batch ab ON ab.id=a.batch_id
                WHERE s.is_active=1 AND ts.status='open' AND ts.tahun=? AND ts.bulan=? AND ts.nominal_tagihan>0 $studentSearchSql
                GROUP BY ts.id,s.NO_induk_diknas,s.NAMA,ts.kelas_rombel_snapshot
            ) unpaid
            WHERE sisa > 0
            ORDER BY $orderUnpaid
        ");
        general_bind($stmtUnpaid, 'ss' . str_repeat('s', count($studentSearchParams)), array_merge([$filter_tahun, $periodMonthCode], $studentSearchParams));
    } elseif ($report_type === 'belum_komite') {
        $stmtUnpaid = $koneksi->prepare("
            SELECT *
            FROM (
                SELECT s.id AS student_id,s.unit_id,s.NO_INDUK, s.NO_induk_diknas, s.NAMA, t.kelas_rombel_snapshot AS KELAS,
                       t.nominal_tagihan AS tagihan,
                       COALESCE(SUM(d.nominal), 0) AS sudah_bayar,
                       GREATEST(t.nominal_tagihan - COALESCE(SUM(d.nominal), 0), 0) AS sisa
                FROM tagihan_komite t
                JOIN siswa s ON s.NO_INDUK = t.no_induk AND s.unit_id=t.unit_id
                LEFT JOIN bayar_komite d ON d.tagihan_komite_id = t.id
                WHERE s.is_active = 1
                  AND t.status = 'open'
                  AND t.tahun = ? AND t.bulan = ?
                  AND t.nominal_tagihan > 0
                  $studentSearchSql
                GROUP BY t.id,s.NO_induk_diknas,s.NAMA,t.kelas_rombel_snapshot
            ) unpaid
            WHERE sisa > 0
            ORDER BY $orderUnpaid
        ");
        general_bind($stmtUnpaid, 'ss' . str_repeat('s', count($studentSearchParams)), array_merge([$filter_tahun,$periodMonthCode], $studentSearchParams));
    } elseif ($report_type === 'belum_du') {
        $stmtUnpaid = $koneksi->prepare("
            SELECT *
            FROM (
                SELECT s.id AS student_id,s.unit_id,s.NO_INDUK, s.NO_induk_diknas, s.NAMA, sta.kelas_rombel_snapshot AS KELAS,
                       tdu.nominal_tagihan AS tagihan,
                       COALESCE(SUM(bd.jumlah), 0) AS sudah_bayar,
                       GREATEST(tdu.nominal_tagihan - COALESCE(SUM(bd.jumlah), 0), 0) AS sisa
                FROM tagihan_daftar_ulang tdu
                JOIN siswa s ON s.NO_INDUK = tdu.no_induk AND s.unit_id=tdu.unit_id
                JOIN siswa_tahun_ajaran sta ON sta.id=tdu.penempatan_id
                LEFT JOIN bayar_du bd ON bd.tagihan_daftar_ulang_id = tdu.id
                WHERE s.is_active = 1 AND tdu.status='open' AND tdu.tahun_ajaran_snapshot = ? AND tdu.nominal_tagihan > 0 $studentSearchSql
                GROUP BY tdu.id,s.NO_induk_diknas,s.NAMA,sta.kelas_rombel_snapshot
            ) unpaid
            WHERE sisa > 0
            ORDER BY $orderUnpaid
        ");
        general_bind($stmtUnpaid, 's' . str_repeat('s', count($studentSearchParams)), array_merge([$academicYear], $studentSearchParams));
    } else {
        $stmtUnpaid = $koneksi->prepare("
            SELECT *
            FROM (
                SELECT s.id AS student_id,s.unit_id,s.NO_INDUK, s.NO_induk_diknas, s.NAMA,
                       t.kelas_rombel_snapshot AS KELAS, t.nama_snapshot AS komponen,
                       t.nominal_tagihan AS tagihan,
                       COALESCE(SUM(d.nominal_snapshot), 0) AS sudah_bayar,
                       GREATEST(t.nominal_tagihan - COALESCE(SUM(d.nominal_snapshot), 0), 0) AS sisa
                FROM tagihan_biaya_lain t
                JOIN siswa s ON s.NO_INDUK=t.no_induk AND s.unit_id=t.unit_id
                LEFT JOIN bayar_biaya_lain d ON d.tagihan_biaya_lain_id=t.id
                WHERE s.is_active = 1 AND t.status='open' $studentSearchSql
                GROUP BY t.id,s.NO_induk_diknas,s.NAMA,t.kelas_rombel_snapshot,t.nama_snapshot,t.nominal_tagihan
            ) unpaid
            WHERE sisa > 0
            ORDER BY $orderUnpaid
        ");
        general_bind($stmtUnpaid, str_repeat('s', count($studentSearchParams)), $studentSearchParams);
    }
    $stmtUnpaid->execute();
    $result = $stmtUnpaid->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtUnpaid->close();
    return $result;
}
