<?php
/*
 Made By Seikagenou
 Telegram : t.me/seikagenou
 Mohon Untuk Tidak Menghapus, Mengedit, dll Watermark Di Dalam Kode Ini
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

$dryRun = in_array('--dry-run', $argv, true);
$dataDir = dirname(__DIR__) . '/data';
$imports = [
    'stats' => ['file' => $dataDir . '/stats.json', 'id' => 'stats', 'default' => stats_default()],
    'config' => ['file' => $dataDir . '/config.json', 'id' => 'config', 'default' => []],
];

echo "Hikarixd JSON → MongoDB migration\n";
echo $dryRun ? "MODE: DRY RUN (no writes)\n" : "MODE: IMPORT\n";

foreach ($imports as $name => $spec) {
    if (!is_file($spec['file'])) {
        echo "- {$name}: file tidak ditemukan, dilewati\n";
        continue;
    }

    $data = read_json_file($spec['file'], $spec['default']);
    echo "- {$name}: ditemukan " . count($data) . " top-level item";
    if ($dryRun) {
        echo " (dry-run)\n";
        continue;
    }

    state_update($spec['id'], static function (array &$current) use ($data): void {
        $current = $data;
    }, $spec['default']);
    echo " → imported\n";
}

if (!$dryRun) {
    mongo_db()->command(['ping' => 1]);
    echo "MongoDB ping: OK\n";
}
echo "Selesai. File JSON lama tidak dihapus dan tetap menjadi backup.\n";
