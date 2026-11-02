<?php
/** UI context only; account authorization and money handling stay in the existing controller. */
function savings_entry_context(array $input, string $prefix = ''): array {
    $context=[];
    $q=$input[$prefix.'q']??'';
    if(is_string($q)&&trim($q)!=='')$context['q']=mb_substr(trim($q),0,100);
    $type=$input[$prefix.'owner_type']??'all';
    if(is_string($type)&&in_array($type,['all','student','account'],true))$context['owner_type']=$type;
    foreach(['class_id'=>0,'page'=>1] as $key=>$default){
        $value=filter_var($input[$prefix.$key]??$default,FILTER_VALIDATE_INT,['options'=>['min_range'=>$default]]);
        if($value!==false)$context[$key]=$value;
    }
    return $context;
}
function savings_entry_return_url(string $kind,array $identity,array $context): string {
    $query=savings_entry_context($context);
    foreach(['student_id','owner_id'] as $key){
        $id=filter_var($identity[$key]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id!==false&&$id!==null)$query[$key]=$id;
    }
    return ($kind==='keluar'?'keluar.php':'masuk.php').'?'.http_build_query($query);
}
