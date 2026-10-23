<?php

namespace App\Services\Chatbot;

use InvalidArgumentException;

/**
 * Ang graph ng isang flow: {"nodes":[{id,type,x,y,config}], "edges":[{from,port,to}]}. Dito ang mga uri ng node, ang
 * mga port ng bawat isa, at ang paglilinis at pagsusuri bago i-save (iisang patakaran para sa editor at sa engine).
 */
class FlowGraph
{
    public const TYPES = ['start', 'condition', 'send', 'set', 'ai', 'clear_ai', 'sheet', 'encode_order', 'sequence', 'end'];

    /** Ang mga cell A..H ng Encode order (ang I ay status, laging blangko): ang pangalan sa config. */
    public const ENCODE_FIELDS = ['date', 'page', 'name', 'phone', 'input', 'shop', 'extracted', 'price'];

    public const RULES = [
        'is' => 'is', 'is_not' => 'is not', 'contains' => 'contains', 'contains_any' => 'contains any of',
        'not_contains' => 'does not contain', 'starts_with' => 'starts with', 'regex' => 'matches regex',
        'is_empty' => 'is empty', 'is_not_empty' => 'is not empty', 'gt' => 'greater than', 'lt' => 'less than',
        'has_phone' => 'has a phone number',
    ];

    public const SET_OPS = ['set', 'append', 'clear', 'clear_all'];

    public const MAX_NODES = 200;
    public const MAX_EDGES = 1000;
    public const MAX_ITEMS = 10;     // Send message
    public const MAX_ROWS = 20;      // Condition at Set field
    public const MAX_COLUMNS = 50;   // Google Sheet
    public const MAX_TEXT = 2000;    // isang text, value o template
    public const MAX_PROMPT = 10000;
    public const MAX_HISTORY = 100;
    public const MAX_SKIP_MINUTES = 23 * 60; // ang padala pagkatapos ng skip ay dapat nasa 24 oras ng Messenger pa

    /** Ang mga port na palabas ng node, ayon sa uri at config. */
    public static function ports(array $node): array
    {
        $config = (array) ($node['config'] ?? []);

        return match ($node['type'] ?? '') {
            'start', 'set', 'sequence', 'clear_ai' => ['next'],
            'condition' => array_merge(array_map(fn ($i) => 'row_' . ($i + 1), array_keys(array_values((array) ($config['rows'] ?? [])))), ['no']),
            'send' => !empty($config['wait']) ? (self::skipMinutes($config) ? ['reply', 'skipped'] : ['reply']) : ['continue'],
            'ai' => ['success', 'failed'],
            'sheet', 'encode_order' => ['next', 'failed'],
            default => [],
        };
    }

    /** Isang bagong flow: Start lang. */
    public static function blank(): array
    {
        return ['nodes' => [['id' => 'n1', 'type' => 'start', 'x' => 80, 'y' => 80, 'config' => []]], 'edges' => []];
    }

    /**
     * Ang malinis na graph, o InvalidArgumentException na may mensaheng para sa tao. Tinatanggihan: maling uri, hindi
     * iisang Start, ulit na id, lampas sa laki, edge na galing o papunta sa wala, sa sarili, sa port na wala sa uri ng
     * node, at dalawang wire sa iisang port.
     */
    public static function clean(mixed $raw): array
    {
        if (!is_array($raw) || !is_array($raw['nodes'] ?? null) || !is_array($raw['edges'] ?? [])) throw new InvalidArgumentException('The graph needs nodes and edges.');
        if (count($raw['nodes']) > self::MAX_NODES) throw new InvalidArgumentException('At most ' . self::MAX_NODES . ' nodes.');
        if (count($raw['edges'] ?? []) > self::MAX_EDGES) throw new InvalidArgumentException('At most ' . self::MAX_EDGES . ' wires.');

        $nodes = [];
        $starts = 0;
        foreach ($raw['nodes'] as $n) {
            $id = is_array($n) ? ($n['id'] ?? null) : null;
            if (!is_string($id) || !preg_match('/^n\d{1,6}$/', $id)) throw new InvalidArgumentException('Every node needs an id like n12.');
            if (isset($nodes[$id])) throw new InvalidArgumentException("Node {$id} is there twice.");
            $type = $n['type'] ?? null;
            if (!in_array($type, self::TYPES, true)) throw new InvalidArgumentException("Node {$id} has an unknown type.");
            if ($type === 'start') $starts++;
            $nodes[$id] = [
                'id' => $id, 'type' => $type,
                'x' => max(-20000, min(20000, (int) ($n['x'] ?? 0))), 'y' => max(-20000, min(20000, (int) ($n['y'] ?? 0))),
                'config' => self::cleanConfig($type, is_array($n['config'] ?? null) ? $n['config'] : []),
            ];
        }
        if ($starts !== 1) throw new InvalidArgumentException('A flow has exactly one Start.');

        $edges = [];
        $seen = [];
        foreach ($raw['edges'] ?? [] as $e) {
            $from = is_array($e) ? ($e['from'] ?? null) : null;
            $to = is_array($e) ? ($e['to'] ?? null) : null;
            $port = is_array($e) ? ($e['port'] ?? null) : null;
            if (!is_string($from) || !isset($nodes[$from]) || !is_string($to) || !isset($nodes[$to])) throw new InvalidArgumentException('A wire points to a node that is not in this flow.');
            if ($from === $to) throw new InvalidArgumentException('A node cannot be wired to itself.');
            if (!is_string($port) || !in_array($port, self::ports($nodes[$from]), true)) throw new InvalidArgumentException("Node {$from} has no port \"" . (is_string($port) ? mb_substr($port, 0, 30) : '?') . '".');
            if (isset($seen[$from . ':' . $port])) throw new InvalidArgumentException("Port {$port} of node {$from} has two wires.");
            $seen[$from . ':' . $port] = true;
            $edges[] = ['from' => $from, 'port' => $port, 'to' => $to];
        }

        return ['nodes' => array_values($nodes), 'edges' => $edges];
    }

