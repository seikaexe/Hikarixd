<?php
/*
 Made By Seikagenou
 Telegram : t.me/seikagenou
 Mohon Untuk Tidak Menghapus, Mengedit, dll Watermark Di Dalam Kode Ini
 */
declare(strict_types=1);

/*
 * Front controller Hikarixd.
 *
 * File ini SENGAJA tidak berada di folder public/. Di Vercel, isi public/ dapat
 * dideploy sebagai aset statis oleh CDN, dan file .php statis dikirim sebagai
 * application/octet-stream sehingga browser mengunduhnya sebagai "download".
 * Pada image Docker, Dockerfile menyalin file ini ke /app/public/index.php
 * (document root FrankenPHP). Lokasi file ini tidak mempengaruhi logikanya:
 * root proyek selalu satu tingkat di atas folder tempat file ini berada.
 */

$root = dirname(__DIR__);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = '/' . ltrim(is_string($path) && $path !== '' ? $path : '/', '/');

// 1) API JSON.
if ($path === '/api' || str_starts_with($path, '/api/')) {
    require $root . '/api.php';
    exit;
}

// 2) Akses langsung ke entry point PHP (/api.php, /health.php).
if (preg_match('~^/(api|health)\.php(?:/|$)~', $path, $m)) {
    require $root . '/' . $m[1] . '.php';
    exit;
}

// 3) Path yang memakai ekstensi file selain .html (mis. /missing.js, /favicon.ico)
//    berarti aset yang tidak ada. Jangan balas dengan HTML 200.
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
if ($ext !== '' && $ext !== 'html') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo "Not found\n";
    exit;
}

// 4) Selain itu: halaman utama (SPA) dari index.html di root proyek.
//    /, /docs, /health, /apikey, dst. ditangani JavaScript di sisi klien.
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Method tidak diizinkan\n";
    exit;
}

$page = $root . '/index.html';
if (!is_file($page) || !is_readable($page)) {
    error_log('[Hikarixd] index.html tidak ditemukan di ' . $page);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "index.html tidak ditemukan di server\n";
    exit;
}

http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache');
if ($method === 'GET') {
    readfile($page);
}
