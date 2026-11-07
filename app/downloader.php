<?php
/*
 Made By Seikagenou
 Telegram : t.me/seikagenou
 Mohon Untuk Tidak Menghapus, Mengedit, dll Watermark Di Dalam Kode Ini
 */
declare(strict_types=1);

function extract_video_id(string $url): ?string
{
    return preg_match('~(?:youtube\.com/(?:[^/]+/.+/|(?:v|e(?:mbed)?|shorts|live)/|.*[?&]v=)|youtu\.be/)([\w-]{11})~i', $url, $m) ? $m[1] : null;
}

function result_ok(array $result): array { return ['status' => true, 'result' => $result]; }
function downloads_from_links(array $links, string $title, ?string $thumbnail = null, string $type = 'video'): array
{
    return result_ok(['title' => $title, 'thumbnail' => $thumbnail ?? '', 'type' => $type, 'downloads' => $links]);
}

function tiktok_api(string $url): array
{
    $data = http_json('https://www.tikwm.com/api/?hd=1&url=' . rawurlencode($url), ['timeout' => 18]);
    if (($data['code'] ?? -1) !== 0 || empty($data['data'])) throw new RuntimeException($data['msg'] ?? 'TikTok tidak dapat diproses.');
    $d = $data['data']; $links = [];
    foreach (['hdplay' => 'HD', 'play' => 'No Watermark', 'wmplay' => 'Watermark'] as $k => $label) {
        if (!empty($d[$k]) && filter_var($d[$k], FILTER_VALIDATE_URL)) $links[] = ['type' => 'video', 'quality' => $label, 'url' => $d[$k]];
    }
    if (!empty($d['images']) && is_array($d['images'])) foreach ($d['images'] as $i => $img) if (filter_var($img, FILTER_VALIDATE_URL)) $links[] = ['type' => 'image', 'quality' => 'Image ' . ($i + 1), 'url' => $img];
    if (!$links) throw new RuntimeException('TikTok tidak menyediakan media yang dapat diunduh.');
    return result_ok(['title' => $d['title'] ?? 'TikTok Media', 'thumbnail' => $d['cover'] ?? '', 'author' => $d['author'] ?? [], 'stats' => $d['play_count'] ?? null, 'type' => !empty($d['images']) ? 'image' : 'video', 'downloads' => $links]);
}

function instagram_api(string $url): array
{
    $body = http_build_query(['q' => preg_replace('/\?.*$/', '', $url), 'vt' => 'reel', 't' => 'media', 'lang' => 'en', 'v' => 'v2']);
    $r = http_request('https://indown.net/api/ajaxSearch', ['method' => 'POST', 'body' => $body, 'headers' => ['Content-Type: application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With: XMLHttpRequest', 'Origin: https://indown.net', 'Referer: https://indown.net/'], 'timeout' => 18]);
    $j = json_decode($r['body'], true);
    if (!is_array($j) || ($j['status'] ?? '') !== 'ok' || empty($j['data'])) throw new RuntimeException(strip_tags((string)($j['mess'] ?? 'Instagram gagal diproses; pastikan akun/postingan publik.')));
    $html = (string)$j['data']; $links = []; $thumb = '';
    if (preg_match_all('~<img[^>]+src=["\']([^"\']+)~i', $html, $ims) && !empty($ims[1][0])) $thumb = html_entity_decode($ims[1][0]);
    if (preg_match_all('~<a[^>]+href=["\'](https?://[^"\']+)["\'][^>]*>(.*?)</a>~is', $html, $ms, PREG_SET_ORDER)) {
        foreach ($ms as $m) {
            $href = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('~(cdninstagram|snapcdn|rapidcdn|download|\.mp4)~i', $href)) $links[] = ['type' => preg_match('~\.mp4|video|reel~i', strip_tags($m[2]) . $href) ? 'video' : 'image', 'url' => $href];
        }
    }
    if (!$links) throw new RuntimeException('Link media Instagram tidak ditemukan. Akun mungkin privat atau provider sedang berubah.');
    return downloads_from_links($links, 'Instagram Media', $thumb, 'media');
}

