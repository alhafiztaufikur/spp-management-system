<?php
/** Presentation only; reconciliation and authorization remain in the existing backend. */
function legacy_ledger_view(array $catalog,array $settings,array $query,?array $plan=null): array {
    $students=[];
    foreach($catalog['students']??[] as $student){$identity=(string)$student['identity'];if(!isset($students[$identity]))$students[$identity]=$student;else $students[$identity]['source_count']=2;}
    $counts=['all'=>0,'blocked'=>0,'pending'=>0,'reviewed'=>0,'reference'=>0];$entries=[];
    foreach($catalog['additional_savings']??[] as $key=>$ledger){
        $identity=(string)$ledger['identity'];$student=$students[$identity]??null;
        $name=$student&&(int)($student['source_count']??1)===1?trim((string)($student['name']??'')):'';
        $operational=($settings['mode']??'')==='operational';
        $separateEligible=$operational&&$ledger['identity']!=='Tanpa identitas siswa';
        $eligible=!empty($ledger['duplicate_proven'])||!empty($ledger['retired_zero_proven'])||$separateEligible;
        $reviewed=!lful_additional_savings_issues([$key=>$ledger],[$key=>$settings['additional_savings'][$key]??[]]);
        $decision=$settings['additional_savings'][$key]??[];
        if($operational&&($decision['reason']??'')==='replace_main'){
            $target=$decision['target_identity']??'';$fix=$settings['op_savings'][$target]??[];
            $targetStudent=$students[$target]??null;$targetBank=$targetStudent['savings']??null;
            $targetIssues=$targetBank?array_values(array_filter($targetBank['issues'],fn($i)=>$i!=='Saldo sumber berbeda dengan jumlah jurnal.'&& !($i==='Jurnal sumber tidak lengkap.'&&$targetBank['balance']==='0.00'&&$targetBank['journals']===0))):['Rekening tidak tersedia'];
            $reviewed=!$targetIssues&&!empty($decision['confirmed'])&&!empty($fix['confirmed'])&&($fix['additional_ledger']??'')===$key&&($fix['amount']??null)===($decision['amount']??null)&&hash_equals($ledger['fingerprint'],(string)($decision['fingerprint']??''));
        }
        $promoted=false;if($operational&&$plan)foreach($plan['resolutions']??[] as $resolution)if($resolution['kind']==='promoted_account'&&$resolution['identity']===$identity&&!empty($plan['owners'][$identity]['savings'])){$promoted=true;$reviewed=true;}
        $status=$operational&&$identity==='Tanpa identitas siswa'?'reference':($reviewed?'reviewed':($eligible?'pending':'blocked'));
        $journal=lf_money(lf_cents($ledger['reconciliation']['incoming'])-lf_cents($ledger['reconciliation']['outgoing']));
        $difference=$ledger['reconciliation']['balance']===null?null:lf_money(lf_cents($ledger['reconciliation']['balance'])-lf_cents($journal,true));
        $entries[$key]=compact('key','ledger','identity','status','eligible','journal','difference','promoted')+['name'=>$name?:'Pemilik belum teridentifikasi'];
        ++$counts['all'];++$counts[$status];
    }
    $search=trim((string)($query['ledger_q']??''));$filter=(string)($query['ledger_filter']??'all');if(!isset($counts[$filter]))$filter='all';
    $matches=array_filter($entries,static fn($e)=>($filter==='all'||$e['status']===$filter)&&($search===''||mb_stripos($e['name'].' '.$e['identity'],$search)!==false));
    $pages=max(1,(int)ceil(count($matches)/6));$page=max(1,min($pages,(int)($query['ledger_page']??1)));
    $rows=array_slice($matches,($page-1)*6,6,true);$selected=$rows[$query['ledger']??'']??($rows?reset($rows):null);
    return compact('counts','search','filter','pages','page','rows','selected')+['total'=>count($matches)];
}
