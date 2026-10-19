<?php
/** Batch decisions operate on a source-bound draft, never on individual posted totals. */
function lop_bulk_patch(array $records,array $catalog,array $settings,int $unit,string $scope,array $masters=[]): array {
    if(!in_array($scope,['all','students','components','savings','payments'],true))throw new DomainException('Pilihan persetujuan tidak valid.');
    $reason='Disahkan sekaligus oleh Super Admin berdasarkan backup Legacy; sumber asli dipertahankan.';
    $patch=[];$replacements=[];$counts=['groups'=>0,'students'=>0,'components'=>0,'new_masters'=>0,'matching_savings'=>0];
    if(in_array($scope,['all','students'],true)){
        $grouped=[];
        foreach($records as $r)if($r['table']==='siswa'){
            $identity=lful_identity(legacy_source_identity($r['raw']));if($identity==='')continue;
            $g=lop_group((string)($r['raw']['KELAS']??''),$unit);$grouped[$g['code']]??=$g+['identities'=>[]];$grouped[$g['code']]['identities'][]=$identity;
            if(lop_testing_identity($r['raw']))$patch['students'][$identity]=['testing_confirmed'=>true];
            ++$counts['students'];
        }
        foreach($grouped as $code=>$g){
            $old=$settings['op_groups'][$code]??[];$kind=$old['kind']??$g['kind'];
            // Unclassified source groups are retained as nonacademic account groups.
            if($kind==='legacy')$kind='account';
            $policy=array_replace($old,['kind'=>$kind,'confirmed'=>true,'source_accepted'=>true,'savings_attested'=>true,
                'evidence'=>$old['evidence']??$reason,'fingerprint'=>hash('sha256',lf_json([$code,$g['identities'],$catalog['manifest']['fingerprint']]))]);
            if(mb_strlen(trim((string)$policy['evidence']))<10)$policy['evidence']=$reason;
            if($kind==='alumni')$policy['graduation_year']=$old['graduation_year']??$g['graduation_year'];
            $patch['op_groups'][$code]=$policy;++$counts['groups'];
        }
        $patch['op_import_unmatched']=true;
    }
    if(in_array($scope,['all','components'],true)){
        $proposals=$settings['new_masters']??[];
        foreach($catalog['components'] as $c){
            $key=$c['key'];$old=$settings['components'][$key]??[];
            $category=$old['category']??'';
            // Keep meaningful explicit mappings; upgrade automatic history-only suggestions.
            if(!empty($c['savings']))$category='savings_reference';
            elseif($c['column']==='U_SORGA')$category=lful_history_category($key);
            elseif(!empty($c['suggestion']))$category=$category&&!str_starts_with($category,'histori:')?$category:$c['suggestion'];
            elseif(!$category||str_starts_with($category,'histori:')){
                $sourceNames=$c['source_names']??[];sort($sourceNames,SORT_STRING);
                $name=!empty($c['ambiguous'])?implode(' / ',$sourceNames):$c['name'];
                if(mb_strlen($name)>100)$name=mb_substr($name,0,88).' '.substr(hash('sha256',$name),0,10);
                $existing=null;foreach($masters as $m)if(mb_strtolower(trim($m['nama']))===mb_strtolower(trim($name))){$existing=$m;break;}
                if($existing)$category='biaya_lain:'.$existing['id'];
                else{$proposalKey=lful_proposal_key('legacy-fee:'.$name);$category=lful_new_category($proposalKey);
                    $proposals[$proposalKey]=array_replace(['name'=>$name,'nominal'=>'0.00','tariff_pending'=>true,'reason'=>$reason],$proposals[$proposalKey]??[],['confirmed'=>true]);}
            }
            $map=array_replace($old,['category'=>$category,'confirmed'=>true,'reason'=>$old['reason']??$reason]);
            if(mb_strlen(trim((string)$map['reason']))<10)$map['reason']=$reason;
            if(!empty($c['ambiguous'])&&!in_array($old['source_name']??'',$c['source_names'],true))$map['accepted_source_names']=$c['source_names'];
            $patch['components'][$key]=$map;++$counts['components'];
        }
        foreach($proposals as &$p){$p['confirmed']=true;if(mb_strlen(trim((string)($p['reason']??'')))<10)$p['reason']=$reason;if(trim((string)($p['nominal']??''))===''){$p['tariff_pending']=true;$p['nominal']='0.00';}}unset($p);
        $patch['new_masters']=$proposals;$counts['new_masters']=count($proposals);
    }
    if(in_array($scope,['all','payments'],true)){
        $patch['other_policy']='source_details';
        $patch['accounting_reason']=$settings['accounting_reason']??$reason;
    }
    if(in_array($scope,['all','savings'],true)){
        $patch['op_accept_matching_savings']=true;
        foreach($catalog['additional_savings']??[] as $key=>$ledger){
            if(!empty($settings['additional_savings'][$key]['confirmed']))continue;
            if(!empty($ledger['duplicate_proven']))$type='duplicate';
            elseif(!empty($ledger['retired_zero_proven']))$type='retired_zero';
            elseif(!empty($ledger['reconciliation']['ready'])&&$ledger['identity']!=='Tanpa identitas siswa'){
                $matches=array_values(array_filter($catalog['students']??[],fn($s)=>(string)$s['identity']===$ledger['identity']));$student=count($matches)===1?$matches[0]:null;
                if(!$student||($student['source_count']??0)!==1||($student['savings']['accounts']??0)!==1||!empty($settings['op_savings'][$ledger['identity']]['confirmed']))continue;
                $type='replace_main';
            }
            else continue;
            $patch['additional_savings'][$key]=['reason'=>$type,'fingerprint'=>$ledger['fingerprint'],'evidence'=>$reason,'confirmed'=>true];++$counts['matching_savings'];
            if($type==='replace_main'){
                $target=$ledger['identity'];$amount=$ledger['reconciliation']['balance'];
                $patch['additional_savings'][$key]+= ['target_identity'=>$target,'amount'=>$amount,'main_fingerprint'=>lop_main_bank_fingerprint($target,$student['savings'])];
                $patch['op_savings'][$target]=['amount'=>$amount,'evidence'=>$reason,'confirmed'=>true,'additional_ledger'=>$key];
                $replacements[]=['name'=>$student['name'],'identity'=>$target,'before'=>$student['savings']['balance'],'after'=>$amount];
            }
        }
    }
    $patch['op_bulk_audit']=['scope'=>$scope,'source'=>$catalog['manifest']['fingerprint'],'reason'=>$reason];
    return ['patch'=>$patch,'counts'=>$counts,'replacements'=>$replacements];
}
function lop_pending_groups(array $plan): array {
    $groups=[];
    foreach($plan['pending'] as $p){
        $root=$p['code']==='owner_review'?($plan['owners'][$p['identity']]['group']??$p['identity']):'';
        if($p['code']==='owner_review'){$p['message']='Setujui kelompok pemilik sekali untuk menyiapkan siswa dan seluruh transaksinya.';$p['section']='owner';}
        $key=hash('sha256',lf_json([$p['code'],$p['message'],$root]));
        if(!isset($groups[$key]))$groups[$key]=['code'=>$p['code'],'section'=>$p['section'],'message'=>$p['message'],'group'=>$root,'rows'=>0,'identities'=>[],'sample_identity'=>$p['identity'],'sample_row'=>$p['row']];
        ++$groups[$key]['rows'];$groups[$key]['identities'][$p['identity']]=true;
    }
    foreach($groups as &$g){$g['owners']=count($g['identities']);$g['action']=lop_problem_action($g);unset($g['identities']);}unset($g);
    return array_values($groups);
}
