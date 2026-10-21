<?php
require_once __DIR__.'/general_multiple.php';
require_once __DIR__.'/pagination.php';
function general_selected_types(array $choices):array{return filter_is_all($choices)?['semua','belum_spp','belum_komite','belum_du','belum_biaya_lain']:$choices;}
function general_filter_key(array $query):string {
    foreach(['fragment','section','section_page','page','panel'] as $key)unset($query[$key]);ksort($query);return hash('sha256',serialize($query));
}
function general_section_page_html(array $section,array $query):string {
    $type=$section['type'];$page=$section['page'];$pages=$section['total_pages'];$count=$section['row_count'];$per=$section['per_page'];
    $e=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
    foreach(['fragment','section','page'] as $key)unset($query[$key]);
    $query['section_page']=is_array($query['section_page']??null)?$query['section_page']:[];$query['section_page'][$type]=$page;
    ob_start(); ?>
    <section class="report-section-page" id="report-section-<?= $type ?>" data-report-section="<?= $type ?>" data-page="<?= $page ?>" data-filter-key="<?= general_filter_key($query) ?>">
    <div class="report-section-content"><?= general_section_html($section,$section['offset']) ?></div>
    <div class="du-pagination-footer"><p class="du-pagination-info">Menampilkan <strong><?= $count?($page-1)*$per+1:0 ?>&ndash;<?= min($count,$page*$per) ?></strong> dari <strong><?= number_format($count) ?></strong> baris</p>
    <?php if($pages>1): ?><nav class="du-pagination" aria-label="Halaman <?= $e($section['title']) ?>"><?php
    [$first,$last]=page_window($page,$pages);$links=['Sebelumnya'=>$page-1];for($i=$first;$i<=$last;$i++)$links[(string)$i]=$i;$links['Berikutnya']=$page+1;
    foreach($links as $label=>$target){
        if($target<1||$target>$pages){echo '<span class="du-page-link is-disabled" aria-disabled="true">'.$e($label).'</span>';continue;}
        if($target===$page){echo '<span class="du-page-link is-active" aria-current="page">'.$e($label).'</span>';continue;}
        $next=$query;$next['section_page'][$type]=$target;$url='index.php?'.http_build_query($next);
        echo '<a class="du-page-link" data-section-page="'.$target.'" href="'.$e($url).'">'.$e($label).'</a>';
    }?></nav><?php endif; ?></div>
    <p class="report-section-status" role="status" aria-live="polite" hidden></p>
    </section>
    <?php return ob_get_clean();
}
