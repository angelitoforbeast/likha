<?php

namespace App\Services\Jnt;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Waybill labels kept on the local disk (spec 269): jnt_labels/{Y-m-d}/{mailno}.pdf, the decoded PDF of J&T's
 * BILLQUERY answer, under the day it was fetched (Asia/Manila). The file is the only record: it exists or it does
 * not. A file is always written under the mailno that was asked of J&T, which is the mailno J&T gave at booking.
 */
class JntLabelStore
{
    public const DIR = 'jnt_labels';

    public function keepDays(): int
    {
        return max(1, (int) config('jnt.labels.keep_days', 14));
    }

    /** A mailno that can be a file name (J&T mailnos are digits, sometimes letters); anything else is never stored. */
    public static function storable(string $mailno): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{1,64}$/', $mailno);
    }

    /** Absolute path of the kept label, newest day first, or null. */
    public function path(string $mailno): ?string
    {
        if (! self::storable($mailno)) {
            return null;
        }

        $day = $this->today();
        for ($d = 0; $d <= $this->keepDays(); $d++) {
            $abs = $this->disk()->path(self::DIR.'/'.$day->copy()->subDays($d)->toDateString()."/{$mailno}.pdf");
            if (is_file($abs) && filesize($abs) > 0) {
                return $abs;
            }
        }

        return null;
    }

    /** Keeps the label under today's folder; written to a temp name first, so a reader never sees half a file. */
    public function put(string $mailno, string $bytes): string
    {
        if (! self::storable($mailno)) {
            throw new \RuntimeException('mailno cannot be a label file name');
        }
        if (! str_starts_with($bytes, '%PDF')) {
            throw new \RuntimeException('label is not a PDF');
        }

        $dir = $this->disk()->path(self::DIR.'/'.$this->today()->toDateString());
        File::ensureDirectoryExists($dir);

        $abs = "{$dir}/{$mailno}.pdf";
        $tmp = "{$abs}.".Str::random(8).'.tmp';
        if (file_put_contents($tmp, $bytes) !== strlen($bytes) || ! rename($tmp, $abs)) {
            @unlink($tmp);
            throw new \RuntimeException('label file could not be written');
        }

        return $abs;
    }

    /** Removes every kept copy of the label (a file that does not parse). */
    public function forget(string $mailno): void
    {
        if (! self::storable($mailno)) {
            return;
        }
        foreach ($this->dayDirs() as $dir) {
            @unlink("{$dir}/{$mailno}.pdf");
        }
    }

    /** Deletes the day folders older than keep_days; returns the number of files removed. */
    public function prune(?int $days = null): int
    {
        $cutoff = $this->today()->subDays($days ?? $this->keepDays())->toDateString();
        $removed = 0;
        foreach ($this->dayDirs() as $day => $dir) {
            if ($day < $cutoff) {
                $removed += count(File::files($dir));
                File::deleteDirectory($dir);
            }
        }

        return $removed;
    }

    /**
     * The PDF bytes of a BILLQUERY answer for $mailno. Throws with the messages the print run always used, and
     * refuses an answer that names another waybill.
     */
    public static function pdfFromPrintResponse(array $json, string $mailno): string
    {
        foreach (['billCode', 'billcode', 'mailno'] as $key) {
            $named = data_get($json, "responseitems.{$key}") ?? data_get($json, "responseitems.0.{$key}");
            $norm = fn ($v) => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $v));
            if (is_scalar($named) && $norm($named) !== '' && $norm($named) !== $norm($mailno)) {
                throw new \RuntimeException("label answer is for {$named}");
            }
        }

        $b64 = data_get($json, 'responseitems.base64Url') ?? data_get($json, 'responseitems.0.base64Url');
        if (! $b64) {
            throw new \RuntimeException('no base64Url');
        }

        $bytes = base64_decode(preg_replace('/\s+/', '', (string) $b64), true);
        if ($bytes === false) {
            throw new \RuntimeException('invalid base64');
        }

        return $bytes;
    }

    /** @return array<string, string> Y-m-d => absolute folder */
    private function dayDirs(): array
    {
        $root = $this->disk()->path(self::DIR);
        if (! is_dir($root)) {
            return [];
        }

        $out = [];
        foreach (File::directories($root) as $dir) {
            $name = basename($dir);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $name)) {
                $out[$name] = $dir;
            }
        }

        return $out;
    }

    private function today(): Carbon
    {
        return now('Asia/Manila')->startOfDay();
    }

    private function disk()
    {
        return Storage::disk('local');
    }
}
