<?php

namespace App\Services;

use App\Http\Controllers\OwnerPrivateController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Note ni Claude per (page, araw) — hiwalay na tables (page_day_claude_actions + logs).
 * Artisan command lang ang sumusulat; ang /owner/private (CEO lang) ang bumabasa.
 * Query builder lang (gaya ng controller); 'Y-m-d' strings ang petsa, walang date cast.
 */
class PageDayClaudeActionService
{
    private const MAX_PAGE_KEY = 255;
    private const MAX_ACTION   = 2000;
    private const MAX_REASON   = 4000;
    private const MAX_SOURCE   = 255;

    /**
     * Insert / update. null = hindi ipinasa (panatilihin ang naka-store); '' = burahin ang field na iyon.
     * Sobrang haba = REJECT (hindi pinuputol).
     *
     * @return array{status: string, page_key: string, ts_date: string}
     */
    public function save(string $pageKey, string $tsDate, ?string $action, ?string $reason, ?string $source): array
    {
        [$pageKey, $tsDate] = $this->validateKey($pageKey, $tsDate);
        $action = $this->cleanText($action, self::MAX_ACTION, 'action');
        $reason = $this->cleanText($reason, self::MAX_REASON, 'reason');
        $source = $this->cleanText($source, self::MAX_SOURCE, 'source');

        $status = DB::transaction(function () use ($pageKey, $tsDate, $action, $reason, $source) {
            $existing = $this->find($pageKey, $tsDate);

            $newAction = $action === null ? ($existing->action ?? null) : ($action !== '' ? $action : null);
            $newReason = $reason === null ? ($existing->reason ?? null) : ($reason !== '' ? $reason : null);
            $newSource = $source === null ? ($existing->source ?? null) : ($source !== '' ? $source : null);

            if ($newAction === null && $newReason === null) {
                throw new InvalidArgumentException('Action and reason are both empty; use --clear to delete the note.');
            }

            if ($existing
                && $newAction === $existing->action
                && $newReason === $existing->reason
                && $newSource === $existing->source) {
                return 'unchanged';
            }

            if ($existing) {
                DB::table('page_day_claude_actions')
                    ->where('page_key', $pageKey)
                    ->where('ts_date', $tsDate)
                    ->update(['action' => $newAction, 'reason' => $newReason, 'source' => $newSource, 'updated_at' => now()]);
            } else {
                DB::table('page_day_claude_actions')->insert([
                    'page_key'   => $pageKey,
                    'ts_date'    => $tsDate,
                    'action'     => $newAction,
                    'reason'     => $newReason,
                    'source'     => $newSource,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->audit($pageKey, $tsDate, $existing->action ?? null, $newAction, $existing->reason ?? null, $newReason, $newSource);

            return $existing ? 'updated' : 'inserted';
        });

        if ($status !== 'unchanged') {
            OwnerPrivateController::bumpCacheVersion();
        }

        return ['status' => $status, 'page_key' => $pageKey, 'ts_date' => $tsDate];
    }

    /**
     * Burahin ang note (may audit row kung may nabura).
     *
     * @return array{status: string, page_key: string, ts_date: string}
     */
    public function clear(string $pageKey, string $tsDate, ?string $source = null): array
    {
        [$pageKey, $tsDate] = $this->validateKey($pageKey, $tsDate);
        $source = $this->cleanText($source, self::MAX_SOURCE, 'source');

        $status = DB::transaction(function () use ($pageKey, $tsDate, $source) {
            $existing = $this->find($pageKey, $tsDate);
            if (!$existing) {
                return 'nothing';
            }

            DB::table('page_day_claude_actions')
                ->where('page_key', $pageKey)
                ->where('ts_date', $tsDate)
                ->delete();

            $logSource = ($source !== null && $source !== '') ? $source : $existing->source;
            $this->audit($pageKey, $tsDate, $existing->action, null, $existing->reason, null, $logSource);

            return 'cleared';
        });

        if ($status === 'cleared') {
            OwnerPrivateController::bumpCacheVersion();
        }

        return ['status' => $status, 'page_key' => $pageKey, 'ts_date' => $tsDate];
    }

    /**
     * Mga note para sa isang petsa (END date ng main table).
     *
     * @return array<string, array{action: ?string, reason: ?string, at: ?string, source: ?string}>
     */
    public function forDate(string $tsDate): array
    {
        if (!Schema::hasTable('page_day_claude_actions')) {
            return [];
        }

        $map = [];
        $rows = DB::table('page_day_claude_actions')
            ->where('ts_date', $tsDate)
            ->get(['page_key', 'action', 'reason', 'source', 'updated_at']);
        foreach ($rows as $r) {
            $entry = $this->entry($r);
            if ($entry !== null) {
                $map[(string) $r->page_key] = $entry;
            }
        }

        return $map;
    }

    /**
     * Mga note ng isang page sa isang range (breakdown), key = 'Y-m-d'.
     *
     * @return array<string, array{action: ?string, reason: ?string, at: ?string, source: ?string}>
     */
    public function forPage(string $pageKey, string $startDate, string $endDate): array
    {
        if (!Schema::hasTable('page_day_claude_actions')) {
            return [];
        }

        $map = [];
        $rows = DB::table('page_day_claude_actions')
            ->where('page_key', $pageKey)
            ->whereBetween('ts_date', [$startDate, $endDate])
            ->get(['ts_date', 'action', 'reason', 'source', 'updated_at']);
        foreach ($rows as $r) {
            $entry = $this->entry($r);
            if ($entry !== null) {
                $map[substr((string) $r->ts_date, 0, 10)] = $entry;
            }
        }

        return $map;
    }

    /** Isang row → payload shape; null kung walang laman ang action at reason. */
    private function entry(object $r): ?array
    {
        $action = trim((string) ($r->action ?? ''));
        $reason = trim((string) ($r->reason ?? ''));
        if ($action === '' && $reason === '') {
            return null;
        }

        return [
            'action' => $action !== '' ? $action : null,
            'reason' => $reason !== '' ? $reason : null,
            'at'     => $r->updated_at ? substr((string) $r->updated_at, 0, 16) : null,
            'source' => ($r->source ?? '') !== '' ? $r->source : null,
        ];
    }

    private function find(string $pageKey, string $tsDate): ?object
    {
        return DB::table('page_day_claude_actions')
            ->where('page_key', $pageKey)
            ->where('ts_date', $tsDate)
            ->first(['action', 'reason', 'source']);
    }

    private function audit(string $pageKey, string $tsDate, ?string $oldAction, ?string $newAction, ?string $oldReason, ?string $newReason, ?string $source): void
    {
        DB::table('page_day_claude_action_logs')->insert([
            'page_key'   => $pageKey,
            'ts_date'    => $tsDate,
            'old_action' => $oldAction,
            'new_action' => $newAction,
            'old_reason' => $oldReason,
            'new_reason' => $newReason,
            'source'     => $source,
            'edited_at'  => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{0: string, 1: string} */
    private function validateKey(string $pageKey, string $tsDate): array
    {
        $pageKey = trim($pageKey);
        if ($pageKey === '' || mb_strlen($pageKey) > self::MAX_PAGE_KEY) {
            throw new InvalidArgumentException('page_key must be 1 to ' . self::MAX_PAGE_KEY . ' characters.');
        }

        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tsDate, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('ts_date must be a real date in Y-m-d format.');
        }

        return [$pageKey, $tsDate];
    }

    /** null = hindi ipinasa; sobrang haba = reject. */
    private function cleanText(?string $text, int $max, string $name): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim($text);
        if (mb_strlen($text) > $max) {
            throw new InvalidArgumentException("$name is too long (max $max characters).");
        }

        return $text;
    }
}
