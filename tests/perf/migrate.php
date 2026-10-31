<?php

/**
 * Builds the perf database from scratch: every migration file on its own, in order. Some migrations are MySQL-only
 * (they fail on sqlite); they are listed and skipped, the chatbot tables do not depend on them.
 * Run: php tests/perf/migrate.php
 */

$root = dirname(__DIR__, 2);
$db = $root . '/storage/perf209/db.sqlite';
@mkdir(dirname($db), 0777, true);
file_put_contents($db, '');

require __DIR__ . '/boot.php';

use Illuminate\Support\Facades\Artisan;

$failed = [];
foreach (glob($root . '/database/migrations/*.php') as $file) {
    try {
        $code = Artisan::call('migrate', ['--path' => $file, '--realpath' => true, '--force' => true]);
        if ($code !== 0) $failed[] = basename($file);
    } catch (Throwable $e) {
        $failed[] = basename($file) . ': ' . strtok($e->getMessage(), "\n");
    }
}
echo 'Migrations skipped: ' . count($failed) . "\n";
foreach ($failed as $f) echo '  ' . mb_substr($f, 0, 200) . "\n";
