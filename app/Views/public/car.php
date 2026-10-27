<?php
/** รายละเอียดรถ + คำนวณราคา: $car $pickup $return $error $quote $catalog $selected $km $busy */
use App\Services\CarService;

$carName = $car['brand'] . ' ' . $car['model'];
\App\Core\View::layout('layouts/public', ['title' => $carName, 'desc' => 'เช่า ' . $carName . ' ราคาเริ่มต้น ' . money0($car['price_day']) . ' บาท/วัน', 'active' => 'cars']);
$bookUrl = $quote !== null ? url('/booking') . '?' . http_build_query([
    'car' => $car['id'], 'pickup' => input_datetime($pickup), 'return' => input_datetime($return),
    'addons' => $selected, 'km' => $km > 0 ? $km : '',
]) : '';
?>
<?php if ($quote !== null): \App\Core\View::start('mobile_bar'); ?>
<div class="fixed inset-x-0 bottom-0 z-[90] flex items-center gap-[9px] border-t border-line bg-white px-4 py-[9px] pb-[calc(9px+env(safe-area-inset-bottom))] shadow-[0_-4px_20px_rgba(22,33,46,.08)] lg:hidden print:hidden">
  <div class="min-w-0 flex-auto overflow-hidden">
    <b class="block truncate text-[1.05rem] leading-tight text-dark"><?= e(money0($quote['grand_total'])) ?> บาท</b>
    <span class="block truncate text-[0.72rem] text-muted"><?= (int) $quote['days'] ?> วัน · มัดจำ <?= e(money0($quote['deposit_amount'])) ?> บ.</span>
  </div>
  <a class="btn flex-none" href="<?= e($bookUrl) ?>">จองคันนี้ <?= icon('arrow') ?></a>
</div>
<?php \App\Core\View::end(); endif; ?>

<div class="wrap pt-3.5">
  <p class="mb-4 text-sm text-muted"><a href="<?= e(url('/cars')) ?>">รถเช่าของเรา</a> / <?= e($carName) ?></p>
</div>

