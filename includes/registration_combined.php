<?php
require_once __DIR__.'/registration_history.php';
require_once __DIR__.'/legacy_billing.php';
require_once __DIR__.'/migrated_history.php';
require_once __DIR__.'/legacy_du.php';
/** Read-only DU grouping; historical payments never settle an unrelated native bill. */
function rc_source(mysqli $db,string $source='all',?array $selection=null): string {
    $parts=[];
    if($source!=='legacy'){
        $f=['q'=>'','student_id'=>0,'values'=>['kelas'=>['*'],'tahun_ajaran'=>['*'],'status'=>['*'],'operator'=>['*']]];[$native]=registration_active_sql($f,$db);
        $target=$selection?' WHERE s.id='.(int)$selection['student_id'].' AND n.unit_id='.(int)$selection['unit_id']:'';
        $parts[]="SELECT n.unit_id,CONCAT('s:',s.id) owner_key,s.id student_id,0 owner_id,n.no_induk identity,n.nama name,n.NO_induk_diknas,n.master_kelas_id,n.kelas,n.th_ajaran,n.tagihan_id native_bill,0 legacy_id,n.total native_total,n.paid native_paid,0 legacy_paid FROM ($native) n JOIN siswa s ON s.NO_INDUK=n.no_induk AND s.unit_id=n.unit_id $target";
    }
    if($source!=='system'&&lop_ready($db)){
        $payments='legacy_op_payments';
        if($selection){
            $owners=array_column(hp_rows($db,'SELECT id FROM legacy_op_owners WHERE unit_id=? AND '.($selection['student_id']?'student_id=?':'id=?'),[$selection['unit_id'],$selection['student_id']?:$selection['owner_id']]),'id');
            $payments='(SELECT * FROM legacy_op_payments WHERE unit_id='.(int)$selection['unit_id'].' AND owner_id IN ('.(implode(',',array_map('intval',$owners))?:'0').'))';
        }
        $lines=ldu_receipt_lines_sql(true,$selection?$payments:null,true,$db);$parts[]="SELECT p.unit_id,IF(o.student_id IS NULL,CONCAT('o:',o.id),CONCAT('s:',o.student_id)) owner_key,COALESCE(o.student_id,0) student_id,o.id owner_id,o.source_identity identity,o.name name,s.NO_induk_diknas,COALESCE(s.master_kelas_id,g.master_kelas_id) master_kelas_id,COALESCE((SELECT t.kelas_snapshot FROM tagihan_daftar_ulang t WHERE t.unit_id=p.unit_id AND t.no_induk=s.NO_INDUK AND t.tahun_ajaran_snapshot=p.year LIMIT 1),p.class_snapshot) kelas,p.year th_ajaran,0 native_bill,p.payment_id legacy_id,0 native_total,0 native_paid,p.amount legacy_paid FROM ($lines) p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id LEFT JOIN siswa s ON s.id=o.student_id AND s.unit_id=o.unit_id LEFT JOIN legacy_op_groups g ON g.id=o.group_id AND g.unit_id=o.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE b.state='completed' AND p.cancelled=0";
    }

    $parts=array_map(static fn($part)=>"SELECT original.*,0 coverage_id FROM ($part) original",$parts);
    if(du_psb_schema_ready($db)&&$source!=='legacy'){
        $ids=[];foreach(hp_rows($db,'SELECT DISTINCT y.label FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id WHERE p.du_covered_by_psb=1') as $y)foreach(du_psb_states($db,$y['label']) as $state)if($state['covered_psb']&&!empty($state['placement']['du_covered_by_psb']))$ids[]=(int)$state['placement']['id'];
        if($ids)$parts[]="SELECT p.unit_id,CONCAT('s:',s.id) owner_key,s.id student_id,0 owner_id,s.NO_INDUK identity,s.NAMA name,s.NO_induk_diknas,p.master_kelas_id,p.kelas_rombel_snapshot kelas,y.label th_ajaran,0 native_bill,0 legacy_id,0 native_total,0 native_paid,0 legacy_paid,p.id coverage_id FROM siswa_tahun_ajaran p JOIN tahun_ajaran y ON y.id=p.tahun_ajaran_id AND y.unit_id=p.unit_id JOIN siswa s ON s.NO_INDUK=p.no_induk AND s.unit_id=p.unit_id WHERE p.id IN (".implode(',',$ids).')';
    }
    if(!$parts)return '(SELECT 0 unit_id,NULL owner_key,0 student_id,0 owner_id,\'\' identity,\'\' name,\'\' NO_induk_diknas,0 master_kelas_id,\'\' kelas,NULL th_ajaran,0 native_bill,0 legacy_id,0 native_total,0 native_paid,0 legacy_paid,0 coverage_id WHERE FALSE)';
    if($selection){
        $owner="'".$db->real_escape_string($selection['owner_key'])."'";
        $parts=array_map(static fn($part)=>"SELECT selected_group.* FROM ($part) selected_group WHERE selected_group.unit_id=".(int)$selection['unit_id']." AND selected_group.owner_key=$owner",$parts);
    }
    return hp_sql($db,'('.implode(' UNION ALL ',$parts).')');
}
function rc_verified_tariffs(mysqli $db): array {
    $e=rc_evidence_sources($db);$proofs=[];
    foreach($e['obligations'] as $key=>$ob){
        if(count($ob)!==1||trim($ob[0]['evidence'])==='')continue;
        [$owner,$year]=explode('|',$key,2);if(!preg_match('/^[12][0-9]{3}\/[12][0-9]{3}$/D',$year))continue;
        $values=$e['tariffIndex'][$e['owners'][$owner]??0][$year]??[];
        foreach($values as $level=>$fees)if(preg_match('/^(?:[1-9]|1[0-2])$/D',(string)$level)&&count($fees['amounts']??[])===1&&empty($fees['invalid']))$proofs[]=['owner'=>(int)$owner,'year'=>$year,'level'=>(int)$level,'principal'=>lf_cents($ob[0]['principal'])];
    }return $proofs;
}
function rc_groups(mysqli $db,string $source='all',?array $selection=null,bool $withProof=true): string {
    // Source selects owner/year groups, never removes settlement credit from a bill.
    $lines=rc_source($db,'all',$selection);
    $groups="SELECT unit_id,owner_key,MAX(student_id) student_id,MAX(owner_id) owner_id,MAX(identity) identity,MAX(name) name,MAX(NO_induk_diknas) NO_induk_diknas,MAX(master_kelas_id) master_kelas_id,IF(COUNT(DISTINCT NULLIF(TRIM(kelas),''))=1,TRIM(MAX(kelas)),NULL) kelas,th_ajaran,CASE WHEN MAX(native_bill)>0 THEN MAX(native_bill) WHEN MAX(coverage_id)>0 THEN -2000000000-MAX(coverage_id) ELSE -1000000000-MIN(NULLIF(legacy_id,0)) END id,SUM(native_total) native_total,SUM(native_paid) native_paid,SUM(legacy_paid) legacy_paid,COUNT(NULLIF(legacy_id,0)) legacy_count,COUNT(DISTINCT NULLIF(native_bill,0)) native_count,MAX(coverage_id) coverage_id FROM $lines x GROUP BY unit_id,owner_key,th_ajaran";
    if($source!=='all')$groups="SELECT * FROM ($groups) source_groups WHERE ".($source==='system'?'(native_count>0 OR coverage_id>0)':'legacy_count>0');
    return $withProof?rc_with_historical_total($db,"($groups)"):"($groups)";
}
function rc_with_historical_total(mysqli $db,string $groups): string {
    [$value,$join]=rc_proof_sql($db,'x');
    return "(SELECT x.*,$value historical_total FROM $groups x $join)";
}
function rc_proof_sql(mysqli $db,string $alias): array {
    if(!preg_match('/^[a-z]+$/D',$alias))throw new LogicException('Invalid proof alias.');
    $proofs=[];foreach(rc_verified_tariffs($db) as $proof)$proofs[strtoupper(bin2hex($proof['owner'].'|'.$proof['year'].'|'.$proof['level']))]=lf_money($proof['principal']);
    if(!$proofs)return ['NULL',''];
    $json=hp_json_document($db,$proofs);$level="CAST(TRIM($alias.kelas) AS UNSIGNED)";
    // HEX keeps the dynamic JSON path safe even when a historical year is malformed.
    $path="CONCAT('$.\"',HEX(CONCAT($alias.owner_id,'|',$alias.th_ajaran,'|',$level)),'\"')";
    return ["CASE WHEN TRIM($alias.kelas) REGEXP CONCAT('^',$level,'[A-Za-z]?$') AND (($alias.unit_id=1 AND $level BETWEEN 1 AND 6) OR ($alias.unit_id=2 AND $level BETWEEN 7 AND 9) OR ($alias.unit_id=3 AND $level BETWEEN 10 AND 12)) THEN CAST(JSON_UNQUOTE(JSON_EXTRACT($json,$path)) AS DECIMAL(20,2)) ELSE NULL END",''];
}
function rc_page(mysqli $db,array $input): array {
    $operatorOptions=history_operator_options($db);
    $duLines=ldu_receipt_lines_sql(true,null,false,$db);
    $source=mh_choice($input,'source',['all','system','legacy'],($input['view']??'')==='legacy'?'legacy':'all');$baseSql=rc_groups($db,$source,null,false);$fullSql=rc_with_historical_total($db,$baseSql);$sql=$baseSql;$where=' WHERE 1=1';$args=[];
    $q=mb_substr(trim(is_string($input['q']??null)?$input['q']:''),0,100);if($q!==''){$where.=' AND (name LIKE ? OR identity LIKE ? OR NO_induk_diknas LIKE ?)';$args=array_fill(0,3,'%'.$q.'%');}
    foreach(['student_id','per_page','page','selected','selected_unit'] as $key)if(isset($input[$key])&&!is_scalar($input[$key]))throw new InvalidArgumentException('Parameter '.$key.' tidak valid.');
    // Validate candidate year syntax now; actual choices come from the same
    // materialized read as the page, so the history is not grouped a second time.
    $rawYears=$input['year']??$input['tahun_ajaran']??null;$yearOptions=[];
    foreach(is_array($rawYears)?$rawYears:[$rawYears] as $v){if($v===null||in_array($v,['','*','all','semua','0'],true))continue;if(!is_scalar($v)||(!preg_match('/^[12][0-9]{3}\/[12][0-9]{3}$/D',(string)$v)&&$v!=='Tidak tercatat'))throw new InvalidArgumentException('Pilihan year tidak tersedia pada unit ini.');$yearOptions[(string)$v]=(string)$v;}

    $classes=class_all($db,false);$classOptions=[];foreach(range(...unit_level_bounds()) as $level)$classOptions['tingkat:'.$level]='Kelas '.$level;foreach($classes as $c)$classOptions[(string)$c['id']]=class_label($c);
    $classInput=$input['class_id']??$input['kelas']??null;if(!isset($input['class_id'])&&is_scalar($classInput)&&ctype_digit((string)$classInput)&&isset($classOptions['tingkat:'.$classInput]))$classInput='tingkat:'.$classInput;if($classInput!==null){$convert=static fn($v)=>is_scalar($v)&&str_starts_with((string)$v,'rombel:')?substr((string)$v,7):$v;$classInput=is_array($classInput)?array_map($convert,$classInput):$convert($classInput);}
    $statusOptions=['lunas'=>'Lunas','cicilan'=>'Belum lunas','covered_psb'=>'Tercakup PSB','unknown'=>'Status belum dapat ditentukan'];
    $choose=static function(string $name,$raw,array $options):array{$values=filter_register($name,$raw,$options);foreach($values as $v)if($v!=='*'&&!array_key_exists($v,$options))throw new InvalidArgumentException('Pilihan '.$name.' tidak tersedia pada unit ini.');return $values;};
    $yearValues=$choose('year',$input['year']??$input['tahun_ajaran']??null,$yearOptions);$year=filter_scalar($yearValues);$where.=filter_sql_values($yearValues,"COALESCE(th_ajaran,'Tidak tercatat')");
    $classValues=$choose('class_id',$classInput,$classOptions);$class=(int)filter_scalar($classValues,'0');if(!filter_is_all($classValues)){$matches=[];foreach($classValues as $v){if(str_starts_with($v,'tingkat:'))$matches[]='master_kelas_id IN (SELECT id FROM master_kelas WHERE tingkat='.(int)substr($v,8).')';else $matches[]='master_kelas_id='.(int)$v;}$where.=' AND ('.implode(' OR ',$matches).')';}
    $student=max(0,(int)($input['student_id']??0));if($student){$where.=' AND student_id=?';$args[]=$student;}
    $statusValues=$choose('status_filter',$input['status_filter']??$input['status']??null,$statusOptions);$status=filter_scalar($statusValues,'all');
    if(!filter_is_all($statusValues))$sql=$fullSql;
    if(!filter_is_all($statusValues)){$matches=[];foreach($statusValues as $v)$matches[]=match($v){'covered_psb'=>'coverage_id>0','unknown'=>'(native_count=0 AND legacy_count>0 AND historical_total IS NULL)','lunas'=>'((native_count>0 AND native_total<=native_paid+legacy_paid) OR (native_count=0 AND legacy_count>0 AND historical_total IS NOT NULL AND historical_total<=legacy_paid+native_paid))','cicilan'=>'((native_count>0 AND native_total>native_paid+legacy_paid) OR (native_count=0 AND legacy_count>0 AND historical_total IS NOT NULL AND historical_total>legacy_paid+native_paid))'};$where.=' AND ('.implode(' OR ',$matches).')';}
    $operators=history_operator_choices($input['operator']??null,$operatorOptions);
    if(!filter_is_all($operators)){
        $matches=[];
        if($source!=='legacy'){$filter=history_operator_where($db,$operators,'b.id','b.unit_id');$matches[]="EXISTS(SELECT 1 FROM bayar_du d JOIN bayar b ON b.id=d.bayar_id AND b.unit_id=d.unit_id JOIN siswa s ON s.NO_INDUK=b.NO_INDUK AND s.unit_id=b.unit_id WHERE s.id=x.student_id AND b.unit_id=x.unit_id AND d.th_ajaran<=>x.th_ajaran $filter)";}
        if($source!=='system'&&lop_ready($db)){$filter=history_operator_where($db,$operators,'(-1000000000-p.id)','p.unit_id');$matches[]="EXISTS(SELECT 1 FROM legacy_op_payments p JOIN ($duLines) dl ON dl.payment_id=p.id AND dl.unit_id=p.unit_id JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id WHERE p.unit_id=x.unit_id AND IF(o.student_id IS NULL,CONCAT('o:',o.id),CONCAT('s:',o.student_id))=x.owner_key AND dl.year<=>x.th_ajaran AND p.cancelled=0 AND (p.source_table='bayar_du' OR JSON_SEARCH(p.items,'one','daftar_ulang',NULL,'$[*].category') IS NOT NULL) $filter)";}
        $where.=$matches?' AND ('.implode(' OR ',$matches).')':' AND FALSE';
    }
    $size=in_array((int)($input['per_page']??25),[10,25,50,100],true)?(int)($input['per_page']??25):25;$page=max(1,(int)($input['page']??1));
    $order="COALESCE(th_ajaran,'') DESC,name,unit_id,id";
    $selected=(int)($input['selected']??0);$selectedUnit=(int)($input['selected_unit']??0);
    $selectedYear=rc_selected_year_sql($db,$input);
    $wanted=$selected?"COALESCE((SELECT CEIL(_position/$size) FROM filtered WHERE id=$selected".($selectedUnit?" AND unit_id=$selectedUnit":'').$selectedYear." LIMIT 1),$page)":(string)$page;
    // DISTINCT prevents CTE merging: aggregation/JSON extraction happens once,
    // reused by year options, exact summary, selection rank and the bounded page.
    [$proofValue,$proofJoin]=filter_is_all($statusValues)?rc_proof_sql($db,'f'):['f.historical_total',''];
    $proofColumn=filter_is_all($statusValues)?",$proofValue historical_total":'';
    $pageSql="WITH records AS (SELECT DISTINCT * FROM $sql base),
        filtered AS (SELECT x.*,ROW_NUMBER() OVER(ORDER BY $order) _position FROM records x $where),
        stats AS (SELECT COUNT(*) _n,COALESCE(SUM(native_total),0) _bill,COALESCE(SUM(native_paid),0) _native_paid,COALESCE(SUM(legacy_paid),0) _legacy_paid,COALESCE(SUM(legacy_count),0) _legacy_count FROM filtered),
        meta AS (SELECT JSON_ARRAYAGG(value) _years FROM (SELECT DISTINCT COALESCE(th_ajaran,'Tidak tercatat') value FROM records) years),
        bounds AS (SELECT LEAST($wanted,GREATEST(1,CEIL(_n/$size))) _page FROM stats)
        SELECT f.*,stats.*,meta._years,bounds._page$proofColumn FROM stats CROSS JOIN meta CROSS JOIN bounds LEFT JOIN filtered f ON f._position>(bounds._page-1)*$size AND f._position<=bounds._page*$size $proofJoin ORDER BY f._position";
    $rows=lf_rows($db,history_performance_du_ready($db)?hp_sql($db,$pageSql):hp_scan_sql($db,$pageSql),$args);$first=$rows[0];$total=(int)$first['_n'];$pages=max(1,(int)ceil($total/$size));$page=(int)$first['_page'];$offset=($page-1)*$size;
    $years=json_decode($first['_years']??'[]',true)??[];rsort($years,SORT_STRING);$yearOptions=array_combine($years,$years);$yearValues=$choose('year',$rawYears,$yearOptions);$year=filter_scalar($yearValues);
    if(!$total)$rows=[];else foreach($rows as &$row){foreach(['_n','_bill','_native_paid','_legacy_paid','_legacy_count','_years','_page','_position'] as $key)unset($row[$key]);}unset($row);
    $summary=['n'=>$total,'bill'=>$first['_bill'],'native_paid'=>$first['_native_paid'],'legacy_paid'=>$first['_legacy_paid'],'legacy_count'=>$first['_legacy_count']];
    return compact('rows','summary','total','size','pages','page','offset','q','year','class','source','status','operators','operatorOptions','yearOptions','classOptions','statusOptions','yearValues','classValues','statusValues')+['read_sql'=>"SELECT * FROM $fullSql x $where ORDER BY COALESCE(th_ajaran,'') DESC,name,unit_id,id",'read_args'=>$args];
}
function rc_evidence_sources(mysqli $db,bool $refresh=false): array {
    static $cache=[];$key=hp_connection_key($db).':'.(int)($GLOBALS['app_unit_id']??unit_active_id());if(!$refresh&&isset($cache[$key]))return $cache[$key];
    $owners=[];$tariffs=[];$obligations=[];$sources=[];
    if(lop_ready($db)){
        foreach(hp_rows($db,"SELECT o.id,o.unit_id,b.source_id FROM legacy_op_owners o JOIN legacy_full_batches b ON b.id=o.batch_id AND b.unit_id=o.unit_id WHERE b.state='completed'") as $r){$owners[$r['id']]=$r['source_id'];$sources[(int)$r['unit_id']][(int)$r['source_id']]=true;}
        $sourceFilter=[];foreach($sources as $unit=>$ids)$sourceFilter[]='(unit_id='.$unit.' AND source_id IN ('.implode(',',array_keys($ids)).'))';
        if($sourceFilter)foreach(hp_rows($db,"SELECT source_id,raw_data FROM legacy_finance_rows WHERE source_table IN ('Daftar_ulang','du') AND (".implode(' OR ',$sourceFilter).")") as $r){$v=json_decode($r['raw_data'],true);$tariffs[$r['source_id']][]=$v;}
        foreach(hp_rows($db,"SELECT owner_id,period,principal,evidence FROM legacy_op_obligations WHERE category='daftar_ulang'") as $r)$obligations[$r['owner_id'].'|'.$r['period']][]=$r;
    }
    $tariffIndex=[];
    foreach($tariffs as $source=>$values)foreach($values as $v){
        $year=trim((string)($v['th_ajaran']??''));$level=trim((string)($v['kelas']??''));
        try{$tariffIndex[$source][$year][$level]['amounts'][lf_cents($v['Jumlah']??$v['jumlah']??null)]=true;}
        catch(Throwable $error){$tariffIndex[$source][$year][$level]['invalid']=true;}
    }
    return $cache[$key]=compact('owners','tariffs','obligations','tariffIndex');
}
function rc_tariff_evidence(mysqli $db,array $g): array {
    if(!empty($g['coverage_id']))return ['status'=>'Tercakup PSB','tariff'=>null,'remaining'=>0.0,'reason'=>'DU kelas awal tercakup PSB. Sisa pembayaran mengikuti kewajiban PSB; tidak ada penagihan DU terpisah.'];
    if(!empty($g['native_count']))return ['status'=>(float)$g['native_paid']+(float)$g['legacy_paid']>=(float)$g['native_total']?'Lunas':'Belum Lunas','tariff'=>(float)$g['native_total'],'remaining'=>max(0,(float)$g['native_total']-(float)$g['native_paid']-(float)$g['legacy_paid']),'reason'=>'Tagihan siswa terhubung ke pembayaran operasional dan Legacy pada tahun ajaran yang sama.'];
    if(!$g['legacy_count'])return ['status'=>$g['native_paid']>=$g['native_total']?'Lunas':'Belum Lunas','tariff'=>$g['native_total'],'remaining'=>max(0,$g['native_total']-$g['native_paid']),'reason'=>'Tagihan operasional terhubung.'];
    $reason=[];$tariffs=[];$tariffInvalid=false;$year=$g['th_ajaran'];$class=trim((string)$g['kelas']);
    if(!$year)$reason[]='Periode tidak tercatat.';if($class==='')$reason[]='Kelas sumber kosong atau berbeda.';
    [$low,$high]=unit_level_bounds((int)$g['unit_id']);$level=preg_match('/^([0-9]{1,2})[A-Za-z]?$/D',$class,$match)?(int)$match[1]:0;if($level<$low||$level>$high){$reason[]='Tingkat sumber tidak sesuai unit.';$level=0;}
    $evidence=rc_evidence_sources($db);$sourceId=$evidence['owners'][$g['owner_id']]??0;
    if($year&&$level){$indexed=$evidence['tariffIndex'][$sourceId][$year][(string)$level]??[];$tariffs=$indexed['amounts']??[];if(!empty($indexed['invalid'])){$reason[]='Nominal tarif sumber tidak valid.';$tariffInvalid=true;}}
    if(count($tariffs)>1)$reason[]='Tarif sumber bentrok.';elseif(!$tariffs)$reason[]='Tarif sumber belum cocok dengan unit, tahun, dan kelas.';
    // A current student's discount is not evidence for a different historical year.
    $ob=$evidence['obligations'][$g['owner_id'].'|'.($year??'')]??[];
    if(count($ob)===1&&trim($ob[0]['evidence'])!==''&&count($tariffs)===1&&!$tariffInvalid&&$level&&$year){$net=lf_cents($ob[0]['principal']);$paid=(int)round(((float)$g['legacy_paid']+(float)$g['native_paid'])*100);return ['status'=>$paid>=$net?'Lunas':'Belum Lunas','tariff'=>$net/100,'remaining'=>max(0,$net-$paid)/100,'reason'=>'Kewajiban historis dan potongan telah disahkan; tidak mengubah tagihan operasional.'];}
    $reason[]='Potongan historis belum terbukti untuk periode ini.';
    return ['status'=>'Status belum dapat ditentukan','tariff'=>null,'remaining'=>null,'source_tariff'=>count($tariffs)===1?array_key_first($tariffs)/100:null,'reason'=>implode(' ',array_unique($reason))];
}
function rc_receipts(mysqli $db,array $g,string $source='all'): array {
    $out=[];if($source!=='legacy'&&$g['student_id'])foreach(hp_rows($db,'SELECT b.id,b.unit_id,b.TGL_BYR,b.total_jumlah,SUM(d.jumlah) du_amount FROM bayar_du d JOIN bayar b ON b.id=d.bayar_id AND b.unit_id=d.unit_id JOIN siswa s ON s.NO_INDUK=b.NO_INDUK AND s.unit_id=b.unit_id WHERE s.id=? AND d.th_ajaran<=>? AND b.unit_id=? GROUP BY b.id,b.unit_id,b.TGL_BYR,b.total_jumlah',[$g['student_id'],$g['th_ajaran'],$g['unit_id']]) as $r)$out[]=$r;
    $ownerColumn=$g['student_id']?'o.student_id':'o.id';$ownerId=(int)($g['student_id']?:$g['owner_id']);
    if($source!=='system'&&lop_ready($db)){
        $owners=array_column(hp_rows($db,"SELECT o.id FROM legacy_op_owners o WHERE $ownerColumn=? AND o.unit_id=?",[$ownerId,$g['unit_id']]),'id');
        if($owners){
            $target='(SELECT * FROM legacy_op_payments WHERE unit_id='.(int)$g['unit_id'].' AND owner_id IN ('.implode(',',array_map('intval',$owners)).'))';
            $lines=str_replace('FROM legacy_op_payments p','FROM '.$target.' p',ldu_receipt_lines_sql());
            $legacy=hp_rows($db,"SELECT -(1000000000+p.id) id,p.unit_id,p.source_date,p.row_key,b.source_id,p.source_date TGL_BYR,p.total total_jumlah,dl.amount du_amount FROM $target p JOIN ($lines) dl ON dl.payment_id=p.id AND dl.unit_id=p.unit_id JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id JOIN legacy_full_batches b ON b.id=p.batch_id AND b.unit_id=p.unit_id WHERE $ownerColumn=? AND p.unit_id=? AND dl.year<=>? AND p.cancelled=0",[$ownerId,$g['unit_id'],$g['th_ajaran']]);
            $times=payment_time_records($db,$legacy);
            foreach($legacy as &$r){$time=$times[$r['unit_id'].'|'.$r['source_id'].'|'.$r['row_key']];$r['TGL_BYR']=$time['payment_datetime'];$r['time_precision']=$time['time_precision'];unset($r['source_date'],$r['row_key'],$r['source_id']);}unset($r);
            usort($legacy,static fn($a,$b)=>($a['TGL_BYR']<=>$b['TGL_BYR'])?:($b['id']<=>$a['id']));array_push($out,...$legacy);
        }
    }

    return $out;
}

