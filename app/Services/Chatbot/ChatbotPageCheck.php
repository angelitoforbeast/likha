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
 * - Content (PageContentCheck): ang item, presyo, larawan at ad video laban sa item na ino-order; ang naitalang sagot ng AI
 *   lang (walang tawag dito). Ang tiyak na mali ay reason (X); ang hindi sigurado ay warning (amber).
 * Naka-cache kada page, sa key na mula sa sariling inputs ng page: ang bakas ng settings nito, ng flow nito at ng mga
 * flow, sequence at step na naaabot ng flow, ng sariling sagot ng content check, at ng mga pangalang kilala ng Check
 * (botfield, system variable, field na isinusulat ng anumang flow, customer field). Ang sulat sa isa sa mga iyon ay
 * bagong key para sa page na iyon lang, kaya ang edit ng isang flow ay hindi nagpapasuri ulit sa lahat ng page.
 * Ang iba (orders, ads, ad video, link ng larawan) ay sumusunod sa oras: isang oras, o 10 minuto habang may link na
 * hindi pa nasuri.
 */
class ChatbotPageCheck
{
    public const CACHE_SECONDS = 3600;
    public const UNCHECKED_SECONDS = 600; // may link ng larawan o content check na hindi pa tapos: susubukan ulit nang mas maaga
    private const CUSTOMER_KEYS_SECONDS = 600;
    public const MAX_URL_CHECKS = 20;
    public const URL_SECONDS = 20; // ang kabuuang oras ng mga tawag sa link kada takbo: ang lampas ay "not checked yet", hindi timeout ng request
    private const OPTIONAL = '/^(seq_(img_)?(1[1-9]|20)|after_order_(img_)?\d+)$/'; // hindi kailanman Missing: walang laman = walang ipapadala
    private const MAX_FLOWS = 50; // ang flow at ang mga flow ng sequence nito

    public function __construct(private PageSettings $settings, private ChatbotFields $fields, private ImageLinkCheck $images, private PageContentCheck $content)
    {
    }

    /** Ang naka-cache na resulta (walang bagong kuwenta), o null. $prints: mula sa prints(), para sa maraming page. */
    public function cached(string $page, ?array $prints = null): ?array
    {
        $prints ??= $this->prints($page);

        return $this->usable(Cache::get($this->key($page, $prints)), $prints);
    }

    /**
     * Ang naitalang resulta kung magagamit pa. Ang sagot ng AI sa larawan at ad video ay kahati ng ibang page (walang page
     * sa key ng sagot), kaya ang resultang may content check na hindi pa tapos ay sinusuri ulit kapag may bagong sagot
     * kahit saan, para hindi manatiling "pass" ang page habang may naitala nang X para dito.
     */
    private function usable(mixed $hit, array $prints): ?array
    {
        if (!is_array($hit) || !is_array($hit['result'] ?? null)) return null;
        $waits = (int) ($hit['result']['content']['unchecked'] ?? 0) > 0;

        return $waits && (int) $hit['answers'] < $prints['answers_max'] ? null : $hit['result'];
    }

    /**
     * @return array{status: string, reasons: array<int, string>, warnings: array<int, string>, content: ?array, unchecked: int, checked_at: string} status: ok | x | none (walang flow)
     */
    public function check(string $page, bool $fresh = false): array
    {
        $prints = $this->prints($page);
        $key = $this->key($page, $prints);
        if (!$fresh && ($hit = $this->usable(Cache::get($key), $prints)) !== null) return $hit;
        $res = $this->run($page, $fresh);
        $waits = $res['unchecked'] > 0 || (int) ($res['content']['unchecked'] ?? 0) > 0;
        Cache::put($key, ['result' => $res, 'answers' => $prints['answers_max']], $waits ? self::UNCHECKED_SECONDS : self::CACHE_SECONDS);

        return $res;
    }

