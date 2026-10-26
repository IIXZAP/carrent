<?php foreach (\App\Core\Flash::pull() as $f): ?>
  <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
<?php endforeach; ?>
