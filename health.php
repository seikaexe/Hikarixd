<?php
/*
 Made By Seikagenou
 Telegram : t.me/seikagenou
 Mohon Untuk Tidak Menghapus, Mengedit, dll Watermark Di Dalam Kode Ini
 */
require __DIR__ . '/app/bootstrap.php';
$checks = health_checks();
$checks['rewrite'] = function_exists('apache_get_modules') ? in_array('mod_rewrite', apache_get_modules(), true) : 'cek melalui URL /api/endpoints';
$required = $checks;
$ok = !in_array(false, $required, true);
json_response(['status'=>$ok,'php'=>PHP_VERSION,'checks'=>$checks], $ok ? 200 : 500);
