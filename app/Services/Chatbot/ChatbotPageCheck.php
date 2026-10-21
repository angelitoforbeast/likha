<?php

namespace App\Services\Chatbot;

use App\Http\Controllers\ChatbotFlowController;
use App\Models\ChatbotFlow;
use App\Models\ChatbotSequenceStep;
use App\Models\PageSetting;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang Check column ng /chatbot/pages: kung kumpleto at tama ang mga botfield ng isang page para sa flow nito.
 * - Kulang: ang botfield na ginagamit ng flow (bawat {{Name}} sa published na graph, at sa flow ng mga sequence na
 *   sinu-subscribe nito) o ng isang ginagamit na botfield, na walang value. Ang customer field at system variable ay hindi.
 * - Larawan: bawat image field na may value ay link na sumasagot ng larawan (ImageLinkCheck, hanggang 20 tawag kada takbo).
 * - Pares: kapag gamit ng flow ang Seq N at Seq Img N, ang may isa pero wala ang kapares.
 * - Hindi kilala: ang {{X}} sa isang botfield na hindi system variable, botfield o customer field.
 * Naka-cache 10 minuto kada page, sa key na may bakas ng settings ng page at ng mga flow: ang anumang sulat ay bagong key.
 */
class ChatbotPageCheck
{
    public const CACHE_SECONDS = 600;
    public const MAX_URL_CHECKS = 20;
    public const URL_SECONDS = 20; // ang kabuuang oras ng mga tawag sa link kada takbo: ang lampas ay "not checked yet", hindi timeout ng request
    private const MAX_FLOWS = 50; // ang flow at ang mga flow ng sequence nito

    public function __construct(private PageSettings $settings, private ChatbotFields $fields, private ImageLinkCheck $images)
    {
    }

    /** Ang naka-cache na resulta (walang bagong kuwenta), o null. $prints: mula sa prints(), para sa maraming page. */
    public function cached(string $page, ?array $prints = null): ?array
    {
        $hit = Cache::get($this->key($page, $prints));

        return is_array($hit) ? $hit : null;
    }

    /**
     * @return array{status: string, reasons: array<int, string>, unchecked: int, checked_at: string} status: ok | x | none (walang flow)
     */
    public function check(string $page, bool $fresh = false): array
    {
        $key = $this->key($page);
        if (!$fresh && ($hit = Cache::get($key)) && is_array($hit)) return $hit;
        $res = $this->run($page, $fresh);
        Cache::put($key, $res, self::CACHE_SECONDS);

        return $res;
    }

    /**
     * Ang bakas ng bawat page (pinakamataas na id at replaced_at ng settings nito) at ng mga flow at sequence, sa iilang query.
     * @return array{pages: array<string, string>, flows: string}
     */
    public function prints(?string $page = null): array
    {
        $q = $page === null ? PageSetting::query() : $this->settings->pageQuery($page);
        $pages = [];
        foreach ($q->whereIn('provider', [ChatbotFields::PROVIDER, 'botcake'])->selectRaw('page_name, MAX(id) AS max_id, MAX(replaced_at) AS max_replaced')->groupBy('page_name')->get() as $r) {
            $pk = $this->settings->pageKey((string) $r->page_name);
            $pages[$pk] = max($pages[$pk] ?? '', sprintf('%012d|%s', (int) $r->max_id, (string) $r->max_replaced));
        }
        $flows = [];
        foreach (['chatbot_flows', 'chatbot_sequences', 'chatbot_sequence_steps'] as $t) {
            if (Schema::hasTable($t)) $flows[] = DB::table($t)->max('updated_at') . '#' . DB::table($t)->count();
        }

        return ['pages' => $pages, 'flows' => implode('|', $flows)];
    }

    private function key(string $page, ?array $prints = null): string
    {
        $prints ??= $this->prints($page);
        $pk = $this->settings->pageKey($page);

        return 'chatbot:page-check:' . sha1($pk . "\0" . ($prints['pages'][$pk] ?? '') . "\0" . $prints['flows']);
    }

