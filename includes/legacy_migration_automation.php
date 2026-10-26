<?php
/** Pure source rules. Fee trace is automatic on both entry points, without source writes. */
require_once __DIR__.'/legacy_fee_trace.php';
const LMA_VERSION = 4;
function lma_metadata_supported(mixed $version): bool {return in_array($version,[1,2,3,LMA_VERSION],true);}
function lma_enabled(array $cfg): bool { return lma_metadata_supported($cfg['automation_version']??0); }
function lma_fee_manual_mapping(array $map): bool {
    return ($map['automatic_mapping']??null)===false||(!array_key_exists('automatic_mapping',$map)&&!empty($map['category'])&&!str_starts_with($map['category'],'histori:'));
}
function lma_catalog_components(array $components): array {
    foreach($components as &$c)if(isset($c['source_code'])&&count($c['source_names']??[])!==1){$c['name']='Biaya Legacy '.($c['source_code']!==''?$c['source_code']:'tanpa kode').' (belum teridentifikasi)';$c['proposal_key']=lful_proposal_key('legacy-unidentified:'.$c['source_code']);}unset($c);return $components;
}
function lma_identity(mixed $value): string { return mb_strtoupper(trim((string)$value)); }
function lma_year(string $value): string {
    return preg_match('~^(20[0-9]{2})/(20[0-9]{2})$~D',trim($value),$m) && (int)$m[2]===(int)$m[1]+1 ? trim($value) : '';
}
/** Existing records have no metadata and retain their original period semantics. */
function lma_item_period(array $item,string $fallback): string {
    if(($item['category']??'')!=='daftar_ulang')return $fallback;
    $meta=$item['migration']??[];
    if(!lma_metadata_supported($meta['version']??0))return $fallback;
    $period=(string)($meta['period']??'');
    return lma_year($period)!=='' || preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D',$period) ? $period : $fallback;
}
function lma_du_key(array $r): string {
    $v=$r['raw'];$du=$r['table']==='bayar_du';
    $year=lma_year((string)($v['th_ajaran']??''));$grade=trim((string)($v[$du?'kelas':'kelas_du']??''));
    if($year===''||!preg_match('/^(?:[1-9]|1[0-2])$/D',$grade))return '';
    return ($r['source_id']??'job').'|'.lma_identity(legacy_source_identity($v)).'|'.$year.'|'.$grade;
}
function lma_priority(array $raw,array $active): string {
    $id=lma_identity(legacy_source_identity($raw));$date=substr((string)($raw['TGL_BYR']??$raw['TANGGAL']??''),0,10);
    $entry=lop_entry_year($id);
    if(isset($active[$id])||$date>='2020-01-01'||($entry!==null&&substr($entry,0,4)>='2020')||substr(trim((string)($raw['th_ajaran']??'')),0,4)>='2020')return 'priority';
    return 'historical';
}
function lma_fee_context(array $v): array {
    $date=substr((string)($v['TGL_BYR']??''),0,10);$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);$time=$parsed&&$parsed->format('Y-m-d')===$date?$parsed->getTimestamp():false;
    $month=lma_month(trim((string)($v['BULAN']??'')).' '.trim((string)($v['TAHUN']??'')));
    $year=lma_year((string)($v['th_ajaran']??''));
    if($year==='')$year=$month!==null?lb_period_year_for_import($month):($time!==false?lb_period_year_for_import(substr($date,0,7)):'');
    preg_match('/^(1[0-2]|[1-9])\s*[A-Za-z]?$/D',trim((string)($v['KELAS']??'')),$grade);
    return ['date'=>$time===false?null:$time,'year'=>$year,'grade'=>$grade[1]??''];
}
function lma_month(string $period): ?string {
    if(preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D',$period))return $period;
    $months=['JANUARI'=>1,'FEBRUARI'=>2,'MARET'=>3,'APRIL'=>4,'MEI'=>5,'JUNI'=>6,'JULI'=>7,'AGUSTUS'=>8,'SEPTEMBER'=>9,'OKTOBER'=>10,'NOVEMBER'=>11,'DESEMBER'=>12];
    if(!preg_match('/^([A-Za-z]+|[0-9]{1,2})\s+(20[0-9]{2})$/D',$period,$m))return null;
    $month=$months[strtoupper($m[1])]??(ctype_digit($m[1])?(int)$m[1]:0);
    return $month>=1&&$month<=12?sprintf('%s-%02d',$m[2],$month):null;
}
/** Pure counterpart of the existing academic-year convention; never used as a payment date. */
function lb_period_year_for_import(string $month): string {
    if(!preg_match('/^(20[0-9]{2})-(0[1-9]|1[0-2])$/D',$month,$m))return '';
    $first=(int)$m[1]-((int)$m[2]<7?1:0);return $first.'/'.($first+1);
}
/** A counterpart must describe the whole receipt, not merely the same amount/date. */
function lma_receipt_key(array $v,int $slot): string {
    $fields=['NO_INDUK','TGL_BYR','jam','user_id','cara_bayar','KETERANGAN','KELAS','BULAN','TAHUN','th_ajaran','kelas_du','U_SPP','U_PANGKAL','U_KEGIATAN','U_SERAGAM','U_BANGUNAN','U_MAKAN','U_SORGA','U_INFAQ','U_LAIN','potong_spp','tabungan_wajib','JUMLAH1','JUMLAH2','JUMLAH3','JUMLAH4'];
    foreach(range(1,4) as $other)if($other!==$slot)$fields[]='LAIN_LAIN'.$other;
    $out=[];foreach($fields as $f)$out[$f]=trim((string)($v[$f]??''));$out['NO_INDUK']=lma_identity($out['NO_INDUK']);
    return hash('sha256',lf_json([$slot,$out]));
}
/** Keep only useful counterpart rows; do not retain all historical table copies in memory. */
function lma_reference_evidence(array $job,array $records): array {
    $masters=lful_source_fee_masters($records);$wanted=[];
    foreach($records as $r)if($r['table']==='bayar')foreach(range(1,4) as $slot){$v=$r['raw'];$code=trim((string)($v['LAIN_LAIN'.$slot]??''));if((float)($v['JUMLAH'.$slot]??0)!=0&&count($masters[$code]??[])!==1)$wanted[lma_receipt_key($v,$slot)]=true;}
    if(!$wanted)return $records;
    $file=fopen(legacy_root().'/'.$job['id'].'/finance.jsonl','rb');$occurrences=[];
    try{while(($line=fgets($file))!==false){$r=json_decode($line,true,512,JSON_THROW_ON_ERROR);if($r['table']==='bayar'||$r['table']==='bayar_du'||!str_starts_with($r['table'],'bayar'))continue;
        $useful=false;foreach(range(1,4) as $slot){$v=$r['raw'];$code=trim((string)($v['LAIN_LAIN'.$slot]??''));if(count($masters[$code]??[])===1&&isset($wanted[lma_receipt_key($v,$slot)])){$useful=true;break;}}
        if(!$useful)continue;$hash=hash('sha256',lf_canonical($r['raw']));$occ=($occurrences[$r['table']][$hash]??0)+1;$occurrences[$r['table']][$hash]=$occ;
        $r['row_key']=hash('sha256',implode('|',[$job['unit'],strtolower($job['hash']),$r['table'],$hash,$occ]));$records[]=$r;
    }}finally{fclose($file);}return $records;
}
function lma_context(array $records,int $unit,string $sourceKey=''): array {
    $sourceIds=[];$active=[];$du=[];$masters=lful_source_fee_masters($records);$fees=[];$anchors=[];$counterparts=[];$masterRows=[];$counterpartRows=[];
    foreach($records as $r){
        if(isset($r['unit_id'])&&(int)$r['unit_id']!==$unit)throw new DomainException('Sumber otomatisasi berbeda unit.');
        if(isset($r['source_id']))$sourceIds[(string)$r['source_id']]=true;
        if($r['table']==='lain'){$raw=array_change_key_case($r['raw'],CASE_UPPER);$masterRows[trim((string)($raw['KODE']??''))][]=$r['row_key']??hash('sha256',lf_canonical($r['raw']));}
        if($r['table']==='siswa'&&in_array(lop_group((string)($r['raw']['KELAS']??''),$unit)['kind'],['academic','psb'],true))$active[lma_identity(legacy_source_identity($r['raw']))]=true;
    }
    if(count($sourceIds)>1)throw new DomainException('Satu pekerjaan otomatisasi harus memakai satu backup sumber.');
    foreach($records as $r){
        $v=$r['raw'];$key=lma_du_key($r);
        if($r['table']==='bayar_du'&&$key!=='')try{$amount=lf_cents($v['jumlah']??null);if($amount>0)$du[$key]['values'][(string)$amount]=true;}catch(DomainException $ignored){}
        if($r['table']==='bayar'&&$key!=='')try{$amount=lf_cents($v['U_KEGIATAN']??'0');if($amount>0)$du[$key]['paid']=($du[$key]['paid']??0)+$amount;}catch(DomainException $ignored){$du[$key]['invalid']=true;}
        if(!str_starts_with($r['table'],'bayar')||$r['table']==='bayar_du')continue;
        foreach(range(1,4) as $slot){
            try{$amount=lf_cents($v['JUMLAH'.$slot]??'0');}catch(DomainException $ignored){continue;}if($amount<=0)continue;
            $code=trim((string)($v['LAIN_LAIN'.$slot]??''));$names=$masters[$code]??[];$ctx=lma_fee_context($v);
            $detail=$ctx+['row_key'=>$r['row_key'],'column'=>'JUMLAH'.$slot,'code'=>$code,'amount'=>$amount,'identity'=>lma_identity(legacy_source_identity($v)),'priority'=>lma_priority($v,$active),'note'=>trim((string)($v['KETERANGAN']??'')),'counterpart'=>lma_receipt_key($v,$slot),'is_pomg'=>legacy_source_is_pomg('JUMLAH'.$slot,$v)];
            if(count($names)===1&&trim((string)($v['rstatus']??''))===''){
                $counterparts[$detail['counterpart']][$names[0]]=true;
                $counterpartRows[$detail['counterpart']][$names[0]][]=$r['row_key'];
                if($r['table']==='bayar')$anchors[(string)$amount][]=$detail+['name'=>$names[0]];
            }
            if($r['table']==='bayar')$fees[$r['row_key']]['JUMLAH'.$slot]=$detail;
        }
    }
    $matched=[];foreach($du as $key=>$g)if(empty($g['invalid'])&&count($g['values']??[])===1&&($g['paid']??0)===(int)array_key_first($g['values']))$matched[$key]=true;
    $decisions=[];
    foreach($fees as $row=>$columns)foreach($columns as $column=>$d){
        $names=$masters[$d['code']]??[];$name=count($names)===1?$names[0]:null;$state=$name!==null?'direct':'unresolved';$rule=$name!==null?'source_master':'unidentified_fee';$supporting=[];
        if($name===null){
            $candidates=$counterparts[$d['counterpart']]??[];
            if(count($candidates)===1){$name=(string)array_key_first($candidates);$state='direct';$rule='receipt_counterpart';$supporting=$counterpartRows[$d['counterpart']][$name];}
        }
        if($name===null&&$d['note']!==''){
            $found=[];foreach($masters as $sourceNames)foreach($sourceNames as $candidate){
                if(count($names)>1&&!in_array($candidate,$names,true))continue;
                if(preg_match('/(?<![\pL\pN])'.preg_quote($candidate,'/').'(?![\pL\pN])/iu',$d['note'])&&!preg_match('/(?:bukan|kecuali|tidak)\s+'.preg_quote($candidate,'/').'/iu',$d['note']))$found[$candidate]=true;
            }
            if(count($found)===1){$name=(string)array_key_first($found);$state='direct';$rule='explicit_description';$supporting=[$row];}
        }
        if($name===null&&$d['code']!==''&&$d['date']!==null&&$d['year']!==''&&$d['grade']!==''){
            $support=[];foreach($anchors[(string)$d['amount']]??[] as $a){
                if($a['date']===null||$a['year']!==$d['year']||$a['grade']!==$d['grade']||abs($a['date']-$d['date'])>45*86400||levenshtein($d['code'],$a['code'])>1)continue;
                if(count($names)>1&&!in_array($a['name'],$names,true))continue;
                if($a['identity']!=='')$support[$a['name']][$a['identity']]=min($support[$a['name']][$a['identity']]??$a['row_key'],$a['row_key']);
            }
            // Any competing candidate prevents inference, even when its sample is smaller.
            if(count($support)===1&&count(reset($support))>=3){$name=(string)array_key_first($support);$state='inferred';$rule='amount_code_cohort_45_days';$supporting=array_values(reset($support));}
        }
        if($d['is_pomg']){$name='Komite (POMG)';$state='direct';$rule='source_pomg';$supporting=[$row];}
        $sourceRows=array_values(array_unique($masterRows[$d['code']]??[]));sort($sourceRows,SORT_STRING);$supporting=array_values(array_unique($supporting));sort($supporting,SORT_STRING);
        $decisions[$row][$column]=['name'=>$name,'status'=>$state,'rule'=>$rule,'source_code'=>$d['code'],'priority'=>$d['priority'],'category'=>$d['is_pomg']?'komite':null,
            'evidence'=>['source_master_rows'=>$sourceRows,'supporting_rows'=>$supporting,'candidate_names'=>$names]];
    }
    if($sourceKey===''){$keys=array_column($records,'row_key');sort($keys,SORT_STRING);$sourceKey=$sourceIds?'source:'.array_key_first($sourceIds):hash('sha256',lf_canonical($keys));}
    return ['fees'=>$decisions,'du'=>$matched,'active'=>$active,'unit_id'=>$unit,'source_key'=>$sourceKey];
}
/** Compact configuration only; per-row evidence is recomputed, never stored in draft settings. */
function lma_configure(array $records,array $cfg,int $unit,array $masters=[]): array {
    $context=lma_context($records,$unit,(string)($cfg['_fee_source_key']??$cfg['source_fingerprint']??''));
    $reason='Pemetaan otomatis dari bukti sumber Legacy; nominal pembayaran asli dipertahankan.';
    $activities=0;$matchedActivities=0;
    foreach($records as $r)if($r['table']==='bayar')try{if(lf_cents($r['raw']['U_KEGIATAN']??'0')>0){$activities++;if(isset($context['du'][lma_du_key($r)]))$matchedActivities++;}}catch(DomainException $ignored){}
    if(lma_enabled($cfg)&&$activities>0&&$activities===$matchedActivities&&($cfg['components']['bayar.U_KEGIATAN']['automatic_mapping']??true)!==false)$cfg['components']['bayar.U_KEGIATAN']=['category'=>'daftar_ulang','reason'=>$reason,'confirmed'=>true,'automatic_mapping'=>true];
    foreach($context['fees'] as $columns)foreach($columns as $column=>$d){
        $slot=substr($column,-1);$componentKey=lful_component_key('bayar',$column,['LAIN_LAIN'.$slot=>$d['source_code']]);
        if(lma_fee_manual_mapping($cfg['components'][$componentKey]??[])||!empty($d['category']))continue;
        if($d['name']===null)continue;$key='auto-fee:'.hash('sha256',$d['name']);
        $existing=null;foreach($masters as $m)if((!isset($m['unit_id'])||(int)$m['unit_id']===$unit)&&mb_strtolower(trim($m['nama']))===mb_strtolower(trim($d['name']))){$existing=$m;break;}
        $proposal=lful_proposal_key('legacy-fee:'.mb_strtolower(trim($d['name'])));$category=$existing?'biaya_lain:'.$existing['id']:lful_new_category($proposal);
        $cfg['components'][$key]=['category'=>$category,'reason'=>$reason,'confirmed'=>true,'automatic_mapping'=>true];
        if(!$existing)$cfg['new_masters'][$proposal]=['name'=>$d['name'],'nominal'=>'0.00','tariff_pending'=>true,'reason'=>$reason,'confirmed'=>true];
    }
    if(!lma_enabled($cfg))return [$cfg,$context];
    $students=[];$banks=[];$extras=[];
    foreach($records as $r){$identity=lma_identity(legacy_source_identity($r['raw']));if($r['table']==='siswa')$students[$identity]=($students[$identity]??0)+1;if(in_array($r['table'],['tabungan','transaksi_m','transaksi_k'],true))$banks[$identity][]=$r;if(in_array($r['table'],['tb_total','tb_masuk','tb_keluar'],true))$extras[$identity][]=$r;}
    foreach(lful_additional_savings($records) as $key=>$ledger){
        if(!empty($cfg['additional_savings'][$key]['confirmed'])&&empty($cfg['additional_savings'][$key]['system_selected']))continue;
        $id=$ledger['identity'];$student=isset($students[$id])?['source_count'=>$students[$id],'savings'=>isset($banks[$id])?lf_reconcile($banks[$id]):null]:null;
        $decision=lma_bank_decision($ledger,$student,$cfg);
        if(($decision['reason']??'')==='keep_main'&&!empty($ledger['reconciliation']['ready'])&&lma_bank_snapshot($banks[$id]??[],$extras[$id]??[]))$decision['reason']='historical_snapshot';
        if($decision)$cfg['additional_savings'][$key]=$decision+['fingerprint'=>$ledger['fingerprint'],'confirmed'=>true,'evidence'=>$reason];
    }
    return [$cfg,$context];
}
function lma_payment(array $r,array $cfg,array $masters,array $context): ?array {
    if($r['table']==='bayar_du')return lma_enabled($cfg)?null:lful_payment($r,$cfg,$masters);
    if(lop_payment_auto_check($r,$cfg))$cfg['other_policy']='source_details';
    $activityMap=$cfg['components']['bayar.U_KEGIATAN']??[];
    $du=lma_enabled($cfg)&&isset($context['du'][lma_du_key($r)])&&(($activityMap['automatic_mapping']??true)!==false||($activityMap['category']??'')==='daftar_ulang');$evidence=[];
    foreach($context['fees'][$r['row_key']]??[] as $column=>$decision){
        $key=lful_component_key('bayar',$column,$r['raw']);$old=$cfg['components'][$key]??[];
        if(lma_fee_manual_mapping($old)){$evidence[$column]=$decision;$evidence[$column]['status']='manual';$evidence[$column]['rule']='explicit_mapping';continue;}
        $target=$decision['category']??($decision['name']!==null?$cfg['components']['auto-fee:'.hash('sha256',$decision['name'])]['category']:lfet_unknown_category($context,$decision['source_code']));
        $label=$decision['name']??('Biaya Legacy '.($decision['source_code']!==''?$decision['source_code']:'tanpa kode').' (belum teridentifikasi)');
        $cfg['components'][$key]=['category'=>$target,'source_name'=>$label,'confirmed'=>true,'reason'=>'Pemetaan otomatis sesuai bukti sumber Legacy.'];unset($cfg['_component_conflicts'][$key]);
        $evidence[$column]=$decision;
    }
    if($du){$key=lful_component_key('bayar','U_KEGIATAN',$r['raw']);$cfg['components'][$key]=['category'=>'daftar_ulang','confirmed'=>true,'reason'=>'Rekap DU cocok dengan jumlah pembayaran asal pada tahun ajaran dan kelas yang sama.'];}
    $p=lful_payment($r,$cfg,$masters);if($p===null)return null;
    foreach($p['items'] as &$item){
        if(!lma_enabled($cfg)&&!lfet_is_detail($item))continue;
        $sourceCode=preg_match('/^JUMLAH([1-4])$/D',$item['column'],$slot)?trim((string)($r['raw']['LAIN_LAIN'.$slot[1]]??'')):null;
        $d=$evidence[$item['column']]??['status'=>'direct','rule'=>'source_column','source_code'=>$sourceCode];
        $period=$item['category']==='daftar_ulang'&&$du?lma_year((string)($r['raw']['th_ajaran']??'')):$p['period'];
        if($item['category']==='daftar_ulang'&&$du)$d=['status'=>'direct','rule'=>'du_recap_reconciled','source_code'=>null];
        $item['migration']=['version'=>LMA_VERSION,'period'=>$period,'source_row_key'=>$r['row_key'],'source_code'=>$d['source_code'],'status'=>$d['status'],'rule'=>$d['rule'],'priority'=>$d['priority']??lma_priority($r['raw'],$context['active']??[])];
        if($item['category']==='daftar_ulang')$item['migration']+=['du_trace_version'=>1,'source_record_id'=>lfet_source_id($r['raw']),'source_unit_id'=>$context['unit_id'],'source_key'=>$context['source_key'],'du_grade'=>trim((string)($r['raw']['kelas_du']??''))];
        if(lfet_is_detail($item))$item['migration']+=lfet_metadata($r,$context,$item['column'],$d,$item['category']);
    }unset($item);return $p;
}
/** Additional evidence cannot silently replace a valid main account. */
function lma_bank_decision(array $ledger,?array $student,array $settings): array {
    if(!empty($ledger['duplicate_proven']))return ['reason'=>'duplicate'];
    if(!empty($ledger['retired_zero_proven']))return ['reason'=>'retired_zero'];
    if($ledger['identity']==='Tanpa identitas siswa'||!$student||($student['source_count']??0)!==1)return [];
    if(($student['savings']['accounts']??0)===1&&($student['savings']['balance']??null)!==null)return ['reason'=>'keep_main','source_balance'=>$student['savings']['balance'],'additional_balance'=>$ledger['reconciliation']['balance'],'additional_issues'=>$ledger['reconciliation']['issues']??[],'system_selected'=>true];
    return []; // Existing promotion verifies and promotes a sole additional ledger later.
}
/** Prove a complete earlier journal prefix, preserving repeated identical entries as a multiset. */
function lma_bank_snapshot(array $main,array $extra): bool {
    $signature=static function(array $r): ?string {
        if(in_array($r['table'],['tabungan','tb_total'],true))return null;$v=$r['raw'];
        return lf_json([lma_identity(legacy_source_identity($v)),(string)($v['TANGGAL']??''),lf_money(lf_cents($v['MASUK']??'0')),lf_money(lf_cents($v['KELUAR']??'0')),trim((string)($v['user_id']??''))]);
    };
    try{$end='';$expected=[];foreach($extra as $r){$key=$signature($r);if($key===null)continue;$expected[$key]=($expected[$key]??0)+1;$end=max($end,(string)($r['raw']['TANGGAL']??''));}if(!$expected||$end==='')return false;
        $actual=[];foreach($main as $r){if((string)($r['raw']['TANGGAL']??'')>$end)continue;$key=$signature($r);if($key!==null)$actual[$key]=($actual[$key]??0)+1;}ksort($actual);ksort($expected);return $actual===$expected;
    }catch(DomainException $ignored){return false;}
}
