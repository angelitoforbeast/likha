<?php

namespace App\Services\Chatbot;

use App\Models\BotcakeChat;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Ang mga kondisyon ng filter builder ng /chatbot/inbox (lahat ay dapat tumugma, AND). Ang `conditions` ay JSON array ng:
 *   {type: field, key, op: has_value|empty|is|contains|not_contains, value}   customer field (key = pangalan ng field)
 *   {type: ordered, value: yes|no, from?, to?}                                 may order event (from/to: Y-m-d, kung wala ay ang window)
 *   {type: last_message, from?, to?}                                           petsa ng huling galaw ng usapan
 *   {type: first_chat, from?, to?}                                             petsa ng unang chat ng customer
 *   {type: bot_reply, value: sent|failed}                                      ang huling send ng bot sa window
 *   {type: handler, value: human|bot}                                          naka-pause ang bot o hindi
 * Ang lahat maliban sa last_message ay EXISTS / NOT EXISTS sa mismong query ng listahan (kaya ang limit ng listahan ay
 * pagkatapos ng filter); ang last_message ay sinasala sa mga naipon na row dahil galing ito sa dalawang table.
 */
class InboxFilters
{
    public const MAX = 10;
    public const OPS = ['has_value', 'empty', 'is', 'contains', 'not_contains'];
    private const TYPES = ['field', 'ordered', 'last_message', 'first_chat', 'bot_reply', 'handler'];

