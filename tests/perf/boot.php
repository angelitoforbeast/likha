<?php

/**
 * Boots the app on the throwaway perf environment (.env.perf209: a local sqlite file under storage/perf209, no
 * queue, no outside keys). Never the real .env: the environment name is forced before Laravel reads any env file.
 */

putenv('APP_ENV=perf209');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'perf209';

$root = dirname(__DIR__, 2);
if (!is_file($root . '/.env.perf209')) {
    fwrite(STDERR, "Missing .env.perf209 at the project root (see the header of tests/perf/run.mjs).\n");
    exit(1);
}

require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (app()->environment() !== 'perf209' || config('database.default') !== 'sqlite' || !str_contains(str_replace('\\', '/', (string) config('database.connections.sqlite.database')), '/storage/perf209/')) {
    fwrite(STDERR, "Refusing to run: not the perf209 sqlite database.\n");
    exit(1);
}

return $app;
