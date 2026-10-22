<?php
require_once __DIR__.'/filter_choices.php';
require_once __DIR__.'/spp_billing.php';
require_once __DIR__.'/tagihan_tahunan.php';
require_once __DIR__.'/general_history.php';
function general_types():array{return ['semua'=>'Semua transaksi','sudah_bayar'=>'Yang sudah bayar','belum_spp'=>'SPP belum lunas','belum_komite'=>'Komite belum lunas','belum_du'=>'Daftar ulang belum lunas','belum_biaya_lain'=>'Biaya lain belum lunas'];}
function general_choices(array $source):array{return filter_register('jenis_laporan',$source['jenis_laporan']??null,general_types(),'semua','semua');}
function general_bind(mysqli_stmt $stmt,string $types,array $params):void{if($params)$stmt->bind_param($types,...$params);}
function general_sections(mysqli $db,array $choices,string $start,string $end,string $search='',string $sort='terbaru',?int $limit=null,int $offset=0,?array $sectionPages=null):array {
    $where=unit_student_selection_where();$params=[];
    if($search!==''){$where.=' AND (s.NO_INDUK LIKE ? OR s.NAMA LIKE ? OR s.NO_induk_diknas LIKE ?)';$params=array_fill(0,3,'%'.$search.'%');}
    $stamp=strtotime($end.' -1 second');$month=date('m',$stamp);$year=date('Y',$stamp);
    $names=['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    $types=filter_is_all($choices)?['semua','belum_spp','belum_komite','belum_du','belum_biaya_lain']:$choices;
    $sections=[];$remaining=$limit;$skip=$offset;
    foreach($types as $type){
        $sectionPage=max(1,(int)($sectionPages[$type]??1));
        if($sectionPages!==null){$remaining=$limit;$skip=($sectionPage-1)*($limit??10);}
        $columns=[['NO_INDUK','NIS'],['NO_induk_diknas','NIS Diknas'],['NAMA','Nama Siswa'],['KELAS','Kelas']];
        if(in_array($type,['semua','sudah_bayar'],true)){
            $summary=general_payment_summary($db,$start,$end,$search,$type==='sudah_bayar');
            $rowCount=(int)$summary['jml_tx'];
            if($sectionPages!==null){$sectionPage=min($sectionPage,max(1,(int)ceil($rowCount/($limit??10))));$skip=($sectionPage-1)*($limit??10);}
            $rows=$limit!==null&&($remaining<=0||$skip>=$rowCount)?[]:general_payment_rows($db,$start,$end,$search,$sort,$remaining,$limit===null?0:$skip,$type==='sudah_bayar');
            foreach($rows as &$row)$row['periode']=$row['BULAN'].' '.$row['TAHUN'];unset($row);
            $columns=array_merge($columns,[['periode','Bulan Tagihan'],['sistem_pembayaran','Sistem Pembayaran'],['total_jumlah','Total Bayar','money'],['TGL_BYR','Tanggal Bayar','datetime']]);
            $totals=[['label'=>'Total Pembayaran','value'=>(float)$summary['total']]];
        }else{
            $rows=general_unpaid($db,$type,$month,$year,$names[$month],du_academic_year_label((int)$month,(int)$year),$where,$params,$sort);
            if($type==='belum_biaya_lain')$columns[]=['komponen','Komponen'];
            $columns=array_merge($columns,[['tagihan','Tagihan','money_optional'],['sudah_bayar','Sudah Dibayar','money'],['sisa','Sisa','money']]);
            $totals=[];foreach(['tagihan'=>'Total Tagihan','sudah_bayar'=>'Sudah Dibayar','sisa'=>'Total Tunggakan'] as $key=>$label)$totals[]=['label'=>$label,'value'=>array_sum(array_column($rows,$key))];
            $rowCount=count($rows);if($sectionPages!==null){$sectionPage=min($sectionPage,max(1,(int)ceil($rowCount/($limit??10))));$skip=($sectionPage-1)*($limit??10);}if($limit!==null)$rows=array_slice($rows,$skip,max(0,$remaining));
        }
        if(unit_all_readonly()){$columns=array_merge([['unit','Unit']],$columns);foreach($rows as &$row)$row['unit']=unit_label((int)$row['unit_id']);unset($row);}
        if($limit!==null){$skip=max(0,$skip-$rowCount);$remaining=max(0,$remaining-count($rows));}
        $section=['row_count'=>$rowCount,'type'=>$type,'title'=>general_types()[$type],'columns'=>$columns,'rows'=>$rows,'totals'=>$totals];
        if($sectionPages!==null){$section+=['page'=>$sectionPage,'per_page'=>$limit??10,'total_pages'=>max(1,(int)ceil($rowCount/($limit??10))),'offset'=>($sectionPage-1)*($limit??10)];}
        if(!in_array($type,['semua','sudah_bayar'],true)){require_once __DIR__.'/general_report_evidence.php';$section['notes']=[general_coverage_text(general_debt_coverage($db,$type,$month,$year,du_academic_year_label((int)$month,(int)$year),$where,$params))];}
        $sections[]=$section;
    }
    return $sections;
}
function general_section_html(array $section,int $start=0):string{
    $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');ob_start();
    echo '<section class="report-multiple-section">';
    if(empty($section['hide_heading']))echo '<h3>'.$e($section['title']).'</h3>';
    if(in_array($section['type']??'', ['semua','sudah_bayar'],true)){
        require_once __DIR__.'/transaction_report_table.php';
        echo transaction_report_table($GLOBALS['koneksi'],$section['rows'],$start,true);
    }else{
        $class=static fn($column)=>match($column[0]){'NO_INDUK'=>'report-id-col','NAMA'=>'report-name-col','KELAS'=>'kelas-col','tagihan','sudah_bayar','sisa'=>'nominal',default=>'report-component-col'};
        // Keep unit and national ID under the immutable identity, as in the payment table.
        $columns=array_values(array_filter($section['columns'],static fn($c)=>!in_array($c[0],['NO_induk_diknas','unit'],true)));
        $minimum=1000+(in_array('komponen',array_column($columns,0),true)?180:0);
        echo '<div class="table-container"><table class="payment-table report-unpaid-table report-structured-table" style="--report-table-min:'.$minimum.'px;--report-id-left:48px;--report-name-left:184px"><colgroup><col class="report-col-number">';
        foreach($columns as $column)echo match($column[0]){'NO_INDUK'=>'<col class="report-col-id">','NAMA'=>'<col class="report-col-name">','KELAS'=>'<col class="report-col-class">','komponen'=>'<col class="report-col-component">',default=>'<col class="report-col-money">'};
        echo '</colgroup><thead><tr><th scope="col" class="report-number-col">No</th>';
        foreach($columns as $column)echo '<th scope="col" class="'.$class($column).'">'.$e($column[0]==='NO_INDUK'?'No. Induk':$column[1]).'</th>';echo '</tr></thead><tbody>';
        if(!$section['rows'])echo '<tr><td colspan="'.(count($columns)+1).'">Tidak ada tunggakan dengan sisa yang terbukti sesuai filter.</td></tr>';
        foreach($section['rows'] as $i=>$row){echo '<tr><td class="report-number-col">'.($start+$i+1).'</td>';foreach($columns as $column){$value=$row[$column[0]]??null;$type=$column[2]??'text';echo '<td class="'.$class($column).'">';
            if($column[0]==='NO_INDUK'){echo '<span class="badge-nis">'.$e($value??'').'</span>';if(unit_all_readonly())echo '<small class="report-secondary-id">'.$e($row['unit']??unit_label((int)$row['unit_id'])).'</small>';if(!empty($row['NO_induk_diknas']))echo '<small class="report-secondary-id">Diknas '.$e($row['NO_induk_diknas']).'</small>';}
            elseif($column[0]==='KELAS')echo '<span class="kelas-badge">'.$e($value??'').'</span>';
            else echo str_starts_with($type,'money')?($value===null?'<span class="report-unknown-amount">Tidak diketahui</span>':'Rp '.number_format((float)$value,fmod((float)$value,1.0)==0.0?0:2,',','.')):(in_array($type,['date','datetime'],true)?$e(spp_date_label($value,$type==='datetime',$row['time_precision']??null)):$e($value??''));echo '</td>';}echo '</tr>';}
        echo '</tbody></table></div>';
    }
    foreach($section['notes']??[] as $note)echo '<p class="report-evidence-note">'.$e($note).'</p>';
    echo '<div class="report-multiple-subtotals">';foreach($section['totals'] as $total)echo '<span>'.$e($total['label']).': <strong>Rp '.number_format($total['value'],fmod((float)$total['value'],1.0)==0.0?0:2,',','.').'</strong></span>';echo '</div></section>';return ob_get_clean();
}
function general_excel_sections(array $sections):array{
    $result=[];foreach($sections as $section){$columns=[spp_excel_column('no','No','number')];foreach($section['columns'] as $column)$columns[]=spp_excel_column($column[0],$column[1],$column[2]??'text');foreach($section['rows'] as $i=>&$row)$row['no']=$i+1;unset($row);$part=spp_excel_section($section['title'],$columns,$section['rows'],$section['totals']);$part['notes']=$section['notes']??[];$result[]=$part;}return $result;
}
function general_document_html(array $doc):string{
    require_once __DIR__.'/transaction_report_pdf.php';
    return transaction_report_document_html($doc);
}
function general_unpaid(mysqli $koneksi,string $report_type,string $filter_bulan,string $filter_tahun,string $bulan_label,string $academicYear,string $studentSearchSql,array $studentSearchParams,string $sort):array{
    if(in_array($report_type,['belum_spp','belum_komite'],true)){
        require_once __DIR__.'/general_report_evidence.php';$rows=general_monthly_positions($koneksi,$report_type,$filter_bulan,$filter_tahun,$studentSearchSql,$studentSearchParams)['rows'];general_unpaid_order($rows,$sort);return $rows;
    }
    $periodMonthCode = $filter_bulan;
    $periodMonthName = $bulan_label;
    $periodMonthLegacy = (string)(int)$filter_bulan;
    $orderUnpaid = match ($sort) {
        'nama' => 'NAMA ASC',
        'kelas' => 'CAST(KELAS AS UNSIGNED) ASC, NAMA ASC',
        'nominal_terbesar', 'sisa_terbesar' => 'sisa DESC, NAMA ASC, unit_id, student_id',
        'nominal_terkecil' => 'sisa ASC, NAMA ASC, unit_id, student_id',
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
    $result=general_unpaid_history($koneksi,$result,$report_type,$filter_bulan,$filter_tahun,$academicYear);
    if(in_array($sort,['nominal_terbesar','nominal_terkecil','sisa_terbesar'],true)){
        $direction=$sort==='nominal_terkecil'?1:-1;
        usort($result,static function($a,$b)use($direction){
            $amount=((float)$a['sisa']<=>(float)$b['sisa'])*$direction;
            return $amount?:([mb_strtolower((string)$a['NAMA']),(int)$a['unit_id'],(int)$a['student_id'],(string)($a['komponen']??'')]
                <=>[mb_strtolower((string)$b['NAMA']),(int)$b['unit_id'],(int)$b['student_id'],(string)($b['komponen']??'')]);
        });
    }
    return $result;
}
