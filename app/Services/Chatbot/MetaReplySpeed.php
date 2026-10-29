<?php

namespace App\Services\Chatbot;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Ang unang section ng /chatbot/speed: ang totoong reply time sa orasan ng Meta (customer sent → sagot namin sent),
 * kada oras, galing sa Forbeast (GET /v1/site/reply-speed). Ang mga page lang na nasa Forbeast ang kasama. Hindi ito
 * kinukuwenta rito: ang Forbeast ang may oras ng Meta sa magkabilang dulo. Ang tawag ay may hangganang 5 s at naka-cache
 * (ngayon 2 minuto, ang lumang araw 30 minuto); ang hindi sumagot ay naaalala nang 1 minuto, para ang Forbeast na
 * mabagal o patay ay hindi nagpapabagal sa bawat bukas ng screen. Ang sagot ay untrusted: numero lang ang kinukuha.
 */
class MetaReplySpeed
{
    public const TIMEOUT = 5;
    public const LIVE_TIMEOUT = 3;
    public const TODAY_CACHE_SECONDS = 120;
    public const PAST_CACHE_SECONDS = 1800;
    public const FAILED_CACHE_SECONDS = 60;
    private const COUNTS = ['messages', 'bot_answered', 'person_answered', 'unanswered'];
    /** Ang pangalan dito => ang field ng Forbeast (segundo). */
    private const SECONDS = ['avg' => 'avg_s', 'median' => 'median_s', 'p90' => 'p90_s', 'max' => 'max_s', 'meta_to_forbeast_avg' => 'meta_to_forbeast_avg_s'];

    public function __construct(private ForbeastSite $site)
    {
    }

    /**
     * Ang section para sa filter ng screen (date, page_id, bot). state: ok, botcake (Bot filter na Botcake: walang
     * oras ng Meta), not_linked (walang tawag) o error (hindi sumagot ang Forbeast; ang at ay ang oras ng subok).
     * @return array{state: string, error: ?string, at: ?string, hours: ?array, total: ?array, chart: ?array}
     */
    public function output(array $f, string $today): array
    {
        $blank = ['state' => 'ok', 'error' => null, 'at' => null, 'hours' => null, 'total' => null, 'chart' => null];
        if ($f['bot'] === ChatbotConnections::BOTS[0]) return ['state' => 'botcake'] + $blank;
        if (!ForbeastSite::linked()) return ['state' => 'not_linked'] + $blank;
        try {
            $started = microtime(true);
            $day = $this->day($f['date'], $f['page_id'], $today, self::TIMEOUT);
            if (!$day['ok']) return ['state' => 'error', 'error' => $day['error'], 'at' => $day['at']] + $blank;
            // Ang reference ng chart: ang naunang araw, parehong page, kinuha sa parehong paraan pero sa natitira lang
            // sa 5 s (hindi bababa sa 1 s), para hindi maghintay ang screen nang dalawang buong timeout. Kapag hindi ito
            // sumagot, wala lang ang dotted na linya.
            $left = max(1, self::TIMEOUT - (int) ceil(microtime(true) - $started));
            $prev = $this->day(CarbonImmutable::parse($f['date'])->subDay()->toDateString(), $f['page_id'], $today, $left);
        } catch (Throwable $e) {
            report($e);

            return ['state' => 'error', 'error' => 'Something went wrong reading it.', 'at' => now()->format('H:i:s')] + $blank;
        }

        return ['hours' => $day['hours'], 'total' => $day['total'],
            'chart' => self::chart($day['hours'], $prev['ok'] ? $prev['hours'] : array_fill(0, 24, null))] + $blank;
    }

    /**
     * Ang average ngayong araw (segundo, orasan ng Meta) para sa Live page, mula sa parehong cache ng screen na ito. state:
     * ok (seconds ay null kapag wala pang sagot ngayong araw), not_linked (walang tawag) o error (hindi sumagot ang Forbeast).
     * Mas maikli ang hangganan ng tawag: ang Live ay pino-poll, at ang pagkuha ay kadalasang pagkatapos na ng sagot.
     * @return array{state: string, seconds: ?float}
     */
    public function todayAverage(?string $page): array
    {
        if (!ForbeastSite::linked()) return ['state' => 'not_linked', 'seconds' => null];
        try {
            $today = now()->toDateString();
            $day = $this->day($today, $page, $today, self::LIVE_TIMEOUT);
        } catch (Throwable $e) {
            report($e);

            return ['state' => 'error', 'seconds' => null];
        }

        return $day['ok'] ? ['state' => 'ok', 'seconds' => $day['total']['avg']] : ['state' => 'error', 'seconds' => null];
    }

