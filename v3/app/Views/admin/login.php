<div class="max-w-sm mx-auto panel mt-8">
  <h1 class="text-xl font-bold">เข้าสู่ระบบหลังบ้าน</h1>
  <form method="post" action="<?= e(url('/admin/login')) ?>" class="mt-6 space-y-4">
    <?= csrf_field() ?>
    <div>
      <label class="label" for="email">อีเมล</label>
      <input class="field" id="email" type="email" name="email" value="<?= e($email) ?>" required autofocus>
    </div>
    <div>
      <label class="label" for="password">รหัสผ่าน</label>
      <input class="field" id="password" type="password" name="password" required>
    </div>
    <button class="btn btn-primary w-full">เข้าสู่ระบบ</button>
  </form>
</div>
