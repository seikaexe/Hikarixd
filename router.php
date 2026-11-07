<?php
/*
 Made By Seikagenou
 Telegram : t.me/seikagenou
 Mohon Untuk Tidak Menghapus, Mengedit, dll Watermark Di Dalam Kode Ini
 */
// Router untuk uji lokal:  php -S 127.0.0.1:8000 -t public router.php
// File statis di public/ (CSS, JS, gambar) dilayani langsung oleh server bawaan PHP.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$static = __DIR__ . '/public' . $path;
if ($path !== '/' && is_file($static) && pathinfo($static, PATHINFO_EXTENSION) !== 'php') {
    return false;
}
require __DIR__ . '/server/index.php';