    private function run(string $page, bool $fresh): array
    {
        $out = ['status' => 'none', 'reasons' => [], 'unchecked' => 0, 'checked_at' => now('Asia/Manila')->format('Y-m-d H:i')];
        $flowId = trim((string) $this->settings->get($page, ChatbotFields::PROVIDER, 'flow_id'));
        $flow = ctype_digit($flowId) && Schema::hasTable('chatbot_flows') ? ChatbotFlow::find((int) $flowId) : null;
        if ($flow === null) return $out;

        $columns = $this->fields->columns();
        $byName = [];
        foreach ($columns as $c) {
            $byName[ChatbotVars::norm($c['key'])] = $c['key'];
            $byName[ChatbotVars::norm($c['label'])] = $c['key'];
        }
        $labels = array_column($columns, 'label', 'key');
        $values = [];
        foreach ($this->settings->pageQuery($page)->where('provider', ChatbotFields::PROVIDER)->where('active', true)->orderBy('id')->get() as $s) {
            if (!in_array($s->key, ChatbotFields::RESERVED, true)) $values[$s->key] = trim((string) $s->plainValue());
        }

        // Ginagamit: mula sa mga graph, saka ang botfield na binabanggit ng ginagamit na botfield (hanggang wala nang bago).
        $used = [];
        foreach ($this->graphs($flow) as $graph) {
            foreach (self::names($graph) as $n) if (isset($byName[$n])) $used[$byName[$n]] = true;
        }
        for ($todo = array_keys($used); $todo; ) {
            $key = array_shift($todo);
            foreach (self::names($values[$key] ?? '') as $n) {
                if (isset($byName[$n]) && !isset($used[$byName[$n]])) { $used[$byName[$n]] = true; $todo[] = $byName[$n]; }
            }
        }
        unset($used['flow']);

        $reasons = [];
        $missing = array_values(array_filter(array_keys($labels), fn ($k) => isset($used[$k]) && ($values[$k] ?? '') === ''));
        if ($missing) $reasons[] = 'Missing: ' . implode(', ', array_map(fn ($k) => $labels[$k], $missing));

        $calls = 0;
        $started = microtime(true);
        foreach ($columns as $c) {
            $url = $values[$c['key']] ?? '';
            if ($c['kind'] !== 'image' || $url === '') continue;
            $res = $fresh ? null : $this->images->cached($url);
            if ($res === null) {
                if ($calls >= self::MAX_URL_CHECKS || microtime(true) - $started > self::URL_SECONDS) { $out['unchecked']++; continue; }
                $calls++;
                $res = $this->images->check($url);
            }
            if (!$res['ok']) $reasons[] = $c['label'] . ': ' . $res['reason'];
        }

        $seqs = array_filter(array_keys($used), fn ($k) => preg_match('/^seq_\d+$/', $k));
        $imgs = array_filter(array_keys($used), fn ($k) => preg_match('/^seq_img_\d+$/', $k));
        if ($seqs && $imgs) {
            foreach ($columns as $c) {
                if (!preg_match('/^seq_(img_)?(\d+)$/', $c['key'], $m)) continue;
                $other = $m[1] ? 'seq_' . $m[2] : 'seq_img_' . $m[2];
                if (($values[$c['key']] ?? '') !== '' && ($values[$other] ?? '') === '' && !in_array($other, $missing, true)) {
                    $reasons[] = $c['label'] . ' has no ' . ($labels[$other] ?? $other);
                }
            }
        }

        $known = $this->knownNames($byName);
        foreach ($columns as $c) {
            $seen = [];
            if (!preg_match_all(ChatbotVars::PATTERN, $values[$c['key']] ?? '', $m)) continue;
            foreach ($m[1] as $raw) {
                $n = ChatbotVars::norm($raw);
                if (isset($known[$n]) || isset($seen[$n])) continue;
                $seen[$n] = true;
                $reasons[] = $c['label'] . ' uses {{' . trim($raw) . '}} (unknown)';
            }
        }

        return ['status' => $reasons ? 'x' : 'ok', 'reasons' => $reasons] + $out;
    }