function facebook_api(string $url): array
{
    $body = http_build_query(['q' => $url, 'lang' => 'en', 'v' => 'v2']);
    $r = http_request('https://snapsave.app/action.php?lang=en', ['method' => 'POST', 'body' => $body, 'headers' => ['Content-Type: application/x-www-form-urlencoded', 'Origin: https://snapsave.app', 'Referer: https://snapsave.app/', 'X-Requested-With: XMLHttpRequest'], 'timeout' => 20]);
    $raw = $r['body']; $j = json_decode($raw, true); $html = is_array($j) ? (string)($j['data'] ?? $j['html'] ?? '') : $raw;
    $links = [];
    if (preg_match_all('~href=["\'](https?://[^"\']+)["\']~i', $html, $m)) foreach ($m[1] as $href) {
        $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('~(\.mp4|video|fbcdn|scontent)~i', $href)) $links[] = ['type' => 'video', 'url' => $href];
    }
    $links = array_values(array_unique($links, SORT_REGULAR));
    if (!$links) throw new RuntimeException('Facebook gagal diproses oleh provider. Coba video publik atau provider lain.');
    return downloads_from_links($links, 'Facebook Video', '', 'video');
}

function youtube_api(string $url, string $format = 'mp4', string $quality = '720p'): array
{
    $id = extract_video_id($url); if (!$id) throw new RuntimeException('URL YouTube tidak valid.');
    $canonical = 'https://www.youtube.com/watch?v=' . $id;
    $title = 'YouTube Video'; $thumb = 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg';
    try { $o = http_json('https://www.youtube.com/oembed?url=' . rawurlencode($canonical) . '&format=json', ['timeout' => 7]); $title = $o['title'] ?? $title; $thumb = $o['thumbnail_url'] ?? $thumb; } catch (Throwable $e) {}
    $headers = ['Origin: https://media.ytmp3.gg', 'Referer: https://media.ytmp3.gg/', 'Content-Type: application/json', 'Accept: application/json, text/plain, */*'];
    $payload = ['url' => $canonical, 'os' => 'macos', 'output' => ['type' => $format === 'mp3' ? 'audio' : 'video', 'format' => $format, 'quality' => $format === 'mp3' ? '' : $quality], 'audio' => ['bitrate' => '128k']];
    $init = http_json('https://hub.convert1s.com/api/download', ['method' => 'POST', 'body' => json_encode($payload), 'headers' => $headers, 'timeout' => 15]);
    if (empty($init['statusUrl']) || !valid_public_url((string)$init['statusUrl'])) throw new RuntimeException('Layanan konversi YouTube tidak memberikan status URL.');
    $download = null;
    for ($i = 0; $i < 14; $i++) {
        usleep(1_000_000);
        $poll = http_json($init['statusUrl'], ['headers' => $headers, 'timeout' => 8]);
        if (($poll['status'] ?? '') === 'completed' && !empty($poll['downloadUrl'])) { $download = $poll['downloadUrl']; break; }
        if (in_array(($poll['status'] ?? ''), ['failed', 'error'], true)) break;
    }
    if (!$download || !valid_public_url((string)$download)) throw new RuntimeException('Konversi YouTube belum menghasilkan link unduhan.');
    return downloads_from_links([['type' => $format === 'mp3' ? 'audio' : 'video', 'quality' => $format === 'mp3' ? '128kbps' : $quality, 'url' => $download]], $title, $thumb, $format === 'mp3' ? 'audio' : 'video');
}

