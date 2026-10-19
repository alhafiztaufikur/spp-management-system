<?php
/** Presentation while the database gate is closed. Never opens MySQL or releases locks. */
function database_access_wait_model(bool $restoring,string $method): array {
    return ['code'=>$restoring?'database_restoring':'migration_in_progress','retry_after'=>10,
        'title'=>$restoring?'Pemulihan database sedang berjalan':'Finalisasi migrasi sedang berjalan',
        'message'=>$restoring?'Sistem sedang memulihkan dan memeriksa database. Akses dibuka kembali setelah proses selesai.':'Data migrasi dan backup sedang diselesaikan serta diverifikasi. Akses dibuka kembali secara otomatis setelah proses selesai.',
        'refresh'=>in_array($method,['GET','HEAD'],true)];
}
function database_access_wait_retry_url(string $uri): string {
    return str_starts_with($uri,'/')&&!str_starts_with($uri,'//')&&!preg_match('/[\x00-\x20\x7f\\\\]/',$uri)?$uri:'/';
}
function database_access_wait_asset_base(): string {
    $root=str_replace('\\','/',realpath(__DIR__.'/..'));
    $file=str_replace('\\','/',realpath($_SERVER['SCRIPT_FILENAME']??'')?:'');
    $script=$_SERVER['SCRIPT_NAME']??'';
    if(str_starts_with(strtolower($file),strtolower($root.'/'))){
        $relative=substr($file,strlen($root)+1);
        if(str_ends_with($script,$relative)){
            $base=substr($script,0,strlen($script)-strlen($relative));
            if(preg_match('~^/(?:[a-zA-Z0-9_%. -]+/)*$~D',$base)&&!str_contains($base,'..'))return str_replace(' ','%20',$base);
        }
    }
    return '/';
}
function database_access_wait_html(array $model,string $retry,string $base): string {
    $e=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    ob_start();
    ?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title><?= $e($model['title']) ?> | SistemSPP</title>
<?php if($model['refresh']): ?><meta http-equiv="refresh" content="10"><?php endif; ?>
<script>try{document.documentElement.setAttribute('data-theme',localStorage.getItem('spp_theme')||'light')}catch(e){}</script>
<link rel="stylesheet" href="<?= $e($base) ?>assets/css/style.css">
<style>
.database-wait{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:var(--bg-surface,#f3fbf6);color:var(--text-primary,#17261e);font-family:Inter,system-ui,sans-serif;box-sizing:border-box}.database-wait *{box-sizing:border-box}.database-wait main{width:100%;max-width:620px;padding:32px;border:1px solid var(--border,#d7e8de);border-radius:24px;background:var(--bg-card,#fff);box-shadow:var(--shadow-sm,0 8px 32px #17261e08)}.database-wait .brand{font-weight:800;color:var(--accent,#168a46);margin-bottom:24px}.database-wait h1{font-size:clamp(22px,4vw,28px);line-height:1.3;margin:0 0 16px}.database-wait p{line-height:1.65;margin:12px 0;color:var(--text-secondary,#40564a)}.database-wait progress{display:block;width:100%;height:10px;margin:24px 0;accent-color:var(--accent,#168a46)}.database-wait a{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:12px 20px;border-radius:14px;background:var(--accent,#168a46);color:#fff;text-decoration:none;font-weight:700;margin-top:12px}.database-wait a:focus-visible{outline:3px solid var(--accent);outline-offset:4px}.database-wait small{display:block;margin-top:18px;color:var(--text-muted,#6b7e72);line-height:1.6}@media(max-width:480px){.database-wait{padding:16px}.database-wait main{padding:24px}.database-wait a{width:100%}}
</style></head><body class="database-wait"><main aria-labelledby="wait-title"><div class="brand">SistemSPP</div><h1 id="wait-title"><?= $e($model['title']) ?></h1><p role="status"><?= $e($model['message']) ?></p><progress aria-label="<?= $e($model['title']) ?>"></progress>
<?php if($model['refresh']): ?><p>Halaman ini memeriksa kembali secara otomatis setiap 10 detik. Tidak perlu menekan Finalisasi Migrasi lagi.</p><?php else: ?><p>Permintaan ini belum diproses. Buka kembali halaman setelah proses selesai dan periksa hasil sebelum mengirim ulang.</p><?php endif; ?>
<a href="<?= $e($retry) ?>">Periksa Status Lagi</a><small>Proses tetap berjalan jika halaman ini ditutup.</small></main></body></html><?php
    return ob_get_clean();
}
function database_access_wait_response(bool $restoring): never {
    $method=$_SERVER['REQUEST_METHOD']??'GET';$model=database_access_wait_model($restoring,$method);
    http_response_code(503);header('Retry-After: 10');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
    $script=$_SERVER['SCRIPT_NAME']??'';$accept=$_SERVER['HTTP_ACCEPT']??'';
    if(str_ends_with($script,'_api.php')||str_contains($accept,'application/json')){
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$model['message'],'code'=>$model['code'],'retry_after'=>10],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    header('Content-Type: text/html; charset=utf-8');if($model['refresh'])header('Refresh: 10');
    if($method!=='HEAD')echo database_access_wait_html($model,database_access_wait_retry_url($_SERVER['REQUEST_URI']??'/'),database_access_wait_asset_base());exit;
}
