<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$admin = admin_require('super');

// ============================================================
// ДЕЙСТВИЯ
// ============================================================
$action = (string)($_GET['action'] ?? '');

// ---- Экспорт таблицы в CSV ----
if ($action === 'export') {
    $table = (string)($_GET['table'] ?? '');
    if (!admin_table_exists($table)) {
        http_response_code(404);
        exit('Таблица не найдена');
    }

    admin_audit((int)$admin['id'], 'db.export', 'table', null, ['table' => $table]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $table . '-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    // BOM для Excel
    fwrite($out, "\xEF\xBB\xBF");

    $stmt = db()->query("SELECT * FROM `$table`");
    $first = true;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($first) {
            fputcsv($out, array_keys($row), ';');
            $first = false;
        }
        fputcsv($out, array_values($row), ';');
    }
    fclose($out);
    exit;
}

// ---- Удаление строки ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check($_POST['csrf'] ?? null);
    $postAction = (string)($_POST['action'] ?? '');

    if ($postAction === 'delete_row') {
        $table = (string)($_POST['table'] ?? '');
        $pk    = (string)($_POST['pk'] ?? '');
        $pkVal = (string)($_POST['pk_val'] ?? '');

        if (!admin_table_exists($table)) {
            flash('Таблица не найдена', 'error');
            redirect(admin_url('database.php?table=' . urlencode($table)));
        }

        // Проверяем, что pk — реальная колонка этой таблицы
        $cols = admin_table_columns($table);
        $pkIsValid = false;
        foreach ($cols as $c) {
            if ($c['Field'] === $pk) { $pkIsValid = true; break; }
        }
        if (!$pkIsValid || $pk === '') {
            flash('Некорректный первичный ключ', 'error');
            redirect(admin_url('database.php?table=' . urlencode($table)));
        }

        try {
            $stmt = db()->prepare("DELETE FROM `$table` WHERE `$pk` = ? LIMIT 1");
            $stmt->execute([$pkVal]);
            admin_audit((int)$admin['id'], 'db.delete_row', 'table', null, [
                'table' => $table, 'pk' => $pk, 'pk_val' => $pkVal,
            ]);
            flash('Строка удалена', 'success');
        } catch (Throwable $e) {
            flash('Ошибка удаления: ' . $e->getMessage(), 'error');
        }
        redirect(admin_url('database.php?table=' . urlencode($table)));
    }
}

// ============================================================
// ДАННЫЕ
// ============================================================
$tables = admin_list_tables();

// Текущая таблица
$current = (string)($_GET['table'] ?? '');
$columns = [];
$rows = [];
$total = 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per'] ?? 50);
$perPage = max(10, min(200, $perPage));
$offset = ($page - 1) * $perPage;

$orderBy = (string)($_GET['order'] ?? '');
$orderDir = strtoupper((string)($_GET['dir'] ?? 'DESC'));
$orderDir = $orderDir === 'ASC' ? 'ASC' : 'DESC';

$searchCol = (string)($_GET['scol'] ?? '');
$searchVal = (string)($_GET['sval'] ?? '');

$primaryKey = null;

