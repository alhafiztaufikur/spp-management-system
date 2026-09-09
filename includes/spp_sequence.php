<?php

/**
 * Helpers murni untuk menyusun urutan kewajiban SPP Juli--Juni.
 *
 * Tanggung jawab historis berasal dari penempatan siswa yang masih berstatus
 * aktif pada tahun ajaran terkait. File ini sengaja bebas database agar aturan
 * kalender dapat diuji tanpa memutasi data aplikasi.
 */

function spp_sequence_month_code($value): string {
    $map = [
        'januari' => '01', 'februari' => '02', 'maret' => '03', 'april' => '04',
        'mei' => '05', 'juni' => '06', 'juli' => '07', 'agustus' => '08',
        'september' => '09', 'oktober' => '10', 'november' => '11', 'desember' => '12',
    ];
    $value = trim((string)$value);
    $lower = mb_strtolower($value, 'UTF-8');
    if (isset($map[$lower])) return $map[$lower];

    $month = (int)$value;
    return $month >= 1 && $month <= 12 ? str_pad((string)$month, 2, '0', STR_PAD_LEFT) : '';
}

function spp_sequence_period_key(string $bulan, string $tahun): string {
    $month = spp_sequence_month_code($bulan);
    return $month !== '' && preg_match('/^\d{4}$/', $tahun) ? $month . '-' . $tahun : '';
}

function spp_sequence_period_order(string $bulan, string $tahun): int {
    $month = (int)spp_sequence_month_code($bulan);
    $year = (int)$tahun;
    return $month >= 1 && $year >= 1 ? ($year * 12) + $month : 0;
}

function spp_sequence_academic_year_label(string $bulan, string $tahun): string {
    $month = (int)spp_sequence_month_code($bulan);
    $year = (int)$tahun;
    if ($month < 1 || $year < 1) return '';
    $start = $month >= 7 ? $year : $year - 1;
    return $start . '/' . ($start + 1);
}

/** @return array<int,array{bulan:string,tahun:string,key:string,order:int,tarif:float,tahun_ajaran:string}> */
function spp_sequence_periods_for_active_placements(array $placements): array {
    $periods = [];
    foreach ($placements as $placement) {
        if (($placement['status'] ?? 'aktif') !== 'aktif') continue;
        $label = trim((string)($placement['tahun_ajaran'] ?? $placement['label'] ?? ''));
        if (!preg_match('/^(\d{4})\/(\d{4})$/', $label, $match) || (int)$match[2] !== (int)$match[1] + 1) continue;

        $startYear = (int)$match[1];
        $tariff = (float)($placement['spp_perbulan_snapshot'] ?? $placement['tarif'] ?? 0);
        foreach ([[$startYear, 7, 12], [$startYear + 1, 1, 6]] as [$year, $firstMonth, $lastMonth]) {
            for ($month = $firstMonth; $month <= $lastMonth; $month++) {
                $bulan = str_pad((string)$month, 2, '0', STR_PAD_LEFT);
                $key = spp_sequence_period_key($bulan, (string)$year);
                $periods[$key] = [
                    'bulan' => $bulan,
                    'tahun' => (string)$year,
                    'key' => $key,
                    'order' => spp_sequence_period_order($bulan, (string)$year),
                    'tarif' => $tariff,
                    'tahun_ajaran' => $label,
                ];
            }
        }
    }
    uasort($periods, static fn(array $left, array $right): int => $left['order'] <=> $right['order']);
    return array_values($periods);
}

/** @return array<int,array{bulan:string,tahun:string,key:string,order:int,tarif:float,tahun_ajaran:string}> */
function spp_sequence_prior_periods(array $placements, string $bulan, string $tahun): array {
    $selectedOrder = spp_sequence_period_order($bulan, $tahun);
    if ($selectedOrder === 0) return [];
    return array_values(array_filter(
        spp_sequence_periods_for_active_placements($placements),
        static fn(array $period): bool => $period['order'] < $selectedOrder
    ));
}

/** @return array<int,array{bulan:string,tahun:string,key:string,order:int,tarif:float,tahun_ajaran:string}> */
function spp_sequence_following_periods(array $placements, string $bulan, string $tahun): array {
    $selectedOrder = spp_sequence_period_order($bulan, $tahun);
    if ($selectedOrder === 0) return [];
    return array_values(array_filter(
        spp_sequence_periods_for_active_placements($placements),
        static fn(array $period): bool => $period['order'] > $selectedOrder
    ));
}

function spp_sequence_tariff_for_period(array $placements, string $bulan, string $tahun, float $fallback): float {
    $key = spp_sequence_period_key($bulan, $tahun);
    foreach (spp_sequence_periods_for_active_placements($placements) as $period) {
        if ($period['key'] === $key) return (float)$period['tarif'];
    }
    return $fallback;
}