function reddit_api(string $url): array
{
    $r = http_request('https://rapidsave.com/info?url=' . rawurlencode($url), ['headers' => ['Referer: https://rapidsave.com/'], 'timeout' => 18]);
    $html = $r['body']; $link = null;
    foreach (['~class=["\']downloadbutton["\'][^>]*href=["\']([^"\']+)~i', '~href=["\']([^"\']*sd\.rapidsave\.com/download\.php[^"\']*)~i'] as $pattern) if (preg_match($pattern, $html, $m)) { $link = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'); break; }
    if (!$link || !valid_public_url($link)) throw new RuntimeException('Reddit tidak dapat diproses oleh RapidSave.');
    $title = 'Reddit Video'; if (preg_match('~<h2[^>]*>(.*?)</h2>~is', $html, $m)) $title = trim(html_entity_decode(strip_tags($m[1])));
    return downloads_from_links([['type' => 'video', 'quality' => 'HD', 'url' => $link]], $title, html_meta($html, 'og:image'), 'video');
}

function twitter_api(string $url): array
{
    if (!preg_match('~status/(\d+)~', $url, $m)) throw new RuntimeException('URL X/Twitter tidak valid.');
    $j = http_json('https://api.fxtwitter.com/status/' . $m[1], ['timeout' => 15]);
    $tweet = $j['tweet'] ?? []; $media = $tweet['media']['videos'] ?? [];
    $links = [];
    foreach ($media as $v) foreach (array_merge([$v['url'] ?? null], (array)($v['urls'] ?? [])) as $u) if (is_string($u) && filter_var($u, FILTER_VALIDATE_URL)) $links[] = ['type' => 'video', 'url' => $u];
    foreach (($tweet['media']['photos'] ?? []) as $p) if (!empty($p['url'])) $links[] = ['type' => 'image', 'url' => $p['url']];
    if (!$links) throw new RuntimeException('Media X/Twitter tidak ditemukan.');
    return downloads_from_links($links, (string)($tweet['text'] ?? 'X Media'), (string)($tweet['media']['all'][0]['url'] ?? ''), 'media');
}

function mediafire_api(string $url): array
{
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host !== 'mediafire.com' && !str_ends_with($host, '.mediafire.com')) throw new RuntimeException('URL harus berasal dari mediafire.com.');
    $r = http_request($url, ['timeout' => 15]); $html = $r['body'];
    $link = null;
    if (preg_match('~(?:id="downloadButton"|class="input popsok")[^>]*href=["\']([^"\']+)~i', $html, $m)) $link = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (!$link && preg_match('~href=["\'](https?://download\d+\.mediafire\.com/[^"\']+)~i', $html, $m)) $link = html_entity_decode($m[1]);
    if (!$link || !valid_public_url($link)) throw new RuntimeException('Link unduhan MediaFire tidak ditemukan.');
    return downloads_from_links([['type' => 'file', 'url' => $link]], html_meta($html, 'og:title') ?? 'MediaFire File', html_meta($html, 'og:image'), 'file');
}

function generic_metadata(string $url, string $platform): array
{
    $r = http_request($url, ['timeout' => 15]); $html = $r['body'];
    $title = html_meta($html, 'og:title') ?? html_meta($html, 'twitter:title') ?? $platform . ' Media';
    $thumb = html_meta($html, 'og:image') ?? html_meta($html, 'twitter:image') ?? '';
    $video = html_meta($html, 'og:video') ?? html_meta($html, 'og:video:url');
    $links = [];
    if ($video && valid_public_url($video)) $links[] = ['type' => 'video', 'url' => $video];
    if (!$links && $thumb && valid_public_url($thumb)) $links[] = ['type' => 'image', 'url' => $thumb];
    if (!$links) throw new RuntimeException('Metadata media tidak ditemukan. Konten mungkin privat atau membutuhkan provider khusus.');
    return downloads_from_links($links, $title, $thumb, 'media');
}

function external_provider(string $key, string $url, array $options): ?array
{
    $map = app_config()['external_providers'] ?? [];
    $cfg = $map[$key] ?? [];
    $envSuffix = strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '_', $key));
    $endpoint = (string)(getenv('EXTERNAL_PROVIDER_' . $envSuffix . '_URL') ?: ($cfg['url'] ?? ''));
    $providerKey = (string)(getenv('EXTERNAL_PROVIDER_' . $envSuffix . '_KEY') ?: ($cfg['key'] ?? ''));
    if ($endpoint === '') return null;
    if (!valid_public_url($endpoint)) throw new RuntimeException('URL external provider tidak valid.');
    $payload = ['url' => $url, 'provider' => $key, 'options' => $options];
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($providerKey !== '') $headers[] = 'Authorization: Bearer ' . $providerKey;
    $j = http_json($endpoint, ['method' => 'POST', 'body' => json_encode($payload), 'headers' => $headers, 'timeout' => 30]);
    if (($j['status'] ?? false) !== true) throw new RuntimeException((string)($j['message'] ?? 'External provider gagal.'));
    return $j;
}

