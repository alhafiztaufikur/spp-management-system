<?php
/** Component proposals stay in the private draft until the cutover transaction. */
function lful_new_category(string $key): string { return 'biaya_baru:'.$key; }
function lful_proposal_key(string $component): string { return 'm_'.substr(hash('sha256',$component),0,16); }
function lful_savings_column(string $column): bool {
    return in_array(strtoupper($column),['U_TABUNGAN','TABUNGAN_WAJIB'],true);
}
function lful_source_fee_masters(array $records): array {
    $out=[];foreach($records as $r)if($r['table']==='lain'){$raw=array_change_key_case($r['raw'],CASE_UPPER);$code=trim((string)($raw['KODE']??''));$name=trim((string)($raw['NAMA']??''));if($code!==''&&$name!=='')$out[$code][$name]=true;}
    foreach($out as &$names)$names=array_keys($names);unset($names);ksort($out,SORT_STRING);return $out;
}
function lful_component_catalog(array $records): array {
    $sourceMasters=lful_source_fee_masters($records);$out=[];$names=['U_SPP'=>'SPP','U_PANGKAL'=>'Uang Pangkal','U_BANGUNAN'=>'Uang Bangunan','U_SERAGAM'=>'Uang Seragam','U_KEGIATAN'=>'Uang Kegiatan','U_MAKAN'=>'Uang Makan','U_INFAQ'=>'Infaq','U_LAIN'=>'Lain-Lain'];
    foreach($records as $r){
        if(!in_array($r['table'],['bayar','bayar_du'],true))continue;
        foreach(legacy_source_money_columns($r) as $column){
            if(strtolower($column)==='potong_spp')continue;$value=legacy_source_money_value($r,$column);
            if($value===null||preg_match('/^0(?:\.0*)?$/D',(string)$value))continue;
            $key=lful_component_key($r['table'],$column,$r['raw']);
            $label=preg_match('/^JUMLAH([1-4])$/D',$column,$m)?(trim((string)($r['raw']['LAIN_LAIN'.$m[1]]??''))?:$column):$column;
            $out[$key]??=['key'=>$key,'table'=>$r['table'],'column'=>$column,'label'=>$label,'name'=>$r['table']==='bayar_du'&&$column==='jumlah'?'Daftar Ulang':($names[$column]??$label),'count'=>0,'invalid_count'=>0,'total_cents'=>0,'min_cents'=>null,'max_cents'=>null,'evidence'=>[],
                'suggestion'=>$column==='U_SPP'?'spp':($r['table']==='bayar_du'&&$column==='jumlah'?'daftar_ulang':''),'savings'=>lful_savings_column($column),'proposal_key'=>lful_proposal_key($key)];
            if(legacy_source_is_pomg($column,$r['raw'])){$out[$key]['is_pomg']=true;$out[$key]['name']='Komite (POMG)';$out[$key]['suggestion']='komite';}
            if(preg_match('/^JUMLAH[1-4]$/D',$column)){
                $code=trim($label===$column?'':$label);$matched=$sourceMasters[$code]??[];
                $out[$key]['source_code']=$code;$out[$key]['source_names']=$matched;$out[$key]['ambiguous']=count($matched)>1;
                $name=count($matched)===1?$matched[0]:($code===''||$code==='022'?'Lain Test':$code);
                $out[$key]['name']=$name;$out[$key]['proposal_key']=lful_proposal_key('legacy-fee:'.$name);
                $out[$key]['group_key']='fee:'.($code!==''?$code:'unidentified');
            }
            $c=&$out[$key];$c['count']++;
            try{$amount=lf_cents($value);if($amount>PHP_INT_MAX-$c['total_cents'])throw new DomainException('Total sumber melampaui batas.');$c['total_cents']+=$amount;$c['min_cents']=$c['min_cents']===null?$amount:min($c['min_cents'],$amount);$c['max_cents']=$c['max_cents']===null?$amount:max($c['max_cents'],$amount);}catch(DomainException $e){$c['invalid_count']++;}
            if(count($c['evidence'])<3)$c['evidence'][]=['row_key'=>$r['row_key']??null,'student'=>$r['raw']['NO_INDUK']??$r['raw']['no_induk']??null,'date'=>$r['raw']['TGL_BYR']??null,'amount'=>$value];unset($c);
        }
    }
    foreach($out as &$c){$c['total']=$c['invalid_count']?null:lf_money($c['total_cents']);$c['minimum']=$c['min_cents']===null?null:lf_money($c['min_cents']);$c['maximum']=$c['max_cents']===null?null:lf_money($c['max_cents']);unset($c['total_cents'],$c['min_cents'],$c['max_cents']);}unset($c);ksort($out);return $out;
}
/** Validate only proposals actually referenced by components or reviewed obligations. */
function lful_proposals(mysqli $db,array $config): array {
    $used=[];foreach($config['components']??[] as $map){$category=$map['category']??'';if(is_string($category)&&str_starts_with($category,'biaya_baru:'))$used[substr($category,11)]=true;}
    foreach($config['students']??[] as $student)foreach($student['coverage']??[] as $c){$category=$c['category']??'';if(is_string($category)&&str_starts_with($category,'biaya_baru:'))$used[substr($category,11)]=true;}
    $out=[];$weights=[];
    foreach(array_keys($used) as $key){
        if(!preg_match('/^m_[a-f0-9]{16}$/D',$key))throw new DomainException('Kunci usulan master tidak valid.');
        $p=$config['new_masters'][$key]??null;if(!is_array($p))throw new DomainException('Usulan master belum dilengkapi: '.$key);
        foreach(['name','reason'] as $field)if(isset($p[$field])&&!is_string($p[$field]))throw new DomainException('Isi usulan master tidak valid.');
        $name=trim((string)($p['name']??''));$reason=trim((string)($p['reason']??''));
        if($name===''||mb_strlen($name)>100||preg_match('/[\x00-\x1f\x7f]/u',$name))throw new DomainException('Nama usulan master wajib diisi, maksimal 100 karakter.');
        $pending=($p['tariff_pending']??false)===true;$amount=$pending?0:lf_cents($p['nominal']??null);if($pending&&!lop_review_schema_ready($db))throw new DomainException('Pasang pembaruan skema Legacy melalui CLI untuk master tanpa tarif.');if(!$pending&&$amount<=0)throw new DomainException('Isi tarif master yang sah dan lebih dari nol untuk '.$name.'. Total histori bukan tarif.');
        if(mb_strlen($reason)<10||mb_strlen($reason)>1000||($p['confirmed']??false)!==true)throw new DomainException('Periksa dan sahkan nama, tarif, serta bukti usulan master '.$name.'.');
        if(lf_one($db,'SELECT id FROM master_biaya_lain WHERE nama=? LIMIT 1',[$name]))throw new DomainException('Nama master sudah tersedia: '.$name.'. Pilih master existing atau ubah nama usulan.');
        $weight=lf_one($db,'SELECT HEX(WEIGHT_STRING(CAST(? AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci)) w',[$name])['w'];
        if(isset($weights[$weight]))throw new DomainException('Usulan master memiliki nama yang sama. Pilih satu usulan untuk komponen-komponen tersebut.');$weights[$weight]=true;
        $out[$key]=['name'=>$name,'nominal'=>lf_money($amount),'reason'=>$reason,'confirmed'=>true,'is_active'=>0,'tariff_pending'=>$pending];
    }
    ksort($out);return $out;
}
function lful_category_label(string $category,array $masters,array $proposals): ?string {
    $label=['spp'=>'SPP','komite'=>'Komite','daftar_ulang'=>'Daftar Ulang','psb'=>'Uang PSB'][$category]??null;
    if(preg_match('/^biaya_lain:([1-9][0-9]*)$/D',$category,$m))$label=$masters[(int)$m[1]]??null;
    if(preg_match('/^biaya_baru:(m_[a-f0-9]{16})$/D',$category,$m))$label=$proposals[$m[1]]['name']??null;
    return $label;
}
/** Resolve once inside the same InnoDB transaction; never run during save/simulate. */
function lful_create_proposals(mysqli $db,int $unit,array $proposals): array {
    $resolved=[];foreach($proposals as $key=>$p){
        if(!empty($p['tariff_pending']))lf_query($db,'INSERT INTO master_biaya_lain(unit_id,nama,nominal,is_active,legacy_tariff_pending) VALUES(?,?,0,0,1)',[$unit,$p['name']]);
        else lf_query($db,'INSERT INTO master_biaya_lain(unit_id,nama,nominal,is_active) VALUES(?,?,?,0)',[$unit,$p['name'],$p['nominal']]);
        $resolved[lful_new_category($key)]='biaya_lain:'.$db->insert_id;
    }return $resolved;
}
function lful_resolve_projection(array $sim,array $resolved): array {
    foreach($sim['payments'] as &$p)foreach($p['items'] as &$i)$i['category']=$resolved[$i['category']]??$i['category'];unset($p,$i);
    foreach($sim['students'] as &$s)foreach($s['coverage'] as &$c)$c['category']=$resolved[$c['category']]??$c['category'];unset($s,$c);return $sim;
}
