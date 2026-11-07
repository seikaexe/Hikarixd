<?php
/*
 Made By Seikagenou
 Telegram : t.me/seikagenou
 Mohon Untuk Tidak Menghapus, Mengedit, dll Watermark Di Dalam Kode Ini
 */
declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) require_once $autoload;
$config = require dirname(__DIR__) . '/config/config.php';
date_default_timezone_set('Asia/Jakarta');

// Error tak tertangani (mis. MongoDB tidak bisa dihubungi) dibalas JSON yang jelas, bukan halaman kosong.
if (PHP_SAPI !== 'cli') {
    set_exception_handler(static function (\Throwable $e): void {
        error_log('[Hikarixd] ' . get_class($e) . ': ' . $e->getMessage());
        $unavailable = $e instanceof \RuntimeException;
        json_response([
            'status' => false,
            'message' => $unavailable ? 'Layanan database sedang tidak tersedia. Coba lagi beberapa saat.' : 'Terjadi kesalahan pada server.',
        ], $unavailable ? 503 : 500);
    });
}

function json_response(array $payload, int $status = 200, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($headers as $name => $value) header($name . ': ' . $value);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function read_json_file(string $file, array $default = []): array
{
    if (!is_file($file)) return $default;
    $raw = @file_get_contents($file);
    $value = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($value) ? $value : $default;
}

function write_json_file(string $file, array $value): void
{
    $tmp = $file . '.tmp';
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        error_log('[Hikarixd] Gagal menyimpan ' . basename($file));
    }
}

function app_config(): array { global $config; return $config; }
function app_data_dir(): string { return dirname(__DIR__) . '/data'; }

function app_day_key(?int $timestamp = null): string
{
    $offset = (int)(app_config()['timezone_offset_hours'] ?? 7);
    return gmdate('Y-m-d', ($timestamp ?? time()) + $offset * 3600);
}

function request_ip(): string
{
    // Do not trust arbitrary forwarded headers by default.
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function safe_equal(string $a, string $b): bool
{
    return hash_equals(hash('sha256', $a), hash('sha256', $b));
}


function mongo_client(): \MongoDB\Client
{
    static $client = null;
    if ($client instanceof \MongoDB\Client) return $client;
    if (!extension_loaded('mongodb') || !class_exists(\MongoDB\Client::class)) {
        throw new RuntimeException('MongoDB PHP extension/library belum tersedia.');
    }
    $uri = trim((string)(getenv('MONGODB_URI') ?: ''));
    if ($uri === '') throw new RuntimeException('MONGODB_URI belum diatur.');
    $client = new \MongoDB\Client($uri, [], [
        'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
    ]);
    return $client;
}

function mongo_db(): \MongoDB\Database
{
    $name = trim((string)(getenv('MONGODB_DATABASE') ?: 'hikarixd'));
    if (!preg_match('/^[A-Za-z0-9_-]{1,63}$/', $name)) throw new RuntimeException('MONGODB_DATABASE tidak valid.');
    return mongo_client()->selectDatabase($name);
}

function state_collection(): \MongoDB\Collection
{
    return mongo_db()->selectCollection('app_state');
}

function state_read(string $id, array $default = []): array
{
    $doc = state_collection()->findOne(['_id' => $id]);
    return is_array($doc) && is_array($doc['data'] ?? null) ? $doc['data'] : $default;
}

/** Optimistic revision check prevents concurrent requests silently overwriting each other. */
function state_update(string $id, callable $callback, array $default = []): array
{
    $collection = state_collection();
    for ($attempt = 0; $attempt < 12; $attempt++) {
        $doc = $collection->findOne(['_id' => $id]);
        $revision = (int)($doc['revision'] ?? 0);
        $data = is_array($doc['data'] ?? null) ? $doc['data'] : $default;
        $callback($data);
        if ($doc === null) {
            try {
                $collection->insertOne(['_id' => $id, 'revision' => 1, 'data' => $data]);
                return $data;
            } catch (\MongoDB\Driver\Exception\BulkWriteException $e) {
                continue;
            }
        }
        $result = $collection->updateOne(
            ['_id' => $id, 'revision' => $revision],
            ['$set' => ['data' => $data], '$inc' => ['revision' => 1]]
        );
        if ($result->getModifiedCount() === 1) return $data;
    }
    throw new RuntimeException('Penyimpanan sedang sibuk; silakan ulangi permintaan.');
}

function ensure_secrets(): array
{
    // Baca dulu: jangan menulis (dan menaikkan revision) di setiap request bila secret sudah ada.
    $current = state_read('config');
    if (!empty($current['secret']) && !empty($current['apiKey'])) return $current;
    return state_update('config', static function (array &$cfg): void {
        if (empty($cfg['secret'])) $cfg['secret'] = bin2hex(random_bytes(32));
        if (empty($cfg['apiKey'])) $cfg['apiKey'] = 'hikarixd-' . bin2hex(random_bytes(24));
    });
}

function stats_default(): array
{
    return [
        'total' => 0, 'hits' => [], 'daily' => [], 'resp' => ['sum' => 0, 'n' => 0],
        'visitors' => [], 'up' => ['since' => (int)(microtime(true) * 1000), 'last' => 0, 'days' => []]
    ];
}

function read_stats(): array { return state_read('stats', stats_default()); }

function update_stats(callable $callback): array
{
    return state_update('stats', $callback, stats_default());
}

function valid_public_url(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) return false;
    if (isset($parts['user']) || isset($parts['pass'])) return false;
    $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) return false;
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return (bool)filter_var($host, FILTER_VALIDATE_IP, $flags);
    }

    // Reject hostnames resolving to private, loopback, link-local, or reserved IP ranges.
    $ips = [];
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (is_array($records)) {
        foreach ($records as $record) {
            if (!empty($record['ip'])) $ips[] = $record['ip'];
            if (!empty($record['ipv6'])) $ips[] = $record['ipv6'];
        }
    }
    if (!$ips) {
        $fallback = @gethostbynamel($host);
        if (is_array($fallback)) $ips = $fallback;
    }
    if (!$ips) return false;
    foreach (array_unique($ips) as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) return false;
    }
    return true;
}

