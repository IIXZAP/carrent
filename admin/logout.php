<?php
require_once __DIR__ . '/../includes/auth.php';
auth_logout();
start_session();
flash('ok', 'ออกจากระบบเรียบร้อย');
redirect('admin/login.php');
