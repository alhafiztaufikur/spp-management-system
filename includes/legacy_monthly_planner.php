<?php
/** Deterministic monthly plan. Amounts are integer cents; no writes or cash dates. */
const LMP_VERSION = 2;

function lmp_months(string $first,string $last): array {
    $out=[];
    for($d=new DateTimeImmutable($first.'-01');$d->format('Y-m')<=$last;$d=$d->modify('+1 month'))$out[]=$d->format('Y-m');
    return $out;
}

/** Payments prove a period and its class. A stable rate only spans its proven interval.
 * The active source header can extend the matching current-year rate to June.
 * Missing evidence stays unknown; an account without student membership has no plan.
 */
function lmp_plan(array $student,array $payments): array {
    $out=['positions'=>[],'exceptions'=>[]];
    if(empty($student['student_id'])||in_array($student['kind']??'', ['account','legacy'],true))return $out;
    $years=[];
    foreach($payments as $p){
        $period=lb_payment_month((string)$p['source_period']);
        if($period===''||$period<'2020-01')continue;
        $year=lb_payment_year($period);$class=trim((string)$p['class_snapshot']);
        foreach($p['items'] as $item){
            $category=$item['category']??'';if(!in_array($category,['spp','komite'],true))continue;
            $amount=lb_amount($item['amount']);if($amount===null||$amount<=0)continue;
            $discount=$category==='spp'?(lb_amount($p['raw']['potong_spp']??0)??0):0;
            $sourceAmount=lb_amount($p['raw'][$category==='spp'?'U_SPP':'U_SORGA']??null);
            // Correcting a receipt changes credit, not the original contractual tariff.
            $rateAmount=$sourceAmount!==null&&$sourceAmount>0?$sourceAmount:$amount;
            $cell=&$years[$year][$category][$period];
            $cell??=['paid'=>0,'rates'=>[],'classes'=>[],'rows'=>[]];
            if(empty($p['cancelled']))$cell['paid']+=$amount;
            $cell['rates'][($rateAmount+$discount).'|'.$discount]=[$rateAmount+$discount,$discount];
            $cell['classes'][$class]=true;$cell['rows'][]=$p['row_key'];unset($cell);
        }
    }
    foreach($years as $year=>$components)foreach($components as $category=>$cells){
        ksort($cells);$rates=[];$classes=[];
        foreach($cells as $c){$rates+=$c['rates'];$classes+=$c['classes'];}
        $first=array_key_first($cells);$last=array_key_last($cells);
        $header=lb_amount($student['raw'][$category==='spp'?'SPP_PERBULAN':'POMG']??null);
        $current=$year===($student['migration_year']??'')&&($student['kind']??'')==='active';
        // Never extend an old rate through a later, different source tariff.
        $lastRates=$cells[$last]['rates'];
        if($current&&count($lastRates)===1&&$header!==null&&reset($lastRates)[0]-reset($lastRates)[1]===$header)$last=substr($year,5,4).'-06';
        foreach(lmp_months($first,$last) as $period){
            $cell=$cells[$period]??null;$rate=null;$class=null;$evidence=[];
            if($cell&&count($cell['rates'])===1&&count($cell['classes'])===1){$rate=reset($cell['rates']);$class=(string)array_key_first($cell['classes']);$evidence=$cell['rows'];}
            elseif(!$cell&&count($rates)===1&&count($classes)===1){$rate=reset($rates);$class=(string)array_key_first($classes);foreach($cells as $c)$evidence=array_merge($evidence,$c['rows']);}
            elseif(!$cell){
                // A change inside a year is bounded by equal neighbouring evidence.
                $before=null;$after=null;
                foreach($cells as $key=>$c){if($key<$period)$before=$c;elseif($key>$period){$after=$c;break;}}
                if($before&&count($before['rates'])===1&&count($before['classes'])===1&&(($after&&$before['rates']===$after['rates']&&$before['classes']===$after['classes'])||(!$after&&$current&&reset($before['rates'])[0]-reset($before['rates'])[1]===$header))){$rate=reset($before['rates']);$class=(string)array_key_first($before['classes']);$evidence=$before['rows'];if($after)$evidence=array_merge($evidence,$after['rows']);}
            }
            if(!$rate||!preg_match('/^(?:[1-9]|1[0-2])[A-Za-z]?$/D',(string)$class)){$out['exceptions'][]=['period'=>$period,'category'=>$category,'reason'=>'Tarif, potongan, atau kelas periode belum tunggal.'];continue;}
            [$gross,$discount]=$rate;$principal=max(0,$gross-$discount);$paid=$cell['paid']??0;
            $out['positions'][$category.'|'.$period]=['unit_id'=>(int)$student['unit_id'],'student_id'=>(int)$student['student_id'],'owner_id'=>(int)$student['id'],'nis'=>$student['NO_INDUK'],'period'=>$period,'year'=>$year,'category'=>$category,'class'=>$class,'gross'=>$gross,'discount'=>$discount,'principal'=>$principal,'paid'=>$paid,'remaining'=>max(0,$principal-$paid),'evidence'=>['planner'=>LMP_VERSION,'rows'=>array_values(array_unique($evidence)),'basis'=>$cell?'payment_period':'stable_rate_interval']];
        }
    }
    if(($student['kind']??'')==='active')foreach(['spp','komite'] as $category)if(empty($years[$student['migration_year']][$category]))$out['exceptions'][]=['period'=>$student['migration_year'],'category'=>$category,'reason'=>'Masa kewajiban tahun peralihan belum terbukti dari pembayaran; gunakan peninjauan atau penerbitan eksplisit.'];
    return $out;
}

/** The existing publication form is explicit evidence for its selected placement/year. */
function lmp_publication(?array $source,string $period,int $gross,int $discount,bool $covered=false): array {
    if($covered)return ['period'=>$period,'gross'=>0,'discount'=>0,'principal'=>0,'status'=>'covered_psb','evidence'=>['basis'=>'psb_coverage']];
    if($source)return $source+['status'=>$source['principal']>0?'open':'waived'];
    $discount=min($gross,max(0,$discount));
    return ['period'=>$period,'gross'=>$gross,'discount'=>$discount,'principal'=>max(0,$gross-$discount),'status'=>$gross>$discount?'open':'waived','evidence'=>['basis'=>'explicit_publication']];
}
