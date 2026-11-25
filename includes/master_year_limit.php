<?php

function master_year_limit_dialog(string $route, string $component, bool $direct = false): void {
    $year = du_next_year();
    $url = htmlspecialchars($route.'?tahun='.urlencode($year), ENT_QUOTES, 'UTF-8');
    $message = htmlspecialchars("Penerbitan $component tersedia hingga tahun ajaran $year.", ENT_QUOTES, 'UTF-8');
    ?>
    <dialog id="master-year-limit" class="master-year-limit" data-max-year="<?= $year ?>"<?= $direct ? ' data-open-direct' : '' ?>
            role="alertdialog" aria-labelledby="master-year-limit-title" aria-describedby="master-year-limit-message">
      <div class="spp-warning-overlay show" data-severity="warning">
        <div class="spp-warning-dialog">
          <div class="spp-warning-head"><h2 class="spp-warning-title" id="master-year-limit-title">Batas tahun ajaran</h2></div>
          <div class="spp-warning-content">
            <p class="spp-warning-message" id="master-year-limit-message"><?= $message ?></p>
            <div class="spp-warning-actions"><a class="btn btn-primary" href="<?= $url ?>" autofocus>Buka <?= $year ?></a></div>
          </div>
        </div>
      </div>
    </dialog>
    <?php if ($direct): ?><noscript><main class="main-card master-year-limit-fallback"><h1>Batas tahun ajaran</h1><p><?= $message ?></p><a class="btn btn-primary" href="<?= $url ?>">Buka <?= $year ?></a></main></noscript><?php endif; ?>
    <script src="assets/js/master_year_limit.js?v=<?= filemtime(__DIR__.'/../assets/js/master_year_limit.js') ?>"></script>
    <?php
}

function master_year_check(string $year, string $route, string $component, string $csrf): void {
    if ($year <= du_next_year()) return;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_string($token) || !hash_equals($csrf, $token)) {
            $_SESSION['flash'] = ['type'=>'error','msg'=>'Permintaan tidak valid atau sesi telah kedaluwarsa.'];
            $year = du_current_academic_year();
        }
        header('Location: '.$route.'?tahun='.urlencode($year), true, 303);
        exit;
    }
    $palette = htmlspecialchars(unit_palette_for_view(unit_active_id()), ENT_QUOTES, 'UTF-8');
    ?>
    <!doctype html><html lang="id" data-palette="<?= $palette ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Batas tahun ajaran | SistemSPP</title><link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__.'/../assets/css/style.css') ?>">
    <script>document.documentElement.dataset.theme=localStorage.getItem('spp_theme')||'light';</script></head><body>
    <?php master_year_limit_dialog($route, $component, true); ?>
    </body></html>
    <?php
    exit;
}
