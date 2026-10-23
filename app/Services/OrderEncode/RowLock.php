<?php

namespace App\Services\OrderEncode;

use Illuminate\Support\Facades\Cache;

/**
 * Ang lock ng isang macro_output row: hawak ng Astra on the spot, ng night run at ng update ng encode habang sumusulat sila sa row,
 * para walang dalawang sabay na sumusulat (ang huli ay hindi bumubura sa gawa ng nauna). Atomic lock ng cache (redis/database).
 */
final class RowLock
{
    /** Ang pinakamatagal na may hawak: ang Astra job (timeout 540 s) at ang encode (600 s). Ang lock ng namatay na worker ay nawawala pagkalipas nito. */
    public const SECONDS = 600;

    public static function key(int $id): string
    {
        return 'macro-row:' . $id;
    }

    /**
     * Patakbuhin ang $fn habang hawak ang row; naghihintay hanggang $wait segundo.
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException kapag hawak pa rin ng iba
     */
    public static function hold(int $id, int $wait, callable $fn): mixed
    {
        return Cache::lock(self::key($id), self::SECONDS)->block($wait, $fn);
    }
}
