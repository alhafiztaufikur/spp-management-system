<?php
/** Shared copy and a no-JavaScript confirmation screen; never writes database state. */
function spp_rate_confirmation_copy(string $action): array {
    return match ($action) {
        'simpan_tarif'=>['Periksa tarif SPP','Pastikan nominal setiap kelas sudah sesuai. Tarif ini akan digunakan saat tagihan SPP diterbitkan.','Simpan Tarif'],
        'ubah_tarif_terbit'=>['Simpan perubahan tarif SPP?','Perubahan berlaku pada tagihan SPP yang belum pernah dibayar. Tagihan yang sudah menerima pembayaran tetap menggunakan nominal sebelumnya. Potongan khusus siswa tetap diperhitungkan.','Simpan Perubahan Tarif'],
        'tunggakan'=>['Ada tunggakan tahun sebelumnya','Siswa yang dipilih masih memiliki tunggakan tahun sebelumnya. Periksa kembali sebelum menerbitkan tagihan baru.','Lanjutkan Penerbitan'],
        default=>['Terbitkan tagihan SPP?','Tagihan akan diterbitkan untuk siswa yang dipilih. Periksa kembali tarif dan bulan mulai tagihan sebelum melanjutkan.','Terbitkan SPP'],
    };
}

function spp_rate_confirmation_screen(array $post, array $oldRates, array $newRates, string $kind): void {
    [$title,$message,$button]=spp_rate_confirmation_copy($kind);
    $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    $year=(string)$post['tahun_ajaran'];$summary=unit_label(unit_active_id()).' · '.$year;
    if (($post['aksi']??'')==='terbitkan') $summary.=' · '.count($post['selected_students']??[]).' siswa';
    foreach ($newRates as $level=>$value) {
        if ($kind==='ubah_tarif_terbit' && abs($value-($oldRates[$level]??0))<.001) continue;
        $summary.="\nKelas ".$level.': '.($kind==='ubah_tarif_terbit'?'Rp '.number_format($oldRates[$level]??0,0,',','.').' → ':'').'Rp '.number_format($value,0,',','.');
    }
    $field=$kind==='tunggakan'?'confirm_previous_debt':(($post['aksi']??'')==='terbitkan'?'confirm_spp_publish':'confirm_rate_change');
    $post[$field]='1';
    $hidden=function(array $items,string $prefix='') use (&$hidden,$e): void {
        foreach ($items as $key=>$value) {
            $name=$prefix===''?(string)$key:$prefix.'['.$key.']';
            if (is_array($value)) $hidden($value,$name);
            else echo '<input type="hidden" name="'.$e($name).'" value="'.$e($value).'">';
        }
    };
    $back='master_spp.php?tahun='.urlencode($year).(($post['aksi']??'')==='ubah_tarif_terbit'?'&edit_tarif=1':'').'#spp-rate-panel';
    ?>
    <!doctype html><html lang="id" data-palette="<?= $e(unit_palette_for_view()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $e($title) ?></title><link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__.'/../assets/css/style.css') ?>"><style>.spp-warning-amount{white-space:pre-line;max-height:40vh;overflow:auto}.spp-warning-actions form{display:contents}</style></head><body>
    <div class="spp-warning-overlay show" data-severity="warning" role="dialog" aria-modal="true" aria-labelledby="rate-confirm-title" aria-describedby="rate-confirm-message"><div class="spp-warning-dialog"><div class="spp-warning-head"><h3 class="spp-warning-title" id="rate-confirm-title"><?= $e($title) ?></h3></div><div class="spp-warning-content"><p class="spp-warning-message" id="rate-confirm-message"><?= $e($message) ?></p><div class="spp-warning-amount"><?= $e($summary) ?></div><div class="spp-warning-actions"><a class="btn btn-ghost" href="<?= $e($back) ?>">Kembali</a><form method="post" action="master_spp.php"><?php $hidden($post); ?><button class="btn btn-primary" type="submit"><?= $e($button) ?></button></form></div></div></div></div>
    </body></html>
    <?php
    exit;
}
