<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

$admin = admin_require('super');

$sql = (string)($_POST['sql'] ?? '');
$result = null;
$error = null;
$rows = [];
$columns = [];
$affected = 0;
$execMs = 0;
$allowDangerous = (bool)($_POST['dangerous'] ?? false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check($_POST['csrf'] ?? null);

    if ($sql !== '') {
        try {
            $start = microtime(true);

            // Проверяем тип запроса
            $firstWord = strtoupper(strtok(trim($sql), " \t\n\r("));
            $dangerous = ['DROP', 'TRUNCATE', 'ALTER', 'RENAME', 'GRANT', 'REVOKE'];

            if (in_array($firstWord, $dangerous, true) && !$allowDangerous) {
                throw new RuntimeException(
                    "Запросы типа $firstWord запрещены. Поставьте галочку «Разрешить опасные»."
                );
            }

            $stmt = db()->prepare($sql);
            $stmt->execute();

            // Если SELECT — показываем результаты
            if ($stmt->columnCount() > 0) {
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) $columns = array_keys($rows[0]);
                $result = 'SELECT';
            } else {
                $affected = $stmt->rowCount();
                $result = 'AFFECTED';
            }

            $execMs = round((microtime(true) - $start) * 1000, 2);

            admin_audit((int)$admin['id'], 'sql.query', null, null, [
                'sql' => mb_substr($sql, 0, 1000),
                'rows' => count($rows),
                'affected' => $affected,
                'ms' => $execMs,
            ]);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            admin_audit((int)$admin['id'], 'sql.error', null, null, [
                'sql' => mb_substr($sql, 0, 1000),
                'error' => $error,
            ]);
        }
    }
}

// Сохранённые запросы
$saved = db()->prepare('SELECT * FROM admin_saved_queries WHERE admin_id = ? OR is_shared = 1 ORDER BY name');
$saved->execute([(int)$admin['id']]);
$savedQueries = $saved->fetchAll();

$pageTitle = 'SQL-консоль';
require __DIR__ . '/includes/header.php';
?>

<div class="admin-sql">
    <form method="post" class="admin-sql__form">
        <?= admin_csrf_field() ?>
        <textarea name="sql" rows="8" placeholder="SELECT * FROM users LIMIT 10;" spellcheck="false"><?= e($sql) ?></textarea>
        <div class="admin-sql__actions">
            <label class="admin-checkbox">
                <input type="checkbox" name="dangerous" <?= $allowDangerous ? 'checked' : '' ?>>
                Разрешить опасные (DROP/ALTER/TRUNCATE)
            </label>
            <button class="btn btn--primary" type="submit">Выполнить</button>
        </div>
    </form>

    <?php if ($savedQueries): ?>
        <div class="admin-card">
            <h3 class="admin-card__title">Сохранённые запросы</h3>
            <ul class="admin-saved-queries">
                <?php foreach ($savedQueries as $sq): ?>
                    <li>
                        <button type="button" class="admin-link js-insert-sql"
                                data-sql="<?= e($sq['sql_text']) ?>">
                            <?= e($sq['name']) ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert--error"><strong>Ошибка:</strong> <?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($result === 'AFFECTED'): ?>
        <div class="alert alert--success">
            Затронуто строк: <strong><?= admin_format_int($affected) ?></strong>
            · <?= e($execMs) ?> ms
        </div>
    <?php endif; ?>

    <?php if ($result === 'SELECT'): ?>
        <div class="admin-card">
            <div class="admin-card__head">
                <h3 class="admin-card__title">Результат (<?= count($rows) ?> строк)</h3>
                <span class="muted"><?= e($execMs) ?> ms</span>
            </div>
            <?php if (!$rows): ?>
                <p class="muted">Пустой результат</p>
            <?php else: ?>
                <div class="admin-db__wrap">
                    <table class="admin-table admin-table--compact">
                        <thead>
                            <tr><?php foreach ($columns as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <?php foreach ($columns as $c): ?>
                                        <td><?= e($r[$c] === null ? 'NULL' : mb_substr((string)$r[$c], 0, 100)) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>