function call_provider(string $key, string $url, array $options = []): array
{
    $external = external_provider($key, $url, $options);
    if ($external !== null) return $external;
    return match (true) {
        str_starts_with($key, 'tiktok/') => tiktok_api($url),
        str_starts_with($key, 'instagram/') => instagram_api($url),
        str_starts_with($key, 'facebook/') => facebook_api($url),
        $key === 'youtube/playlist' => generic_metadata($url, 'youtube'),
        str_starts_with($key, 'youtube/') => youtube_api($url, strtolower((string)($options['format'] ?? 'mp4')), (string)($options['quality'] ?? '720p')),
        str_starts_with($key, 'reddit/') => reddit_api($url),
        str_starts_with($key, 'twitter/') => twitter_api($url),
        $key === 'resolver/mediafire' => mediafire_api($url),
        $key === 'resolver/unshorten' => (function() use ($url) { $r = http_request($url, ['timeout' => 15]); return result_ok(['url' => $r['url'], 'title' => 'Resolved URL']); })(),
        in_array($key, ['applemusic/aplmate', 'bandcamp/bandcampdownloader', 'pixiv/ajax', 'threads/threadster', 'soundcloud/klickaud', 'spotify/spotmate', 'spotify/spotidown', 'spotify/soundloaders', 'spotify/spotisaver', 'pinterest/pindown', 'terabox/sechno', 'resolver/sfile', 'resolver/safelinku', 'resolver/sub2unlock', 'resolver/rekonise'], true) => generic_metadata($url, explode('/', $key)[0]),
        default => throw new RuntimeException('Provider ini belum memiliki adapter PHP. Tambahkan external_providers di config/config.php untuk menghubungkan API pengganti.')
    };
}

function downloader_registry(): array
{
    $groups = [
        'applemusic' => ['aplmate'], 'bandcamp' => ['bandcampdownloader'], 'facebook' => ['snapsave','fdown'],
        'instagram' => ['indown','snapinsta','snapsave'], 'pinterest' => ['pindown'], 'pixiv' => ['ajax'],
        'reddit' => ['rapidsave'], 'soundcloud' => ['klickaud'], 'spotify' => ['spotmate','spotidown','soundloaders','spotisaver'],
        'terabox' => ['sechno'], 'threads' => ['threadster'], 'tiktok' => ['snaptik','tiktokio','savetik','ssstik','tikdownloader'],
        'twitter' => ['tweeload','tvd','savetwt'], 'youtube' => ['ytmp3','ytmp3gg'],
        'resolver' => ['sfile','safelinku','mediafire','sub2unlock','rekonise','unshorten'],
    ];
    $info = [
        'tiktok'=>['video/foto TikTok','https://www.tiktok.com/@username/video/1234567890'], 'instagram'=>['postingan/Reel Instagram','https://www.instagram.com/reel/EXAMPLE/'],
        'facebook'=>['video Facebook','https://www.facebook.com/watch/?v=1234567890'], 'youtube'=>['video/audio YouTube','https://www.youtube.com/watch?v=VIDEO_ID'],
        'twitter'=>['media Twitter/X','https://x.com/username/status/1234567890'], 'threads'=>['postingan Threads','https://www.threads.net/@username/post/POST_ID'],
        'pinterest'=>['gambar/video Pinterest','https://pin.it/EXAMPLE'], 'pixiv'=>['karya Pixiv','https://www.pixiv.net/en/artworks/12345678'],
        'reddit'=>['media Reddit','https://www.reddit.com/r/subreddit/comments/POST_ID/title/'], 'spotify'=>['lagu Spotify','https://open.spotify.com/track/TRACK_ID'],
        'soundcloud'=>['lagu SoundCloud','https://soundcloud.com/artist/track'], 'applemusic'=>['lagu Apple Music','https://music.apple.com/us/album/title/123456789'],
        'bandcamp'=>['lagu Bandcamp','https://artist.bandcamp.com/track/title'], 'terabox'=>['file TeraBox','https://www.terabox.com/s/SHARE_CODE'],
        'youtube-playlist'=>['playlist YouTube','https://www.youtube.com/playlist?list=PLAYLIST_ID']
    ];
    $separate = ['youtube/playlist' => 'youtube-playlist'];
    $groups['youtube'][] = 'playlist';
    $extra = ['youtube/ytmp3'=>['format'], 'youtube/ytmp3gg'=>['format','quality'], 'bandcamp/bandcampdownloader'=>['quality']];
    $out = [];
    foreach ($groups as $platform => $providers) {
        foreach ($providers as $provider) {
            $key = $platform . '/' . $provider;
            $id = $separate[$key] ?? ($platform === 'resolver' ? $provider : $platform);
            if (!isset($out[$id])) {
                $i = $info[$id] ?? [$id, 'https://example.com/'];
                $out[$id] = ['id'=>$id, 'providers'=>[], 'extras'=>[], 'endpoint'=>['id'=>$id,'method'=>'GET','path'=>'/api/downloader/'.$id,'category'=>'Downloader','desc'=>'Unduh '.$i[0],'params'=>[['name'=>'url','required'=>true,'example'=>$i[1],'desc'=>'Link '.$i[0]]]]];
            }
            $out[$id]['providers'][] = ['name'=>$provider,'key'=>$key];
            foreach (($extra[$key] ?? []) as $e) if (!in_array($e, $out[$id]['extras'], true)) $out[$id]['extras'][] = $e;
        }
    }
    foreach ($out as &$d) {
        foreach ($d['extras'] as $e) $d['endpoint']['params'][] = ['name'=>$e,'required'=>false,'example'=>$e==='format'?'mp4':'720p','desc'=>$e==='format'?'Format output: mp4 atau mp3':'Kualitas output'];
        if (count($d['providers']) > 1) $d['endpoint']['params'][] = ['name'=>'provider','required'=>false,'example'=>$d['providers'][0]['name'],'desc'=>'Pilih provider: '.implode(', ', array_column($d['providers'],'name')).' (otomatis jika kosong)'];
    }
    unset($d);
    return array_values($out);
}

