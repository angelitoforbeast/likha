<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Ang returned source ayon sa app_settings.stock_returned_source (owner lang ang nagpapalit).
 * Wala o 'jnt' → J&T; 'nexus' → Nexus RTS; iba → J&T na may babala (hindi kailanman dalawa).
 */
class ReturnedSources
{
    public const SETTING = 'stock_returned_source';

    /** @return array{source: ReturnedSource, setting: string, warning: ?string} */
    public function current(): array
    {
        $raw = Schema::hasTable('app_settings')
            ? DB::table('app_settings')->where('key', self::SETTING)->value('value')
            : null;

        return $this->forSetting($raw);
    }

    /** @return array{source: ReturnedSource, setting: string, warning: ?string} */
    public function forSetting(?string $raw): array
    {
        if ($raw === null || $raw === 'jnt') {
            return ['source' => app(JntReturnedSource::class), 'setting' => 'jnt', 'warning' => null];
        }
        if ($raw === 'nexus') {
            return ['source' => app(NexusRtsReturnedSource::class), 'setting' => 'nexus', 'warning' => null];
        }

        Log::warning('item stock: unknown stock_returned_source, using jnt', ['value' => mb_substr($raw, 0, 40)]);

        return ['source' => app(JntReturnedSource::class), 'setting' => 'jnt', 'warning' => 'unknown_source'];
    }
}
