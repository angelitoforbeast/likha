<?php

namespace Tests\Feature\OrderEncode;

use App\Services\OrderEncode\LocationStore;

/** Ang LocationStore sa sariling folder ng test, para hindi magalaw ang totoong storage/app/order-encode. */
class FakeLocationStore extends LocationStore
{
    private string $dir;

    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'order-encode-test-' . bin2hex(random_bytes(4));
    }

    public function path(string $kind, string $sheetId): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $kind . '-' . preg_replace('/[^A-Za-z0-9_-]/', '', $sheetId) . '.json';
    }

    public function cleanup(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) @unlink($f);
        @rmdir($this->dir);
    }
}
