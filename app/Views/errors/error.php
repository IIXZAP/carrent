<div class="wrap py-16 text-center">
  <p class="text-5xl font-extrabold text-line"><?= e($status) ?></p>
  <p class="mt-3 text-ink-2"><?= e($message !== '' ? $message : 'เกิดข้อผิดพลาด') ?></p>
  <a class="btn btn-ghost mt-6" href="<?= e(url('/')) ?>">กลับหน้าแรก</a>
</div>
