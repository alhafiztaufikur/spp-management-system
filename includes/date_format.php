<?php
/** Presentation only; storage/request dates remain ISO. */
function spp_date_value($value): ?DateTimeImmutable {
    $zone=new DateTimeZone('Asia/Jakarta');
    if($value instanceof DateTimeInterface)return DateTimeImmutable::createFromInterface($value)->setTimezone($zone);
    if(is_int($value))return (new DateTimeImmutable('@'.$value))->setTimezone($zone);
    $text=trim((string)$value);if($text==='')return null;
    foreach(['!Y-m-d','!Y-m-d H:i:s','!Y-m-d H:i:s.u','!Y-m-d\TH:i:sP','!Y-m-d\TH:i:s.uP','!Y-m-d\TH:i:s\Z'] as $format){
        $tz=str_ends_with($format,'\Z')?new DateTimeZone('UTC'):$zone;
        $date=DateTimeImmutable::createFromFormat($format,$text,$tz);$errors=DateTimeImmutable::getLastErrors();
        if($date&&(!$errors||(!$errors['warning_count']&&!$errors['error_count'])))return $date->setTimezone($zone);
    }return null;
}
function spp_date_label($value,bool $withTime=false):string {
    $date=spp_date_value($value);if(!$date)return '—';
    $hasTime=$value instanceof DateTimeInterface||is_int($value)||preg_match('/[ T]\d{2}:\d{2}/',(string)$value);
    return $date->format('d/m/Y').($withTime&&$hasTime?' '.$date->format('H:i:s').' WIB':'');
}

function spp_date_parts($value):array {
    $date=spp_date_value($value);
    $hasTime=$value instanceof DateTimeInterface||is_int($value)||preg_match('/[ T]\d{2}:\d{2}/',(string)$value);
    return ['date'=>$date?$date->format('d/m/Y'):'—','time'=>$date&&$hasTime?$date->format('H:i:s').' WIB':'—'];
}


