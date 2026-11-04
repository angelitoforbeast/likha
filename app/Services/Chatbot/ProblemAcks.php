<?php

namespace App\Services\Chatbot;

use App\Models\User;
use App\Services\PageSettings;
use Carbon\Carbon;
use Throwable;

/**
 * Ang Acknowledge at Done ng isang linya ng problema (ProblemsPanel), may note, sino at kailan. Nakatala sa page settings ng
 * page (chatbot.problem_ack.<kind>): ang bawat pindot ay bagong row, kaya ang kasaysayan (sino, kailan, ano) ay nasa Changes.
 *
 * - ack: "nakita na, inaayos": wala sa listahan hanggang `until` (1 araw kung walang pinili), o hanggang lumala ang kulay
 *   (hal. naging pula ang dating amber).
 * - done: "ayos na": nagsisimula sa zero ang bilang mula sa Done; bumabalik lang kapag nangyari ulit pagkatapos nito (ang
 *   estado gaya ng setup na kulang: pagkalipas ng DONE_GRACE_MINUTES kung kulang pa rin).
 * Ang kind ay ang linya na walang numero at oras (pareho ang "57 of 58 since 16:08" at "60 of 61 since 16:40").
 */
class ProblemAcks
{
    public const PROVIDER = 'chatbot';
    public const PREFIX = 'problem_ack.';
    public const STATUSES = ['ack', 'done'];
    public const DONE_GRACE_MINUTES = 30;
    public const DEFAULT_ACK_HOURS = 24;
    public const MAX_NOTE = 300;

    public function __construct(private PageSettings $settings)
    {
    }

    /** Ang uri ng linya: ang text na walang numero, para pareho ang susi habang nagbabago ang bilang at oras. */
    public static function kind(string $text): string
    {
        return substr(sha1(mb_strtolower(trim((string) preg_replace('/\d+([.,:]\d+)*/', '#', $text)))), 0, 12);
    }

    /**
     * Ang ack ng linya ngayon, o null kapag wala.
     * @return array{status: string, note: string, by: string, at: string, until: ?string, hidden: bool, back: ?string}|null
     */
    public function state(string $page, string $kind, string $level, ?string $since = null, ?int $window = null): ?array
    {
        $raw = $this->settings->get($page, self::PROVIDER, self::PREFIX . $kind);
        if ($raw === null || $raw === '') return null;
        $a = json_decode($raw, true);
        if (!is_array($a) || !in_array($a['status'] ?? null, self::STATUSES, true)) return null;
        try {
            $at = Carbon::parse((string) $a['at']);
            $until = isset($a['until']) && $a['until'] !== null ? Carbon::parse((string) $a['until']) : null;
        } catch (Throwable) {
            return null;
        }
        $rank = array_flip(array_keys(ProblemsPanel::LEVELS));
        $worse = isset($a['level'], $rank[$a['level']], $rank[$level]) && $rank[$level] < $rank[$a['level']];
        $back = null;
        if ($a['status'] === 'done') {
            // Done: nagsisimula sa zero ang bilang mula sa Done. Ang linyang may simula ($since: ang unang pumalya, ang oras
            // ng lipat) ay bumabalik lang kapag nagsimula ito pagkatapos ng Done; ang may window (huling N minuto) kapag ang
            // buong window ay pagkatapos na ng Done; ang estado (hal. setup na kulang) pagkalipas ng DONE_GRACE_MINUTES.
            $sinceAt = null;
            try {
                $sinceAt = $since !== null && $since !== '' ? Carbon::parse($since) : null;
            } catch (Throwable) {
                $sinceAt = null;
            }
            if ($sinceAt !== null) {
                if ($sinceAt->gt($at)) $back = 'new since it was marked done';
            } elseif ($window !== null && $window > 0) {
                if (now()->gt($at->copy()->addMinutes($window))) $back = 'new since it was marked done';
            } elseif (now()->gt($at->copy()->addMinutes(self::DONE_GRACE_MINUTES))) {
                $back = 'still happening after it was marked done';
            }
        } elseif ($worse) {
            $back = 'got worse since it was acknowledged';
        } elseif ($until !== null && now()->gt($until)) {
            $back = 'the acknowledgement ended';
        }

        return ['status' => $a['status'], 'note' => (string) ($a['note'] ?? ''), 'by' => (string) ($a['by'] ?? ''), 'at' => $at->format('Y-m-d H:i'),
            'until' => $until?->format('Y-m-d H:i'), 'hidden' => $back === null, 'back' => $back];
    }

    /**
     * Itinatala ang ack (o ang pag-alis nito: status "clear"). $until: "Y-m-d" o "Y-m-d H:i", para sa ack lang.
     * @return array{ok: bool, error?: string}
     */
    public function set(string $page, string $kind, string $status, string $note, ?string $until, string $level, User $by): array
    {
        if (!preg_match('/^[0-9a-f]{12}$/', $kind)) return ['ok' => false, 'error' => 'Unknown problem.'];
        $note = trim($note);
        $key = self::PREFIX . $kind;
        if ($status === 'clear') {
            foreach ($this->settings->pageQuery($page)->where('provider', self::PROVIDER)->where('key', $key)->where('active', true)->get() as $row) {
                $this->settings->deactivate($row, $by->id);
            }

            return ['ok' => true];
        }
        if (!in_array($status, self::STATUSES, true)) return ['ok' => false, 'error' => 'Pick Acknowledge or Done.'];
        if ($note === '') return ['ok' => false, 'error' => 'Add a note: what was done or who is on it.'];
        if (!array_key_exists($level, ProblemsPanel::LEVELS)) $level = 'white';
        $untilAt = null;
        if ($status === 'ack') {
            try {
                $untilAt = $until !== null && trim($until) !== '' ? Carbon::parse($until, config('app.timezone')) : now()->addHours(self::DEFAULT_ACK_HOURS);
            } catch (Throwable) {
                return ['ok' => false, 'error' => 'The date is not valid.'];
            }
            if (strlen(trim((string) $until)) === 10) $untilAt->endOfDay();
            if ($untilAt->lte(now())) return ['ok' => false, 'error' => 'Pick a time in the future.'];
            if ($untilAt->gt(now()->addDays(90))) return ['ok' => false, 'error' => 'At most 90 days.'];
        }
        $value = ['status' => $status, 'note' => mb_substr($note, 0, self::MAX_NOTE), 'by' => mb_substr((string) $by->name, 0, 100),
            'at' => now()->format('Y-m-d H:i:s'), 'until' => $untilAt?->format('Y-m-d H:i:s'), 'level' => $level];
        $this->settings->set($page, self::PROVIDER, $key, json_encode($value, JSON_UNESCAPED_UNICODE), false, $by->id, true);

        return ['ok' => true];
    }
}
