<?php
function page_int_param(string $name, int $default = 1): int {
    return max(1, (int)($_GET[$name] ?? $default));
}

function page_size_param(string $name = 'per_page', array $allowed = [10, 25, 50], int $default = 10): int {
    $requested = (int)($_GET[$name] ?? $default);
    return in_array($requested, $allowed, true) ? $requested : $default;
}

function total_pages(int $totalRows, int $perPage): int {
    return max(1, (int)ceil(max(0, $totalRows) / max(1, $perPage)));
}

function page_window(int $page, int $totalPages, int $width = 5): array {
    $start = max(1, $page - (int)floor($width / 2));
    $end = min($totalPages, $start + $width - 1);
    $start = max(1, $end - $width + 1);
    return [$start, $end];
}

function page_url(string $path, array $query, int $page, string $pageParam = 'page'): string {
    $query[$pageParam] = max(1, $page);
    return $path . '?' . http_build_query(function_exists('filter_query')?filter_query($query):$query);
}

function pagination_query(array $extra = [], string $pageParam = 'page'): array {
    $query = $_GET;
    unset($query[$pageParam]);
    return array_merge($query, $extra);
}

/** Shared desktop controls; URLs and attributes belong to the calling list. */
function pagination_controls(int $page,int $pages,callable $url,string $label='Navigasi halaman',array $attributes=[]): string {
    if($pages<=1)return '';
    $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    $html='<nav class="desktop-pager" aria-label="'.$e($label).'">';
    foreach([-1=>'Sebelumnya',1=>'Selanjutnya'] as $delta=>$text){
        $target=$page+$delta;$disabled=$target<1||$target>$pages;$direction=$delta<0?'prev':'next';
        $attrs='';foreach($attributes as $key=>$value)$attrs.=' '.$e($key).'="'.$e(is_callable($value)?$value($target):$value).'"';
        $html.=$disabled?'<span class="desktop-page is-'.$direction.' is-disabled" aria-disabled="true">':'<a class="desktop-page is-'.$direction.'" rel="'.$direction.'" href="'.$e($url($target)).'"'.$attrs.'>';
        $icon='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="'.($delta<0?'m14 6-6 6 6 6':'m10 6 6 6-6 6').'"/></svg>';
        $html.=($delta<0?$icon:'').'<span>'.$text.'</span>'.($delta>0?$icon:'').($disabled?'</span>':'</a>');
    }return $html.'</nav>';
}

/** Legacy lists already supply filtered URLs. Adapt only their list navigation. */
function pagination_legacy_markup(string $html):string {
    if(strpos($html,'<nav')===false||!preg_match('~^\s*(?:<!doctype html|<html)\b~i',$html))return $html;
    return preg_replace_callback('~<nav\b([^>]*class="[^"]*\b(?:legacy-actions|legacy-ledger-pagination|legacy-directory-pagination|lf-section-head)\b[^"]*"[^>]*)>(.*?)</nav>~s',static function($m){
        if(str_contains($m[1],'pagination-mobile'))return $m[0];
        $links=[];
        preg_match_all('~<a\b([^>]*)>(.*?)</a>~s',$m[2],$anchors,PREG_SET_ORDER);
        foreach($anchors as $anchor){
            $label=trim(html_entity_decode(strip_tags($anchor[2]),ENT_QUOTES,'UTF-8'));
            if(!in_array($label,['Sebelumnya','Berikutnya','Selanjutnya'],true))return $m[0];
            if(preg_match('~\bhref="([^"]*)"~',$anchor[1],$href))$links[$label==='Sebelumnya'?'prev':'next']=html_entity_decode($href[1],ENT_QUOTES,'UTF-8');
        }
        if(!$links)return $m[0];
        $page=isset($links['prev'])?2:1;$pages=isset($links['next'])?$page+1:$page;
        $pager=pagination_controls($page,$pages,static fn($target)=>$links[$target<$page?'prev':'next']);
        return $pager.'<nav '.preg_replace('~class="~','class="pagination-mobile ',$m[1],1).'>'.$m[2].'</nav>';
    },$html)??$html;
}

function render_pagination(string $path, array $query, int $page, int $totalPages, int $totalRows, int $perPage, string $label = 'data', string $pageParam = 'page'): void {
    if ($totalRows <= 0) return;
    $firstShown = (($page - 1) * $perPage) + 1;
    $lastShown = min($totalRows, $page * $perPage);
    [$windowStart, $windowEnd] = page_window($page, $totalPages);
    ?>
    <?= pagination_controls($page,$totalPages,static fn($target)=>page_url($path,$query,$target,$pageParam)) ?>
    <div class="du-pagination-footer pagination-mobile">
      <p class="du-pagination-info">Menampilkan <strong><?= number_format($firstShown) ?>&ndash;<?= number_format($lastShown) ?></strong> dari <strong><?= number_format($totalRows) ?></strong> <?= htmlspecialchars($label) ?></p>
      <?php if ($totalPages > 1): ?>
      <nav class="du-pagination" aria-label="Navigasi halaman">
        <?php if ($page > 1): ?>
          <a class="du-page-link du-page-desktop-only" href="<?= htmlspecialchars(page_url($path, $query, 1, $pageParam)) ?>">Awal</a>
          <a class="du-page-link du-page-prev" href="<?= htmlspecialchars(page_url($path, $query, $page - 1, $pageParam)) ?>" rel="prev">Sebelumnya</a>
        <?php else: ?>
          <span class="du-page-link du-page-desktop-only is-disabled" aria-disabled="true">Awal</span>
          <span class="du-page-link du-page-prev is-disabled" aria-disabled="true">Sebelumnya</span>
        <?php endif; ?>

        <span class="du-page-mobile-label">Halaman <?= $page ?> dari <?= $totalPages ?></span>
        <span class="du-page-numbers">
          <?php for ($pageNumber = $windowStart; $pageNumber <= $windowEnd; $pageNumber++): ?>
            <?php if ($pageNumber === $page): ?>
              <span class="du-page-link is-active" aria-current="page"><?= $pageNumber ?></span>
            <?php else: ?>
              <a class="du-page-link" href="<?= htmlspecialchars(page_url($path, $query, $pageNumber, $pageParam)) ?>"><?= $pageNumber ?></a>
            <?php endif; ?>
          <?php endfor; ?>
        </span>

        <?php if ($page < $totalPages): ?>
          <a class="du-page-link du-page-next" href="<?= htmlspecialchars(page_url($path, $query, $page + 1, $pageParam)) ?>" rel="next">Berikutnya</a>
          <a class="du-page-link du-page-desktop-only" href="<?= htmlspecialchars(page_url($path, $query, $totalPages, $pageParam)) ?>">Akhir</a>
        <?php else: ?>
          <span class="du-page-link du-page-next is-disabled" aria-disabled="true">Berikutnya</span>
          <span class="du-page-link du-page-desktop-only is-disabled" aria-disabled="true">Akhir</span>
        <?php endif; ?>
      </nav>
      <?php endif; ?>
    </div>
    <?php
}
