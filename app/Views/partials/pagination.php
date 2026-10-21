<?php
/**
 * ตัวแบ่งหน้า (เลขหน้าเรียงต่อกัน)
 * ตัวแปร: page (หน้าปัจจุบัน), pages (จำนวนหน้า), query (พารามิเตอร์ GET เดิมที่ต้องคงไว้)
 */
if ($pages <= 1) {
    return;
}
?>
<div class="mt-4 flex flex-wrap justify-center gap-1.5">
  <?php for ($i = 1; $i <= $pages; $i++):
      $qs = ['page' => $i] + $query; $qs['page'] = $i; ?>
    <a class="btn btn-sm <?= $i === $page ? '' : 'btn-ghost' ?>" href="?<?= e(http_build_query($qs)) ?>"><?= $i ?></a>
  <?php endfor; ?>
</div>