    /**
     * Ang bakas ng sariling inputs ng bawat page (o ng isang page), sa iilang query: ang settings (pinakamataas na id at
     * replaced_at), ang flow nito kasama ang mga naaabot nito, ang sariling sagot ng content check, at ang mga kilalang
     * pangalan (iisa para sa lahat).
     * @return array{pages: array<string, string>, flows: array<string, string>, answers: array<string, string>, answers_max: int, known: string}
     */
    public function prints(?string $page = null): array
    {
        $scoped = fn () => $page === null ? PageSetting::query() : $this->settings->pageQuery($page);
        $pages = [];
        foreach ($scoped()->whereIn('provider', [ChatbotFields::PROVIDER, 'botcake', ForbeastPageSetup::PROVIDER])->selectRaw('page_name, MAX(id) AS max_id, MAX(replaced_at) AS max_replaced')->groupBy('page_name')->get() as $r) {
            $pk = $this->settings->pageKey((string) $r->page_name);
            $pages[$pk] = max($pages[$pk] ?? '', sprintf('%012d|%s', (int) $r->max_id, (string) $r->max_replaced));
        }

        // Ang flow ng page (ang pinakabagong active na flow_id, gaya ng get()) at ang bakas ng lahat ng naaabot nito.
        $flowPrints = $this->flowPrints();
        $deps = $flowPrints['deps'];
        $flows = [];
        foreach ($scoped()->where('provider', ChatbotFields::PROVIDER)->where('key', 'flow_id')->where('active', true)->orderBy('id')->get() as $s) {
            $id = trim((string) $s->plainValue());
            $flows[$this->settings->pageKey((string) $s->page_name)] = $id === '' ? '' : ($deps[(int) $id] ?? 'none:' . $id);
        }

        // Ang mga sagot ng content check ay itinatala kasama ang page na nagtanong.
        $answers = [];
        $answersMax = 0;
        if (Schema::hasTable('chatbot_content_answers')) {
            foreach (DB::table('chatbot_content_answers')->selectRaw('page_name, MAX(id) AS max_id, COUNT(*) AS n')->groupBy('page_name')->get() as $r) {
                $pk = $this->settings->pageKey((string) $r->page_name);
                $answers[$pk] = ($answers[$pk] ?? '') . $r->max_id . '#' . $r->n . ';';
                $answersMax = max($answersMax, (int) $r->max_id);
            }
        }

        $known = array_keys($this->knownNames(self::byName($this->fields->columns()), $flowPrints));
        sort($known);

        return ['pages' => $pages, 'flows' => $flows, 'answers' => $answers, 'answers_max' => $answersMax, 'known' => sha1(implode("\n", $known))];
    }

    private function key(string $page, ?array $prints = null): string
    {
        $prints ??= $this->prints($page);
        $pk = $this->settings->pageKey($page);

        return 'chatbot:page-check:' . sha1(implode("\0", [$pk, $prints['pages'][$pk] ?? '', $prints['flows'][$pk] ?? '', $prints['answers'][$pk] ?? '', $prints['known']]));
    }

