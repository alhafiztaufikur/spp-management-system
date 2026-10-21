<?php
/** Read-only report evidence. No placement, tariff, or historical bill is manufactured here. */
function report_source_period(string $value): ?array {
    $value=trim($value);
    if(preg_match('~^(\d{4})/(\d{4})$~D',$value,$m)&&((int)$m[2]===(int)$m[1]+1))
        return ['key'=>$value,'year'=>$value,'start'=>$m[1].'-07-01','end'=>$m[2].'-06-30','kind'=>'annual'];
    $month=lb_payment_month($value);
    if($month!=='')return ['key'=>$month,'year'=>lb_payment_year($month),'start'=>$month.'-01','end'=>date('Y-m-t',strtotime($month.'-01')),'kind'=>'monthly'];
    if(preg_match('~^[12]\d{3}$~D',$value))return ['key'=>$value,'year'=>'','start'=>$value.'-01-01','end'=>$value.'-12-31','kind'=>'year'];
    return null;
}
function report_undated_matches(array $f,string $period): bool {
    if(empty($f['include_undated']))return false;
    $p=report_source_period($period);
    return $p!==null&&$p['start']<=$f['tanggal_akhir']&&$p['end']>=$f['tanggal_awal'];
}
function report_undated_journal_periods(mysqli $db,string $table): array {
    if(!in_array($table,['legacy_op_journals','legacy_full_journals'],true))throw new LogicException('Invalid journal source.');
    static $cache=[];$key=$db->thread_id.':'.report_query_unit_id().':'.$table;
    if(isset($cache[$key]))return $cache[$key];$out=[];
    foreach(hp_rows($db,"SELECT j.id,r.raw_data FROM $table j JOIN legacy_full_batches b ON b.id=j.batch_id AND b.unit_id=j.unit_id JOIN legacy_finance_rows r ON r.source_id=b.source_id AND r.unit_id=b.unit_id AND r.row_key=j.row_key WHERE b.state='completed' AND j.source_date IS NULL") as $r){
        $raw=json_decode($r['raw_data'],true,512,JSON_THROW_ON_ERROR);$period=(string)($raw['source_period']??$raw['periode']??$raw['th_ajaran']??'');
        if(!report_source_period($period))$period=trim((string)($raw['BULAN']??$raw['bulan']??'')).' '.trim((string)($raw['TAHUN']??$raw['tahun']??''));
        if(report_source_period($period))$out[(int)$r['id']]=$period;
    }
    return $cache[$key]=$out;
}
function report_uses_transaction_dates(string $template,array $f): bool {
    return in_array($template,['penerimaan','setoran','tabungan-siswa','kas-tabungan'],true)
        ||($template==='per-item'&&!report_item_is_monthly_category($f['kategori'])&&!report_item_is_annual_category($f['kategori'])&&!report_item_is_one_time_category($f['kategori']));
}
function report_component_method_matches(array $f,string $method): bool {
    $values=$f['_multi']['metode']??(($f['metode']??'')!==''?[$f['metode']]:['*']);
    return filter_is_all($values)||in_array($method,$values,true)||in_array('Legacy',$values,true);
}
function report_component_matches(array $row,array $f): bool {
    if(!report_row_matches_filters($row,$f))return false;
    if(in_array($f['_template']??'',['per-item','status'],true)&&isset($row['student_active'])){
        if($f['siswa_status']==='active'&&!(int)$row['student_active'])return false;
        if($f['siswa_status']==='archived'&&(int)$row['student_active'])return false;
    }
    return true;
}
function report_component_class_matches(mysqli $db,array $f,array $row): bool {
    static $classes=[];$key=$db->thread_id.':'.report_query_unit_id();
    if(!isset($classes[$key])){$classes[$key]=[];foreach(class_all($db,false) as $c)$classes[$key][class_label($c)]=$c;}
    $label=(string)($row['kelas']??'');$class=$classes[$key][$label]??null;
    $row['master_kelas_id']=$class['id']??0;$row['tingkat']=$class['tingkat']??$label;
    return report_class_matches_row($f,$row);
}
function report_evidence_notes(array $report,string $template,array $f): array {
    $notes=[];
    foreach($report['sections']??[] as $part)$notes=array_merge($notes,$part['notes']??[]);
    if(report_uses_transaction_dates($template,$f)&&!empty($f['include_undated']))$notes[]='Termasuk transaksi tanpa tanggal sesuai periode kewajiban';
    if(array_filter($report['rows'],static fn($r)=>!empty($r['_incomplete'])))$notes[]='Total kewajiban hanya mencakup nominal yang diketahui';
    $report['notes']=array_values(array_unique($notes));return $report;
}
function report_annual_evidence(mysqli $db,array $f): array {
    $category=$f['kategori'];$year=$f['tahun_ajaran'];$legacy=report_legacy_periods($db);$placements=[];$bills=[];
    foreach(hp_rows($db,'SELECT p.*,y.label FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE y.label=?',[$year]) as $p)if($p['status']!=='dibatalkan')$placements[$p['no_induk']]=$p;
    if($category==='daftar_ulang')foreach(hp_rows($db,'SELECT t.*,COALESCE(SUM(d.jumlah),0) paid FROM tagihan_daftar_ulang t LEFT JOIN bayar_du d ON d.tagihan_daftar_ulang_id=t.id AND d.unit_id=t.unit_id WHERE t.tahun_ajaran_snapshot=? GROUP BY t.id',[$year]) as $b)$bills[$b['no_induk']]=$b;
    $nativePsb=[];if($category==='psb')foreach(hp_rows($db,'SELECT NO_INDUK,SUM(U_PSB) paid FROM bayar GROUP BY NO_INDUK') as $p)$nativePsb[$p['NO_INDUK']]=(float)$p['paid'];
    $rows=[];
    foreach(hp_rows($db,'SELECT s.*,mk.tingkat,mk.kode_rombel,mk.is_placeholder,mk.jenis,mk.nama_custom,mk.legacy_kind,mk.graduation_year FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id WHERE s.legacy_pending=0') as $s){
        if(($f['siswa_status']==='active'&&!(int)$s['is_active'])||($f['siswa_status']==='archived'&&(int)$s['is_active']))continue;
        $nis=$s['NO_INDUK'];$source=lb_student($db,$nis);$native=$bills[$nis]??null;$placement=$placements[$nis]??null;$credit=$legacy['payments'][$nis]['daftar_ulang'][$year]??null;
        if($category==='daftar_ulang'&&!$native&&!$placement&&!$credit&&(!$source||$source['migration_year']!==$year))continue;
        $proof=$category==='psb'?lb_psb_source($db,$nis):lb_du_source($db,$nis,$year);
        $known=$category==='psb'?(!$source||!empty($proof['known'])):($native!==null||!empty($proof['known'])||!$source);
        $bill=$category==='psb'?max(0,(float)$s['PSB']-(float)$s['potongan_psb_nominal']):($native!==null?(float)$native['nominal_tagihan']:(float)($proof['total']??0));
        if($category==='psb'&&$source&&$known&&$bill<=0)$bill=(float)$proof['total'];
        $psbCredit=$category==='psb'?(!empty($proof['known'])?(float)$proof['paid']:($source?(lb_cache($db)['paid'][$source['id']]['psb']??0)/100:0.0)):0.0;
        $paid=$category==='psb'?($nativePsb[$nis]??0)+$psbCredit:($native!==null?(float)$native['paid']:0)+($credit['paid']??0)/100;
        if(!$known)$bill=null;
        $cancelled=($native['status']??'')==='cancelled';$status=$known?report_billing_status((float)$bill,$paid,$cancelled?'cancelled':'open'):($paid>.001?'Ada Pembayaran':'Belum Diketahui');
        $label=$category==='psb'?class_label($s):($native['kelas_rombel_snapshot']??$placement['kelas_rombel_snapshot']??$credit['class']??'Tidak tercatat');
        $row=['nis'=>$nis,'student_id'=>(int)$s['id'],'unit_id'=>(int)$s['unit_id'],'nis_diknas'=>$s['NO_induk_diknas']??'','nama'=>$s['NAMA'],'kelas'=>$label,'master_kelas_id'=>$category==='psb'?$s['master_kelas_id']:($native['master_kelas_id']??$placement['master_kelas_id']??0),'tingkat'=>$category==='psb'?$s['KELAS']:($placement['kelas']??$label),'periode'=>$category==='psb'?'Sekali saat masuk':$year,'tagihan'=>$bill,'terbayar'=>$paid,'sisa'=>$known?($cancelled?0.0:max(0,$bill-$paid)):null,'status'=>$status,'_status'=>$status,'_incomplete'=>!$known];
        $row['tanggal_dibuat']=$source?null:$s['created_at'];
        if(report_class_matches_row($f,$row))$rows[]=$row;
    }
    usort($rows,static fn($a,$b)=>[$a['kelas'],$a['nama'],$a['nis']]<=>[$b['kelas'],$b['nama'],$b['nis']]);
    return ['title'=>report_categories($db)[$category],'subtitle'=>'Status kewajiban · '.($category==='psb'?'Sekali saat masuk':$year),'columns'=>[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['periode','Periode'],['tagihan','Tagihan','money_optional'],['terbayar','Terbayar','money'],['sisa','Sisa','money_optional'],['status','Status','status']],'rows'=>report_filter_rows($rows,$f)];
}
/** Payment evidence with no native bill remains visible, without inventing a principal/date. */
function report_billing_legacy_rows(mysqli $db,array $f,array $existing): array {
    if(!empty($f['filter_tanggal_tagihan'])&&$f['tanggal_awal']!=='')return [];
    $seen=[];foreach($existing as $r){$period=$r['periode_code']??($r['tahun_ajaran']??'');$seen[$r['nis'].'|'.$r['komponen_key'].'|'.$period]=true;}
    $students=[];foreach(hp_rows($db,'SELECT * FROM siswa WHERE legacy_pending=0') as $s)$students[$s['NO_INDUK']]=$s;
    $classes=[];foreach(class_all($db,false) as $c)$classes[class_label($c)]=$c;
    $rows=[];
    foreach(report_legacy_periods($db)['payments'] as $nis=>$categories){$s=$students[$nis]??null;if(!$s)continue;
        if(($f['siswa_status']==='active'&&!(int)$s['is_active'])||($f['siswa_status']==='archived'&&(int)$s['is_active']))continue;
        foreach($categories as $category=>$periods){if(!in_array($category,['spp','komite','daftar_ulang'],true))continue;
            foreach($periods as $period=>$p){if(isset($seen[$nis.'|'.$category.'|'.$period])||$p['paid']<=0)continue;$c=$classes[$p['class']]??null;
                $row=['nis'=>(string)$nis,'student_id'=>(int)$s['id'],'unit_id'=>(int)$s['unit_id'],'nis_diknas'=>$s['NO_induk_diknas']??'','nama'=>$s['NAMA'],'kelas'=>$p['class']?:'Tidak tercatat','master_kelas_id'=>$c['id']??0,'tingkat'=>$c['tingkat']??$p['class'],'komponen'=>['spp'=>'SPP','komite'=>'Komite','daftar_ulang'=>'Daftar Ulang'][$category],'komponen_key'=>$category,'periode'=>$category==='daftar_ulang'?$period:report_months()[substr($period,5,2)].' '.substr($period,0,4),'periode_code'=>$period,'tahun_ajaran'=>lb_payment_year($period),'tanggal_dibuat'=>null,'tagihan'=>null,'terbayar'=>$p['paid']/100,'sisa'=>null,'status'=>'Ada Pembayaran','_status'=>'Ada Pembayaran','_incomplete'=>true];
                if(report_class_matches_row($f,$row))$rows[]=$row;
            }
        }
    }
    return $rows;
}
function report_history_component_status(mysqli $db,array $f,bool $transactionDates=false): array {
    $cash=$f;
    if(!$transactionDates){[$start,$end]=explode('/',$f['tahun_ajaran']);$cash['tanggal_awal']=$start.'-07-01';$cash['tanggal_akhir']=$end.'-06-30';$cash['include_undated']=true;}
    $rows=[];
    foreach(report_payment_components($db,$cash) as $p){if($p['kategori_key']!==$f['kategori'])continue;if(!$transactionDates&&isset($p['legacy_owner_id'])&&empty($p['student_id']))continue;
        $key=$p['nis'].'|'.$p['kelas'];$rows[$key]??=['nis'=>$p['nis'],'nis_diknas'=>$p['nis_diknas']??'','nama'=>$p['nama'],'kelas'=>$p['kelas'],'periode'=>$transactionDates?report_item_date_period_label($f):$f['tahun_ajaran'],'tagihan'=>null,'terbayar'=>0.0,'sisa'=>null,'status'=>'Ada Pembayaran','_status'=>'Ada Pembayaran','_incomplete'=>true];$rows[$key]['terbayar']+=$p['nominal'];
    }
    $label=report_categories($db)[$f['kategori']]??'Pembayaran';
    return ['title'=>$transactionDates?'Rekap Pembayaran per Item: '.$label:$label,'subtitle'=>$transactionDates?'Tanggal transaksi: '.report_item_date_period_label($f):'Tahun ajaran '.$f['tahun_ajaran'],'columns'=>[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['periode','Periode'],['tagihan','Tagihan','money_optional'],['terbayar','Terbayar','money'],['sisa','Sisa','money_optional'],['status','Status','status']],'rows'=>report_filter_rows(array_values($rows),$f)];
}
function report_optional_positions(mysqli $db,string $category,string $year): array {
    $out=[];$master=(int)substr($category,11);[$first,$last]=explode('/',$year);
    foreach(hp_rows($db,"SELECT d.nominal_snapshot,b.NO_INDUK FROM bayar_biaya_lain d JOIN bayar b ON b.id=d.bayar_id AND b.unit_id=d.unit_id WHERE d.master_biaya_lain_id=? AND d.tagihan_biaya_lain_id IS NULL AND b.TGL_BYR>=? AND b.TGL_BYR<?",[$master,$first.'-07-01',$last.'-07-01']) as $r)$out[$r['NO_INDUK']]['paid']=($out[$r['NO_INDUK']]['paid']??0)+lf_cents($r['nominal_snapshot']);
    foreach(report_legacy_periods($db)['payments'] as $nis=>$categories)foreach($categories[$category]??[] as $period=>$r){$p=report_source_period((string)$period);if(!$p||($p['year']??'')!==$year)continue;$out[$nis]['paid']=($out[$nis]['paid']??0)+$r['paid'];$out[$nis]['legacy']=true;}
    return $out;
}
function report_legacy_years(mysqli $db): array {
    static $readCache=[];$cacheRead=PHP_SAPI!=='cli'&&($_SERVER['REQUEST_METHOD']??'')==='GET';
    $cacheKey=$db->thread_id.':'.report_query_unit_id();
    if($cacheRead&&isset($readCache[$cacheKey]))return $readCache[$cacheKey];
    $years=[];
    foreach(['legacy_full_payments'=>lful_read_ready($db),'legacy_op_payments'=>lop_ready($db)] as $table=>$ready){if(!$ready)continue;
        foreach(hp_rows($db,"SELECT DISTINCT p.source_period FROM $table p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed'".($table==='legacy_op_payments'?' AND p.cancelled=0':'')) as $p){$period=report_source_period((string)$p['source_period']);if($period&&$period['year']!=='')$years[$period['year']]=true;}
    }
    if($cacheRead)$readCache[$cacheKey]=$years;
    return $years;
}
/** Stream and aggregate once per request/scope; keep exact source cents and stable identities. */
function report_legacy_periods(mysqli $db,?array $onlyPeriods=null): array {
    static $cache=[];
    $revision=lop_ready($db)?(int)hp_one($db,'SELECT COALESCE(MAX(id),0) n FROM legacy_op_audit')['n']:0;
    $key=$db->thread_id.':'.report_query_unit_id().':'.$revision.($onlyPeriods===null?'':':'.implode(',',$onlyPeriods));
    if(isset($cache[$key]))return $cache[$key];
    $out=['payments'=>[],'years'=>[]];
    $periodWhere=static function(string $table)use($db,$onlyPeriods):string{
        if($onlyPeriods===null)return '';$matches=[];
        foreach(hp_rows($db,"SELECT DISTINCT source_period FROM $table".($table==='legacy_op_payments'?' WHERE cancelled=0':'')) as $row){$period=report_source_period((string)$row['source_period']);if($period&&in_array($period['key'],$onlyPeriods,true))$matches[]="'".$db->real_escape_string($row['source_period'])."'";}
        return ' AND p.source_period IN ('.(implode(',',$matches)?:'NULL').')';
    };
    if(report_query_unit_id()===0){
        foreach(lful_read_ready($db)?hp_rows($db,"SELECT DISTINCT p.source_period FROM legacy_full_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed'"):[] as $p){$period=report_source_period((string)$p['source_period']);if($period&&$period['year']!=='')$out['years'][$period['year']]=true;}
        foreach(lop_ready($db)?hp_rows($db,"SELECT DISTINCT p.source_period FROM legacy_op_payments p JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed' AND p.cancelled=0"):[] as $p){$period=report_source_period((string)$p['source_period']);if($period&&$period['year']!=='')$out['years'][$period['year']]=true;}
        return $cache[$key]=$out;
    }
    $add=static function(array $p,array $items)use(&$out):void {
        if(empty($p['NO_INDUK']))return;
        $period=report_source_period((string)$p['source_period']);if(!$period)return;
        if($period['year']!=='')$out['years'][$period['year']]=true;
        foreach($items as $item){
            $category=(string)$item['category'];$periodKey=$category==='daftar_ulang'?lb_payment_year((string)$p['source_period']):$period['key'];
            if(in_array($category,['spp','komite'],true)&&$period['kind']!=='monthly')continue;
            if($periodKey==='')continue;
            $cell=&$out['payments'][$p['NO_INDUK']][$category][$periodKey];
            $cell??=['paid'=>0,'class'=>(string)$p['class_snapshot']];$cell['paid']+=lf_cents($item['amount']);unset($cell);
        }
    };
    if(lful_read_ready($db)){
        $cursor=$db->query(hp_sql($db,"SELECT s.NO_INDUK,p.source_period,p.class_snapshot,i.category,i.amount FROM legacy_full_payments p JOIN legacy_full_items i ON i.payment_id=p.id AND i.unit_id=p.unit_id JOIN siswa s ON s.id=p.student_id AND s.unit_id=p.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed'".$periodWhere('legacy_full_payments')),MYSQLI_USE_RESULT);
        while($r=$cursor->fetch_assoc())$add($r,[['category'=>$r['category'],'amount'=>$r['amount']]]);$cursor->free();
    }
    if(lop_ready($db)){
        $cursor=$db->query(hp_sql($db,"SELECT s.NO_INDUK,p.source_period,p.class_snapshot,p.items FROM legacy_op_payments p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed' AND p.cancelled=0".$periodWhere('legacy_op_payments')),MYSQLI_USE_RESULT);
        while($r=$cursor->fetch_assoc())$add($r,json_decode($r['items'],true,512,JSON_THROW_ON_ERROR));$cursor->free();
    }
    return $cache[$key]=$out;
}
function report_monthly_evidence(mysqli $db,string $category,array $months,array $f): array {
    $periods=array_map(static fn($m)=>sprintf('%04d-%02d',$m[1],$m[0]),$months);
    $years=array_values(array_unique(array_map('lb_payment_year',$periods)));
    $students=[];foreach(hp_rows($db,'SELECT s.*,mk.tingkat,mk.kode_rombel,mk.is_placeholder,mk.jenis,mk.nama_custom,mk.legacy_kind,mk.graduation_year FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id WHERE s.legacy_pending=0') as $s){
        if(($f['siswa_status']==='active'&&!(int)$s['is_active'])||($f['siswa_status']==='archived'&&(int)$s['is_active']))continue;
        $students[$s['NO_INDUK']]=$s;
    }
    $roster=[];$cells=[];$classes=[];
    foreach(class_all($db,false) as $c)$classes[class_label($c)]=$c;
    $place=static function(string $nis,string $year,string $label,?int $classId=null)use(&$roster,$students,$classes,$f):void{
        if(!isset($students[$nis]))return;$s=$students[$nis];$c=$classes[$label]??null;
        $row=['nis'=>$nis,'student_id'=>(int)$s['id'],'unit_id'=>(int)$s['unit_id'],'nis_diknas'=>$s['NO_induk_diknas']??'','nama'=>$s['NAMA'],'kelas'=>$label?:'Tidak tercatat','tahun_ajaran'=>$year,'master_kelas_id'=>$classId??($c['id']??0),'tingkat'=>$c['tingkat']??$label];
        if(!report_class_matches_row($f,$row)||!report_row_matches_filters($row,$f))return;
        $roster[$nis.'|'.$year.'|'.$row['kelas']]=$row;
    };
    foreach(hp_rows($db,'SELECT p.*,y.label FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id') as $p)
        if(in_array($p['label'],$years,true)&&$p['status']!=='dibatalkan')$place((string)$p['no_induk'],$p['label'],(string)$p['kelas_rombel_snapshot'],(int)$p['master_kelas_id']);
    $sql=$category==='komite'
      ? "SELECT t.*,sta.master_kelas_id,COALESCE(SUM(p.nominal),0) paid FROM tagihan_komite t JOIN siswa_tahun_ajaran sta ON sta.id=t.penempatan_id AND sta.unit_id=t.unit_id LEFT JOIN bayar_komite p ON p.tagihan_komite_id=t.id AND p.unit_id=t.unit_id GROUP BY t.id"
      : "SELECT t.*,COALESCE(SUM(CASE WHEN b.status='active' THEN a.nominal_dari_bayar ELSE 0 END),0) paid FROM tagihan_spp t LEFT JOIN spp_alokasi a ON a.tagihan_spp_id=t.id AND a.unit_id=t.unit_id LEFT JOIN spp_alokasi_batch b ON b.id=a.batch_id AND b.unit_id=a.unit_id GROUP BY t.id";
    foreach(hp_rows($db,$sql) as $b){$period=sprintf('%04d-%02d',(int)$b['tahun'],(int)$b['bulan']);if(!in_array($period,$periods,true))continue;
        $nis=(string)$b['no_induk'];$cells[$nis][$period]=['bill'=>(float)$b['nominal_tagihan'],'paid'=>(float)$b['paid'],'status'=>$b['status'],'class'=>(string)$b['kelas_rombel_snapshot'],'native'=>true];
        $place($nis,lb_payment_year($period),(string)$b['kelas_rombel_snapshot'],(int)$b['master_kelas_id']);
    }
    $legacy=report_legacy_periods($db,$periods);
    foreach($legacy['payments'] as $nis=>$categories)foreach($categories[$category]??[] as $period=>$payment){
        if(!in_array($period,$periods,true))continue;
        if(!isset($cells[$nis][$period])){$cells[$nis][$period]=['bill'=>null,'paid'=>0.0,'status'=>'unknown','class'=>$payment['class'],'native'=>false];$place((string)$nis,lb_payment_year($period),$payment['class']);}
        $cells[$nis][$period]['paid']+=$payment['paid']/100;
    }
    $coverage=lful_monthly_coverage_snapshot($db,array_map('strval',array_keys($students)),$category,$periods);
    $openingDebts=[];
    if($coverage['coverage'])foreach(hp_rows($db,"SELECT d.review_id,dm.component,dm.period,t.nominal_tagihan,t.status,COALESCE(SUM(p.nominal_snapshot),0) paid FROM legacy_debts d JOIN legacy_debt_masters dm ON dm.id=d.master_id AND dm.unit_id=d.unit_id JOIN tagihan_biaya_lain t ON t.id=d.bill_id AND t.unit_id=d.unit_id LEFT JOIN bayar_biaya_lain p ON p.tagihan_biaya_lain_id=t.id AND p.unit_id=t.unit_id GROUP BY d.id,t.id") as $debt){
        $key=$debt['review_id'].'|'.$debt['component'].'|'.$debt['period'];
        $openingDebts[$key]=array_key_exists($key,$openingDebts)?null:$debt;
    }
    foreach($roster as &$row){$nis=$row['nis'];$known=0.0;$paid=0.0;$remaining=0.0;$unknown=false;$unknownRemaining=false;$hasBill=false;$hasOpen=false;$hasPaid=false;
        foreach($periods as $period){
            $p=null;
            if(lb_payment_year($period)===$row['tahun_ajaran']){
                $p=$cells[$nis][$period]??null;
                if($p&&$p['class']!==$row['kelas'])$p=null;
                if(!$p){
                    $legacyStudent=$coverage['students'][$nis]??null;
                    $isHistorical=$legacyStudent&&$period<($legacyStudent['settings']['start_period']??'');
                    $p=['bill'=>$isHistorical?null:0.0,'paid'=>0.0,'status'=>$isHistorical?'unknown':'unbilled','native'=>false,'unissued'=>!$isHistorical];
                }
                $legacyStudent=$coverage['students'][$nis]??null;$explicit=$legacyStudent?($coverage['coverage'][$legacyStudent['student_id']][$category][$period]??null):null;
                if(empty($p['native'])&&$explicit){
                    unset($p['unissued']);
                    if($explicit['disposition']==='settled'){$p['bill']=null;$p['remaining']=0.0;$p['text']='Lunas';$p['status']='settled';}
                    elseif($explicit['disposition']==='legacy_debt'){
                        $p['bill']=null;
                        $debt=$openingDebts[$legacyStudent['review_id'].'|'.($category==='spp'?'SPP':'Komite').'|'.$period]??null;
                        if(!$debt){$p['status']='unknown';}
                        else{$debtPaid=(float)$debt['paid'];$debtBill=(float)$debt['nominal_tagihan'];$p['paid']+=$debtPaid;$cancelledOpening=$debt['status']==='cancelled';$p['remaining']=$cancelledOpening?0.0:max(0,$debtBill-$debtPaid);$p['text']=$cancelledOpening?'Dibatalkan':report_status_name($debtBill,$debtPaid);$p['status']=$cancelledOpening?'cancelled':'opening';}
                    }else{$p['bill']=0.0;$p['remaining']=0.0;$p['text']=$p['paid']>.001?'Ada Pembayaran':'Tidak Ditagihkan';$p['status']='unbilled';}
                }
            }
            if(!$p){$row['periods'][$period]=['bill'=>null,'paid'=>null,'remaining'=>null,'text'=>'—','status'=>''];continue;}
            $bill=$p['bill'];$amount=$p['paid'];$cancelled=$p['status']==='cancelled';$isUnknown=$bill===null;
            $text=$isUnknown?($amount>.001?'Ada Pembayaran':'Belum Diketahui'):($p['status']==='unbilled'?'Tidak Ditagihkan':($p['status']==='covered_psb'?'Tercakup Uang PSB':($p['status']==='waived'?'Potongan Penuh':($cancelled?'Dibatalkan':report_status_name($bill,$amount)))));
            $text=$p['text']??$text;$rest=$p['remaining']??($isUnknown?null:($cancelled?0.0:max(0,$bill-$amount)));
            $row['periods'][$period]=['bill'=>$bill,'paid'=>$amount,'remaining'=>$rest,'text'=>$text,'status'=>report_status_key($text),'evidence_state'=>!empty($p['unissued'])?'unissued':($rest===null?'rekonsiliasi':report_status_key($text))];
            $unknown=$unknown||$isUnknown;$unknownRemaining=$unknownRemaining||$rest===null;$known+=$cancelled?0:(float)$bill;$paid+=$amount;$remaining+=(float)$rest;
            $hasBill=$hasBill||(!empty($p['native'])||($bill??0)>.001||isset($p['remaining'])&&$p['status']!=='unbilled');$hasOpen=$hasOpen||(!$cancelled&&$p['status']!=='unbilled'&&(!$isUnknown||isset($p['remaining'])));$hasPaid=$hasPaid||$amount>.001;
        }
        $row['total_tagihan']=$unknown&&$known<=.001?null:$known;$row['total_bayar']=$paid;$row['tunggakan']=$unknownRemaining&&$remaining<=.001?null:$remaining;
        $row['status']=$remaining>.001?($hasPaid?'Cicilan':'Belum Bayar'):($unknownRemaining?($hasPaid?'Ada Pembayaran':'Belum Diketahui'):(!$hasBill?'Tidak Ditagihkan':(!$hasOpen?'Dibatalkan':'Lunas')));
        $row['_status']=$row['status'];$row['_incomplete']=$unknown;
    }unset($row);
    usort($roster,static fn($a,$b)=>[$a['tahun_ajaran'],$a['kelas'],$a['nama'],$a['nis']]<=>[$b['tahun_ajaran'],$b['kelas'],$b['nama'],$b['nis']]);
    return array_values($roster);
}
function report_monthly_data(mysqli $db,array $f,bool $annual=false): array {
    $category=$annual?'spp':$f['kategori'];$months=$annual?report_academic_months($f['tahun_ajaran']):report_month_range((int)$f['bulan_awal'],max(2000,(int)$f['tahun_awal']),(int)$f['bulan_akhir'],max(2000,(int)$f['tahun_akhir']));
    $rows=report_monthly_evidence($db,$category,$months,$f);$columns=[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas']];if(!$annual)$columns[]=['tahun_ajaran','Tahun Ajaran'];
    foreach($months as [$m,$y]){$key=$annual?'m'.sprintf('%02d',$m).'_'.$y:'m'.sprintf('%04d_%02d',$y,$m);$period=sprintf('%04d-%02d',$y,$m);$columns[]=[$key,report_months()[sprintf('%02d',$m)].' '.$y,'html'];foreach($rows as &$row){$cell=$row['periods'][$period];$row[$key]=['text'=>$cell['text'],'sub'=>$cell['paid']===null?'':report_money($cell['paid']),'status'=>$cell['status']];}unset($row);}
    foreach($rows as &$row)unset($row['periods']);unset($row);
    $columns=array_merge($columns,[['total_tagihan','Total Tagihan','money_optional'],['total_bayar','Total Bayar','money'],['tunggakan','Tunggakan','money_optional']]);if(!$annual)$columns[]=['status','Status','status'];
    $rows=report_filter_rows($rows,$f);
    return ['title'=>$annual?'Rekap SPP Tahun Ajaran per Kelas':'Rekap Pembayaran per Item: '.(report_categories($db)[$category]??$category),'subtitle'=>$annual?'Tahun ajaran '.$f['tahun_ajaran']:'Periode tagihan: '.report_month_range_label($months),'columns'=>$columns,'rows'=>$rows];
}
function report_monthly_status(mysqli $db,array $f): array {
    [$month,$year]=report_period_from_academic($f['tahun_ajaran'],$f['bulan_awal']);$period=sprintf('%04d-%02d',(int)$year,(int)$month);$label=report_months()[$month].' '.$year;$rows=[];
    foreach(report_monthly_evidence($db,$f['kategori'],[[(int)$month,(int)$year]],$f) as $row){$p=$row['periods'][$period];unset($row['periods']);$row+=['periode'=>$label,'tagihan'=>$p['bill'],'terbayar'=>$p['paid'],'sisa'=>$p['remaining']];$row['status']=$p['text'];$row['_status']=$p['text'];$rows[]=$row;}
    return ['title'=>report_categories($db)[$f['kategori']],'subtitle'=>'Status kewajiban · '.$label,'columns'=>[['nis','NIS'],['nama','Nama Siswa'],['kelas','Kelas'],['periode','Periode'],['tagihan','Tagihan','money_optional'],['terbayar','Terbayar','money'],['sisa','Sisa','money_optional'],['status','Status','status']],'rows'=>report_filter_rows($rows,$f)];
}