function rc_selected_year_sql(mysqli $db,array $input): string {
    if(!array_key_exists('selected_year',$input))return '';
    $year=$input['selected_year'];
    if(!is_string($year)||($year!==''&&!preg_match('~^[12][0-9]{3}/[12][0-9]{3}$~D',$year)))throw new InvalidArgumentException('Tahun pilihan tidak valid.');
    return ' AND th_ajaran<=>'.($year===''?'NULL':"'".$db->real_escape_string($year)."'");
}

/** Resolve a selected owner/year before reading its complete native + Legacy settlement. */
function rc_selected_group(mysqli $db,array $input,int $unit): array {
    $raw=$input['selected']??'';
    if(!is_scalar($raw)||!preg_match('/^-?[1-9][0-9]{0,15}$/D',(string)$raw))throw new InvalidArgumentException('Pilihan riwayat tidak valid.');
    $id=(int)$raw;$source=mh_choice($input,'source',['all','system','legacy'],($input['view']??'')==='legacy'?'legacy':'all');
    if($id>0){
        $selection=hp_one($db,"SELECT s.unit_id,s.id student_id,0 owner_id,CONCAT('s:',s.id) owner_key FROM tagihan_daftar_ulang t JOIN siswa s ON s.NO_INDUK=t.no_induk AND s.unit_id=t.unit_id WHERE t.id=? AND t.unit_id=?",[$id,$unit]);
    }elseif($id<=-2000000000){
        $selection=hp_one($db,"SELECT s.unit_id,s.id student_id,0 owner_id,CONCAT('s:',s.id) owner_key FROM siswa_tahun_ajaran p JOIN siswa s ON s.NO_INDUK=p.no_induk AND s.unit_id=p.unit_id WHERE p.id=? AND p.unit_id=? AND p.du_covered_by_psb=1",[-$id-2000000000,$unit]);
    }else{
        $selection=hp_one($db,"SELECT o.unit_id,COALESCE(o.student_id,0) student_id,o.id owner_id,IF(o.student_id IS NULL,CONCAT('o:',o.id),CONCAT('s:',o.student_id)) owner_key FROM legacy_op_payments p JOIN legacy_op_owners o ON o.id=p.owner_id AND o.unit_id=p.unit_id WHERE p.id=? AND p.unit_id=?",[-$id-1000000000,$unit]);
    }
    if(!$selection)throw new OutOfBoundsException('Riwayat tidak tersedia pada unit ini.');
    $groups=rc_groups($db,$source,$selection);
    $row=hp_one($db,"SELECT * FROM $groups x WHERE id=? AND unit_id=?".rc_selected_year_sql($db,$input)." ORDER BY COALESCE(th_ajaran,'') DESC LIMIT 1",[$id,$unit]);
    if(!$row)throw new OutOfBoundsException('Riwayat tidak tersedia. Muat ulang daftar.');
    return $row;
}