<div class="wrap pb-10">
  <div class="grid items-start gap-[18px] lg:grid-cols-[minmax(0,1.9fr)_minmax(0,1fr)] lg:gap-6">
    <div class="min-w-0">
      <div class="panel">
        <?php
        $gallery = [];
        if (upload_exists($car['cover_image'] ?? null)) {
            $gallery[] = $car['cover_image'];
        }
        foreach ($images as $im) {
            if (upload_exists($im['path'])) {
                $gallery[] = $im['path'];
            }
        }
        ?>
        <div class="mb-3.5 overflow-hidden rounded-xl bg-white">
          <?php if ($gallery): ?>
            <img id="mainPhoto" class="h-auto w-full object-contain" src="<?= e(upload_url($gallery[0])) ?>" alt="<?= e($carName) ?>" width="900" height="640" fetchpriority="high">
          <?php else: ?>
            <div class="grid aspect-[4/3] place-items-center bg-surface text-muted"><?= icon('car', 'h-10 w-10 opacity-50') ?></div>
          <?php endif; ?>
        </div>
        <?php if (count($gallery) > 1): ?>
          <div class="scroll-hide mb-3.5 flex gap-2 overflow-x-auto" id="thumbs">
            <?php foreach ($gallery as $i => $path): ?>
              <button type="button" data-src="<?= e(upload_url($path)) ?>" aria-label="รูปที่ <?= $i + 1 ?>"
                      class="h-[58px] w-[78px] flex-none cursor-pointer overflow-hidden rounded-lg border-2 bg-white p-0 <?= $i === 0 ? 'border-accent' : 'border-line' ?>">
                <img class="h-full w-full object-cover" src="<?= e(upload_url($path)) ?>" alt="" loading="lazy">
              </button>
            <?php endforeach; ?>
          </div>
          <script>
          (function () {
            var main = document.getElementById('mainPhoto'), box = document.getElementById('thumbs');
            if (!main || !box) return;
            box.addEventListener('click', function (e) {
              var b = e.target.closest('button[data-src]');
              if (!b) return;
              main.src = b.dataset.src;
              box.querySelectorAll('button').forEach(function (x) { x.classList.replace('border-accent', 'border-line'); });
              b.classList.replace('border-line', 'border-accent');
            });
          })();
          </script>
        <?php endif; ?>

        <div class="eyebrow"><?= e($car['type_label']) ?></div>
        <h1 class="mb-[0.2em]"><?= e($carName) ?> <?= e($car['year'] ?: '') ?></h1>
        <p class="text-sm text-muted"><?= $car['color'] ? 'สี' . e($car['color']) . ' · ' : '' ?>ทะเบียน <?= e($car['plate']) ?> <?= e($car['province']) ?></p>

        <div class="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-[0.8rem] text-ink-2">
          <span class="inline-flex items-center gap-1"><?= icon('seat', 'h-[14px] w-[14px] text-muted') ?><?= (int) $car['seats'] ?> ที่นั่ง</span>
          <span class="inline-flex items-center gap-1"><?= icon('gear', 'h-[14px] w-[14px] text-muted') ?><?= e(CarService::GEARS[$car['gear']] ?? $car['gear']) ?></span>
          <span class="inline-flex items-center gap-1"><?= icon('fuel', 'h-[14px] w-[14px] text-muted') ?><?= e(CarService::FUELS[$car['fuel']] ?? $car['fuel']) ?></span>
        </div>
        <?php if (!empty($car['description'])): ?><p class="mb-0 mt-4"><?= nl2br(e($car['description'])) ?></p><?php endif; ?>
      </div>

      <div class="panel mt-4">
        <h3>เรตค่าเช่า</h3>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>แบบ</th><th class="num">ราคา</th><th class="num">เฉลี่ย/วัน</th></tr></thead>
            <tbody>
              <tr><td>รายวัน</td><td class="num"><?= e(money0($car['price_day'])) ?></td><td class="num"><?= e(money0($car['price_day'])) ?></td></tr>
              <?php if ((float) $car['price_week'] > 0): ?>
                <tr><td>รายสัปดาห์ <span class="text-[0.79rem] text-muted">(7 วัน)</span></td><td class="num"><?= e(money0($car['price_week'])) ?></td><td class="num text-ok"><?= e(money0($car['price_week'] / 7)) ?></td></tr>
              <?php endif; ?>
              <?php if ((float) $car['price_month'] > 0): ?>
                <tr><td>รายเดือน <span class="text-[0.79rem] text-muted">(30 วัน)</span></td><td class="num"><?= e(money0($car['price_month'])) ?></td><td class="num text-ok"><?= e(money0($car['price_month'] / 30)) ?></td></tr>
              <?php endif; ?>
              <tr><td>เงินประกัน <span class="text-[0.79rem] text-muted">(คืนเมื่อคืนรถ)</span></td><td class="num"><?= e(money0($car['security_deposit'])) ?></td><td class="num">—</td></tr>
            </tbody>
          </table>
        </div>
        <p class="mb-0 mt-4 text-[0.79rem] text-muted">เช่ายาวระบบจะคิดเรตสัปดาห์/เดือนให้อัตโนมัติ เลือกยอดที่ถูกที่สุดให้เสมอ</p>
      </div>

      <?php if ($busy): ?>
      <div class="panel mt-4">
        <h3>ช่วงที่รถไม่ว่าง (3 เดือนข้างหน้า)</h3>
        <ul class="mb-0 mt-2 text-sm text-ink-2">
          <?php foreach ($busy as $b): ?><li><?= e(date('d/m/Y H:i', strtotime($b['start_at']))) ?> – <?= e(date('d/m/Y H:i', strtotime($b['end_at']))) ?></li><?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>

    <aside class="min-w-0 lg:sticky lg:top-[88px]" id="book">
      <div class="panel">
        <h2 class="mb-3">คำนวณราคา</h2>
        <form method="get" action="<?= e(url('/cars/' . $car['id'])) ?>#book">
          <div class="field">
            <label for="pickup">วัน–เวลารับรถ</label>
            <input type="datetime-local" id="pickup" name="pickup" value="<?= e(input_datetime($pickup)) ?>" required>
          </div>
          <div class="field">
            <label for="return">วัน–เวลาคืนรถ</label>
            <input type="datetime-local" id="return" name="return" value="<?= e(input_datetime($return)) ?>" required>
          </div>
          <?php if ($catalog): ?>
            <div class="mt-4 text-sm font-semibold text-ink-2">บริการเสริม</div>
            <?php foreach ($catalog as $code => $addon): ?>
              <label class="checkline"><input type="checkbox" name="addons[<?= e($code) ?>]" value="1" <?= isset($selected[$code]) ? 'checked' : '' ?>>
                <span><?= e($addon['name']) ?></span></label>
            <?php endforeach; ?>
            <div class="field mt-2">
              <label for="km">ระยะทางส่ง–รับรถจากกรุงเทพ (กม.)</label>
              <input type="number" id="km" name="km" min="0" step="1" value="<?= e($km > 0 ? $km : '') ?>">
              <div class="hint">กรอกเฉพาะเมื่อเลือกส่ง/รับรถถึงที่</div>
            </div>
          <?php endif; ?>
          <button class="btn btn-block mt-3"><?= icon('search') ?> คำนวณราคา</button>
        </form>

        <?php if ($error !== null): ?><div class="alert alert-danger mt-4"><?= e($error) ?></div><?php endif; ?>
        <?php if ($quote !== null): ?>
          <div class="mt-4"><?php require __DIR__ . '/../partials/quote_box.php'; ?></div>
          <a class="btn btn-accent btn-lg btn-block mt-4" href="<?= e($bookUrl) ?>"><?= icon('check') ?> จองรถคันนี้</a>
        <?php endif; ?>
      </div>
    </aside>
  </div>
</div>