if ($current !== '' && admin_table_exists($current)) {
    $columns = admin_table_columns($current);

    // Ищем PRIMARY KEY
    foreach ($columns as $c) {
        if ($c['Key'] === 'PRI') { $primaryKey = $c['Field']; break; }
    }

    // Валидируем orderBy как реальную колонку
    if ($orderBy !== '') {
        $ok = false;
        foreach ($columns as $c) {
            if ($c['Field'] === $orderBy) { $ok = true; break; }
        }
        if (!$ok) $orderBy = '';
    }

    // Валидируем search-колонку
    if ($searchCol !== '') {
        $ok = false;
        foreach ($columns as $c) {
            if ($c['Field'] === $searchCol) { $ok = true; break; }
        }
        if (!$ok) $searchCol = '';
    }

    // WHERE
    $whereSql = '';
    $whereParams = [];
    if ($searchCol !== '' && $searchVal !== '') {
        $whereSql = "WHERE `$searchCol` LIKE ?";
        $whereParams[] = '%' . $searchVal . '%';
    }

    // COUNT
    try {
        $cnt = db()->prepare("SELECT COUNT(*) FROM `$current` $whereSql");
        $cnt->execute($whereParams);
        $total = (int)$cnt->fetchColumn();
    } catch (Throwable $e) {
        $total = 0;
    }

    // ORDER
    if ($orderBy !== '') {
        $orderSql = "ORDER BY `$orderBy` $orderDir";
    } elseif ($primaryKey !== null) {
        $orderSql = "ORDER BY `$primaryKey` $orderDir";
    } else {
        $orderSql = '';
    }

    // SELECT
    try {
        $sql = "SELECT * FROM `$current` $whereSql $orderSql LIMIT $perPage OFFSET $offset";
        $stmt = db()->prepare($sql);
        $stmt->execute($whereParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        flash('Ошибка выборки: ' . $e->getMessage(), 'error');
        $rows = [];
    }
}

$pageTitle = $current !== '' ? "Таблица: $current" : 'База данных';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-db">
    <aside class="admin-db__tables">
        <h3 class="admin-card__title">Таблицы (<?= count($tables) ?>)</h3>
        <input type="text" class="admin-db__search" id="table-search"
               placeholder="Фильтр таблиц..." autocomplete="off">
        <ul id="table-list">
            <?php foreach ($tables as $t): ?>
                <li data-name="<?= e(mb_strtolower($t['name'])) ?>">
                    <a class="<?= $t['name'] === $current ? 'is-active' : '' ?>"
                       href="?table=<?= urlencode($t['name']) ?>">
                        <span><?= e($t['name']) ?></span>
                        <small>
                            <?= admin_format_int((int)$t['rows']) ?>
                            · <?= e(admin_format_bytes((int)$t['size'])) ?>
                        </small>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </aside>

    <div class="admin-db__content">
        <?php if ($current === ''): ?>
            <div class="admin-empty">
                <h2 style="margin:0 0 8px">База данных</h2>
                <p>Выберите таблицу слева для просмотра и редактирования.</p>
                <p class="muted" style="margin-top:24px">
                    Всего таблиц: <strong><?= count($tables) ?></strong><br>
                    Общий размер: <strong><?= e(admin_format_bytes(array_sum(array_column($tables, 'size')))) ?></strong>
                </p>
            </div>
        <?php else: ?>

            <div class="admin-db__head">
                <div>
                    <h2><?= e($current) ?></h2>
                    <span class="muted"><?= admin_format_int($total) ?> строк</span>
                </div>
                <div class="admin-db__head-actions">
                    <a class="btn btn--ghost btn--sm"
                       href="?action=export&table=<?= urlencode($current) ?>">⬇ CSV</a>
                    <a class="btn btn--ghost btn--sm"
                       href="<?= e(admin_url('sql.php')) ?>">⌨ SQL-консоль</a>
                </div>
            </div>

            <form class="admin-filters" method="get">
                <input type="hidden" name="table" value="<?= e($current) ?>">
                <select name="scol">
                    <option value="">Поиск по колонке…</option>
                    <?php foreach ($columns as $c): ?>
                        <option value="<?= e($c['Field']) ?>"
                                <?= $searchCol === $c['Field'] ? 'selected' : '' ?>>
                            <?= e($c['Field']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="sval" value="<?= e($searchVal) ?>" placeholder="Значение">
                <select name="per">
                    <?php foreach ([25, 50, 100, 200] as $n): ?>
                        <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>>
                            <?= $n ?> на страницу
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn--primary">Применить</button>
                <?php if ($searchCol !== '' || $searchVal !== ''): ?>
                    <a class="btn btn--ghost" href="?table=<?= urlencode($current) ?>">Сбросить</a>
                <?php endif; ?>
            </form>

            <div class="admin-db__wrap">
                <table class="admin-table admin-table--compact">
                    <thead>
                        <tr>
                            <?php foreach ($columns as $c): ?>
                                <?php
                                    $isPk = $c['Key'] === 'PRI';
                                    $colName = $c['Field'];
                                    $nextDir = ($orderBy === $colName && $orderDir === 'DESC') ? 'ASC' : 'DESC';
                                    $url = '?' . http_build_query(array_merge($_GET, [
                                        'table' => $current,
                                        'order' => $colName,
                                        'dir' => $nextDir,
                                        'page' => 1,
                                    ]));
                                ?>
                                <th>
                                    <a href="<?= e($url) ?>" class="admin-th-sort">
                                        <?= e($colName) ?>
                                        <?php if ($isPk): ?><sup class="admin-pk" title="Primary Key">PK</sup><?php endif; ?>
                                        <?php if ($orderBy === $colName): ?>
                                            <span class="admin-sort-ind"><?= $orderDir === 'ASC' ? '▲' : '▼' ?></span>
                                        <?php endif; ?>
                                    </a>
                                    <div class="muted" style="font-size:10px;font-weight:400">
                                        <?= e($c['Type']) ?>
                                    </div>
                                </th>
                            <?php endforeach; ?>
                            <?php if ($admin['role'] === 'super'): ?>
                                <th style="width:40px"></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="<?= count($columns) + 1 ?>" class="muted"
                                style="text-align:center;padding:40px">
                                Нет данных
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <?php foreach ($columns as $c): ?>
                                    <?php
                                        $v = $r[$c['Field']] ?? null;
                                        $display = '';

                                        if ($v === null) {
                                            $display = '<span class="muted">NULL</span>';
                                        } else {
                                            $str = (string)$v;
                                            if (mb_strlen($str) > 80) {
                                                $str = mb_substr($str, 0, 80) . '…';
                                            }
                                            $display = e($str);
                                        }
                                    ?>
                                    <td><?= $display ?></td>
                                <?php endforeach; ?>
                                <?php if ($admin['role'] === 'super' && $primaryKey !== null): ?>
                                    <td>
                                        <form method="post" style="display:inline"
                                              onsubmit="return confirm('Удалить строку?')">
                                            <?= admin_csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_row">
                                            <input type="hidden" name="table" value="<?= e($current) ?>">
                                            <input type="hidden" name="pk" value="<?= e($primaryKey) ?>">
                                            <input type="hidden" name="pk_val"
                                                   value="<?= e((string)($r[$primaryKey] ?? '')) ?>">
                                            <button class="admin-link admin-link--danger" title="Удалить">🗑</button>
                                        </form>
                                    </td>
                                <?php elseif ($admin['role'] === 'super'): ?>
                                    <td></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php
                $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
                $from = $total > 0 ? $offset + 1 : 0;
                $to = min($offset + $perPage, $total);
            ?>
            <div class="admin-db__footer">
                <span class="muted">
                    Показано <?= $from ?>–<?= $to ?> из <?= admin_format_int($total) ?>
                </span>
                <?php if ($totalPages > 1): ?>
                    <nav class="admin-pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">‹</a>
                        <?php endif; ?>

                        <?php
                            $start = max(1, $page - 3);
                            $end = min($totalPages, $page + 5);
                            if ($start > 1) echo '<span class="pagination__ellipsis">…</span>';
                            for ($i = $start; $i <= $end; $i++):
                        ?>
                            <a class="<?= $i === $page ? 'is-active' : '' ?>"
                               href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($end < $totalPages) echo '<span class="pagination__ellipsis">…</span>'; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">›</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </div>
</div>

<script>
/* Фильтр таблиц по имени */
(function () {
    var input = document.getElementById('table-search');
    var list = document.getElementById('table-list');
    if (!input || !list) return;
    input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        list.querySelectorAll('li').forEach(function (li) {
            li.hidden = q !== '' && li.dataset.name.indexOf(q) === -1;
        });
    });
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>