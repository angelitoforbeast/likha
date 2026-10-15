<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isang value na nakuha ng AI sa submission (isang field ng task). Ang number, money at percent
 * ay nasa value_number; ang date (Y-m-d) at text ay nasa value_text; ang yes_no ay nasa value_bool.
 */
class ChecklistSubmissionValue extends Model
{
    public const TYPES = ['number', 'money', 'percent', 'text', 'date', 'yes_no'];

    protected $fillable = [
        'submission_id', 'checklist_task_id', 'date', 'field_key',
        'value_number', 'value_text', 'value_bool', 'source', 'raw',
    ];

    protected $casts = [
        'value_number' => 'float',
        'value_bool'   => 'boolean',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ChecklistSubmission::class, 'submission_id');
    }

    /** Ang sagot ng AI para sa isang field → mga column na ise-save. Ang hindi mabasa ay null. */
    public static function fromAi(string $type, mixed $raw): array
    {
        $out = [
            'value_number' => null,
            'value_text'   => null,
            'value_bool'   => null,
            'raw'          => match (true) {
                $raw === null   => null,
                is_bool($raw)   => $raw ? 'true' : 'false',
                is_scalar($raw) => (string) $raw,
                default         => json_encode($raw, JSON_UNESCAPED_UNICODE),
            },
        ];
        if ($raw === null || !is_scalar($raw)) {
            return $out;
        }

        match ($type) {
            'number', 'money', 'percent' => $out['value_number'] = self::parseNumber($raw),
            'date'                       => $out['value_text']   = self::parseDate((string) $raw),
            'yes_no'                     => $out['value_bool']   = self::parseBool($raw),
            default                      => $out['value_text']   = trim((string) $raw) === '' ? null : mb_substr(trim((string) $raw), 0, 1000),
        };

        return $out;
    }

    /**
     * "1,612" → 1612, "₱2,295.67" → 2295.67, "2,01%" → 2.01. Ang comma ay decimal lang kapag
     * ito lang ang separator, iisa, at 1-2 digit ang kasunod; kung hindi, thousands separator.
     */
    public static function parseNumber(mixed $raw): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        // Ang minus ay bilang lang kapag nakadikit sa numero o sa ₱ at hindi bahagi ng salita ("ORDER- 23" = 23)
        if (!is_string($raw) || !preg_match('/(?:(?<!\w)(-))?(?:₱|PHP)?(\d[\d.,]*)/iu', $raw, $m)) {
            return null;
        }
        $num = rtrim($m[2], '.,');
        if (!str_contains($num, '.') && substr_count($num, ',') === 1 && preg_match('/,\d{1,2}$/', $num)) {
            $num = str_replace(',', '.', $num);
        } else {
            $num = str_replace(',', '', $num);
        }
        if (substr_count($num, '.') > 1) {
            $num = str_replace('.', '', $num);   // 1.612.000
        }

        return preg_match('/^\d+(\.\d+)?$/', $num) ? (float) ($m[1] . $num) : null;
    }

    public static function parseDate(string $raw): ?string
    {
        if (!preg_match('/\d/', $raw)) {
            return null;   // "today", "n/a": hindi petsa
        }
        try {
            return Carbon::parse(trim($raw))->toDateString();
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function parseBool(mixed $raw): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        $s = mb_strtolower(trim((string) $raw));

        return match (true) {
            in_array($s, ['yes', 'y', 'true', '1', 'oo', 'opo', 'done', 'ok'], true)      => true,
            in_array($s, ['no', 'n', 'false', '0', 'hindi', 'wala', 'none'], true)        => false,
            default                                                                        => null,
        };
    }

    /** Para sa chip sa report: "1,612", "₱2,295.67", "2.01%", "Yes". */
    public function display(?string $type): string
    {
        if ($this->value_bool !== null) {
            return $this->value_bool ? 'Yes' : 'No';
        }
        if ($this->value_number !== null) {
            $n = $this->value_number;
            return match ($type) {
                'money'   => '₱' . number_format($n, 2),
                'percent' => self::plainNumber($n) . '%',
                default   => number_format($n, strlen(substr(strrchr(self::plainNumber($n), '.') ?: '', 1))),
            };
        }

        return $this->value_text ?? '—';
    }

    /** Para sa CSV: walang thousands separator at walang ₱ o %, para mabilang sa spreadsheet. */
    public function plain(): string
    {
        if ($this->value_bool !== null) {
            return $this->value_bool ? 'yes' : 'no';
        }
        if ($this->value_number !== null) {
            return self::plainNumber($this->value_number);
        }

        return (string) ($this->value_text ?? '');
    }

    private static function plainNumber(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
