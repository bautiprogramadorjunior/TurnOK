        </main>
    </div>
</div>
<script>
window.TURNOK = {
    baseUrl: <?= json_encode(APP_URL, JSON_UNESCAPED_SLASHES) ?>,
    csrfToken: <?= json_encode(csrf_token()) ?>
};
</script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
<?php if (!empty($pageScript)): ?>
<script src="<?= e(url($pageScript)) ?>"></script>
<?php endif; ?>
</body>
</html>
