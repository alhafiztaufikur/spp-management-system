<?php

function global_report_filter_groups(string $template): array {
    $groups = [];
    if (in_array($template,['status','spp-tahunan','per-item'],true)) $groups[] = 'tahun_ajaran';
    if ($template === 'riwayat-tagihan') $groups[] = 'billing_year';
    if (in_array($template,['status','per-item','penerimaan'],true)) $groups[] = 'kategori';
    if (in_array($template,['penerimaan','tabungan-siswa','setoran','kas-tabungan'],true)) $groups[] = 'operator';
    return $groups;
}

function global_report_filter_operators(mysqli $db, string $template, bool $includeHistory): array {
    return $template === 'tabungan-siswa'
        ? savings_operator_options($db,false,$includeHistory)
        : array_column(report_operator_options($db,$includeHistory),'nama','id');
}

function global_report_filter_catalog(mysqli $db, string $template, array $query, bool $deferred): array {
    $groups = global_report_filter_groups($template);
    $yearGroup = $template === 'riwayat-tagihan' ? 'billing_year' : 'tahun_ajaran';
    $years = in_array($yearGroup,$groups,true) || isset($query['tahun_ajaran'])
        ? report_years($db,!$deferred) : [];
    $categories = in_array('kategori',$groups,true) || isset($query['kategori'])
        ? report_categories($db,!$deferred) : [];
    $operators = in_array('operator',$groups,true) || isset($query['operator'])
        ? global_report_filter_operators($db,$template,!$deferred) : [];

    if ($deferred) {
        // Preserve explicit archive selections until the complete catalog is opened.
        // The normal report request checks those selections against live data.
        $knownYears = array_column($years,null,'label');
        foreach ((array)($query[$yearGroup]??[]) as $year) {
            if (is_string($year) && preg_match('/^\d{4}\/\d{4}$/D',$year)) {
                $knownYears[$year] ??= ['label'=>$year,'status'=>'histori'];
            }
        }
        krsort($knownYears,SORT_STRING);
        $years = array_values($knownYears);
        foreach ((array)($query['kategori']??[]) as $category) {
            if (is_string($category) && strlen($category)<=255 && str_starts_with($category,'histori:')) {
                $categories[$category] ??= $category;
            }
        }
        foreach ((array)($query['operator']??[]) as $operator) {
            if (is_string($operator) && strlen($operator)<=512 && preg_match('/^(?:[1-9]\d*|legacy:[123]:[a-f0-9]*)$/D',$operator)) {
                $operators[$operator] ??= str_starts_with($operator,'legacy:') ? 'Operator Legacy' : 'Operator #'.$operator;
            }
        }
    }

    return [
        'deferred'=>$deferred,
        'years'=>$years,
        'categories'=>$categories,
        'operators'=>$operators,
        'classes'=>!in_array($template,['setoran','kas-tabungan'],true) || isset($query['kelas']) ? report_classes($db) : [],
        'billingCategories'=>$template==='riwayat-tagihan' || isset($query['komponen_tagihan']) ? report_billing_categories($db) : [],
    ];
}

function global_report_filter_group_options(mysqli $db, string $template, string $group): array {
    return match ($group) {
        'tahun_ajaran'=>array_column(array_map(static fn($year)=>[
            'value'=>$year['label'],'label'=>$year['label'].' · '.$year['status'],
        ],report_years($db)), 'label','value'),
        'billing_year'=>billing_history_year_options(report_years($db)),
        'kategori'=>array_map(static fn($label)=>global_report_present_label($label,$template),report_categories($db)),
        'operator'=>global_report_filter_operators($db,$template,true),
    };
}

function global_report_filter_student_display(mysqli $db, array $filters): string {
    $query = $filters['q'];
    $studentId = $filters['student_id'];
    if (!$studentId && $query === '') return '';
    $identity = 'id=?';
    if (!$studentId) {
        $identity = '(NO_INDUK=? OR NO_induk_diknas=?)'.match ($filters['siswa_status']) {
            'all'=>'',
            'archived'=>' AND is_active=0',
            default=>' AND is_active=1',
        };
    }
    $stmt = $db->prepare('SELECT NAMA FROM siswa WHERE legacy_pending=0 AND '.$identity.' ORDER BY NAMA,id LIMIT 1');
    $stmt->execute($studentId ? [$studentId] : [$query,$query]);
    return $stmt->get_result()->fetch_column() ?: $query;
}