    /**
     * Ang malinis na mga kondisyon mula sa request. Ang from/to ay nagiging 'Y-m-d 00:00:00' / 'Y-m-d 23:59:59'.
     * @return array<int, array<string, mixed>>
     * @throws InvalidArgumentException
     */
    public static function parse(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) return [];
        if (is_string($raw)) {
            if (mb_strlen($raw) > 8000) throw new InvalidArgumentException('Too many conditions.');
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw) || !array_is_list($raw)) throw new InvalidArgumentException('Conditions must be a list.');
        if (count($raw) > self::MAX) throw new InvalidArgumentException('At most ' . self::MAX . ' conditions.');

        return array_map(fn ($c) => self::one($c), $raw);
    }

    private static function one(mixed $c): array
    {
        if (!is_array($c) || !in_array($c['type'] ?? null, self::TYPES, true)) throw new InvalidArgumentException('Unknown condition.');
        $type = $c['type'];
        $value = is_scalar($c['value'] ?? null) ? trim((string) $c['value']) : '';

        if ($type === 'field') {
            $key = is_string($c['key'] ?? null) ? ChatbotVars::norm($c['key']) : '';
            if ($key === '' || str_starts_with(trim((string) $c['key']), '__')) throw new InvalidArgumentException('Pick a customer field.');
            if (!in_array($c['op'] ?? null, self::OPS, true)) throw new InvalidArgumentException('Unknown operator.');
            $needsValue = !in_array($c['op'], ['has_value', 'empty'], true);
            if ($needsValue && ($value === '' || mb_strlen($value) > 200)) throw new InvalidArgumentException('Type a value (up to 200 characters).');

            return ['type' => 'field', 'key' => $key, 'op' => $c['op'], 'value' => $needsValue ? $value : null];
        }
        if ($type === 'ordered' || $type === 'bot_reply' || $type === 'handler') {
            $allowed = ['ordered' => ['yes', 'no'], 'bot_reply' => ['sent', 'failed'], 'handler' => ['human', 'bot']][$type];
            if (!in_array($value, $allowed, true)) throw new InvalidArgumentException('Unknown value.');
        }
        if ($type === 'ordered' || $type === 'last_message' || $type === 'first_chat') {
            $from = self::date($c['from'] ?? null, '00:00:00');
            $to = self::date($c['to'] ?? null, '23:59:59');
            if ($type !== 'ordered' && !$from && !$to) throw new InvalidArgumentException('Pick a date.');
            if ($from && $to && $from > $to) throw new InvalidArgumentException('The "from" date is after the "to" date.');

            return ['type' => $type, 'value' => $value ?: null, 'from' => $from, 'to' => $to];
        }

        return ['type' => $type, 'value' => $value];
    }

    private static function date(mixed $v, string $time): ?string
    {
        if ($v === null || $v === '') return null;
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) throw new InvalidArgumentException('Dates look like 2026-10-23.');

        return $v . ' ' . $time;
    }

    /** Ang huling galaw ng usapan ($lastAt) ay pasok sa lahat ng last_message na kondisyon. */
    public static function lastMessageOk(array $conditions, string $lastAt): bool
    {
        foreach ($conditions as $c) {
            if ($c['type'] !== 'last_message') continue;
            if (($c['from'] && $lastAt < $c['from']) || ($c['to'] && $lastAt > $c['to'])) return false;
        }

        return true;
    }

    public static function hasLastMessage(array $conditions): bool
    {
        return (bool) array_filter($conditions, fn ($c) => $c['type'] === 'last_message');
    }

    /**
     * Idagdag ang mga kondisyon sa query ng listahan. $psidCol: ang column ng psid ng query (botcake_chats.psid ay string,
     * chatbot_replies.psid ay bigint); ang cast ay nasa panig ng labas na row para magamit pa rin ang index ng loob.
     */
    public function apply($query, array $conditions, string $psidCol, bool $psidIsString, int $pageId, Carbon $since): void
    {
        $int = $psidIsString ? 'CAST(' . $psidCol . ' AS ' . (DB::getDriverName() === 'mysql' ? 'SIGNED' : 'BIGINT') . ')' : $psidCol;
        $str = $psidIsString ? $psidCol : 'CAST(' . $psidCol . ' AS ' . (['mysql' => 'CHAR', 'pgsql' => 'VARCHAR'][DB::getDriverName()] ?? 'TEXT') . ')';
        $key = DB::getQueryGrammar()->wrap('v.key');
        $field = "SELECT 1 FROM chatbot_customer_values v WHERE v.page_id = ? AND v.psid = {$int} AND {$key} = ?";

        foreach ($conditions as $c) {
            switch ($c['type']) {
                case 'field':
                    $like = '%' . strtr(mb_strtolower((string) $c['value']), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
                    [$not, $extra, $b] = match ($c['op']) {
                        'has_value' => ['', " AND TRIM(v.value) <> ''", []],
                        'empty' => ['NOT ', " AND TRIM(v.value) <> ''", []],
                        'is' => ['', ' AND LOWER(TRIM(v.value)) = ?', [mb_strtolower((string) $c['value'])]],
                        'contains' => ['', " AND LOWER(v.value) LIKE ? ESCAPE '!'", [$like]],
                        'not_contains' => ['NOT ', " AND LOWER(v.value) LIKE ? ESCAPE '!'", [$like]],
                    };
                    $query->whereRaw("{$not}EXISTS ({$field}{$extra})", [$pageId, $c['key'], ...$b]);
                    break;
                case 'handler':
                    $query->whereRaw(($c['value'] === 'bot' ? 'NOT ' : '') . "EXISTS ({$field} AND v.value = '1')", [$pageId, CustomerFields::BOT_PAUSED]);
                    break;
                case 'ordered':
                    $yes = $c['value'] === 'yes';
                    if (!Schema::hasTable('chatbot_events')) {
                        if ($yes) $query->whereRaw('1 = 0');
                        break;
                    }
                    $sql = "SELECT 1 FROM chatbot_events e WHERE e.page_id = ? AND e.psid = {$int} AND e.type = 'order' AND e.created_at >= ?" . ($c['to'] ? ' AND e.created_at <= ?' : '');
                    $query->whereRaw(($yes ? '' : 'NOT ') . "EXISTS ({$sql})", array_filter([$pageId, $c['from'] ?? $since->format('Y-m-d H:i:s'), $c['to']], fn ($x) => $x !== null));
                    break;
                case 'bot_reply':
                    $query->whereRaw("EXISTS (SELECT 1 FROM chatbot_replies r WHERE r.page_id = ? AND r.psid = {$int} AND r.kind = 'send' AND r.status = ? AND r.id = ("
                        . "SELECT MAX(r2.id) FROM chatbot_replies r2 WHERE r2.page_id = ? AND r2.psid = r.psid AND r2.kind = 'send' AND r2.sent_at >= ?))",
                        [$pageId, $c['value'], $pageId, $since->format('Y-m-d H:i:s')]);
                    break;
                case 'first_chat':
                    // Ang unang chat ay nasa [from, to]: may chat na hindi lampas sa to, at wala nang mas luma kaysa sa from.
                    $chat = fn (string $op, string $at) => BotcakeChat::active()->from('botcake_chats as c')->selectRaw('1')->where('c.page_id', (string) $pageId)
                        ->whereRaw("c.psid = {$str}")->where('c.received_at', $op, $at);
                    if ($c['to']) { $s = $chat('<=', $c['to']); $query->whereRaw('EXISTS (' . $s->toSql() . ')', $s->getBindings()); }
                    else { $s = $chat('>=', '0001-01-01 00:00:00'); $query->whereRaw('EXISTS (' . $s->toSql() . ')', $s->getBindings()); }
                    if ($c['from']) { $s = $chat('<', $c['from']); $query->whereRaw('NOT EXISTS (' . $s->toSql() . ')', $s->getBindings()); }
                    break;
            }
        }
    }
}