    /** @return array<int, array> ang published na graph ng flow at ng mga flow ng sequence na sinu-subscribe nito */
    private function graphs(ChatbotFlow $flow): array
    {
        $graphs = [];
        $todo = [$flow];
        $seen = [$flow->id => true];
        $hasSteps = Schema::hasTable('chatbot_sequence_steps');
        while ($todo && count($graphs) < self::MAX_FLOWS) {
            $f = array_shift($todo);
            $graph = is_array($f->graph) ? $f->graph : [];
            $graphs[] = $graph;
            $seqIds = [];
            foreach ((array) ($graph['nodes'] ?? []) as $n) {
                $c = (array) ($n['config'] ?? []);
                if (($n['type'] ?? '') === 'sequence' && ($c['action'] ?? '') === 'subscribe' && (int) ($c['sequence_id'] ?? 0) > 0) $seqIds[] = (int) $c['sequence_id'];
            }
            if (!$seqIds || !$hasSteps) continue;
            $ids = ChatbotSequenceStep::whereIn('sequence_id', $seqIds)->where('active', true)->whereNotNull('flow_id')->pluck('flow_id')->all();
            foreach (ChatbotFlow::whereIn('id', array_diff($ids, array_keys($seen)))->get() as $next) {
                $seen[$next->id] = true;
                $todo[] = $next;
            }
        }

        return $graphs;
    }

    /**
     * Ang mga pangalang binabanggit: bawat {{X}} sa anumang text ng graph (o sa isang value), at ang variable ng isang
     * condition (maaaring walang {{ }}). Naka-norm, gaya ng ChatbotVars.
     * @return array<int, string>
     */
    public static function names(mixed $node): array
    {
        $out = [];
        if (is_string($node)) {
            if (preg_match_all(ChatbotVars::PATTERN, $node, $m)) foreach ($m[1] as $raw) $out[] = ChatbotVars::norm($raw);
            return $out;
        }
        if (!is_array($node)) return $out;
        foreach ($node as $k => $v) {
            if ($k === 'variable' && is_string($v)) $out[] = ChatbotVars::norm(trim($v, "{} \t"));
            else array_push($out, ...self::names($v));
        }

        return $out;
    }

    /**
     * Ang mga kilalang pangalan: system variable, botfield, at customer field (ang isinusulat ng anumang flow, at ang
     * naitala na sa chatbot_customer_values; ang huli ay naka-cache 10 minuto).
     * @return array<string, true>
     */
    private function knownNames(array $byName): array
    {
        $known = array_fill_keys(array_keys($byName), true);
        foreach (ChatbotFlowController::RUN_VARS as $v) $known[ChatbotVars::norm($v)] = true;
        if (!Schema::hasTable('chatbot_flows')) return $known;

        foreach (ChatbotFlow::get(['graph', 'draft_graph']) as $f) {
            foreach ([$f->graph, $f->draft_graph] as $g) {
                foreach ((array) ($g['nodes'] ?? []) as $n) {
                    $c = (array) ($n['config'] ?? []);
                    foreach ([$c['save_to'] ?? '', ...array_column((array) ($c['ops'] ?? []), 'field'), ...(array) ($c['fields'] ?? [])] as $field) {
                        if (is_string($field) && ($field = ChatbotVars::norm($field)) !== '') $known[$field] = true;
                    }
                }
            }
        }
        $stored = Cache::remember('chatbot:page-check:customer-keys', self::CACHE_SECONDS, fn () => Schema::hasTable('chatbot_customer_values')
            ? DB::table('chatbot_customer_values')->distinct()->limit(5000)->pluck('key')->all() : []);
        foreach ($stored as $k) if (!str_starts_with((string) $k, '__')) $known[ChatbotVars::norm((string) $k)] = true;

        return $known;
    }
}
