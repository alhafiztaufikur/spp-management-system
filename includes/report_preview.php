<?php

function report_preview_escape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function report_preview_url(array $source, array $replace = [], array $remove = []): string
{
    foreach ($remove as $key) {
        unset($source[$key]);
    }

    foreach ($replace as $key => $value) {
        if ($value === null) {
            unset($source[$key]);
            continue;
        }
        $source[$key] = $value;
    }

    return '?' . http_build_query($source);
}

function render_report_export_preview(string $documentHtml, array $options): void
{
    if(!empty($options['pdf_url'])){require_once __DIR__.'/report_pdf_preview.php';render_native_report_preview($options);}
    $global=($options['presentation']??'')==='global';
    $transaction=($options['presentation']??'')==='transaction';
    $styled=$global||$transaction;$pdfActions=!empty($options['pdf_actions']);
    $palette=[1=>'165c44',2=>'203e80',3=>'832a35',0=>'513080'][(int)($options['unit']??1)]??'165c44';
    $title = (string)($options['title'] ?? 'Preview Laporan');
    $subtitle = (string)($options['subtitle'] ?? 'Periksa data sebelum mengunduh laporan.');
    $generated = (string)($options['generated'] ?? spp_date_label(new DateTimeImmutable('now'),true));
    $rowCount = max(0, (int)($options['row_count'] ?? 0));
    $orientation = ($options['orientation'] ?? 'portrait') === 'landscape' ? 'landscape' : 'portrait';
    $fileType = strtoupper((string)($options['file_type'] ?? 'PDF'));
    $fileType = in_array($fileType, ['PDF', 'EXCEL'], true) ? $fileType : 'PDF';
    $downloadUrl = (string)($options['download_url'] ?? '#');
    $backUrl = (string)($options['back_url'] ?? 'javascript:history.back()');
    $autoPrint = !empty($options['auto_print']);
    $showPrint = array_key_exists('show_print', $options) ? (bool)$options['show_print'] : $fileType === 'PDF';
    $documentLabel = $fileType === 'EXCEL'
        ? 'Lembar Excel'
        : ($orientation === 'landscape' ? 'A4 Landscape' : 'A4 Portrait');
    if(!empty($options['document_label']))$documentLabel=(string)$options['document_label'];
    $frameTitle = 'Dokumen ' . $title;
    $stageWidth = (string)($options['stage_width'] ?? ($orientation === 'landscape' ? '1480px' : '980px'));
    if (!preg_match('/^\d{3,4}px$/', $stageWidth)) $stageWidth = '980px';
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= report_preview_escape($title) ?> | Preview <?= report_preview_escape($fileType) ?></title>
    <link rel="icon" type="image/png" href="../assets/img/favicon.png?v=2">
    <style>
        :root{color-scheme:light;--green:#0f8f52;--green-dark:#0b5a3d;--line:#d7e7de;--muted:#65776e;--canvas:#edf5f0}
        *{box-sizing:border-box}
        html,body{min-height:100%;margin:0;overflow-x:hidden}
        body{font-family:Inter,"Segoe UI",Arial,sans-serif;background:var(--canvas);color:#17251e}
        button,a{font:inherit}
        .preview-toolbar{position:sticky;top:0;z-index:20;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:14px 22px;background:rgba(255,255,255,.98);border-bottom:1px solid var(--line);box-shadow:0 8px 24px rgba(28,75,51,.08)}
        .preview-heading{display:flex;align-items:center;gap:13px;min-width:0}
        .preview-back{display:grid;place-items:center;width:40px;height:40px;flex:0 0 40px;border:1px solid #c7dfd1;border-radius:8px;background:#f4fbf7;color:var(--green-dark);text-decoration:none}
        .preview-back:hover{background:#e8f7ee}
        .preview-heading-copy{min-width:0}
        .preview-eyebrow{display:block;margin-bottom:3px;color:var(--green);font-size:11px;font-weight:800;text-transform:uppercase}
        .preview-heading h1{overflow:hidden;margin:0;font-size:18px;line-height:1.2;text-overflow:ellipsis;white-space:nowrap}
        .preview-heading p{overflow:hidden;margin:4px 0 0;color:var(--muted);font-size:12px;text-overflow:ellipsis;white-space:nowrap}
        .preview-actions{display:flex;align-items:center;gap:9px;flex:0 0 auto}
        .preview-button{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:9px 15px;border:1px solid #c8dfd2;border-radius:8px;background:#fff;color:#244337;font-weight:750;text-decoration:none;white-space:nowrap;cursor:pointer}
        .preview-button:hover{background:#f3faf6}
        .preview-button.primary{border-color:var(--green);background:var(--green);color:#fff}
        .preview-button.primary:hover{background:#0c7946}
        .preview-button svg{width:17px;height:17px}
        .preview-meta{display:flex;align-items:center;justify-content:center;gap:8px;padding:13px 18px 0;color:var(--muted);font-size:12px}
        .preview-meta span{display:inline-flex;align-items:center;min-height:28px;padding:5px 10px;border:1px solid #d5e8de;border-radius:999px;background:rgba(255,255,255,.82)}
        .preview-help{display:flex;align-items:center;justify-content:center;gap:7px;padding:8px 18px 0;color:#718078;font-size:11px}
        .preview-help svg{width:14px;height:14px;flex:0 0 auto}
        .preview-stage{padding:14px 18px 30px}
        .preview-frame-shell{width:min(100%,<?= $stageWidth ?>);margin:0 auto;overflow:hidden;border:1px solid #cddfd5;border-radius:8px;background:#fff;box-shadow:0 18px 48px rgba(24,67,45,.13)}
        .preview-frame{display:block;width:100%;height:500px;min-height:500px;border:0;background:#fff;overflow:hidden}
        .preview-loading{padding:22px;color:var(--muted);text-align:center}
        @media(max-width:760px){
            .preview-toolbar{position:relative;align-items:stretch;flex-direction:column;width:100%;padding:13px 14px}
            .preview-heading h1,.preview-heading p{white-space:normal}
            .preview-actions{display:grid;width:100%;grid-template-columns:repeat(<?= $showPrint ? '2' : '1' ?>,minmax(0,1fr))}
            .preview-button{width:100%;padding:9px 8px;font-size:13px}
            .preview-meta{flex-wrap:wrap;padding-top:10px}
            .preview-stage{padding:10px 0 18px}
            .preview-frame-shell{border-right:0;border-left:0;border-radius:0;box-shadow:none}
            .preview-frame{min-height:420px}
        }
        @media print{.preview-toolbar,.preview-meta,.preview-help{display:none!important}.preview-stage{padding:0}.preview-frame-shell{width:100%;border:0;box-shadow:none}}
        <?php if($styled): ?>
        :root{--green:#<?= $palette ?>;--green-dark:#<?= $palette ?>}body{font-size:14px}.preview-button{font-size:13px;min-height:44px;border-radius:10px}.preview-heading h1{font-size:18px}.preview-eyebrow{display:none}.preview-toolbar{padding:18px 24px}.preview-frame-shell{border-radius:14px}.preview-help{display:none}.preview-button.primary:hover{background:var(--green-dark)}
        html[data-theme="dark"]{--canvas:#111820;--line:#32404b;--muted:#acb8c3;color-scheme:dark}html[data-theme="dark"] body{color:#eef3f7}html[data-theme="dark"] .preview-toolbar{background:#1b2530}html[data-theme="dark"] .preview-button:not(.primary),html[data-theme="dark"] .preview-back,html[data-theme="dark"] .preview-meta span{background:#25323e;color:#eef3f7;border-color:var(--line)}
        <?php endif; ?>
        <?php if(!empty($options['full_width'])): ?>
        .preview-frame-shell{width:100%;max-width:none}.preview-status{margin:0;padding:8px 20px;color:var(--muted);font-size:12px;text-align:center}.preview-button[aria-disabled=true]{opacity:.6;cursor:wait}.preview-button:focus-visible{outline:3px solid var(--green);outline-offset:3px}[hidden]{display:none!important}
        <?php endif; ?>
    </style>
    <?php if($styled): ?><script>document.documentElement.dataset.theme=localStorage.getItem('spp_theme')||'light';</script><?php endif; ?>
</head>
<body<?php if($pdfActions): ?> data-transaction-preview data-pdf-url="<?= report_preview_escape($options['pdf_url_action']) ?>" data-html-url="<?= report_preview_escape($options['html_url']) ?>" data-model="<?= report_preview_escape($options['model_fingerprint']) ?>" data-filename="rekap-laporan-transaksi.pdf"<?php endif; ?>>
    <header class="preview-toolbar">
        <div class="preview-heading">
            <a class="preview-back" href="<?= report_preview_escape($backUrl) ?>" title="Kembali ke laporan" aria-label="Kembali ke laporan">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            </a>
            <div class="preview-heading-copy">
                <span class="preview-eyebrow">Preview <?= report_preview_escape($fileType) ?></span>
                <h1><?= report_preview_escape($title) ?></h1>
                <p><?= report_preview_escape($subtitle) ?></p>
            </div>
        </div>
        <div class="preview-actions">
            <?php if ($showPrint): ?>
            <button class="preview-button" type="button" data-preview-print>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Cetak
            </button>
            <?php endif; ?>
            <a class="preview-button primary" data-preview-download href="<?= report_preview_escape($downloadUrl) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/></svg>
                Download <?= report_preview_escape($fileType) ?>
            </a>
        </div>
    </header>
    <div class="preview-meta" aria-label="Informasi dokumen">
        <span><?= report_preview_escape($documentLabel) ?></span>
        <span data-preview-count><?= number_format($rowCount) ?> data</span>
        <span data-preview-generated>Dibuat <?= report_preview_escape($generated) ?></span>
    </div>
    <?php if($pdfActions): ?><p class="preview-status" role="status" aria-live="polite"><span data-preview-status>Pratinjau siap. Menyiapkan file PDF…</span> <button type="button" class="preview-button" data-preview-retry hidden>Coba Lagi</button></p><?php endif; ?>
    <?php if(!$styled): ?><div class="preview-help"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg><span>Periksa isi laporan berikut sebelum mengunduh<?= $showPrint ? ' atau mencetak' : '' ?>.</span></div>
    <?php endif; ?><main class="preview-stage">
        <div class="preview-frame-shell">
            <div class="preview-loading" data-preview-loading>Menyiapkan tampilan dokumen...</div>
            <iframe class="preview-frame" data-preview-frame scrolling="no" title="<?= report_preview_escape($frameTitle) ?>" srcdoc="<?= report_preview_escape($documentHtml) ?>"></iframe>
        </div>
    </main>
    <script>
        (function(){
            <?php if(!empty($options['close_on_back'])): ?>
            document.querySelector('.preview-back')?.addEventListener('click',function(event){
                if(event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
                event.preventDefault();const fallback=this.href;window.close();setTimeout(function(){location.href=fallback;},100);
            });
            <?php endif; ?>
            const frame=document.querySelector('[data-preview-frame]');
            const loading=document.querySelector('[data-preview-loading]');
            const printButton=document.querySelector('[data-preview-print]');
            let ready=false;
            let resizeObserver=null;
            const resizeFrame=function(){
                if(!frame||!frame.contentDocument)return;
                const doc=frame.contentDocument;
                if(doc.documentElement)doc.documentElement.style.overflow='hidden';
                if(doc.body)doc.body.style.overflow='visible';
                const height=Math.max(
                    doc.body?doc.body.scrollHeight:0,
                    doc.documentElement?doc.documentElement.scrollHeight:0,
                    420
                );
                frame.style.height=(height+2)+'px';
            };
            const printDocument=function(){<?php if($global&&!empty($options['print_url'])): ?>window.open(<?= json_encode($options['print_url'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,'_blank','noopener');return;<?php endif; ?>if(frame&&frame.contentWindow){frame.contentWindow.focus();frame.contentWindow.print();}};
            if(frame){
                frame.addEventListener('load',function(){
                    ready=true;
                    frame.dataset.ready='1';
                    if(loading)loading.hidden=true;
                    resizeFrame();
                    const body=frame.contentDocument&&frame.contentDocument.body;
                    if(resizeObserver)resizeObserver.disconnect();
                    if(body&&window.ResizeObserver){resizeObserver=new ResizeObserver(resizeFrame);resizeObserver.observe(body);}
                    window.setTimeout(resizeFrame,120);
                    document.dispatchEvent(new Event('report-preview-ready'));
                    <?php if($autoPrint): ?>window.setTimeout(printDocument,180);<?php endif; ?>
                });
                window.addEventListener('resize',resizeFrame,{passive:true});
            }
            <?php if(!$pdfActions): ?>if(printButton){printButton.addEventListener('click',function(){if(ready)printDocument();});}<?php endif; ?>
        })();
    </script>
    <?php if($pdfActions): ?><script src="../assets/js/transaction_report_preview.js?v=<?= filemtime(__DIR__.'/../assets/js/transaction_report_preview.js') ?>"></script><?php endif; ?>
</body>
</html>
    <?php
    exit;
}

function render_report_pdf_preview(string $documentHtml, array $options): void
{
    $options['file_type'] = 'PDF';
    $options['show_print'] = true;
    render_report_export_preview($documentHtml, $options);
}
