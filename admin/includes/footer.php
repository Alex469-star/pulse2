<?php
declare(strict_types=1);
?>
    </div><!-- /.admin-content -->
<?php if (admin_current()): ?>
</main><!-- /.admin-main -->
<?php else: ?>
</main>
<?php endif; ?>
<script src="<?= e(admin_url('assets/js/admin.js')) ?>"></script>
</body>
</html>