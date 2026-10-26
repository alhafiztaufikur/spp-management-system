<?php
/** Pure provenance helpers. A receipt remains one cash receipt, regardless of its slots. */
const LEGACY_FEE_TRACE_VERSION = 1;

function lfet_is_detail(array $item): bool {return preg_match('/^JUMLAH[1-4]$/D',(string)($item['column']??$item['source_column']??''))===1;}
function lfet_source_has_fees(array $record): bool {
    foreach(range(1,4) as $slot){$value=$record['raw']['JUMLAH'.$slot]??null;if($value===null||$value==='')continue;try{if(lf_cents($value)!==0)return true;}catch(DomainException $e){return true;}}
    return false;
}
function lfet_source_id(array $raw): ?string {
    foreach($raw as $key=>$value)if(strtolower((string)$key)==='id'&&is_scalar($value))return (string)$value;
    return null;
}
function lfet_unknown_category(array $context,string $code): string {
    return 'histori:'.substr(hash('sha256',json_encode(['fee',(int)$context['unit_id'],(string)$context['source_key'],$code],JSON_THROW_ON_ERROR)),0,32);
}
function lfet_metadata(array $record,array $context,string $column,array $decision,string $category): array {
    $slot=(int)substr($column,-1);$rawCode=(string)($record['raw']['LAIN_LAIN'.$slot]??'');
    $unit=(int)$context['unit_id'];$source=(string)$context['source_key'];$row=(string)$record['row_key'];
    return ['trace_version'=>LEGACY_FEE_TRACE_VERSION,'detail_key'=>hash('sha256',json_encode([$unit,$source,$row,$column],JSON_THROW_ON_ERROR)),
        'source_unit_id'=>$unit,'source_key'=>$source,'source_table'=>$record['table'],'source_record_id'=>lfet_source_id($record['raw']),
        'source_row_key'=>$row,'source_column'=>$column,'source_slot'=>$slot,'source_code_raw'=>$rawCode,'source_code'=>trim($rawCode),
        'target_category'=>$category,'target_master_id'=>str_starts_with($category,'biaya_lain:')?(int)substr($category,11):null,
        'evidence'=>$decision['evidence']??[]];
}
function lfet_resolve_item(array $item,array $resolved=[]): array {
    $item['category']=$resolved[$item['category']]??$item['category'];
    if(($item['migration']['trace_version']??0)===LEGACY_FEE_TRACE_VERSION){
        $item['migration']['target_category']=$item['category'];
        $item['migration']['target_master_id']=str_starts_with($item['category'],'biaya_lain:')?(int)substr($item['category'],11):null;
    }
    return $item;
}
function lfet_corrected_item(array $item): array {
    if(lfet_is_detail($item)&&isset($item['migration'])){
        $item['migration']['original_resolution']??=['status'=>$item['migration']['status']??'archived','rule'=>$item['migration']['rule']??'source_archive'];
        $item['migration']['status']='manual';$item['migration']['rule']='authorized_correction';$item['migration']['correction_preserved']=true;
    }
    return lfet_resolve_item($item);
}
function lfet_validate_payment(array $payment): void {
    $seen=[];$total=0;
    foreach($payment['items'] as $item){$total+=lf_cents($item['amount']);if(!lfet_is_detail($item))continue;$meta=$item['migration']??[];
        if(($meta['trace_version']??0)!==LEGACY_FEE_TRACE_VERSION)continue;
        $unit=(int)($payment['unit_id']??$meta['source_unit_id']??0);$row=(string)$payment['row_key'];$column=$item['column'];
        $expected=hash('sha256',json_encode([$unit,(string)($meta['source_key']??''),$row,$column],JSON_THROW_ON_ERROR));
        $master=str_starts_with($item['category'],'biaya_lain:')?(int)substr($item['category'],11):null;
        if(($meta['source_unit_id']??null)!==$unit||($meta['source_row_key']??null)!==$row||($meta['source_column']??null)!==$column||($meta['source_slot']??null)!==(int)substr($column,-1)
            ||($meta['source_code']??null)!==trim((string)($meta['source_code_raw']??''))||($meta['target_category']??null)!==$item['category']||($meta['target_master_id']??null)!==$master
            ||($meta['detail_key']??null)!==$expected||isset($seen[$expected]))throw new DomainException('Jejak rincian biaya lain tidak cocok dengan transaksi sumber/tujuan.');
        $seen[$expected]=true;
    }
    if(isset($payment['total'])&&$total!==lf_cents($payment['total']))throw new DomainException('Total rincian pembayaran tidak cocok.');
}
/** Also reconstructs a read-only reference for old receipts without v3 metadata. */
function lfet_receipt_items(array $payment,array $raw=[]): array {
    $out=[];$items=is_array($payment['items']??null)?$payment['items']:json_decode($payment['items']??'[]',true,512,JSON_THROW_ON_ERROR);
    foreach($items as $item){
        if(lfet_is_detail($item)){
            $column=$item['column']??$item['source_column'];$meta=$item['migration']??[];$slot=(int)substr($column,-1);
            $source=(string)($meta['source_key']??$payment['source_hash']??$payment['batch_id']??$payment['source_id']??'');
            $unit=(int)($payment['unit_id']??$meta['source_unit_id']??0);$row=(string)($payment['row_key']??$meta['source_row_key']??'');
            $code=$meta['source_code_raw']??$raw['LAIN_LAIN'.$slot]??$meta['source_code']??null;
            $item['source_trace']=['detail_key'=>$meta['detail_key']??hash('sha256',json_encode([$unit,$source,$row,$column],JSON_THROW_ON_ERROR)),
                'unit_id'=>$unit,'source_key'=>$source,'row_key'=>$row,'transaction_id'=>$meta['source_record_id']??lfet_source_id($raw),
                'column'=>$column,'slot'=>$slot,'code_raw'=>$code,'code'=>$code===null?null:trim((string)$code),
                'category'=>$item['category'],'master_id'=>str_starts_with($item['category'],'biaya_lain:')?(int)substr($item['category'],11):null,
                'status'=>$meta['status']??'archived','rule'=>$meta['rule']??'source_archive'];
        }
        $out[]=$item;
    }
    return $out;
}
function lfet_item_reference(array $item): string {
    $trace=$item['source_trace']??null;if(!$trace)return '';
    $parts=[];if($trace['transaction_id']!==null)$parts[]='Legacy #'.$trace['transaction_id'];
    $parts[]='kode '.($trace['code']!==null&&$trace['code']!==''?$trace['code']:'tidak tercatat');$parts[]='slot '.$trace['slot'];
    return implode(' · ',$parts);
}
/** Receipt/slot counts never become a second receipt amount. */
function lfet_summary(array $payments,array $pending=[]): array {
    $out=['rule'=>LEGACY_FEE_TRACE_VERSION,'details'=>0,'amount_cents'=>0,'direct'=>0,'inferred'=>0,'unresolved'=>0,'manual'=>0,'master_linked'=>0,'master_proposed'=>0,'trace_complete'=>0,'held_transactions'=>0];
    foreach($payments as $payment)foreach($payment['items'] as $item)if(lfet_is_detail($item)){
        $out['details']++;$out['amount_cents']+=lf_cents($item['amount']);$meta=$item['migration']??[];$status=$meta['status']??'unresolved';
        $out[in_array($status,['direct','inferred','manual'],true)?$status:'unresolved']++;
        if(str_starts_with($item['category'],'biaya_lain:'))$out['master_linked']++;
        elseif(str_starts_with($item['category'],'biaya_baru:'))$out['master_proposed']++;
        if(!empty($meta['detail_key'])&&isset($meta['source_unit_id'],$meta['source_key'],$meta['source_column']))$out['trace_complete']++;
    }
    foreach($pending as $p)if(in_array($p['code']??'',['payment_review','fee_transaction_held'],true)&&($p['section']??'')==='payment')$out['held_transactions']++;
    return $out;
}