function resolve_redirect_url(string $baseUrl, string $location): string
{
    $location = trim($location);
    if ($location === '') return $baseUrl;
    if (preg_match('~^https?://~i', $location)) return $location;

    $base = parse_url($baseUrl);
    if (!$base || empty($base['scheme']) || empty($base['host'])) {
        throw new RuntimeException('URL redirect tidak valid.');
    }
    $origin = $base['scheme'] . '://' . $base['host']
        . (isset($base['port']) ? ':' . $base['port'] : '');
    if (str_starts_with($location, '//')) return $base['scheme'] . ':' . $location;

    $basePath = (string)($base['path'] ?? '/');
    if (str_starts_with($location, '?')) return $origin . $basePath . $location;
    if (str_starts_with($location, '#')) return $origin . $basePath . $location;

    $target = str_starts_with($location, '/')
        ? $location
        : substr($basePath, 0, (int)strrpos($basePath, '/') + 1) . $location;
    $suffix = '';
    $qPos = strpos($target, '?');
    $hPos = strpos($target, '#');
    $cut = min($qPos === false ? PHP_INT_MAX : $qPos, $hPos === false ? PHP_INT_MAX : $hPos);
    if ($cut !== PHP_INT_MAX) {
        $suffix = substr($target, $cut);
        $target = substr($target, 0, $cut);
    }

    $segments = [];
    foreach (explode('/', $target) as $segment) {
        if ($segment === '' || $segment === '.') continue;
        if ($segment === '..') array_pop($segments);
        else $segments[] = $segment;
    }
    return $origin . '/' . implode('/', $segments) . $suffix;
}

function http_request(string $url, array $options = []): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('Ekstensi PHP cURL belum aktif di hosting.');
    $currentUrl = $url;
    $method = strtoupper((string)($options['method'] ?? 'GET'));
    $bodyData = $options['body'] ?? '';
    $maxRedirects = min(5, max(0, (int)($options['max_redirects'] ?? 5)));

    for ($redirect = 0; $redirect <= $maxRedirects; $redirect++) {
        if (!valid_public_url($currentUrl)) {
            throw new RuntimeException('URL tujuan atau redirect tidak valid / tidak diizinkan.');
        }
        $ch = curl_init($currentUrl);
        $headers = $options['headers'] ?? [];
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => (int)($options['connect_timeout'] ?? 8),
            CURLOPT_TIMEOUT => (int)($options['timeout'] ?? app_config()['provider_timeout']),
            CURLOPT_USERAGENT => $options['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124 Safari/537.36',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function($curl, string $line) use (&$responseHeaders): int {
                $len = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                return $len;
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyData);
        }
        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($responseBody === false) {
            throw new RuntimeException('Koneksi provider gagal: ' . ($error ?: 'cURL error'));
        }

        $redirectStatus = in_array($status, [301, 302, 303, 307, 308], true);
        $location = (string)($responseHeaders['location'] ?? '');
        if ($redirectStatus && $location !== '') {
            if ($redirect >= $maxRedirects) throw new RuntimeException('Batas redirect provider terlampaui.');
            $currentUrl = resolve_redirect_url($currentUrl, $location);
            if (in_array($status, [301, 302, 303], true) && $method === 'POST') {
                $method = 'GET';
                $bodyData = '';
            }
            continue;
        }

        if ($status >= 400) throw new RuntimeException('Provider merespons HTTP ' . $status . '.');
        if ($effective !== '' && !valid_public_url($effective)) {
            throw new RuntimeException('Redirect provider tidak diizinkan.');
        }
        return ['body' => $responseBody, 'status' => $status, 'headers' => $responseHeaders, 'url' => $effective ?: $currentUrl];
    }

    throw new RuntimeException('Redirect provider gagal diproses.');
}

function http_json(string $url, array $options = []): array
{
    $r = http_request($url, $options);
    $json = json_decode($r['body'], true);
    if (!is_array($json)) throw new RuntimeException('Respons provider bukan JSON yang valid.');
    return $json;
}

function html_meta(string $html, string $name): ?string
{
    $q = preg_quote($name, '/');
    if (preg_match('/<meta[^>]+(?:property|name)=["\']' . $q . '["\'][^>]+content=["\']([^"\']+)/i', $html, $m)) return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . $q . '["\']/i', $html, $m)) return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return null;
}


// ===================== HEALTH =====================

function health_checks(): array
{
    return [
        'php_8_4' => PHP_VERSION_ID >= 80400,
        'curl' => extension_loaded('curl'),
        'json' => extension_loaded('json'),
        'openssl' => extension_loaded('openssl'),
        'mongodb' => (static function (): bool { try { mongo_db()->command(['ping' => 1]); return true; } catch (Throwable $e) { error_log('[Hikarixd] MongoDB health check failed: ' . $e->getMessage()); return false; } })(),
    ];
}
