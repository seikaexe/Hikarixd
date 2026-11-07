<?php
/*
 Made By Seikagenou
 Telegram : t.me/seikagenou
 Mohon Untuk Tidak Menghapus, Mengedit, dll Watermark Di Dalam Kode Ini
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/downloader.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = preg_replace('~^/api(?:\.php)?~', '', $path);
$path = '/' . trim($path, '/');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method !== 'GET') json_response(['status'=>false,'message'=>'Method tidak diizinkan'], 405, ['Allow'=>'GET']);

// /api/health tidak boleh bergantung pada MongoDB agar tetap bisa melaporkan status saat database bermasalah.
if ($path === '/health') {
    $checks = health_checks();
    $ok = !in_array(false, $checks, true);
    json_response(['status'=>$ok,'time'=>gmdate('c'),'checks'=>$checks], $ok ? 200 : 503);
}

$secretConfig = ensure_secrets();
$apiKey = getenv('API_KEY') ?: $secretConfig['apiKey'];
$stats = read_stats();

if ($path === '/endpoints') {
    $endpoints = array_map(static function($d) use ($stats) {
        $ep = $d['endpoint']; $ep['hits'] = (int)($stats['hits'][$ep['id']]['count'] ?? 0); return $ep;
    }, downloader_registry());
    json_response(['status'=>true,'endpoints'=>$endpoints]);
}
if ($path === '/stats') {
    $days = [];
    for ($i=29; $i>=0; $i--) $days[] = (int)($stats['daily'][app_day_key(time() - $i*86400)] ?? 0);
    json_response(['status'=>true,'total'=>(int)($stats['total']??0),'hits'=>$stats['hits']??[],'days'=>$days]);
}
if ($path === '/key') {
    // Tanpa akun: API key server bersifat publik dan dipakai bersama oleh semua pengunjung.
    json_response(['status'=>true,'apiKey'=>(string)$apiKey]);
}
if ($path === '/live') {
    $now = (int)(microtime(true)*1000); $visitor = hash_hmac('sha256', request_ip(), $secretConfig['secret']);
    $stats = update_stats(static function (&$s) use ($visitor, $now) {
        $s['visitors'][$visitor] = $now;
        if (!isset($s['up'])) $s['up'] = ['since'=>$now, 'last'=>0, 'days'=>[]];
        $last = (int)($s['up']['last'] ?? 0);
        if ($last > 0 && $now - $last <= 15000) {
            $day = app_day_key((int)($now / 1000));
            $s['up']['days'][$day] = (float)($s['up']['days'][$day] ?? 0) + (($now - $last) / 1000);
        }
        $s['up']['last'] = $now;
        $keepFrom = app_day_key((int)(($now / 1000) - 60 * 86400));
        foreach (($s['up']['days'] ?? []) as $day => $seconds) if ($day < $keepFrom) unset($s['up']['days'][$day]);
        foreach (($s['visitors'] ?? []) as $k=>$v) if ($v < $now - 30*86400000) unset($s['visitors'][$k]);
    });
    $active = count(array_filter($stats['visitors'] ?? [], static fn($v) => $now-(int)$v <= 120000));
    $days = []; $sum = 0; $expectedSum = 0; $since = (int)($stats['up']['since'] ?? $now);
    for ($i=29; $i>=0; $i--) {
        $key = app_day_key((int)(($now/1000) - $i*86400));
        $expected = max(0, min($now, (strtotime($key.' 23:59:59 UTC')+1)*1000) - max($since, strtotime($key.' 00:00:00 UTC')*1000));
        if ($expected < 1000) { $days[] = null; continue; }
        $up = min((int)($stats['up']['days'][$key] ?? 0)*1000, $expected);
        $sum += $up; $expectedSum += $expected; $days[] = round($up/$expected*100, 2);
    }
    json_response(['status'=>true,'now'=>$now,'total'=>(int)($stats['total']??0),'avgMs'=>!empty($stats['resp']['n'])?round($stats['resp']['sum']/$stats['resp']['n']):null,'respSamples'=>(int)($stats['resp']['n']??0),'activeUsers'=>$active,'uniqueUsers'=>count($stats['visitors']??[]),'today'=>(int)($stats['daily'][app_day_key()]??0),'uptime'=>['percent'=>$expectedSum?round($sum/$expectedSum*100,2):100,'days'=>$days,'runningSec'=>max(0,(int)(time()-($stats['up']['since']/1000))),'since'=>$since]]);
}

if (preg_match('~^/downloader/([a-z0-9-]+)$~i', $path, $m)) {
    $provided = (string)($_GET['apikey'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($provided === '' || !safe_equal($provided, $apiKey)) json_response(['status'=>false,'message'=>'API key tidak valid'], 401);
    $id = strtolower($m[1]); $started = microtime(true); $day = app_day_key();
    update_stats(static function (&$s) use ($id, $day) {
        $s['total'] = (int)($s['total']??0)+1;
        if (!isset($s['hits'][$id])) $s['hits'][$id] = ['count'=>0,'ok'=>0,'last'=>0];
        $s['hits'][$id]['count']++; $s['hits'][$id]['last'] = (int)(microtime(true)*1000);
        $s['daily'][$day] = (int)($s['daily'][$day]??0)+1;
    });
    register_shutdown_function(static function () use ($id, $started): void {
        $elapsed = (microtime(true)-$started)*1000;
        $success = http_response_code() < 400;
        update_stats(static function (&$s) use ($id, $elapsed, $success) {
            if (!isset($s['resp'])) $s['resp']=['sum'=>0,'n'=>0];
            if ($success) {
                $s['resp']['sum'] += $elapsed;
                $s['resp']['n']++;
                if (isset($s['hits'][$id])) $s['hits'][$id]['ok']++;
            }
        });
    });
    handle_download($id, $_GET);
}
json_response(['status'=>false,'message'=>'Endpoint tidak ditemukan'], 404);
