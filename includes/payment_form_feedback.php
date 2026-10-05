<?php

require_once __DIR__ . '/spp_payment_status.php';
require_once __DIR__ . '/spp_billing.php';
require_once __DIR__ . '/daftar_ulang.php';

function payment_du_selection_status(DaftarUlangSelectionException $error): array {
    $titles = [
        'missing' => 'Pilih tagihan Daftar Ulang',
        'not_found' => 'Tagihan tidak ditemukan',
        'wrong_student' => 'Tagihan tidak cocok',
        'cancelled' => 'Tagihan dibatalkan',
        'future' => 'Tagihan belum bisa dibayar',
        'settled' => 'Daftar Ulang sudah lunas',
        'changed' => 'Tagihan berubah',
        'over_limit' => 'Melebihi sisa tagihan',
        'overpaid' => 'Riwayat pembayaran perlu diperiksa',
    ];
    return [
        'code' => 'du_' . $error->reason,
        'severity' => 'error',
        'title' => $titles[$error->reason] ?? 'Periksa Daftar Ulang',
        'message' => $error->getMessage(),
        'target' => 'du-input',
    ];
}

/** Mengubah kegagalan penyimpanan menjadi pesan popup yang dapat ditindaklanjuti. */
function payment_failure_flash(Throwable $error, string $fallbackPrefix): array {
    if ($error instanceof mysqli_sql_exception && in_array((int)$error->getCode(), [1205, 1213], true)) {
        $error = new SppPaymentException([
            'code' => 'billing_changed', 'severity' => 'error', 'title' => 'Tagihan berubah',
            'message' => 'Tagihan sedang diperbarui oleh kasir lain. Perbarui tagihan, lalu periksa kembali sebelum menyimpan.',
            'target' => 'spp-input',
        ]);
    } elseif ($error instanceof mysqli_sql_exception) {
        error_log('Pembayaran gagal di database (kode ' . $error->getCode() . ').');
        $error = new SppPaymentException([
            'code' => 'database_error', 'severity' => 'error', 'title' => 'Pembayaran belum tersimpan',
            'message' => 'Pembayaran belum dapat diproses. Hubungi administrator sistem dan periksa riwayat sebelum mencoba lagi.',
            'target' => 'spp-input',
        ]);
    }

    if ($error instanceof DaftarUlangSelectionException) {
        $error = new SppPaymentException(payment_du_selection_status($error));
    }

    if ($error instanceof SppBillingOrderException) {
        $error = new SppPaymentException([
            'code' => 'prior_unpaid', 'severity' => 'error', 'title' => 'Ada SPP yang lebih lama',
            'message' => $error->getMessage(), 'target' => 'bulan-bayar',
        ]);
    }

    if (!$error instanceof SppPaymentException) {
        $message = $error->getMessage();
        if (str_contains($message, 'melebihi sisa tagihan')) {
            $target = str_contains($message, 'Uang PSB') ? 'psb-input'
                : (str_contains($message, 'Daftar Ulang') ? 'du-input'
                : (str_contains($message, 'SPP') ? 'spp-input'
                : (str_contains($message, 'Komite') ? 'komite-input' : 'psb-input')));
            $error = new SppPaymentException([
                'code' => 'over_limit', 'severity' => 'error', 'title' => 'Melebihi sisa tagihan',
                'message' => $message, 'target' => $target,
            ]);
        } elseif (str_contains($message, 'Tagihan Biaya Lain tidak tersedia') || str_contains($message, 'sudah lunas dan tidak dapat ditambahkan lagi')) {
            $error = new SppPaymentException([
                'code' => 'billing_changed', 'severity' => 'error', 'title' => 'Tagihan berubah',
                'message' => 'Tagihan berubah sejak halaman dibuka. Perbarui tagihan, lalu periksa kembali sebelum menyimpan.',
                'target' => 'biaya-lain-list',
            ]);
        } elseif (str_contains($message, 'Tagihan Komite') && str_contains($message, 'belum tersedia')) {
            $error = new SppPaymentException([
                'code' => 'billing_changed', 'severity' => 'error', 'title' => 'Tagihan berubah',
                'message' => 'Tagihan Komite bulan ini belum tersedia. Perbarui tagihan sebelum menyimpan.',
                'target' => 'komite-input',
            ]);
        } elseif (str_contains($message, 'tidak boleh menjadi tunggakan')) {
            $error = new SppPaymentException([
                'code' => 'prior_unpaid_edit', 'severity' => 'error', 'title' => 'Ada SPP yang lebih lama',
                'message' => $message . ' Periksa urutan bulan sebelum mengubah transaksi.',
                'target' => 'bulan-bayar',
            ]);
        }
    }

    if ($error instanceof SppPaymentException) {
        return [
            'type' => 'error',
            'scope' => 'spp',
            'msg' => $error->getMessage(),
            'spp_status' => $error->status(),
        ];
    }
    return ['type' => 'error', 'msg' => $fallbackPrefix . $error->getMessage()];
}

/** Simpan hanya isian form yang perlu dipulihkan setelah penyimpanan gagal. */
function payment_capture_draft(array $source): array {
    $draft = [];
    foreach (['no_induk', 'bulan_bayar', 'tahun_bayar', 'sistem_pembayaran',
        'tagihan_daftar_ulang_id', 'catatan',  'uang_psb', 'uang_spp', 'uang_komite', 'uang_du'] as $key) {
        if (isset($source[$key]) && is_scalar($source[$key])) {
            $draft[$key] = mb_substr((string)$source[$key], 0, 100);
        }
    }
    foreach (['biaya_lain_tagihan_id', 'biaya_lain_nominal', 'biaya_lain_keterangan'] as $key) {
        if (isset($source[$key]) && is_array($source[$key])) {
            $draft[$key] = array_map(
                static fn($value) => is_scalar($value) ? mb_substr((string)$value, 0, 255) : '',
                array_slice($source[$key], 0, 12)
            );
        }
    }
    return $draft;
}