function handle_download(string $id, array $query): never
{
    $url = trim((string)($query['url'] ?? ''));
    if ($url === '') json_response(['status'=>false,'message'=>'Parameter url wajib diisi'], 400);
    if (!valid_public_url($url)) json_response(['status'=>false,'message'=>'Parameter url harus berupa link http(s) publik yang valid'], 400);
    $registry = downloader_registry(); $downloader = null;
    foreach ($registry as $d) if ($d['id'] === $id) { $downloader = $d; break; }
    if (!$downloader) json_response(['status'=>false,'message'=>'Endpoint tidak ditemukan'], 404);
    $options = [];
    foreach ($downloader['extras'] as $e) {
        $v = trim((string)($query[$e] ?? ''));
        if ($v === '') continue;
        if ($e === 'format' && !preg_match('/^(mp4|mp3)$/i', $v)) json_response(['status'=>false,'message'=>'Parameter format tidak valid'], 400);
        if ($e === 'quality' && !preg_match('/^[\w.]{1,12}$/', $v)) json_response(['status'=>false,'message'=>'Parameter quality tidak valid'], 400);
        $options[$e] = strtolower($v);
    }
    $providers = $downloader['providers']; $wanted = strtolower(trim((string)($query['provider'] ?? '')));
    if ($wanted !== '') {
        $providers = array_values(array_filter($providers, fn($p) => strtolower($p['name']) === $wanted));
        if (!$providers) json_response(['status'=>false,'message'=>'Provider tidak tersedia. Pilihan: '.implode(', ', array_column($downloader['providers'],'name'))], 400);
    }
    $errors = []; $started = microtime(true);
    foreach ($providers as $provider) {
        if ((microtime(true) - $started) > (float)(app_config()['total_timeout'] ?? 55)) break;
        try {
            $data = call_provider($provider['key'], $url, $options);
            if (($data['status'] ?? false) === true) { $data['provider'] = $provider['name']; json_response($data); }
            $errors[] = ['provider'=>$provider['name'],'message'=>$data['message'] ?? 'Provider gagal.'];
        } catch (Throwable $e) {
            $errors[] = ['provider'=>$provider['name'],'message'=>strtok($e->getMessage(), "\n") ?: 'Provider gagal.'];
        }
    }
    json_response(['status'=>false,'message'=>count($errors)===1 ? $errors[0]['message'] : 'Semua provider gagal mengambil data.','errors'=>$errors], 502);
}
