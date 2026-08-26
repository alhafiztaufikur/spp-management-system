<?php
function page_int_param(string $name, int $default = 1): int {
    $raw = $_GET[$name] ?? $default;
    return max(1, is_scalar($raw) ? (int)$raw : $default);
}

function page_size_param(string $name = 'per_page', array $allowed = [10, 25, 50], int $default = 10): int {
    $raw = $_GET[$name] ?? $default;
    $requested = is_scalar($raw) ? (int)$raw : $default;
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
    return $path . '?' . http_build_query($query);
}

function pagination_query(array $extra = [], string $pageParam = 'page'): array {
    $query = $_GET;
    unset($query[$pageParam]);
    return array_merge($query, $extra);
}

function render_pagination(string $path, array $query, int $page, int $totalPages, int $totalRows, int $perPage, string $label = 'data', string $pageParam = 'page'): void {
    if ($totalRows <= 0) return;
    $firstShown = (($page - 1) * $perPage) + 1;
    $lastShown = min($totalRows, $page * $perPage);
    [$windowStart, $windowEnd] = page_window($page, $totalPages);
    ?>
    <div class="du-pagination-footer">
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
