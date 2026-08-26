<?php

// Salin menjadi app.local.php hanya pada server tujuan. app.local.php
// diabaikan Git dan direktori config ditolak dari akses HTTP.
// Security runtime dikonfigurasi lewat environment process/server:
// - SPP_ENABLE_HSTS=1 hanya setelah HTTPS end-to-end dan redirect HTTP siap.
// - SPP_RATE_LIMIT_KEY=<secret-acak-stabil> untuk HMAC bucket login; jangan commit nilainya.
return [
    'app_env' => 'production',
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_user' => 'spp_app',
    'db_pass' => 'GANTI_DENGAN_SECRET_SERVER',
    'db_name' => 'db_spp',
];
