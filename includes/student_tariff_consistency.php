<?php

function student_amount($value): float {
    if ($value === null || $value === '') return 0.0;
    $normalized = str_replace(['.', ','], ['', '.'], trim((string)$value));
    $amount = is_numeric($normalized) ? (float)$normalized : NAN;
    if (!is_finite($amount) || $amount < 0 || $amount > 9999999999999.99) {
        throw new RuntimeException('Nominal harus berupa angka positif atau nol.');
    }
    return $amount;
}

function student_snapshots_differ(array $before, array $after): bool {
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
        $left = $before[$key] ?? null;
        $right = $after[$key] ?? null;
        if (is_numeric($left) && is_numeric($right)) {
            if (abs((float)$left - (float)$right) > .001) return true;
        } elseif ((string)$left !== (string)$right) {
            return true;
        }
    }
    return false;
}

function student_tariff_component_changes(array $before, array $after): array {
    $fields = [
        'spp' => ['SPP_PERBULAN'],
        'psb' => ['PSB'],
        'komite' => ['POMG'],
        'daftar_ulang' => ['DAFTAR_ULANG', 'potong_du', 'tot_du'],
    ];
    $changed = [];
    foreach ($fields as $component => $columns) {
        foreach ($columns as $column) {
            if (abs((float)($before[$column] ?? 0) - (float)($after[$column] ?? 0)) > .001) {
                $changed[] = $component;
                break;
            }
        }
    }
    return $changed;
}

function student_tariff_label(string $component): string {
    $labels = [
        'spp'=>'SPP', 'psb'=>'PSB', 'komite'=>'Komite',
        'daftar_ulang'=>'Daftar Ulang',
    ];
    return $labels[$component] ?? ucfirst(str_replace('_', ' ', $component));
}

function student_tariff_labels(array $components): string {
    return implode(', ', array_map('student_tariff_label', array_values(array_unique($components))));
}
