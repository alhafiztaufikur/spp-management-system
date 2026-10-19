<?php
function lful_ui_proposals(mysqli $db,array $config,string $job=''): void {
    $batch=$job!==''?lf_one($db,'SELECT manifest FROM legacy_full_batches WHERE id=?',[$job]):null;
    if($batch){$manifest=json_decode($batch['manifest'],true,512,JSON_THROW_ON_ERROR);$proposals=[];foreach($manifest['resolved_masters']??[] as $logical=>$actual){$key=substr($logical,11);$proposals[$key]=$manifest['mapping']['new_masters'][$key];}}
    else{try{$proposals=lful_proposals($db,$config);}catch(DomainException $e){return;}}
    if(!$proposals)return;
    echo '<div class="lful-proposal-list"><h3>Master baru · Nonaktif</h3><ul>';
    foreach($proposals as $p)echo '<li>'.lful_e($p['name']).' · '.lful_e(!empty($p['tariff_pending'])?'Tarif belum ditetapkan':lf_ui_amount($p['nominal'])).' · Nonaktif</li>';
    echo '</ul><p>Master dibuat bersama migrasi, tanpa menerbitkan tagihan baru.</p></div>';
}
