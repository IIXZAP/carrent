<?php
use App\Models\Setting;
$bank = Setting::get('bank_name'); $acct = Setting::get('bank_account_no'); $acctName = Setting::get('bank_account_name');
$pp = Setting::get('promptpay'); $shopPhone = Setting::get('phone');
if ($acct !== '' || $pp !== ''): ?>
<div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
  <p class="font-medium">ช่องทางโอนเงิน</p>
  <?php if ($acct !== ''): ?>
    <p class="mt-1"><?= e($bank) ?> เลขที่ <strong><?= e($acct) ?></strong><?= $acctName !== '' ? ' · ' . e($acctName) : '' ?></p>
  <?php endif; ?>
  <?php if ($pp !== ''): ?><p class="mt-1">พร้อมเพย์ <strong><?= e($pp) ?></strong></p><?php endif; ?>
  <?php if ($shopPhone !== ''): ?><p class="mt-1 text-slate-500">สอบถาม <?= e($shopPhone) ?></p><?php endif; ?>
</div>
<?php endif; ?>
