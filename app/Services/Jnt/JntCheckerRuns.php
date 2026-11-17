<?php

namespace App\Services\Jnt;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Ang mga takbo ng J&T Order Checker (spec 283): isang upload = isang run (uuid).
 *
 * - Ang estado ng run (status, counts, may-ari) at ng Update nito ay nasa cache, 24 oras.
 * - Ang resulta (rows, not-in-Excel rows, updatable list) ay JSON file sa
 *   storage/app/jnt_checker/{uuid}.json, dahil ang live cache ay Redis na allkeys-lru: puwedeng
 *   mabura nang maaga ang malaking entry. 24 oras din; binubura ang luma tuwing may bagong run.
 * - Ang inupload na file ay nasa storage/app/jnt_checker/{uuid}/ habang tumatakbo ang job.
 *
 * Kapag wala na ang estado o ang file, "expired" ang run: magpa-upload ulit, hindi error page.
 */
class JntCheckerRuns
{
    public const TTL_SECONDS = 86400;

    /** Lampas dito, ang "running" na upload/update ay itinuturing na bigo (job timeout 600 s + palugit). */
    public const RUNNING_STALE_SECONDS = 900;

    public const STATUS_QUEUED  = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE    = 'done';
    public const STATUS_ERROR   = 'error';   // file problem: today's error message, e.g. missing column
    public const STATUS_FAILED  = 'failed';  // the job itself broke or timed out

    public static function baseDir(): string
    {
        return storage_path('app' . DIRECTORY_SEPARATOR . 'jnt_checker');
    }

    public static function isValidId(?string $id): bool
    {
        return is_string($id) && Str::isUuid($id);
    }

    public function create(int $userId, ?string $start = null, ?string $end = null): string
    {
        $this->prune();

        $id = (string) Str::uuid();
        $this->put($id, [
            'id'                => $id,
            'user_id'           => $userId,
            'filter_date_start' => $start,
            'filter_date_end'   => $end,
            'status'     => self::STATUS_QUEUED,
            'error'      => null,
            'success'    => null,
            'counts'     => null,
            'created_at' => now()->timestamp,
            'started_at' => null,
            'updated_at' => now()->timestamp,
        ]);

        return $id;
    }

    public function get(string $id): ?array
    {
        if (!self::isValidId($id)) return null;
        $state = Cache::get($this->key($id));

        return is_array($state) ? $state : null;
    }

    public function put(string $id, array $state): void
    {
        $state['updated_at'] = now()->timestamp;
        Cache::put($this->key($id), $state, self::TTL_SECONDS);
    }

    public function patch(string $id, array $changes): ?array
    {
        $state = $this->get($id);
        if ($state === null) return null;
        $state = array_merge($state, $changes);
        $this->put($id, $state);

        return $state;
    }

    // ---------------- Update state (isang Update lang kada run) ----------------

    /** true kung ito ang unang Update ng run; false kung may Update na (ibang click o tab). */
    public function claimUpdate(string $id): bool
    {
        return Cache::add($this->updateKey($id), [
            'status'     => self::STATUS_QUEUED,
            'count'      => null,
            'error'      => null,
            'created_at' => now()->timestamp,
            'started_at' => null,
        ], self::TTL_SECONDS);
    }

    /** Para sa pag-ulit pagkatapos ng bigong Update. */
    public function resetUpdate(string $id): void
    {
        Cache::put($this->updateKey($id), [
            'status'     => self::STATUS_QUEUED,
            'count'      => null,
            'error'      => null,
            'created_at' => now()->timestamp,
            'started_at' => null,
        ], self::TTL_SECONDS);
    }

    public function getUpdate(string $id): ?array
    {
        if (!self::isValidId($id)) return null;
        $state = Cache::get($this->updateKey($id));

        return is_array($state) ? $state : null;
    }

    public function patchUpdate(string $id, array $changes): void
    {
        $state = $this->getUpdate($id) ?? [
            'status' => self::STATUS_QUEUED, 'count' => null, 'error' => null,
            'created_at' => now()->timestamp, 'started_at' => null,
        ];
        Cache::put($this->updateKey($id), array_merge($state, $changes), self::TTL_SECONDS);
    }

    // ---------------- Files ----------------

    public function workDir(string $id): string
    {
        return self::baseDir() . DIRECTORY_SEPARATOR . $id;
    }

    public function resultPath(string $id): string
    {
        return self::baseDir() . DIRECTORY_SEPARATOR . $id . '.json';
    }

    public function writeResult(string $id, array $data): void
    {
        if (!is_dir(self::baseDir())) @mkdir(self::baseDir(), 0775, true);

        // PRESERVE_ZERO_FRACTION: 120.0 ay mananatiling float (gaya ng sa session dati).
        // INVALID_UTF8_SUBSTITUTE: hindi babagsak ang buong run dahil sa isang sirang character sa Excel.
        $json = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE
        );

        $tmp = $this->resultPath($id) . '.tmp';
        file_put_contents($tmp, $json, LOCK_EX);
        rename($tmp, $this->resultPath($id));
    }

    public function readResult(string $id): ?array
    {
        if (!self::isValidId($id)) return null;
        $path = $this->resultPath($id);
        if (!is_file($path)) return null;

        $data = json_decode((string) @file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    public function deleteWorkDir(string $id): void
    {
        $this->rrmdir($this->workDir($id));
    }

    /** Binubura ang mga result file at naiwang upload folder na lampas 24 oras. */
    public function prune(): void
    {
        $dir = self::baseDir();
        if (!is_dir($dir)) return;

        $cutoff = time() - self::TTL_SECONDS;
        foreach (new \DirectoryIterator($dir) as $entry) {
            if ($entry->isDot()) continue;
            if ($entry->getMTime() >= $cutoff) continue;

            if ($entry->isDir()) {
                $this->rrmdir($entry->getPathname());
            } elseif ($entry->isFile()) {
                @unlink($entry->getPathname());
            }
        }
    }

    private function key(string $id): string
    {
        return 'jnt_checker:run:' . $id;
    }

    private function updateKey(string $id): string
    {
        return 'jnt_checker:update:' . $id;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) @rmdir($file->getPathname());
            else @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