    /** @return array{ok: true, hours: array, total: array}|array{ok: false, error: string, at: string} */
    private function day(string $date, ?string $page, string $today, int $timeout): array
    {
        $key = 'meta-reply-speed:v1:' . md5(json_encode([$date, $page]));
        $hit = Cache::get($key);
        if (is_array($hit) && isset($hit['ok'])) return $hit;

        $res = $this->site->replySpeed($date, $page, $timeout);
        $read = $res['ok'] ? self::read($res['body']) : null;
        $out = $read !== null ? ['ok' => true] + $read : ['ok' => false, 'at' => now()->format('H:i:s'),
            'error' => match (true) {
                $res['ok'] => 'Its answer could not be read.',
                $res['status'] === 0 => 'No answer within ' . $timeout . ' s.',
                // Ang page na wala sa Forbeast (hal. nasa Botcake, o sa kabilang site) ay hindi "hindi sumagot".
                $res['status'] === 404 && ($res['body']['error'] ?? null) === 'unknown_page' => 'This page is not on Forbeast for this site.',
                default => (string) $res['error'],
            }];
        Cache::put($key, $out, !$out['ok'] ? self::FAILED_CACHE_SECONDS : ($date === $today ? self::TODAY_CACHE_SECONDS : self::PAST_CACHE_SECONDS));

        return $out;
    }

    /**
     * Ang sagot ng Forbeast bilang 24 na oras (null = wala sa sagot: ang hinaharap ng ngayon) at ang Day. null kapag
     * hindi ito ang hugis na inaasahan.
     * @return array{hours: array, total: array}|null
     */
    private static function read(array $body): ?array
    {
        if (!is_array($body['hours'] ?? null) || !array_is_list($body['hours']) || !is_array($body['total'] ?? null)) return null;
        $hours = array_fill(0, 24, null);
        foreach ($body['hours'] as $h) {
            if (!is_array($h) || !is_int($h['hour'] ?? null) || $h['hour'] < 0 || $h['hour'] > 23) return null;
            $hours[$h['hour']] = ['hour' => $h['hour']] + self::row($h);
        }

        return ['hours' => $hours, 'total' => self::row($body['total'])];
    }

    private static function row(array $r): array
    {
        $out = [];
        foreach (self::COUNTS as $k) $out[$k] = is_int($r[$k] ?? null) && $r[$k] >= 0 ? $r[$k] : 0;
        foreach (self::SECONDS as $k => $field) {
            $v = $r[$field] ?? null;
            $out[$k] = (is_int($v) || is_float($v)) && is_finite((float) $v) && $v >= 0 ? (float) $v : null;
        }

        return $out;
    }

    /** Ang chart: average, median at p90 kada oras (null = puwang), ang naunang araw bilang reference, gaya ng ibang chart. */
    private static function chart(array $hours, array $prev): array
    {
        $col = fn (array $rows, string $k) => array_map(fn ($r) => $r === null ? null : $r[$k], $rows);
        $out = [];
        foreach (['avg', 'median', 'p90'] as $k) {
            $out['series'][$k] = $col($hours, $k);
            $out['prev'][$k] = $col($prev, $k);
        }
        foreach (['messages' => 'messages', 'bot answered' => 'bot_answered', 'by a person' => 'person_answered', 'unanswered' => 'unanswered'] as $label => $k) {
            $out['counts'][$label] = $col($hours, $k);
            $out['prev_counts'][$label] = $col($prev, $k);
        }
        // Gaya ng ibang chart: ang average lang ang nakikita sa simula, at ang taas ng axis ay mula rito.
        $out['shown'] = ['avg'];
        $out['top'] = ResponseSpeed::axisTop($out['series']['avg'], $out['prev']['avg']);

        return $out;
    }
}
