<?php
use App\Models\Setting;
$bank = Setting::get('bank_name'); $acct = Setting::get('bank_account_no'); $acctName = Setting::get('bank_account_name');
$pp = Setting::get('promptpay'); $shopPhone = Setting::get('phone');
if ($acct !== '' || $pp !== ''): ?>
<div class="rounded-xl border border-line bg-surface p-4 text-sm">
  <div class="mb-1 font-bold text-dark">ช่องทางโอนเงิน</div>
  <?php if ($acct !== ''): ?><div><?= e($bank) ?> เลขที่ <b><?= e($acct) ?></b><?= $acctName !== '' ? ' · ' . e($acctName) : '' ?></div><?php endif; ?>
  <?php if ($pp !== ''): ?><div>พร้อมเพย์ <b><?= e($pp) ?></b></div><?php endif; ?>
  <?php if ($shopPhone !== ''): ?><div class="text-muted">สอบถาม <?= e($shopPhone) ?></div><?php endif; ?>
</div>
<?php endif; ?>
