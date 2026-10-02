<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Itago ang CATEGORY column ng /item (owner_private column config sa app_settings).
 * Kapag may naka-save na config: idagdag ang 'category' sa `hidden` (isang beses) at alisin sa bawat
 * `visible_by_role` list. Walang naka-save = walang gagawin (DEFAULT_VISIBLE na ang magtatago).
 * Data at tables ng category, hindi ginagalaw.
 */
return new class extends Migration
{
    private const KEY = 'owner_private_cols';

    private function config(): ?array
    {
        if (!Schema::hasTable('app_settings')) return null;

        $value = DB::table('app_settings')->where('key', self::KEY)->value('value');
        $decoded = $value ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    private function save(array $config): void
    {
        DB::table('app_settings')->where('key', self::KEY)->update([
            'value'      => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    public function up(): void
    {
        $config = $this->config();
        if ($config === null) return;

        $hidden = is_array($config['hidden'] ?? null) ? $config['hidden'] : [];
        if (!in_array('category', $hidden, true)) $hidden[] = 'category';
        $config['hidden'] = array_values($hidden);

        if (is_array($config['visible_by_role'] ?? null)) {
            foreach ($config['visible_by_role'] as $role => $list) {
                if (is_array($list)) {
                    $config['visible_by_role'][$role] = array_values(array_filter($list, fn ($id) => $id !== 'category'));
                }
            }
        }

        $this->save($config);
    }

    /** 'category' lang ang inaalis sa `hidden`; hindi naibabalik ang role grants na inalis ng up(). */
    public function down(): void
    {
        $config = $this->config();
        if ($config === null || !is_array($config['hidden'] ?? null)) return;

        $config['hidden'] = array_values(array_filter($config['hidden'], fn ($id) => $id !== 'category'));

        $this->save($config);
    }
};
