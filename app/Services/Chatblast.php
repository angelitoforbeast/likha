<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\BotcakeMessage;
use App\Models\PageSetting;
use Illuminate\Support\Facades\DB;

/**
 * /chatblast: ang listahan ng mga order update na ipapadala sa customer sa Botcake, at ang text nila.
 * Uri (kind) "delivering": ang order na ang waybill ay Delivering sa J&T (from_jnts.status = Delivering, walang RTS
 * reason; kapareho ng /jnt status) sa huling 7 araw (ts_date). Isang row kada waybill at uri; ready kapag may PSID ang
 * order at kumpleto ang Botcake settings ng page, kung hindi ay no_psid o no_config (kasama kung ano ang kulang).
 */
class Chatblast
{
    /** Default na text kada uri. Walang emoji: lumabas na "�" ang isa sa naunang test. */
    public const KINDS = [
        'delivering' => "Hello Ma'am/Sir {name}, Idedeliver po ngayon ang order niyo. Maraming salamat po sa pag-order, ingat po kayo at salamat ulit sa tiwala!",
    ];

    /** Ang kailangan ng page para makapagpadala (page_id para sa bawat API path). */
    public const REQUIRED = ['page_id', 'token', 'flow.shipping', 'field.message'];

    /** Ang mga status na hindi pa naipapadala: dito lang nagbabago ang text kapag pinalitan ang template. */
    public const UNSENT = ['ready', 'no_psid', 'no_config', 'failed', 'field_mismatch', 'skipped'];

    public const BUILD_DAYS = 7;
    public const MAX_TEMPLATE = 1000;

    public function __construct(private PageSettings $settings)
    {
    }

    public function template(string $kind): string
    {
        return (string) (AppSetting::get('chatblast.template.' . $kind) ?? self::KINDS[$kind] ?? '');
    }

    /** Bagong template ng uri; binubuo ulit ang text ng mga hindi pa naipapadala. @return int bilang ng nabago */
    public function setTemplate(string $kind, string $template): int
    {
        AppSetting::set('chatblast.template.' . $kind, $template);

        $changed = 0;
        BotcakeMessage::where('kind', $kind)->whereIn('status', self::UNSENT)->select('id', 'vars', 'text')
            ->chunkById(500, function ($rows) use ($template, &$changed) {
                foreach ($rows as $row) {
                    $text = $this->render($template, (array) $row->vars);
                    if ($text !== $row->text) $changed += BotcakeMessage::whereKey($row->id)->whereIn('status', self::UNSENT)->update(['text' => $text]);
                }
            });

        return $changed;
    }

    /** Ang text: {name}, {item}, {cod}, {waybill}, {page} mula sa order. */
    public function render(string $template, array $vars): string
    {
        $map = [];
        foreach (['name', 'item', 'cod', 'waybill', 'page'] as $k) $map['{' . $k . '}'] = trim((string) ($vars[$k] ?? ''));

        return trim(strtr($template, $map));
    }

