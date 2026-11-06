<?php

namespace App\Services\Chatbot;

use InvalidArgumentException;

/**
 * Ang graph ng isang flow: {"nodes":[{id,type,x,y,config}], "edges":[{from,port,to}]}. Dito ang mga uri ng node, ang
 * mga port ng bawat isa, at ang paglilinis at pagsusuri bago i-save (iisang patakaran para sa editor at sa engine).
 */
class FlowGraph
{
    public const TYPES = ['start', 'condition', 'send', 'set', 'ai', 'clear_ai', 'sheet', 'encode_order', 'upload_macro', 'encode_macro', 'sequence', 'end'];

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
    // Ang mga hangganan ng Messenger sa button at quick reply (gaya ng send API ng gateway).
    public const MAX_BUTTONS = 3;
    public const MAX_QUICK_REPLIES = 13;
    public const MAX_TITLE = 20;
    public const MAX_BUTTON_TEXT = 640;
    public const MAX_PAYLOAD = 1000;
    public const BUTTON_TYPES = ['postback', 'web_url'];
    // Ang id ng button o quick reply (ginagawa ng editor, hindi galing sa title o puwesto): ang port nito ay tap_<id>. Ang D:
    // ang $ ay hindi tumatanggap ng newline sa dulo (gaya ng ChatbotButtons sa editor).
    public const TAP_ID = '/^[a-z0-9]{6,16}$/D';

    /** Ang mga port na palabas ng node, ayon sa uri at config. */
    public static function ports(array $node): array
    {
        $config = (array) ($node['config'] ?? []);

        return match ($node['type'] ?? '') {
            'start', 'set', 'sequence', 'clear_ai' => ['next'],
            'condition' => array_merge(array_map(fn ($i) => 'row_' . ($i + 1), array_keys(array_values((array) ($config['rows'] ?? [])))), ['no']),
            'send' => !empty($config['wait']) ? array_merge(['reply'], array_keys(self::tapPorts((array) ($config['items'] ?? []))), self::skipMinutes($config) ? ['skipped'] : []) : ['continue'],
            'ai' => ['success', 'failed'],
            'sheet', 'encode_order', 'upload_macro', 'encode_macro' => ['next', 'failed'],
            default => [],
        };
    }