    /** Ang config ng isang uri, na may allow-list ng mga key at hangganan ng haba. */
    public static function cleanConfig(string $type, array $c): array
    {
        $text = fn ($v, int $max = self::MAX_TEXT) => mb_substr(is_scalar($v) ? (string) $v : '', 0, $max);
        $field = fn ($v) => ChatbotVars::norm(is_scalar($v) ? (string) $v : '');
        // "Counts as an order" (Results): naka-save lang kapag naka-tick, kaya ang lumang graph ay hindi nagbabago.
        $order = in_array($type, ['send', 'set', 'sheet', 'encode_order'], true) && !empty($c['counts_order']) ? ['counts_order' => true] : [];

        return $order + match ($type) {
            'condition' => ['rows' => array_values(array_map(fn ($r) => [
                'variable' => $text($r['variable'] ?? '', 100),
                'rule' => array_key_exists($r['rule'] ?? '', self::RULES) ? $r['rule'] : 'is',
                'value' => $text($r['value'] ?? ''),
            ], array_slice(array_filter((array) ($c['rows'] ?? []), 'is_array'), 0, self::MAX_ROWS)))],
            'send' => [
                'items' => array_values(array_map(fn ($i) => [
                    'type' => ($i['type'] ?? '') === 'image' ? 'image' : 'text',
                    'value' => $text($i['value'] ?? ''),
                ] + (($i['ai'] ?? null) === true ? ['ai' => true] : []), array_slice(array_filter((array) ($c['items'] ?? []), 'is_array'), 0, self::MAX_ITEMS))),
                'wait' => !empty($c['wait']),
                'save_to' => $field($c['save_to'] ?? ''),
            ] + (!empty($c['wait']) && ($skip = self::cleanSkip($c['skip_after'] ?? null)) ? ['skip_after' => $skip] : []),
            'set' => ['ops' => array_values(array_map(fn ($o) => [
                'op' => in_array($o['op'] ?? '', self::SET_OPS, true) ? $o['op'] : 'set',
                'field' => $field($o['field'] ?? ''),
                'value' => $text($o['value'] ?? ''),
                'separator' => $text($o['separator'] ?? ', ', 10),
            ], array_slice(array_filter((array) ($c['ops'] ?? []), 'is_array'), 0, self::MAX_ROWS)))],
            'ai' => [
                'model' => preg_match('/^[a-z0-9][a-z0-9.\-:]{0,60}$/i', (string) ($c['model'] ?? '')) ? (string) $c['model'] : 'gpt-4o-mini',
                'system' => $text($c['system'] ?? '', self::MAX_PROMPT),
                'user' => $text(($c['user'] ?? '') !== '' ? $c['user'] : '{{last_input}}', self::MAX_PROMPT),
                // Ang bagong step (walang key) ay 100; ang 0 at ang naka-save na value ay hindi ginagalaw.
                'history' => max(0, min(self::MAX_HISTORY, (int) (array_key_exists('history', $c) ? $c['history'] : self::MAX_HISTORY))),
                'max_tokens' => max(1, min(4000, (int) ($c['max_tokens'] ?? 250) ?: 250)),
                'save_to' => $field($c['save_to'] ?? ''),
                'send' => !empty($c['send']),
            ],
            // Ang mga customer field na binubura kasabay ng alaala ng AI (wala = wala).
            'clear_ai' => ['fields' => array_slice(array_values(array_unique(array_filter(array_map($field, array_filter((array) ($c['fields'] ?? []), 'is_scalar')), fn ($f) => $f !== ''))), 0, self::MAX_ROWS)],
            'sheet' => [
                'sheet' => $text($c['sheet'] ?? '', 300),
                'tab' => $text($c['tab'] ?? '', 100),
                'columns' => array_slice(array_values(array_map(fn ($l) => $text($l), is_array($c['columns'] ?? null)
                    ? $c['columns'] : preg_split('/\r\n|\r|\n/', (string) ($c['columns'] ?? '')))), 0, self::MAX_COLUMNS),
            ],
            // Encode order: ang mga template ng order (A..H), ang kasabay na sulat sa sheet at ang gagawin kapag pumalya.
            'encode_order' => array_combine(self::ENCODE_FIELDS, array_map(fn ($k) => $text($c[$k] ?? ''), self::ENCODE_FIELDS)) + [
                'also_sheet' => !empty($c['also_sheet']),
                'sheet' => $text($c['sheet'] ?? '', 300),
                'tab' => $text($c['tab'] ?? '', 100),
                'on_failure' => ($c['on_failure'] ?? '') === 'continue' ? 'continue' : 'sheet',
            ],
            // sequence_id 0 = lahat (unsubscribe lang)
            'sequence' => [
                'action' => ($c['action'] ?? '') === 'unsubscribe' ? 'unsubscribe' : 'subscribe',
                'sequence_id' => max(0, (int) (is_numeric($c['sequence_id'] ?? null) ? $c['sequence_id'] : 0)),
            ],
            default => [],
        };
    }

