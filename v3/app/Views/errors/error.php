<div class="text-center py-16">
  <p class="text-5xl font-bold text-slate-300"><?= e($status) ?></p>
  <p class="mt-3"><?= e($message !== '' ? $message : 'เกิดข้อผิดพลาด') ?></p>
  <a class="btn btn-primary mt-6" href="<?= e(url('/')) ?>">กลับหน้าแรก</a>
</div>