    /**
     * Ang mga exit ng tap ng isang Send, sa ayos ng form (bawat item: ang mga button, saka ang mga quick reply): tap_<id> =>
     * ang button o quick reply. Ang postback at quick reply na may id lang; ang link ay walang tap kaya walang port.
     */
    public static function tapPorts(array $items): array
    {
        $out = [];
        foreach ($items as $i) {
            if (!is_array($i)) continue;
            foreach (array_merge((array) ($i['buttons'] ?? []), (array) ($i['quick_replies'] ?? [])) as $t) {
                if (!is_array($t) || ($t['type'] ?? 'postback') !== 'postback' || !is_string($t['id'] ?? null) || !preg_match(self::TAP_ID, $t['id'])) continue;
                $out['tap_' . $t['id']] ??= $t;
            }
        }

        return $out;
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
                'config' => self::cleanConfig($type, is_array($n['config'] ?? null) ? $n['config'] : [], $id),
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
    public static function cleanConfig(string $type, array $c, string $id = ''): array
    {
        $text = fn ($v, int $max = self::MAX_TEXT) => mb_substr(is_scalar($v) ? (string) $v : '', 0, $max);
        $field = fn ($v) => ChatbotVars::norm(is_scalar($v) ? (string) $v : '');
        // "Counts as an order" (Results): naka-save lang kapag naka-tick, kaya ang lumang graph ay hindi nagbabago.
        $order = in_array($type, ['send', 'set', 'sheet', 'encode_order', 'upload_macro'], true) && !empty($c['counts_order']) ? ['counts_order' => true] : [];

        return $order + match ($type) {
            'condition' => ['rows' => array_values(array_map(fn ($r) => [
                'variable' => $text($r['variable'] ?? '', 100),
                'rule' => array_key_exists($r['rule'] ?? '', self::RULES) ? $r['rule'] : 'is',
                'value' => $text($r['value'] ?? ''),
            ], array_slice(array_filter((array) ($c['rows'] ?? []), 'is_array'), 0, self::MAX_ROWS)))],
            'send' => [
                'items' => self::uniqueTapIds(array_values(array_map(fn ($i) => self::cleanTaps($i, $id, [
                    'type' => ($i['type'] ?? '') === 'image' ? 'image' : 'text',
                    'value' => $text($i['value'] ?? ''),
                ] + (($i['ai'] ?? null) === true ? ['ai' => true] : [])), array_slice(array_filter((array) ($c['items'] ?? []), 'is_array'), 0, self::MAX_ITEMS))), $id),
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
            // Upload to macro: ang mga template ng order (A..H), Insert o Update, at ang gagawin kapag walang row sa Update.
            'upload_macro' => array_combine(self::ENCODE_FIELDS, array_map(fn ($k) => $text($c[$k] ?? ''), self::ENCODE_FIELDS)) + [
                'mode' => ($c['mode'] ?? '') === 'update' ? 'update' : 'insert',
                'if_not_found' => ($c['if_not_found'] ?? '') === 'skip' ? 'skip' : 'insert',
            ] + (array_key_exists('ad_id', $c) ? ['ad_id' => $text($c['ad_id'])] : []), // walang key = {{ad_id}} (ang lumang step)
            // Encode order: si Astra kapag hindi PROCEED (naka-on kapag walang key). Walang hintay: ang "wait" ng lumang config ay hindi na itinatabi.
            'encode_macro' => [
                'astra' => !array_key_exists('astra', $c) || !empty($c['astra']),
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
     * Ang mga button (Text lang) at quick reply ng isang item ng Send, idinadagdag sa $item kapag may laman lang (ang lumang
     * graph ay hindi nagkakaroon ng bagong key). Bawat isa: title, type at payload o url lang; ang hindi kilalang type ay
     * tinatanggal. Tinatanggihan ang lampas sa hangganan ng Messenger na hindi maaabot sa editor; ang kulang na maaabot
     * habang gumagawa (walang title, mali ang link, wala sa huling item) ay sa Publish (publishProblems).
     */
    private static function cleanTaps(array $raw, string $id, array $item): array
    {
        $str = fn ($v, int $max) => mb_substr(is_scalar($v) ? (string) $v : '', 0, $max);
        $step = 'Send message step ' . ($id !== '' ? $id . ' ' : '');
        $title = function ($v) use ($str, $step) {
            // mb_scrub gaya ng mb_substr ng ibang field: ang sirang UTF-8 ay hindi makaka-json_encode ng draft.
            $t = is_scalar($v) ? mb_scrub((string) $v, 'UTF-8') : '';
            if (mb_strlen($t) > self::MAX_TITLE) throw new InvalidArgumentException($step . 'has a button or quick reply title over ' . self::MAX_TITLE . ' characters.');

            return $t;
        };
        // Ang id (kapag tama ang anyo lang) ang pangalan ng port; ang link ay may id din, para bumalik ang port kapag ginawang postback.
        $tapId = fn ($t) => is_string($t['id'] ?? null) && preg_match(self::TAP_ID, $t['id']) ? ['id' => $t['id']] : [];
        $buttons = [];
        foreach (array_filter(is_array($raw['buttons'] ?? null) ? $raw['buttons'] : [], 'is_array') as $b) {
            if (!in_array($b['type'] ?? null, self::BUTTON_TYPES, true)) continue;
            $buttons[] = ['title' => $title($b['title'] ?? ''), 'type' => $b['type']]
                + ($b['type'] === 'web_url' ? ['url' => $str($b['url'] ?? '', self::MAX_TEXT)] : ['payload' => $str($b['payload'] ?? '', self::MAX_PAYLOAD)]) + $tapId($b);
        }
        $quick = array_values(array_map(fn ($q) => ['title' => $title($q['title'] ?? ''), 'payload' => $str($q['payload'] ?? '', self::MAX_PAYLOAD)] + $tapId($q),
            array_filter(is_array($raw['quick_replies'] ?? null) ? $raw['quick_replies'] : [], 'is_array')));

        if (count($buttons) > self::MAX_BUTTONS) throw new InvalidArgumentException($step . 'can have at most ' . self::MAX_BUTTONS . ' buttons on a message.');
        if (count($quick) > self::MAX_QUICK_REPLIES) throw new InvalidArgumentException($step . 'can have at most ' . self::MAX_QUICK_REPLIES . ' quick replies on a message.');
        if ($buttons && $quick) throw new InvalidArgumentException($step . 'has buttons and quick replies on one message: use one or the other (both is not allowed).');
        if ($buttons && $item['type'] === 'image') throw new InvalidArgumentException($step . 'has buttons on an Image link: buttons go on a Text message.');
        if ($buttons && mb_strlen($item['value']) > self::MAX_BUTTON_TEXT) throw new InvalidArgumentException($step . 'has a message with buttons over ' . self::MAX_BUTTON_TEXT . ' characters.');

        return $item + ($buttons ? ['buttons' => $buttons] : []) + ($quick ? ['quick_replies' => $quick] : []);
    }

    /** Ang mga item ng isang Send kung walang id na umuulit sa step (iisang port ang bawat id); hindi ito naaabot sa editor. */
    private static function uniqueTapIds(array $items, string $id): array
    {
        $ids = [];
        foreach ($items as $i) foreach (array_merge($i['buttons'] ?? [], $i['quick_replies'] ?? []) as $t) {
            if (!isset($t['id'])) continue;
            if (isset($ids[$t['id']])) throw new InvalidArgumentException('Send message step ' . ($id !== '' ? $id . ' ' : '') . 'has two buttons or quick replies with the same id.');
            $ids[$t['id']] = true;
        }

        return $items;
    }

    /** Ang default na payload ng isang title: naka-uppercase, bawat pagitan na hindi letra o numero ay _ ("Oo po!" → OO_PO). */
    public static function payloadFor(string $title): string
    {
        $p = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '_', mb_strtoupper($title)), '_');

        return $p !== '' ? mb_substr($p, 0, self::MAX_PAYLOAD) : 'BUTTON';
    }

    /**
     * Ang kulang na hindi hinaharang ng pag-save ng draft pero hinaharang ng Publish: ang Encode order na gagamit ng sheet
     * (kasabay na sulat o "Write to Google Sheet instead") na walang tamang link. Null kapag wala.
     */
    public static function publishProblems(array $graph): ?string
    {
        foreach ($graph['nodes'] ?? [] as $n) {
            if (($n['type'] ?? '') === 'send' && ($p = self::tapProblem((array) ($n['config']['items'] ?? [])))) return 'Send message step ' . $n['id'] . ' ' . $p;
            if (($n['type'] ?? '') !== 'encode_order') continue;
            $c = (array) ($n['config'] ?? []);
            if ((($c['on_failure'] ?? 'sheet') === 'sheet' || !empty($c['also_sheet'])) && self::spreadsheetId((string) ($c['sheet'] ?? '')) === null) {
                return 'Encode order step ' . $n['id'] . ' needs a Google Sheet link (for "Write to Google Sheet instead" or "Also write to Google Sheet"), or choose "Continue without saving".';
            }
        }

        return null;
    }

    /**
     * Ang paalala ng Publish na hindi humaharang: ang Encode order na walang Upload to macro bago ito sa anumang daan papunta
     * rito (ang bukas na row ng customer ang ie-encode, o failed kapag wala). Null kapag wala.
     */
    public static function publishNotice(array $graph): ?string
    {
        $types = array_column($graph['nodes'] ?? [], 'type', 'id');
        $from = [];
        foreach ($graph['edges'] ?? [] as $e) $from[$e['to']][] = $e['from'];
        $lonely = [];
        foreach ($types as $id => $type) {
            if ($type !== 'encode_macro') continue;
            // Pabalik sa mga wire: may Upload to macro ba sa dinaanan bago ang step na ito?
            $seen = [$id => true];
            $queue = $from[$id] ?? [];
            $found = false;
            while (!$found && $queue) {
                $n = array_shift($queue);
                if (isset($seen[$n])) continue;
                $seen[$n] = true;
                $found = ($types[$n] ?? '') === 'upload_macro';
                foreach ($from[$n] ?? [] as $p) $queue[] = $p;
            }
            if (!$found) $lonely[] = $id;
        }

        $notes = [];
        if ($lonely) $notes[] = 'Encode order step ' . implode(', ', $lonely) . " has no Upload to macro before it: it encodes the customer's open order row, or goes to failed when there is none.";
        // Ang tap ay sagot lang kapag naghihintay ang step; kung hindi, bagong mensahe ito (mula sa Start).
        $noWait = array_column(array_filter($graph['nodes'] ?? [], fn ($n) => ($n['type'] ?? '') === 'send' && empty($n['config']['wait']) && self::hasTaps((array) ($n['config']['items'] ?? []))), 'id');
        if ($noWait) $notes[] = 'Send message step ' . implode(', ', $noWait) . ' has a button or quick reply to tap but does not wait for the reply: a tap starts the flow again from Start. (In a sequence step a tap always goes to the page\'s main flow.)';

        // Ang exit ng tap: walang wire = sa "reply"; iisang payload sa dalawang mensahe = ang una ang laging sinusundan.
        $wired = [];
        foreach ($graph['edges'] ?? [] as $e) $wired[$e['from'] . ':' . $e['port']] = true;
        foreach ($graph['nodes'] ?? [] as $n) {
            if (($n['type'] ?? '') !== 'send' || empty($n['config']['wait'])) continue;
            $taps = self::tapPorts((array) ($n['config']['items'] ?? []));
            $loose = array_map(fn ($t) => (string) ($t['title'] ?? ''), array_filter($taps, fn ($port) => !isset($wired[$n['id'] . ':' . $port]), ARRAY_FILTER_USE_KEY));
            if ($loose) $notes[] = 'Send message step ' . $n['id'] . ' has button or quick reply exits with no wire (' . implode(', ', $loose) . '): a tap there leaves by "reply".';
            $payloads = array_map(fn ($t) => self::tapPayload((string) ($t['payload'] ?? '')), $taps);
            $twice = array_keys(array_filter(array_count_values(array_filter($payloads, fn ($p) => $p !== '')), fn ($c) => $c > 1));
            if ($twice) $notes[] = 'Send message step ' . $n['id'] . ' has two buttons or quick replies with the same payload (' . implode(', ', $twice) . '): a tap always takes the first one\'s exit.';
        }

        return $notes ? implode(' ', $notes) : null;
    }

    /** Ang payload gaya ng pagdating nito mula sa gateway (trim, hanggang 1000): ang ikinukumpara sa tap. */
    public static function tapPayload(string $payload): string
    {
        return mb_substr(trim($payload), 0, self::MAX_PAYLOAD);
    }

    /** May button na postback o quick reply ba (ang may tap na dumarating bilang sagot)? Ang web_url ay walang tap. */
    public static function hasTaps(array $items, bool $links = false): bool
    {
        foreach ($items as $i) {
            if (!empty($i['quick_replies'])) return true;
            foreach ((array) ($i['buttons'] ?? []) as $b) if ($links || ($b['type'] ?? '') === 'postback') return true;
        }

        return false;
    }

    /** Ang unang kulang ng mga button at quick reply ng isang Send (para sa Publish), o null. */
    private static function tapProblem(array $items): ?string
    {
        $last = count($items) - 1;
        foreach (array_values($items) as $k => $i) {
            $taps = array_merge((array) ($i['buttons'] ?? []), (array) ($i['quick_replies'] ?? []));
            if (!$taps) continue;
            // Ang item na walang text (o link ng larawan) ay hindi ipinapadala, kaya hindi rin makikita ang mga tap nito.
            if (($i['type'] ?? 'text') === 'image' ? !FlowEngine::isUrl(trim((string) ($i['value'] ?? ''))) && !str_contains((string) ($i['value'] ?? ''), '{{') : trim((string) ($i['value'] ?? '')) === '') {
                return ($i['type'] ?? 'text') === 'image' ? 'has quick replies but no image link.' : 'has buttons or quick replies but no text.';
            }
            if (!empty($i['quick_replies']) && $k !== $last) return 'has quick replies on a message that is not the last: Messenger shows quick replies only on the last message.';
            $titles = [];
            $payloads = [];
            foreach ($taps as $t) {
                if (trim((string) ($t['title'] ?? '')) === '') return 'has a button or quick reply with no title.';
                if (($t['type'] ?? 'postback') === 'web_url') {
                    if (!self::httpsUrl((string) ($t['url'] ?? ''))) return 'has a link button that is not an https:// link.';
                } else {
                    if (trim((string) ($t['payload'] ?? '')) === '') return 'has a button or quick reply with no payload.';
                    $payloads[] = (string) $t['payload'];
                }
                $titles[] = mb_strtolower(trim((string) $t['title']));
            }
            if (count(array_unique($titles)) !== count($titles) || count(array_unique($payloads)) !== count($payloads)) {
                return 'has two buttons or quick replies with the same title or payload on one message.';
            }
        }

        return null;
    }

    /** Isang https:// na link na buo (walang variable): ang link ng button. */
    public static function httpsUrl(string $v): bool
    {
        return FlowEngine::isUrl($v) && strtolower((string) parse_url($v, PHP_URL_SCHEME)) === 'https' && !str_contains($v, '{{');
    }

    /**
     * Ang skip_after ng isang wait: {amount, unit} kapag may amount na 1 o higit pa, null kapag wala (walang key sa
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
