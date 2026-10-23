<?php

namespace App\Services\OrderEncode;

/**
 * Ang LocationData ng mga sheet bilang file sa storage/app/order-encode (ayon sa ayos ng sheet, mahalaga sa "unang candidate"):
 * macro-<id>.json = A, B, C, D ng macro sheet; after-<id>.json = B, C, D ng AFTER MACRO. Gaya ng J&T list: file, hindi table.
 */
class LocationStore
{
    public const MACRO = 'macro';
    public const AFTER = 'after';

    /** @var array<string, array{0: int, 1: array}> path => [mtime, rows], para sa isang proseso ng worker */
    private static array $memo = [];

    public function path(string $kind, string $sheetId): string
    {
        return storage_path('app/order-encode/' . $kind . '-' . preg_replace('/[^A-Za-z0-9_-]/', '', $sheetId) . '.json');
    }

    /** @param array<int, array<int, string>> $rows */
    public function write(string $kind, string $sheetId, array $rows): void
    {
        $path = $this->path($kind, $sheetId);
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        rename($tmp, $path);
        unset(self::$memo[$path]);
    }

    /** @return array<int, array<int, string>>|null null kapag wala pang file */
    public function read(string $kind, string $sheetId): ?array
    {
        $path = $this->path($kind, $sheetId);
        if (!is_file($path)) return null;
        $mtime = (int) filemtime($path);
        if ((self::$memo[$path][0] ?? null) === $mtime) return self::$memo[$path][1];

        $rows = json_decode((string) file_get_contents($path), true);
        if (!is_array($rows)) return null;
        self::$memo = [$path => [$mtime, $rows]] + array_slice(self::$memo, 0, 3, true); // ilang file lang ang itinatago

        return $rows;
    }
}
