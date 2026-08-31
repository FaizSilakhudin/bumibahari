<?php
/**
 * Komponen pagination terpusat — satu tampilan untuk semua halaman list
 * (admin_pusat, admin_pic). Selalu ringkas 1 baris walau puluhan halaman:
 * jendela ±2 di sekitar halaman aktif + link halaman pertama/terakhir + "…".
 *
 * URL tiap halaman dibangun dari $_GET saat ini (filter/search apa pun yang
 * sudah aktif otomatis ikut terbawa), hanya parameter halaman yang diganti.
 */

if (!function_exists('render_pagination')) {
    function render_pagination(
        int $page,
        int $totalPages,
        ?array $info = null,
        string $pageParam = 'page',
        array $extraParams = []
    ): void {
        if ($totalPages <= 1) {
            return;
        }

        static $style_printed = false;
        if (!$style_printed) {
            $style_printed = true;
            ?>
            <style>
                .pg-bar{display:flex;flex-direction:column;gap:10px;align-items:center;justify-content:space-between;padding:14px 4px;}
                @media (min-width:768px){.pg-bar{flex-direction:row;}}
                .pg-info{color:#8f9bba;font-size:12.5px;font-weight:500;white-space:nowrap;}
                .pg-nav{display:flex;flex-wrap:nowrap;gap:4px;overflow-x:auto;max-width:100%;padding-bottom:2px;}
                .pg-nav .page-item{list-style:none;}
                .pg-nav .page-link{border-radius:8px!important;border:1px solid #e2e8f0;color:#4318ff;font-weight:700;font-size:13px;padding:6px 12px;min-width:34px;text-align:center;line-height:1.3;white-space:nowrap;display:block;text-decoration:none;background:#fff;transition:all .15s ease;}
                .pg-nav .page-link:hover{background:#f1f5f9;border-color:#cbd5e1;color:#4318ff;}
                .pg-nav .page-item.active .page-link{background:#4318ff;border-color:#4318ff;color:#fff;box-shadow:0 4px 10px -2px rgba(67,24,255,.4);}
                .pg-nav .page-item.disabled .page-link{color:#cbd5e1;background:#f8fafc;pointer-events:none;}
                .pg-nav .page-item.pg-ellipsis .page-link{border-color:transparent;background:transparent;color:#94a3b8;padding:6px 4px;min-width:auto;}
            </style>
            <?php
        }

        $urlFor = static function (int $p) use ($pageParam, $extraParams): string {
            return '?' . http_build_query(array_merge($_GET, $extraParams, [$pageParam => $p]));
        };

        $window = 2;
        $start  = max(1, $page - $window);
        $end    = min($totalPages, $page + $window);
        ?>
        <div class="pg-bar">
            <?php if ($info): ?>
                <div class="pg-info">
                    Menampilkan <b><?= (int) $info['from'] ?></b>&ndash;<b><?= (int) $info['to'] ?></b> dari <b><?= (int) $info['total'] ?></b> <?= h($info['label'] ?? 'data') ?>
                </div>
            <?php else: ?>
                <div class="pg-info">Halaman <?= $page ?> dari <?= $totalPages ?></div>
            <?php endif; ?>

            <nav>
                <ul class="pagination pg-nav mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $page > 1 ? h($urlFor($page - 1)) : '#' ?>"><i class="bi bi-chevron-left"></i></a>
                    </li>

                    <?php if ($start > 1): ?>
                        <li class="page-item"><a class="page-link" href="<?= h($urlFor(1)) ?>">1</a></li>
                        <?php if ($start > 2): ?>
                            <li class="page-item disabled pg-ellipsis"><span class="page-link">&hellip;</span></li>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= h($urlFor($i)) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($end < $totalPages): ?>
                        <?php if ($end < $totalPages - 1): ?>
                            <li class="page-item disabled pg-ellipsis"><span class="page-link">&hellip;</span></li>
                        <?php endif; ?>
                        <li class="page-item"><a class="page-link" href="<?= h($urlFor($totalPages)) ?>"><?= $totalPages ?></a></li>
                    <?php endif; ?>

                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $page < $totalPages ? h($urlFor($page + 1)) : '#' ?>"><i class="bi bi-chevron-right"></i></a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php
    }
}
