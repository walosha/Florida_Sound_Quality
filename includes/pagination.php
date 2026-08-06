<?php
/**
 * Shared list pagination helpers (page + per_page).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** @var list<int> */
const PAGINATION_PER_PAGE_OPTIONS = [10, 25, 50, 100];

const PAGINATION_DEFAULT_PER_PAGE = 25;

/**
 * Parse page / per_page from a query array (defaults to $_GET).
 *
 * @param array<string, mixed>|null $query
 * @return array{page:int,per_page:int,offset:int}
 */
function paginationParams(?array $query = null): array
{
    $query ??= $_GET;

    $perPage = filter_var($query['per_page'] ?? PAGINATION_DEFAULT_PER_PAGE, FILTER_VALIDATE_INT);
    if ($perPage === false || !in_array($perPage, PAGINATION_PER_PAGE_OPTIONS, true)) {
        $perPage = PAGINATION_DEFAULT_PER_PAGE;
    }

    $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT);
    if ($page === false || $page < 1) {
        $page = 1;
    }

    return [
        'page'     => $page,
        'per_page' => $perPage,
        'offset'   => ($page - 1) * $perPage,
    ];
}

/**
 * Clamp page and compute display range from a total row count.
 *
 * @return array{total:int,page:int,per_page:int,total_pages:int,offset:int,from:int,to:int}
 */
function paginationMeta(int $total, int $page, int $perPage): array
{
    $total = max(0, $total);
    $perPage = in_array($perPage, PAGINATION_PER_PAGE_OPTIONS, true)
        ? $perPage
        : PAGINATION_DEFAULT_PER_PAGE;
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page < 1) {
        $page = 1;
    }
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $from = $total === 0 ? 0 : $offset + 1;
    $to = $total === 0 ? 0 : min($offset + $perPage, $total);

    return [
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => $totalPages,
        'offset'      => $offset,
        'from'        => $from,
        'to'          => $to,
    ];
}

/**
 * Run a COUNT query plus a data query with LIMIT/OFFSET.
 * $dataSql must not include LIMIT/OFFSET; integers are interpolated after validation.
 *
 * @param array<int|string, mixed> $params
 * @return array{rows:list<array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int,offset:int,from:int,to:int}
 */
function dbPaginate(string $countSql, string $dataSql, array $params, int $page, int $perPage): array
{
    $countRow = dbFetchOne($countSql, $params);
    $total = (int) ($countRow['cnt'] ?? $countRow['COUNT(*)'] ?? 0);
    $meta = paginationMeta($total, $page, $perPage);

    $limit = (int) $meta['per_page'];
    $offset = (int) $meta['offset'];
    $rows = dbFetchAll(
        $dataSql . " LIMIT {$limit} OFFSET {$offset}",
        $params
    );

    return ['rows' => $rows] + $meta;
}

/**
 * Build a URL for a pagination control, preserving other query params.
 *
 * @param array<string, scalar|null> $baseQuery
 */
function paginationUrl(string $path, array $baseQuery, int $page, int $perPage): string
{
    $query = $baseQuery;
    $query['page'] = $page;
    $query['per_page'] = $perPage;
    $qs = http_build_query($query);
    return $path . ($qs !== '' ? '?' . $qs : '');
}

/**
 * Render per-page select + prev/next pager.
 *
 * @param array{total:int,page:int,per_page:int,total_pages:int,from:int,to:int} $meta
 * @param array<string, scalar|null> $baseQuery Query params to keep (e.g. section)
 */
function renderPagination(array $meta, string $path, array $baseQuery = []): void
{
    $total = (int) $meta['total'];
    $page = (int) $meta['page'];
    $perPage = (int) $meta['per_page'];
    $totalPages = (int) $meta['total_pages'];
    $from = (int) $meta['from'];
    $to = (int) $meta['to'];

    if ($total === 0) {
        return;
    }

    static $pagerId = 0;
    $pagerId++;
    $selectId = 'per_page_' . $pagerId;

    $safePath = htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="list-pagination" role="navigation" aria-label="Pagination">
        <form class="list-pagination-per-page" method="get" action="<?= $safePath ?>">
            <?php foreach ($baseQuery as $key => $value): ?>
                <?php if ($value === null || $value === '') {
                    continue;
                } ?>
                <input type="hidden" name="<?= htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') ?>"
                       value="<?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?>">
            <?php endforeach; ?>
            <input type="hidden" name="page" value="1">
            <label for="<?= htmlspecialchars($selectId, ENT_QUOTES, 'UTF-8') ?>">Per page</label>
            <select id="<?= htmlspecialchars($selectId, ENT_QUOTES, 'UTF-8') ?>" name="per_page" onchange="this.form.submit()">
                <?php foreach (PAGINATION_PER_PAGE_OPTIONS as $opt): ?>
                    <option value="<?= $opt ?>"<?= $opt === $perPage ? ' selected' : '' ?>><?= $opt ?></option>
                <?php endforeach; ?>
            </select>
        </form>

        <p class="list-pagination-range">
            Showing <?= $from ?>–<?= $to ?> of <?= $total ?>
        </p>

        <div class="list-pagination-nav">
            <?php if ($page > 1): ?>
                <a class="btn-secondary"
                   href="<?= htmlspecialchars(paginationUrl($path, $baseQuery, $page - 1, $perPage), ENT_QUOTES, 'UTF-8') ?>">
                    Previous
                </a>
            <?php else: ?>
                <span class="btn-secondary is-disabled" aria-disabled="true">Previous</span>
            <?php endif; ?>

            <span class="list-pagination-page">Page <?= $page ?> of <?= $totalPages ?></span>

            <?php if ($page < $totalPages): ?>
                <a class="btn-secondary"
                   href="<?= htmlspecialchars(paginationUrl($path, $baseQuery, $page + 1, $perPage), ENT_QUOTES, 'UTF-8') ?>">
                    Next
                </a>
            <?php else: ?>
                <span class="btn-secondary is-disabled" aria-disabled="true">Next</span>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