    /**
     * Ang kulang na hindi hinaharang ng pag-save ng draft pero hinaharang ng Publish: ang Encode order na gagamit ng sheet
     * (kasabay na sulat o "Write to Google Sheet instead") na walang tamang link. Null kapag wala.
     */
    public static function publishProblems(array $graph): ?string
    {
        foreach ($graph['nodes'] ?? [] as $n) {
            if (($n['type'] ?? '') !== 'encode_order') continue;
            $c = (array) ($n['config'] ?? []);
            if ((($c['on_failure'] ?? 'sheet') === 'sheet' || !empty($c['also_sheet'])) && self::spreadsheetId((string) ($c['sheet'] ?? '')) === null) {
                return 'Encode order step ' . $n['id'] . ' needs a Google Sheet link (for "Write to Google Sheet instead" or "Also write to Google Sheet"), or choose "Continue without saving".';
            }
        }

        return null;
    }

    /**
     * Ang skip_after ng isang wait:{amount, unit} kapag may amount na 1 o higit pa, null kapag wala (walang key sa
     * config, kaya ang lumang graph ay hindi nagbabago). Lampas sa 23 oras: InvalidArgumentException.
     */
    private static function cleanSkip(mixed $v): ?array
    {
        if (!is_array($v) || !is_numeric($v['amount'] ?? null) || (float) $v['amount'] < 1) return null;
        if ((float) $v['amount'] > self::MAX_SKIP_MINUTES) throw new InvalidArgumentException('Skip after can be at most 23 hours.');
        $skip = ['amount' => (int) $v['amount'], 'unit' => ($v['unit'] ?? '') === 'hours' ? 'hours' : 'minutes'];
        if (self::skipMinutes(['skip_after' => $skip]) > self::MAX_SKIP_MINUTES) throw new InvalidArgumentException('Skip after can be at most 23 hours.');

        return $skip;
    }

    /** Ilang minuto ang skip_after ng config ng isang Send (0 = walang skip). */
    public static function skipMinutes(array $config): int
    {
        $skip = $config['skip_after'] ?? null;
        if (!is_array($skip)) return 0;

        // Hangganan bago paramihin: ang napakalaking numero ay hindi nagiging float.
        return min(max(0, (int) ($skip['amount'] ?? 0)), self::MAX_SKIP_MINUTES + 1) * (($skip['unit'] ?? '') === 'hours' ? 60 : 1);
    }

    /** "2 h", "30 min", "1 h 30 min" (para sa mga event). */
    public static function skipLabel(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h ? $h . ' h' : '') . ($h && $m ? ' ' : '') . ($m ? $m . ' min' : ''));
    }

    /** Ang spreadsheet ID mula sa URL o ID mismo (null kapag hindi mukhang ID). */
    public static function spreadsheetId(string $sheet): ?string
    {
        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]{20,})#', $sheet, $m)) return $m[1];

        return preg_match('/^[a-zA-Z0-9_-]{20,}$/', trim($sheet)) ? trim($sheet) : null;
    }
}
