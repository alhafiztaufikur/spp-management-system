<?php
/** Read scope for the Global catalogue only; never changes the operational session. */
function global_report_templates(): array {
    return ['status','penerimaan','spp-tahunan','per-item','tabungan-siswa','saldo-tabungan','riwayat-tagihan','setoran','kas-tabungan'];
}

function report_query_unit_id(): int { return (int)($GLOBALS['app_unit_id']??unit_active_id()); }
function report_query_level_bounds(): array { return unit_level_bounds(report_query_unit_id()); }
function report_query_level_sql(): string { [$first,$last]=report_query_level_bounds();return 'BETWEEN '.$first.' AND '.$last; }
function global_report_unit_value(int $unit): string { return $unit===0?'all':(string)$unit; }

function global_report_validate_choice($choice): string {
    if (!is_string($choice) || !in_array($choice,['','active','all','1','2','3'],true)) {
        http_response_code(400);exit('Pilihan unit laporan tidak valid.');
    }
    return $choice;
}

function global_report_scope(mysqli $db, array &$query): int {
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') { http_response_code(405);header('Allow: GET');exit('Gunakan GET untuk membaca laporan.'); }
    if (empty($_SESSION['admin_id']) || !in_array($_SESSION['admin_role']??'',['super_admin','admin','kasir','bendahara'],true)) {
        http_response_code(403);exit('Akses laporan tidak diizinkan.');
    }
    $choice=global_report_validate_choice($query['unit']??'');
    $unit=in_array($choice,['','active'],true)?unit_active_id():($choice==='all'?0:(int)$choice);
    unit_set_context($db,$unit);$query['unit']=global_report_unit_value($unit);
    // Presentation follows this report scope for every role, independently of the account unit.
    $GLOBALS['global_report_view_unit']=$unit;
    if (isset($query['scope_change'])) {
        if ($query['scope_change']!=='1') { http_response_code(400);exit('Pergantian unit laporan tidak valid.'); }
        $query=global_report_preserved_query($db,$query);
        $query['unit']=global_report_unit_value($unit);
    }
    return $unit;
}

/** Unit-dependent identities/categories are omitted intentionally on scope navigation. */
function global_report_preserved_query(mysqli $db, array $query): array {
    $allowed=['template','tanggal_awal','tanggal_akhir','tahun_ajaran','bulan_awal','bulan_akhir','tahun','tahun_awal','tahun_akhir','mode','per_page'];
    $keep=array_intersect_key($query,array_flip($allowed));
    if (isset($keep['tahun_ajaran'])) {
        $years=array_column($db->query('SELECT DISTINCT label FROM tahun_ajaran')->fetch_all(MYSQLI_ASSOC),'label');
        if (is_array($keep['tahun_ajaran'])) {
            $values=in_array('*',$keep['tahun_ajaran'],true)?['*']:array_values(array_filter($keep['tahun_ajaran'],static fn($v)=>is_string($v)&&in_array($v,$years,true)));
            if ($values) $keep['tahun_ajaran']=$values;else unset($keep['tahun_ajaran']);
        } elseif (!is_string($keep['tahun_ajaran']) || !in_array($keep['tahun_ajaran'],$years,true)) unset($keep['tahun_ajaran']);
    }
    return $keep;
}

function global_report_selector(mysqli $db, int $selected, array $query, string $path): string {
    $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    $html='<section class="global-unit-selector" aria-label="Unit Laporan"><h2>Unit Laporan</h2><nav class="global-unit-options" aria-label="Pilih unit laporan">';
    try {
        foreach ([1=>'SD',2=>'SMP',3=>'SMA',0=>'Semua Unit'] as $unit=>$label) {
            unit_set_context($db,$unit);$params=global_report_preserved_query($db,$query);
            $params['unit']=global_report_unit_value($unit);$params['scope_change']='1';
            $html.='<a class="global-unit-choice'.($selected===$unit?' is-active':'').'" data-report-unit="'.$unit.'" href="'.$e($path.'?'.http_build_query($params)).'"'.($selected===$unit?' aria-current="true"':'').'>'.$label.'</a>';
        }
    } finally { unit_set_context($db,$selected); }
    return $html.'</nav></section>';
}