    /**
     * "Kunin ang mga bago": nagdadagdag ng row para sa bawat Delivering na waybill sa huling 7 araw na wala pang row ng
     * uring ito, at sinusuri ulit ang mga no_psid / no_config (baka may PSID o settings na).
     * @return array{added:int, ready:int, no_psid:int, no_config:int, updated:int}
     */
    public function build(string $kind = 'delivering', ?int $userId = null): array
    {
        $out      = ['added' => 0, 'ready' => 0, 'no_psid' => 0, 'no_config' => 0, 'updated' => 0];
        $missing  = $this->missingByPage();
        $template = $this->template($kind);
        $since    = now('Asia/Manila')->subDays(self::BUILD_DAYS)->toDateString();
        $now      = now();
        $seen     = [];

        $this->deliveringOrders($since, $kind)->chunkById(500, function ($orders) use (&$out, &$seen, $missing, $template, $kind, $userId, $now) {
            $rows = [];
            foreach ($orders as $o) {
                $waybill = trim((string) $o->waybill);
                if ($waybill === '' || isset($seen[$waybill])) continue; // isang row kada waybill (kahit may kopya ang order)
                $seen[$waybill] = true;

                $page = $this->settings->normPage((string) $o->page);
                $vars = ['name' => trim((string) $o->fb_name) ?: trim((string) $o->receiver), 'item' => $o->item, 'cod' => $o->cod, 'waybill' => $waybill, 'page' => $page];
                [$status, $reason] = $this->readiness($o->botcake_psid, $missing[$this->settings->pageKey($page)] ?? self::REQUIRED);

                $rows[] = [
                    'page_name' => $page, 'macro_output_id' => $o->id, 'waybill' => $waybill, 'kind' => $kind,
                    'fb_name' => $o->fb_name, 'psid' => trim((string) $o->botcake_psid) ?: null, 'vars' => json_encode($vars),
                    'text' => $this->render($template, $vars), 'jnt_status' => 'Delivering', 'status' => $status, 'reason' => $reason,
                    'attempts' => 0, 'is_test' => false, 'dedupe_key' => $waybill . '|' . $kind, 'created_by' => $userId,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            if (!$rows) return;

            // insertOrIgnore: kapag sabay ang dalawang kuha, ang unique dedupe_key ang humaharang sa doble.
            $added = DB::table('botcake_messages')->insertOrIgnore($rows);
            $out['added'] += $added;
            if ($added === count($rows)) {
                foreach ($rows as $r) $out[$r['status']]++;
            }
        }, 'mo.id', 'id');

        $out['updated'] = $this->recheck($kind, $missing);

        return $out;
    }

    /** @return array{0:string, 1:?string} [status, dahilan] */
    private function readiness(?string $psid, array $missingKeys): array
    {
        if ($missingKeys) return ['no_config', 'walang ' . implode(', ', $missingKeys)];
        if (trim((string) $psid) === '') return ['no_psid', 'walang PSID ang order (hindi pa tugma sa Botcake customer)'];

        return ['ready', null];
    }

    /** Ang mga no_psid / no_config na maaaring ready na. @return int bilang ng nabago */
    private function recheck(string $kind, array $missing): int
    {
        $changed = 0;
        BotcakeMessage::where('kind', $kind)->where('is_test', false)->whereIn('status', ['no_psid', 'no_config'])
            ->chunkById(500, function ($rows) use (&$changed, $missing) {
                $psids = DB::table('macro_output')->whereIn('id', $rows->pluck('macro_output_id')->filter()->all())->pluck('botcake_psid', 'id');
                foreach ($rows as $row) {
                    $psid = trim((string) ($psids[$row->macro_output_id] ?? $row->psid)) ?: null;
                    [$status, $reason] = $this->readiness($psid, $missing[$this->settings->pageKey($row->page_name)] ?? self::REQUIRED);
                    if ($status === $row->status && $reason === $row->reason && $psid === $row->psid) continue;
                    $changed += BotcakeMessage::whereKey($row->id)->whereIn('status', ['no_psid', 'no_config'])
                        ->update(['status' => $status, 'reason' => $reason, 'psid' => $psid]);
                }
            });

        return $changed;
    }

    /**
     * Ang kulang na Botcake settings ng bawat page na may kahit isa (isang query). Ang page na wala rito: lahat kulang.
     * @return array<string, array<int, string>> pageKey => kulang na key
     */
    public function missingByPage(): array
    {
        $have = [];
        PageSetting::where('active', true)->where('provider', 'botcake')->whereIn('key', self::REQUIRED)->get(['page_name', 'key'])
            ->each(function ($s) use (&$have) { $have[$this->settings->pageKey($s->page_name)][$s->key] = true; });

        return array_map(fn ($keys) => array_values(array_filter(self::REQUIRED, fn ($k) => !isset($keys[$k]))), $have);
    }

    private function deliveringOrders(string $since, string $kind)
    {
        $col = fn (string $c) => DB::getDriverName() === 'pgsql' ? 'mo."' . $c . '"' : 'mo.`' . $c . '`';

        return DB::table('macro_output as mo')
            ->where('mo.ts_date', '>=', $since)
            ->whereNotNull('mo.waybill')->where('mo.waybill', '<>', '')
            ->whereExists(fn ($e) => $e->from('from_jnts as j')->whereColumn('j.waybill_number', 'mo.waybill')->where('j.status', 'Delivering')
                ->where(fn ($r) => $r->whereNull('j.rts_reason')->orWhereRaw("TRIM(j.rts_reason) = ''"))->selectRaw('1'))
            ->whereNotExists(fn ($e) => $e->from('botcake_messages as m')->whereColumn('m.waybill', 'mo.waybill')->where('m.kind', $kind)
                ->where('m.is_test', false)->selectRaw('1'))
            ->select('mo.id', 'mo.fb_name', 'mo.waybill', 'mo.botcake_psid')
            ->selectRaw($col('PAGE') . ' AS page, ' . $col('ITEM_NAME') . ' AS item, ' . $col('COD') . ' AS cod')
            ->selectSub(fn ($s) => $s->from('from_jnts as r')->whereColumn('r.waybill_number', 'mo.waybill')->selectRaw('MAX(r.receiver)'), 'receiver');
    }
}
