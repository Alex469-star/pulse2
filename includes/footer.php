</main>

<footer class="site-footer">
    <div class="container site-footer__inner">
        <div class="site-footer__brand">
            <span class="logo__mark">P</span>
            <span>Pulse</span>
        </div>
        <p class="site-footer__copy">© <?= date('Y') ?> Pulse. Все права защищены.</p>
        <div class="site-footer__links">
            <a href="<?= e(url('index.php')) ?>">О проекте</a>
            <a href="#">Приватность</a>
            <a href="#">Условия</a>
        </div>
    </div>
</footer>

<script src="<?= e(url('assets/js/main.js')) ?>"></script>
<?php foreach (($extraJs ?? []) as $js): ?>
    <script src="<?= e($js) ?>"></script>
<?php endforeach; ?>
<?php if (!empty($inlineJs)): ?>
    <script><?= $inlineJs ?></script>
<?php endif; ?>
</body>
</html>