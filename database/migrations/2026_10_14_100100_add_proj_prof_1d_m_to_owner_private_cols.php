<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ilagay ang PROF(1D)-M ('proj_prof_1d_m') kasunod agad ng 'proj_prof_1d' sa naka-save na order ng
 * /owner/private columns (app_settings owner_private_cols). Iba pang laman ng config, hindi ginagalaw:
 * hidden at visible_by_role ay pareho (kaya hindi ito makikita ng Marketing hangga't hindi tini-tick ng CEO).
 * Walang gagawin kapag walang naka-save na order, walang 'proj_prof_1d' doon, o nandiyan na ang id
 * (loadConfig na ang magdadagdag sa dulo).
 */
return new class extends Migration
{
    private const KEY = 'owner_private_cols';
    private const ID  = 'proj_prof_1d_m';

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
        if ($config === null || !is_array($config['order'] ?? null)) return;

        $order = array_values($config['order']);
        if (in_array(self::ID, $order, true)) return;
        $pos = array_search('proj_prof_1d', $order, true);
        if ($pos === false) return;

        array_splice($order, $pos + 1, 0, [self::ID]);
        $config['order'] = $order;

        $this->save($config);
    }

    public function down(): void
    {
        $config = $this->config();
        if ($config === null || !is_array($config['order'] ?? null)) return;

        $config['order'] = array_values(array_filter($config['order'], fn ($id) => $id !== self::ID));

        $this->save($config);
    }
};
