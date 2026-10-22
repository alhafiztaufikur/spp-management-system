<?php
/** Source defaults prepare working data; they never certify unknown debts or fix balances. */
function lop_source_setup(array $records, array $manifest, array $settings, int $unit): array
{
    // Only the initial preparation opts in. Published and previously prepared jobs keep their rules.
    if(!isset($settings['mode'])){$settings['automation_version']=LMA_VERSION;$settings['komite_mapping_version']??=LCS_KOMITE_MAPPING_VERSION;}
    $settings['source_fingerprint'] = $manifest['fingerprint'];
    $settings['mode'] = 'operational';
    $reason = 'Menggunakan data pada backup Legacy yang diperiksa; sumber asli tetap tersimpan.';
    foreach (['evidence'=>$reason, 'accounting_reason'=>$reason,
        'other_policy'=>'total_details', 'discount_policy'=>'information', 'du_policy'=>'separate'] as $key=>$value) {
        if (empty($settings[$key])) $settings[$key] = $value;
    }
    if (empty($settings['cutoff'])) $settings['cutoff'] = date('Y-m-d H:i:00');
    $groups = [];
    foreach ($records as $record) {
        if ($record['table'] !== 'siswa') continue;
        $identity = lful_identity(legacy_source_identity($record['raw']));
        if ($identity === '') continue;
        $group = lop_group((string)($record['raw']['KELAS']??''), $unit);
        $groups[$group['code']] ??= $group + ['identities'=>[]];
        $groups[$group['code']]['identities'][] = $identity;
    }
    foreach ($groups as $code=>$group) {
        // Unclassified groups remain available for individual review.
        if (in_array($group['kind'],['legacy','alumni'],true) || !empty($settings['op_groups'][$code]['confirmed'])) continue;
        $settings['op_groups'][$code] = array_replace($settings['op_groups'][$code]??[], [
            'graduation_year'=>$group['graduation_year']??null, 'kind'=>$group['kind'], 'confirmed'=>true, 'source_accepted'=>true,
            'evidence'=>$reason, 'fingerprint'=>hash('sha256', lf_json([
                $code, $group['identities'], $manifest['fingerprint']
            ]))
        ]);
    }
    foreach (lful_catalog($records,$settings) as $key=>$component) {
        if (!empty($settings['components'][$key]['category'])) continue;
        $settings['components'][$key] = [
            'category'=>$component['savings'] ? 'savings_reference' :
                ($component['suggestion'] ?: lful_history_category($key)),
            'reason'=>$reason, 'confirmed'=>empty($component['ambiguous']), 'automatic_mapping'=>true
        ];
    }
    return lcs_configure($settings,lful_catalog($records,$settings));
}

function lop_default_year(mysqli $db): int
{
    $start = (int)date('Y') - ((int)date('n') < 7 ? 1 : 0);
    $year = lf_one($db, "SELECT id FROM tahun_ajaran WHERE label=? AND status<>'closed'", [$start.'/'.($start+1)]);
    return (int)($year['id']??0);
}
