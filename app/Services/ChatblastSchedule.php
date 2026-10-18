<?php

namespace App\Services;

use App\Models\ChatblastCampaign;
use Carbon\Carbon;

/**
 * Ang schedule ng campaign na naka-auto: ang mga oras ng padala (Manila) sa mga napiling araw ng linggo (wala = araw-
 * araw), at ang sariling oras ng isang step (ang mga row ng step na iyon ay sa oras nito lang ipinapadala). Ang isang oras
 * ay tumatakbo isang beses kada araw, hanggang 3 oras pagkatapos nito, at hindi kailanman bago ang huling takbo
 * (auto_ran_at: itinatala rin kapag in-auto, in-on ulit, o nagdagdag ng oras na lumipas na).
 */
class ChatblastSchedule
{
    public const LATE_LIMIT_HOURS = 3;
    public const DAY_NAMES = [1 => 'Lun', 2 => 'Mar', 3 => 'Miy', 4 => 'Huw', 5 => 'Biy', 6 => 'Sab', 7 => 'Lin'];
    private const TIME = '/^([01]\d|2[0-3]):([0-5]\d)$/';

    /** Ang mga oras ng step (0 = Day 1): ang sarili nito, o ang mga oras ng campaign. @return string[] */
    public function timesFor(ChatblastCampaign $c, int $step): array
    {
        $own = ((array) ($c->step_times ?? []))[$step] ?? null;
        $times = is_array($own) && $own ? $own : (array) ($c->send_times ?? []);

        return array_values(array_filter($times, fn ($t) => is_string($t) && preg_match(self::TIME, $t)));
    }

    /** Lahat ng oras ng campaign at ng mga step nito, magkakasunod. @return string[] */
    public function allTimes(ChatblastCampaign $c): array
    {
        $all = [];
        foreach (array_keys((array) $c->days) as $i) array_push($all, ...$this->timesFor($c, $i));
        $all = array_values(array_unique($all));
        sort($all);

        return $all;
    }

    public function onWeekday(ChatblastCampaign $c, Carbon $day): bool
    {
        $days = (array) ($c->weekdays ?? []);
        return !$days || in_array($day->isoWeekday(), array_map('intval', $days), true);
    }

    /**
     * Ang mga oras na dapat tumakbo ngayon: ngayong araw (kung kasama ang araw), dumating na, hindi lampas 3 oras, at
     * pagkatapos ng huling takbo. @return array<string, Carbon> "HH:MM" => oras ngayong araw (Manila)
     */
    public function due(ChatblastCampaign $c, Carbon $now): array
    {
        $now = $now->copy()->timezone('Asia/Manila');
        if (!$this->onWeekday($c, $now)) return [];

        $due = [];
        foreach ($this->allTimes($c) as $t) {
            $at = $now->copy()->setTimeFromTimeString($t);
            if ($at->gt($now) || $now->gte($at->copy()->addHours(self::LATE_LIMIT_HOURS))) continue;
            if ($c->auto_ran_at && $c->auto_ran_at->gte($at)) continue;
            $due[$t] = $at;
        }

        return $due;
    }

    /** Ang mga step (1 = Day 1) na may isa sa mga oras na ito. @param string[] $times @return int[] */
    public function stepsAt(ChatblastCampaign $c, array $times): array
    {
        $steps = [];
        foreach (array_keys((array) $c->days) as $i) {
            if (array_intersect($this->timesFor($c, $i), $times)) $steps[] = $i + 1;
        }

        return $steps;
    }

    /** Ang susunod na oras ng takbo (Manila), o null kapag manual, naka-off, o walang oras. */
    public function nextRun(ChatblastCampaign $c, Carbon $now): ?Carbon
    {
        if (!$c->active || $c->mode !== 'auto' || !($times = $this->allTimes($c))) return null;
        $now = $now->copy()->timezone('Asia/Manila');
        for ($i = 0; $i <= 7; $i++) {
            $day = $now->copy()->addDays($i)->startOfDay();
            if (!$this->onWeekday($c, $day)) continue;
            foreach ($times as $t) {
                $at = $day->copy()->setTimeFromTimeString($t);
                if ($at->gt($now)) return $at;
            }
        }

        return null;
    }

    /** Hal. "Araw-araw, 9:15 AM at 3:00 PM; Day 1 tuwing 10:00 AM". */
    public function summary(ChatblastCampaign $c): string
    {
        $days = array_map('intval', (array) ($c->weekdays ?? []));
        sort($days);
        $when = !$days || count($days) === 7 ? 'Araw-araw' : implode(', ', array_map(fn ($d) => self::DAY_NAMES[$d] ?? '?', $days));
        $times = (array) ($c->send_times ?? []);
        $text = $when . ', ' . ($times ? self::join(array_map([self::class, 'clock'], $times)) : 'walang oras');

        foreach ((array) ($c->step_times ?? []) as $i => $own) {
            if (is_array($own) && $own) $text .= '; Day ' . ($i + 1) . ' tuwing ' . self::join(array_map([self::class, 'clock'], $own));
        }
        if (!$c->active) return 'Naka-off. ' . $text;
        if ($c->mode !== 'auto') return 'Mano-mano (hindi sinusunod ang oras). ' . $text;

        return $text;
    }

    /**
     * Pagkatapos baguhin ang schedule ng campaign na auto: ang oras na dapat tumakbo ngayon sa bago pero hindi sa dati
     * (hal. bagong oras na lumipas na, o idinagdag ang araw na ito) ay itinatala bilang tumakbo na, kaya hindi ito
     * tatakbo; ang dating oras na hindi pa tumatakbo ay hindi naaapektuhan kapag mas huli ito. Hindi sine-save.
     */
    public function stampNewPastTimes(ChatblastCampaign $before, ChatblastCampaign $after): void
    {
        if ($after->mode !== 'auto' || $before->mode !== 'auto') return; // ang paglipat sa auto ay may sariling tatak
        $now = Carbon::now('Asia/Manila');
        $added = array_diff_key($this->due($after, $now), $this->due($before, $now));
        if (!$added) return;
        $latest = max($added);
        if (!$after->auto_ran_at || $after->auto_ran_at->lt($latest)) $after->auto_ran_at = $latest->copy()->timezone(config('app.timezone'));
    }

    /** "15:00" → "3:00 PM". */
    public static function clock(string $t): string
    {
        return Carbon::createFromFormat('H:i', $t)->format('g:i A');
    }

    private static function join(array $parts): string
    {
        if (count($parts) < 2) return implode('', $parts);
        $last = array_pop($parts);
        return implode(', ', $parts) . ' at ' . $last;
    }
}