    /**
     * Ang bakas ng bawat flow kasama ang lahat ng binabasa ng Check mula rito (ang mga flow na naaabot sa mga sequence na
     * sinu-subscribe nito, ang mga sequence at lahat ng step nila, at kung step ito ng anumang sequence), at ang mga field
     * na isinusulat ng anumang flow (bahagi ng mga kilalang pangalan). Kinukuwenta lang kapag may nagbago sa mga table ng
     * flow (MAX(updated_at) at COUNT), kaya iisang basa ng mga graph kada pagbabago.
     * @return array{deps: array<int, string>, saved: array<int, string>}
     */
    private function flowPrints(): array
    {
        if (!Schema::hasTable('chatbot_flows')) return ['deps' => [], 'saved' => []];
        $tables = [];
        foreach (['chatbot_flows', 'chatbot_sequences', 'chatbot_sequence_steps'] as $t) {
            $tables[] = Schema::hasTable($t) ? DB::table($t)->max('updated_at') . '#' . DB::table($t)->count() : '-';
        }

        return Cache::remember('chatbot:page-check:flow-prints:' . sha1(implode('|', $tables)), 86400, function () {
            $flows = ChatbotFlow::get(['id', 'graph', 'draft_graph', 'published_at', 'updated_at'])->keyBy('id');
            $hasSteps = Schema::hasTable('chatbot_sequence_steps');
            $steps = $hasSteps ? DB::table('chatbot_sequence_steps')->orderBy('id')->get()->groupBy('sequence_id') : collect();
            $asStep = $hasSteps ? DB::table('chatbot_sequence_steps')->whereNotNull('flow_id')->distinct()->pluck('flow_id')->map(fn ($id) => (int) $id)->flip() : collect();
            $seqRows = Schema::hasTable('chatbot_sequences') ? DB::table('chatbot_sequences')->get()->keyBy('id') : collect();

            $deps = [];
            foreach ($flows as $flow) {
                // Ang parehong lakad ng graphs(): ang published na graph, ang sequence na sinu-subscribe, ang active na step nito.
                $reached = [];
                $seqs = [];
                $todo = [$flow->id];
                while ($todo) {
                    $id = array_shift($todo);
                    if (isset($reached[$id])) continue;
                    $f = $flows[$id] ?? null;
                    $reached[$id] = $f ? [(string) $f->updated_at, (string) $f->published_at] : null;
                    foreach ((array) (($f && is_array($f->graph) ? $f->graph : [])['nodes'] ?? []) as $n) {
                        $c = (array) ($n['config'] ?? []);
                        if (($n['type'] ?? '') !== 'sequence' || ($c['action'] ?? '') !== 'subscribe' || ($seq = (int) ($c['sequence_id'] ?? 0)) <= 0 || isset($seqs[$seq])) continue;
                        $seqs[$seq] = [$seqRows[$seq] ?? null, $steps[$seq] ?? []];
                        foreach ($steps[$seq] ?? [] as $st) if ($st->active && $st->flow_id !== null) $todo[] = (int) $st->flow_id;
                    }
                }
                ksort($reached);
                ksort($seqs);
                $deps[$flow->id] = sha1(json_encode([$reached, $seqs, isset($asStep[$flow->id])]));
            }

            $saved = [];
            foreach ($flows as $f) {
                foreach ([$f->graph, $f->draft_graph] as $g) {
                    foreach ((array) ($g['nodes'] ?? []) as $n) {
                        $c = (array) ($n['config'] ?? []);
                        foreach ([$c['save_to'] ?? '', ...array_column((array) ($c['ops'] ?? []), 'field'), ...(array) ($c['fields'] ?? [])] as $field) {
                            if (is_string($field) && ($field = ChatbotVars::norm($field)) !== '') $saved[$field] = true;
                        }
                    }
                }
            }

            return ['deps' => $deps, 'saved' => array_keys($saved)];
        });
    }

    /**
     * Ang Comment PM at Comment Reply na mas mahaba sa tinatanggap ng Forbeast (hindi pinuputol: pupalya sa padala). Ang
     * value gaya ng naka-save (bago punan ang variable).
     * @return array<int, string>
     */
    private function commentLengths(string $page): array
    {
        $limits = [CommentReplies::PM => GatewayComments::MAX_PM] + array_fill_keys(CommentReplies::VARIANTS, GatewayComments::MAX_REPLY);
        $labels = ChatbotFields::defaults();
        $out = [];
        foreach ($limits as $key => $max) {
            $n = mb_strlen(trim((string) $this->settings->get($page, ChatbotFields::PROVIDER, $key)));
            if ($n > $max) $out[] = $labels[$key] . ' is ' . $n . ' characters: Forbeast sends at most ' . $max;
        }

        return $out;
    }

