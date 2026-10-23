<?php

namespace App\Services\OrderEncode;

use App\Models\AddressNote;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Ang pagsala ng address_notes: iisa para sa pahina (/order-encode/notes) at sa `order-encode:notes-review --approve-where`.
 * Mga susi: q (isinulat o lugar), status, source, conflict (yes/no), delivered (pinakamababa), seen (pinakamababa).
 */
final class NoteFilter
{
    public const STATUSES = ['pending', 'approved', 'rejected'];
    public const SOURCES = ['staff_fix', 'astra_fix', 'delivered', 'manual'];
    public const KEYS = ['q', 'status', 'source', 'conflict', 'delivered', 'seen'];

    /** @param array<string, mixed> $f */
    public static function apply(Builder $q, array $f): Builder
    {
        $text = Text::lower(Text::jsTrim((string) ($f['q'] ?? '')));
        if ($text !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text) . '%';
            $q->where(function ($w) use ($like) {
                foreach (['written_key', 'written_example', 'province', 'city', 'barangay'] as $col) $w->orWhereRaw('LOWER(' . $col . ') LIKE ?', [$like]);
            });
        }
        if (in_array($f['status'] ?? '', self::STATUSES, true)) $q->where('status', $f['status']);
        if (in_array($f['source'] ?? '', self::SOURCES, true)) {
            $s = $f['source'];
            $q->where(function ($w) use ($s) {
                $w->where('source', $s)->orWhere('source', 'like', $s . ',%')->orWhere('source', 'like', '%,' . $s)->orWhere('source', 'like', '%,' . $s . ',%');
            });
        }
        if (($f['conflict'] ?? '') === 'yes') $q->where('conflict', true);
        if (($f['conflict'] ?? '') === 'no') $q->where('conflict', false);
        if (is_numeric($f['delivered'] ?? null) && (int) $f['delivered'] > 0) $q->where('delivered', '>=', (int) $f['delivered']);
        if (is_numeric($f['seen'] ?? null) && (int) $f['seen'] > 0) $q->where('seen', '>=', (int) $f['seen']);

        return $q;
    }

    /**
     * Ang --approve-where ng command: "status=pending,source=staff_fix,conflict=no,delivered>=2". Ang >= ay para sa delivered at seen lang.
     * @return array<string, string>
     * @throws InvalidArgumentException sa hindi kilalang susi o halaga
     */
    public static function parse(string $where): array
    {
        $out = [];
        foreach (preg_split('/\s*(?:,|\band\b)\s*/i', trim($where)) as $clause) {
            if ($clause === '') continue;
            if (!preg_match('/^(\w+)\s*(>=|=)\s*(.+)$/', $clause, $m)) throw new InvalidArgumentException("Cannot read \"{$clause}\".");
            [, $key, $op, $value] = $m;
            if (!in_array($key, self::KEYS, true)) throw new InvalidArgumentException("Unknown filter \"{$key}\" (use " . implode(', ', self::KEYS) . ').');
            if ($op === '>=' && !in_array($key, ['delivered', 'seen'], true)) throw new InvalidArgumentException('">=" works for delivered and seen only.');
            if (in_array($key, ['delivered', 'seen'], true) && !ctype_digit(trim($value))) throw new InvalidArgumentException("\"{$key}\" needs a number.");
            $out[$key] = trim($value, " \"'");
        }
        if (!$out) throw new InvalidArgumentException('The filter is empty.');

        return $out;
    }

    public static function query(array $f): Builder
    {
        return self::apply(AddressNote::query(), $f);
    }
}
