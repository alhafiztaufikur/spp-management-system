<?php
/** Pure regression: no bootstrap, connection, importer job or database mutation. */
require_once __DIR__.'/legacy_operational_pure_test.php';
require_once __DIR__.'/../includes/legacy_component_semantics.php';
$checks=0;
function kc_assert(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function kc_row(string $table,array $raw):array{return ['table'=>$table,'raw'=>$raw,'reference_only'=>false,'row_key'=>hash('sha256',lf_json([$table,$raw])),'money_columns'=>array_values(array_intersect(array_keys($raw),['U_SPP','U_SORGA','U_LAIN','JUMLAH1','POMG','SPP_PERBULAN'])),'student_ordinal'=>1];}
$raw=['NO_INDUK'=>'212200001','KELAS'=>'1A','U_SPP'=>'530000','U_SORGA'=>'15000','BULAN'=>'10','TAHUN'=>'2026','TGL_BYR'=>'2026-10-14 08:25:09'];
$payment=kc_row('bayar',$raw);$original=$payment;$oldCfg=op_config([$payment]);
foreach(lful_catalog([$payment]) as $key=>$component)$oldCfg['components'][$key]=['category'=>$component['suggestion']?:lful_history_category($key),'reason'=>'Original component mapping before Komite rule.'];
$old=lful_payment($payment,$oldCfg,[]);$old['items'][1]['category']=lcs_sorga_history_key();$old['items'][1]['label']='U_SORGA';$old['identity']='212200001';$before=lful_projection_fingerprint([$old],[]);
kc_assert($old['items'][1]['category']===lcs_sorga_history_key()&&$old['items'][1]['label']==='U_SORGA','Existing jobs must keep their original projection');
foreach([1,2,3] as $unit){
    $student=op_raw('212200001',[1=>'1A',2=>'7A',3=>'10A'][$unit]);$rows=[$student,$payment];
    $cfg=lop_source_setup($rows,['fingerprint'=>str_repeat('a',64)],['cutoff'=>'2026-10-19 11:00:00'],$unit);
    kc_assert(lcs_komite_enabled($cfg)&&$cfg['components']['bayar.U_SORGA']['category']==='komite','New job did not map Komite');
    $plan=lop_prepare($rows,$cfg,$unit,'2026-10-17');$item=$plan['payments'][0]['items'][1];
    kc_assert($plan['summary']['payment_total']==='545000.00'&&$item['category']==='komite'&&$item['label']==='Komite','Unit cash or Komite amount changed');
    kc_assert($item['migration']['source_code']==='U_SORGA'&&$item['migration']['source_row_key']===$payment['row_key']&&$item['migration']['rule']==='sorga_is_komite'&&$item['migration']['komite_mapping_version']===1,'Source meaning or row evidence missing');
    kc_assert(empty($plan['owners']['212200001']['decision']['coverage']),'A historical payment must not invent an obligation');
    $catalog=['manifest'=>['fingerprint'=>str_repeat('a',64)],'components'=>array_values(lful_catalog($rows)),'students'=>[],'additional_savings'=>[]];
    $bulk=lop_bulk_patch($rows,$catalog,$cfg,$unit,'components');kc_assert($bulk['patch']['components']['bayar.U_SORGA']['category']==='komite'&&!$bulk['patch']['new_masters'],'Komite created an optional master or reverted to history');
    $cfg['components']['bayar.U_SORGA']=['category'=>lcs_sorga_history_key(),'reason'=>'Explicit mapping confirmed with additional evidence.','automatic_mapping'=>false,'confirmed'=>true];
    $manual=lop_prepare($rows,$cfg,$unit,'2026-10-17');kc_assert($manual['payments'][0]['items'][1]['category']==='komite','24 October SORGA decision must apply to new projections; stored corrections remain untouched');
}
$newCfg=lcs_configure($oldCfg+['komite_mapping_version'=>1],lful_catalog([$payment],['komite_mapping_version'=>1]));
$full=lful_payment($payment,$newCfg,[]);kc_assert($full['items'][1]['category']==='komite'&&$full['items'][1]['label']==='Komite'&&$newCfg['components']['bayar.U_SORGA']['migration_rule']['rule']==='sorga_is_komite','Full migration rule missing from manifest configuration');
$pomg=kc_row('bayar',$raw+['U_LAIN'=>'7000','LAIN_LAIN1'=>'POMG','JUMLAH1'=>'7000']);$rows=[op_raw('212200001','1A'),$pomg];$cfg=lop_source_setup($rows,['fingerprint'=>str_repeat('a',64)],['cutoff'=>'2026-10-19 11:00:00'],1);$plan=lop_prepare($rows,$cfg,1,'2026-10-17');$komite=array_filter($plan['payments'][0]['items'],fn($i)=>$i['category']==='komite');
kc_assert(count($komite)===2&&array_sum(array_map(fn($i)=>lf_cents($i['amount']),$komite))===2200000&&$plan['summary']['payment_total']==='552000.00','POMG and SORGA source amounts lost or counted twice');
$undated=kc_row('bayar',['NO_INDUK'=>'212200001','U_SORGA'=>'15000']);$cfg=lcs_configure(op_config([$undated])+['komite_mapping_version'=>1],lful_catalog([$undated],['komite_mapping_version'=>1]));$p=lful_payment($undated,$cfg,[]);kc_assert($p['date']===null&&$p['period']===''&&$p['total']==='15000.00','Missing date or period fabricated');
$zero=kc_row('bayar',['NO_INDUK'=>'212200001','U_SORGA'=>'0']);kc_assert(!lful_catalog([$zero],['komite_mapping_version'=>1]),'Zero Komite became a component');
$view=lcs_component_view($old['items'][1]);kc_assert($view===['display_label'=>'Komite','cash_category'=>'komite']&&$old['items'][1]['category']===lcs_sorga_history_key(),'Cash projection mutated stored category');
kc_assert(lcs_component_view(['label'=>'U_SORGA','category'=>'biaya_lain:1'])['cash_category']==='biaya_lain:1','Label-only resemblance was reclassified');
kc_assert(lcs_component_view(['column'=>'U_SORGA','category'=>'biaya_lain:1','label'=>'Explicit fee'])['display_label']==='Explicit fee','Confirmed non-Komite mapping lost');
kc_assert($payment===$original&&lful_projection_fingerprint([$old],[])===$before,'Source or old fingerprint changed');
echo "PASS: $checks new/old Komite mapping, three units, source amounts, POMG, manual mapping, missing dates, no invented obligations and immutable cash projections.\n";
