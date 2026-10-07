<?php
/** Shared OR filters. Array input is strict; established scalar URLs remain compatible. */
function filter_choice_error(string $field): void {
    $message='Pilihan filter '.$field.' tidak valid. Pilih minimal satu opsi yang tersedia pada unit ini.';
    if(PHP_SAPI==='cli')throw new InvalidArgumentException($message);
    http_response_code(400);header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><main style="font:16px Arial;max-width:650px;margin:8vh auto;padding:24px"><h1>Periksa filter</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p><button onclick="history.back()">Kembali</button></main>';exit;
}
function filter_choices($input,array $allowed,string $default='',array $allAliases=['','all','semua','0']): array {
    $allowed=array_map('strval',array_keys($allowed));
    if(!is_array($input)){
        $value=(string)($input??$default);
        if($value==='*'||in_array($value,$allAliases,true))return ['*'];
        return [$value];
    }
    if(!$input||count($input)>10000)filter_choice_error('');
    $values=[];foreach($input as $value){if(!is_scalar($value))filter_choice_error('');$value=(string)$value;
        if($value!=='*'&&!in_array($value,$allowed,true))filter_choice_error('');$values[]=$value;}
    $values=array_values(array_unique($values));
    if(in_array('*',$values,true)){if(count($values)!==1)filter_choice_error('');return ['*'];}
    return array_values(array_intersect($allowed,$values));
}
function filter_is_all(array $values):bool{return $values===['*'];}
function filter_scalar(array $values,string $all=''):string{return count($values)===1&&!filter_is_all($values)?$values[0]:$all;}
function filter_sql_values(array $values,string $expression):string{
    if(filter_is_all($values))return '';
    return ' AND CAST('.$expression.' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci IN ('.implode(',',array_map(static fn($v)=>"CONVERT(UNHEX('".bin2hex($v)."') USING utf8mb4) COLLATE utf8mb4_general_ci",$values)).')';
}
function filter_query(array $query):array{
    foreach($GLOBALS['spp_filter_choices']??[] as $name=>$config){if(!empty($config['multi'])&&array_key_exists($name,$query)&&!is_array($query[$name]))$query[$name]=$config['values'];}
    return $query;
}
function filter_build_query(array $query):string{return http_build_query(filter_query($query));}
function filter_register(string $name,$input,array $options,string $default='',string $all='',array $aliases=['','all','semua','0']):array{
    foreach($aliases as $alias)unset($options[$alias]);
    $values=filter_choices($input,$options,$default,$aliases);
    $GLOBALS['spp_filter_choices'][$name]=['values'=>$values,'options'=>$options,'all'=>$all,'multi'=>is_array($input)];
    return $values;
}
/** Only explicitly marked search controls are converted; transaction controls stay single. */
function filter_choices_markup(string $html):string{
    return preg_replace_callback('/<select\b([^>]*data-filter-multiple[^>]*)>(.*?)<\/select>/is',static function($match){
        if(!preg_match('/\bname="([^"\[\]]+)(?:\[\])?"/',$match[1],$name))return $match[0];
        $config=$GLOBALS['spp_filter_choices'][$name[1]]??null;if(!$config)return $match[0];
        $attrs=preg_replace('/\bname="[^"]+"/','name="'.$name[1].'[]"',$match[1]);
        $attrs=preg_replace('/\s+multiple\b/','',$attrs).' multiple';
        $body=preg_replace_callback('/<option\b([^>]*)>(.*?)<\/option>/is',static function($option)use($config){
            $attrs=preg_replace('/\s+selected(?:="[^"]*")?/i','',$option[1]);
            $value=preg_match('/\bvalue="([^"]*)"/',$attrs,$v)?html_entity_decode($v[1],ENT_QUOTES,'UTF-8'):html_entity_decode(strip_tags($option[2]),ENT_QUOTES,'UTF-8');
            // Existing "Semua" options become the wildcard for native no-JS submission.
            if(!array_key_exists($value,$config['options'])){
                if(in_array($value,['','all','semua','0'],true))return '<option value="*"'.(filter_is_all($config['values'])?' selected':'').'>'.$option[2].'</option>';
                return $option[0];
            }
            $selected=!filter_is_all($config['values'])&&in_array((string)$value,$config['values'],true);
            return '<option'.$attrs.($selected?' selected':'').'>'.$option[2].'</option>';
        },$match[2]);
        if(!str_contains($body,'value="*"'))$body='<option value="*"'.(filter_is_all($config['values'])?' selected':'').'>Semua pilihan</option>'.$body;
        return '<select'.$attrs.'>'.$body.'</select>';
    },$html)??$html;
}
function filter_output_start():void{ob_start('filter_choices_markup');}
