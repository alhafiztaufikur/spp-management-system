<?php
/** Shared source accessors. Never rename/inject fields into the original raw archive. */
const LEGACY_SOURCE_READER_VERSION = 1;
/** Calendar date from the record itself; never infer a date from NIS or backup time. */
function legacy_source_record_date(array $record): ?string {
    $raw=$record['raw']??[];$keys=($record['table']??'')==='tbg'?['tgl']:['TGL_BYR','TANGGAL'];
    foreach($keys as $key){
        $value=$raw[$key]??null;if($value===null||$value==='')continue;
        if(!is_string($value)||!preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2}:\d{2}:\d{2})(?:\.[0-9]+)?)?$/D',$value,$m))return null;
        $date=$m[1].' '.($m[2]??'00:00:00');$parsed=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$date);
        return $parsed&&$parsed->format('Y-m-d H:i:s')===$date?$date:null;
    }return null;
}
/** Confirmed by the foundation: Legacy POMG is the monthly Komite fee. */
function legacy_source_komite(array $raw): ?string {
    $value=$raw['POMG']??null;
    if($value===null||$value==='')return null;
    // SQL Server exports exact decimal strings; do not round or coerce invalid text to zero.
    if(!is_string($value)&&!is_int($value))throw new DomainException('Nominal POMG sumber tidak valid.');
    if(!preg_match('/^([0-9]{1,12})(?:\.([0-9]+))?$/D',(string)$value,$m)||strlen(rtrim($m[2]??'','0'))>2)throw new DomainException('Nominal POMG sumber tidak valid.');
    return $m[1].'.'.str_pad(substr($m[2]??'',0,2),2,'0');
}
function legacy_source_is_pomg(string $column,array $raw=[]): bool {
    if(in_array(strtoupper($column),['POMG','U_POMG'],true))return true;
    return preg_match('/^JUMLAH([1-4])$/D',$column,$m)===1&&mb_strtoupper(trim((string)($raw['LAIN_LAIN'.$m[1]]??'')))==='POMG';
}
function legacy_source_identity(array $raw): string {
    return (string)($raw['no_induk']??$raw['NO_INDUK']??'');
}
function legacy_source_identity_issues(array $raw): array {
    $primary=mb_strtoupper(trim(legacy_source_identity($raw)));
    $secondary=mb_strtoupper(trim((string)($raw['NO_INDUK1']??'')));
    return $secondary!==''&&$primary!==$secondary?['Nomor induk utama dan nomor induk pembanding berbeda. Periksa identitas pada sumber.']:[];
}
function legacy_source_schema_signature(int $unit,string $version='2019'): string {
    if(!in_array($unit,[1,2,3],true)||!in_array($version,['2019','2026'],true))throw new DomainException('Profil sumber tidak dikenali.');
    $file=__DIR__.'/../scripts/legacy_full_profile'.($version==='2019'?'':'_2026').'.json';
    return hash('sha256',hash_file('sha256',$file).'|'.[1=>'SD',2=>'SMP',3=>'SMA'][$unit]);
}
function legacy_source_manifest_check(array $manifest,int $unit): void {
    if(!isset($manifest['source_profile']))return; // Already checked jobs created by the old worker.
    $version=(string)($manifest['source_profile_version']??'');
    if(!in_array($version,['2019','2026'],true)||($manifest['source_profile']??'')!=='legacy-'.$version.'-'.[1=>'sd',2=>'smp',3=>'sma'][$unit]
        ||!hash_equals(legacy_source_schema_signature($unit,$version),(string)($manifest['source_schema_signature']??''))
        ||($manifest['reader_version']??null)!==LEGACY_SOURCE_READER_VERSION)throw new DomainException('Pembaca atau profil sumber berubah. Unggah dan periksa ulang backup.');
}
function legacy_source_additional_savings(array $record): bool {
    return in_array($record['table']??$record['source_table']??'', ['tb_total','tb_masuk','tb_keluar','tbg'],true);
}
/** Text tabungan_wajib is a monetary field too; NULL/empty means not recorded, never a made-up zero. */
function legacy_source_money_columns(array $record): array {
    $columns=array_values(array_filter($record['money_columns'],static fn($c)=>!in_array(strtolower($c),['id','nomer'],true)));
    if(($record['table']??'')==='bayar'&&array_key_exists('tabungan_wajib',$record['raw'])&&trim((string)$record['raw']['tabungan_wajib'])!==''&&!in_array('tabungan_wajib',$columns,true))$columns[]='tabungan_wajib';
    return $columns;
}
function legacy_source_money_value(array $record,string $column): mixed {
    $value=$record['raw'][$column]??null;
    return $column==='tabungan_wajib'&&is_string($value)?trim($value):$value;
}