function global_report_filter_student_options(mysqli $db, array $query, int $unitId): array {
    $term = is_string($query['q']??null) ? mb_substr(trim($query['q']),0,100) : '';
    if (mb_strlen($term)<2) return [];
    $where = ['s.legacy_pending=0'];
    $params = [];
    $statuses = array_filter((array)($query['siswa_status']??'active'),'is_string');
    if (!array_intersect($statuses,['*','','all','semua','0'])) {
        $active = in_array('active',$statuses,true);
        $archived = in_array('archived',$statuses,true);
        if (!$active || !$archived) $where[] = $archived ? 's.is_active=0' : 's.is_active=1';
    }
    $classChoices = array_filter((array)($query['kelas']??[]),'is_string');
    if (!array_intersect($classChoices,['*','','all','semua','0'])) {
        $classWhere = [];
        foreach (array_unique($classChoices) as $choice) {
            $filter = ['kelas'=>report_class_filter_value($choice)];
            $rombel = report_class_filter_rombel_id($filter);
            $level = report_class_filter_level($filter);
            if ($rombel > 0) $classWhere[] = 's.master_kelas_id='.$rombel;
            if ($level > 0) $classWhere[] = "CAST(COALESCE(NULLIF(mk.tingkat,''),s.KELAS) AS UNSIGNED)=".$level;
        }
        if ($classWhere) $where[] = '('.implode(' OR ',$classWhere).')';
    }
    $pattern = '%'.strtr($term,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';
    $where[] = "(s.NO_INDUK LIKE ? ESCAPE '!' OR s.NO_induk_diknas LIKE ? ESCAPE '!' OR s.NAMA LIKE ? ESCAPE '!')";
    array_push($params,$pattern,$pattern,$pattern,$term,$term);
    $stmt = $db->prepare("SELECT s.id,s.unit_id,s.NO_INDUK,s.NO_induk_diknas,s.NAMA,s.master_kelas_id,
        COALESCE(NULLIF(mk.tingkat,''),s.KELAS) tingkat,mk.kode_rombel,mk.is_placeholder
        FROM siswa s LEFT JOIN master_kelas mk ON mk.id=s.master_kelas_id AND mk.unit_id=s.unit_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY (s.NO_INDUK=? OR s.NO_induk_diknas=?) DESC,s.NAMA,s.id LIMIT 20");
    $stmt->execute($params);
    return array_map(static function(array $row) use ($unitId): array {
        $class = class_label(['tingkat'=>$row['tingkat'],'kode_rombel'=>$row['kode_rombel']??'BELUM','is_placeholder'=>$row['is_placeholder']??1]);
        return [
            'id'=>(int)$row['id'],'unit'=>(int)$row['unit_id'],'nis'=>$row['NO_INDUK'],
            'diknas'=>$row['NO_induk_diknas']??'','name'=>$row['NAMA'],
            'class'=>($unitId===0 ? unit_label((int)$row['unit_id']).' · ' : '').$class,
        ];
    },$stmt->get_result()->fetch_all(MYSQLI_ASSOC));
}

function global_report_filter_options_response(mysqli $db, string $template, array $query, int $unitId): never {
    $group = $query['filter_group'];
    if ($group === 'student' && !in_array($template,['setoran','kas-tabungan'],true)) {
        $options = global_report_filter_student_options($db,$query,$unitId);
    } elseif (in_array($group,global_report_filter_groups($template),true)) {
        $options = [];
        foreach (global_report_filter_group_options($db,$template,$group) as $value=>$label) {
            $options[] = ['value'=>(string)$value,'label'=>$label];
        }
    } else {
        http_response_code(400);
        $options = [];
    }
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    echo json_encode($options,JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    exit;
}