    /**
     * Ang Check, saka ang mga comment column (CommentCheck at ang naitalang sagot ng AI sa Comment PM): kita kahit walang
     * flow ang page, dahil ang sagot sa comment ay hindi kailangan ng flow. Ang info ay nasa notes (hindi babala).
     */
    private function run(string $page, bool $fresh): array
    {
        $res = $this->runFlow($page, $fresh) + ['notes' => []];
        foreach ($this->content->comments($page, false)['findings'] as $f) {
            match ($f['level']) {
                'x' => $res['reasons'][] = $f['reason'],
                'warn' => $res['warnings'][] = $f['reason'],
                'info' => $res['notes'][] = $f['reason'],
                default => null,
            };
        }
        if ($res['reasons']) $res['status'] = 'x';
        elseif ($res['status'] === 'none' && $res['warnings']) $res['status'] = 'ok';

        return $res;
    }

    private function runFlow(string $page, bool $fresh): array
    {
        $out = ['status' => 'none', 'reasons' => [], 'warnings' => $this->commentLengths($page), 'content' => null, 'unchecked' => 0, 'checked_at' => now('Asia/Manila')->format('Y-m-d H:i')];
        // Ang sagot sa comment ay hindi kailangan ng flow: ang babala ay kita kahit walang flow ang page.
        if ($out['warnings']) $out['status'] = 'ok';
        $flowId = trim((string) $this->settings->get($page, ChatbotFields::PROVIDER, 'flow_id'));
        $flow = ctype_digit($flowId) && Schema::hasTable('chatbot_flows') ? ChatbotFlow::find((int) $flowId) : null;
        // Ang FLOW (kapag On ang bot): walang flow, o wala o hindi published, = mali; kasama ang setup ng bot (chat.key),
        // dahil hindi na umaabot sa content check ang takbo.
        $on = $this->settings->get($page, ChatbotFields::PROVIDER, 'active') !== '0';
        $setup = $flow === null || $flow->published_at === null ? array_column($this->content->setup($page), 'reason') : [];
        if ($on && $flowId !== '' && ($flow === null || $flow->published_at === null)) {
            return ['status' => 'x', 'reasons' => ['Flow not found / not published', ...$setup]] + $out;
        }
        if ($flow === null) return $setup ? ['status' => 'x', 'reasons' => $setup] + $out : $out;

        $columns = $this->fields->columns();
        $byName = self::byName($columns);
        $labels = array_column($columns, 'label', 'key');
        $values = [];
        foreach ($this->settings->pageQuery($page)->where('provider', ChatbotFields::PROVIDER)->where('active', true)->orderBy('id')->get() as $s) {
            if (!in_array($s->key, ChatbotFields::RESERVED, true)) $values[$s->key] = trim((string) $s->plainValue());
        }
        $values[ShopDetails::KEY] = ShopDetails::build($page, $values); // binubuo: ang kulang na bahagi ay X ng content check

        $used = self::usedKeys($flow, $columns, $values);

        $reasons = [];
        if ($on && Schema::hasTable('chatbot_sequence_steps') && ChatbotSequenceStep::where('flow_id', $flow->id)->exists()) {
            $reasons[] = 'Flow "' . $flow->name . '" is a sequence step, not a main flow';
        }
        $missing = array_values(array_filter(array_keys($labels), fn ($k) => isset($used[$k]) && ($values[$k] ?? '') === '' && !preg_match(self::OPTIONAL, $k)));
        if ($missing) $reasons[] = 'Missing: ' . implode(', ', array_map(fn ($k) => $labels[$k], $missing));

        $calls = 0;
        $started = microtime(true);
        foreach ($columns as $c) {
            $url = $values[$c['key']] ?? '';
            if ($url === '') continue;
            // Maling uri ng value (kasama ang opsyonal na Seq 11-20): link lang sa text column, o hindi link sa image column.
            if ($c['kind'] !== 'image') {
                if ($c['kind'] !== 'flow' && preg_match('~^https?://\S+$~i', $url)) {
                    $img = preg_replace('/^(seq|after_order)_(\d+)$/', '$1_img_$2', $c['key']);
                    $reasons[] = $img !== $c['key'] && isset($labels[$img]) ? $c['label'] . ' is an image link: put it in ' . $labels[$img] : $c['label'] . ' is a link: this column needs text';
                }
                continue;
            }
            if (!preg_match('~^[a-z][a-z0-9+.-]*://\S+$~i', $url)) { $reasons[] = $c['label'] . ' is not an image link'; continue; }
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

        $content = $this->content->run($page, false);
        $warnings = $out['warnings'];
        foreach ($content['findings'] as $f) {
            if ($f['kind'] === 'comment') continue; // nasa run(), kasama ang page na walang flow
            if ($f['level'] === 'x') $reasons[] = $f['reason'];
            elseif ($f['level'] === 'warn') $warnings[] = $f['reason'];
        }

        return ['status' => $reasons ? 'x' : 'ok', 'reasons' => $reasons, 'warnings' => $warnings, 'content' => $content] + $out;
    }

    /**
     * Ang mga botfield na ginagamit ng flow: mula sa mga graph (ang flow at ang mga flow ng sequence nito), saka ang
     * botfield na binabanggit ng ginagamit na botfield sa $values ng page (hanggang wala nang bago). Ang FLOW ay hindi.
     * @param array<int, array{key: string, label: string}> $columns ChatbotFields::columns()
     * @param array<string, string> $values botfield key => value ng page
     * @return array<string, true>
     */
    public static function usedKeys(ChatbotFlow $flow, array $columns, array $values): array
    {
        $byName = self::byName($columns);
        $used = [];
        foreach (self::graphs($flow) as $graph) {
            foreach (self::names($graph) as $n) if (isset($byName[$n])) $used[$byName[$n]] = true;
        }
        for ($todo = array_keys($used); $todo; ) {
            $key = array_shift($todo);
            foreach (self::names($values[$key] ?? '') as $n) {
                if (isset($byName[$n]) && !isset($used[$byName[$n]])) { $used[$byName[$n]] = true; $todo[] = $byName[$n]; }
            }
        }
        unset($used['flow']);

        return $used;
    }

    /** Ang botfield key ayon sa pangalan (key o label, naka-norm), gaya ng hanap ng takbo. @return array<string, string> */
    private static function byName(array $columns): array
    {
        $byName = [];
        foreach ($columns as $c) {
            $byName[ChatbotVars::norm($c['key'])] = $c['key'];
            $byName[ChatbotVars::norm($c['label'])] = $c['key'];
        }

        return $byName;
    }

    /** @return array<int, array> ang published na graph ng flow at ng mga flow ng sequence na sinu-subscribe nito */
    private static function graphs(ChatbotFlow $flow): array
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
    private function knownNames(array $byName, ?array $flowPrints = null): array
    {
        $known = array_fill_keys(array_keys($byName), true);
        foreach (ChatbotFlowController::RUN_VARS as $v) $known[ChatbotVars::norm($v)] = true;
        if (!Schema::hasTable('chatbot_flows')) return $known;

        foreach (($flowPrints ?? $this->flowPrints())['saved'] as $field) $known[$field] = true;
        $stored = Cache::remember('chatbot:page-check:customer-keys', self::CUSTOMER_KEYS_SECONDS, fn () => Schema::hasTable('chatbot_customer_values')
            ? DB::table('chatbot_customer_values')->distinct()->limit(5000)->pluck('key')->all() : []);
        foreach ($stored as $k) if (!str_starts_with((string) $k, '__')) $known[ChatbotVars::norm((string) $k)] = true;

        return $known;
    }
}
