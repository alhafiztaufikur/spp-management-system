<?php
/** Read projections never replace archived categories or publication fingerprints. */
const LCS_KOMITE_MAPPING_VERSION = 1;
function lcs_komite_enabled(array $config): bool {
    return ($config['komite_mapping_version']??0) === LCS_KOMITE_MAPPING_VERSION;
}
function lcs_sorga_history_key(): string {
    static $key=null;return $key??='histori:'.substr(hash('sha256','bayar.U_SORGA'),0,32);
}
function lcs_component_view(array $item): array {
    $category=(string)($item['category']??'');
    $column=strtoupper(trim((string)($item['column']??$item['source_column']??'')));
    $sorga=$category===lcs_sorga_history_key()||($column==='U_SORGA'&&in_array($category,['','komite'],true));
    return ['display_label'=>$sorga?'Komite':(string)($item['label']??''),'cash_category'=>$sorga?'komite':$category];
}
/** Expressions are internal SQL fragments, never supplied by a request. */
function lcs_sorga_sql(string $column,string $category): string {
    $key=lcs_sorga_history_key();
    return "($category='$key' OR (UPPER(TRIM(COALESCE($column,'')))='U_SORGA' AND COALESCE($category,'') IN ('','komite')))";
}
function lcs_cash_category_sql(string $column,string $category): string {
    return 'CASE WHEN '.lcs_sorga_sql($column,$category)." THEN 'komite' ELSE $category END";
}
function lcs_display_label_sql(string $column,string $category,string $label): string {
    return 'CASE WHEN '.lcs_sorga_sql($column,$category)." THEN 'Komite' ELSE $label END";
}
function lcs_sql_text(string $expression): string {
    return "CONVERT(($expression) USING utf8mb4) COLLATE utf8mb4_general_ci";
}
function lcs_catalog(array $components,array $config): array {
    if(!lcs_komite_enabled($config))return $components;
    foreach($components as &$component)if($component['table']==='bayar'){
        if(strtoupper($component['column'])==='U_SORGA'){$component['name']='Komite';$component['suggestion']='komite';}
        elseif(!empty($component['is_pomg'])){$component['name']='Komite (POMG)';$component['suggestion']='komite';}
    }unset($component);
    return $components;
}
/** Only the new rule's automatic suggestions supersede an old automatic profile. */
function lcs_configure(array $config,array $components): array {
    if(!lcs_komite_enabled($config))return $config;
    foreach($components as $component){
        if($component['table']!=='bayar'||(strtoupper($component['column'])!=='U_SORGA'&&empty($component['is_pomg'])))continue;
        $key=$component['key'];$old=$config['components'][$key]??[];
        if(($old['automatic_mapping']??true)===false){
            if(($old['category']??'')!=='komite')unset($config['components'][$key]['migration_rule']);
            continue;
        }
        $config['components'][$key]=array_replace($old,['category'=>'komite','automatic_mapping'=>true,'confirmed'=>true,
            'reason'=>'U_SORGA dan POMG merupakan pembayaran Komite sesuai pengesahan sumber Legacy; nominal asli dipertahankan.']);
        if(strtoupper($component['column'])==='U_SORGA')$config['components'][$key]['migration_rule']=['komite_mapping_version'=>LCS_KOMITE_MAPPING_VERSION,'source_column'=>'U_SORGA','status'=>'direct','rule'=>'sorga_is_komite'];
    }
    return $config;
}
