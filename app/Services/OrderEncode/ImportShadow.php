<?php

namespace App\Services\OrderEncode;

use App\Jobs\EncodeOrder;
use App\Models\AppSetting;
use App\Models\MacroOutput;
use App\Models\OrderEncode;
use App\Services\Chatbot\FlowGraph;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ang shadow encode ng bawat bagong order na ginawa ng Likha import (tab TO WEBSITE), sa lahat ng page. Dispatch lang:
 * walang GPT sa import job, at anumang error dito ay hindi kailanman nagpapabagsak sa import. Ang switch, ang daily cap at ang
 * skip list ay nasa app_settings.
 */
class ImportShadow
{
    public const SWITCH = 'order_encode_import_shadow';
    public const CAP = 'order_encode_import_daily_cap';
    public const SKIP_PAGES = 'order_encode_import_skip_pages';
    public const SKIPPED = 'order_encode_import_skipped'; // {date, cap, pages}: ilang order ang hindi na-encode ngayong araw
    public const DEFAULT_CAP = 2000;
    public const MODE = 'import';

    private ?bool $on = null;

    public function on(): bool
    {
        return $this->on ??= strtolower(trim($this->app(self::SWITCH))) === 'on';
    }

    public function cap(): int
    {
        $v = trim($this->app(self::CAP));

        return ctype_digit($v) ? (int) $v : self::DEFAULT_CAP;
    }

    /** Ang nakasulat na listahan (comma) para sa form. */
    public function skipPagesText(): string
    {
        return trim($this->app(self::SKIP_PAGES));
    }

    /** @return array<int, string> lowercase, walang puwang sa dulo */
    public function skipPages(): array
    {
        return array_values(array_filter(array_map(fn ($p) => mb_strtolower(trim($p)), explode(',', $this->skipPagesText())), fn ($p) => $p !== ''));
    }

    /** @return array{date: string, cap: int, pages: int} ang mga hindi na-encode ngayong araw (cap, skip list) */
    public function skippedToday(): array
    {
        $today = now()->toDateString();
        $saved = json_decode($this->app(self::SKIPPED), true);

        return is_array($saved) && ($saved['date'] ?? '') === $today
            ? ['date' => $today, 'cap' => (int) ($saved['cap'] ?? 0), 'pages' => (int) ($saved['pages'] ?? 0)]
            : ['date' => $today, 'cap' => 0, 'pages' => 0];
    }

    /** @param 'cap'|'pages' $why */
    public function countSkipped(string $why): void
    {
        $now = $this->skippedToday();
        $now[$why]++;
        AppSetting::set(self::SKIPPED, json_encode($now));
    }

    /** Ilan na ang na-queue na import encode ngayong araw. */
    public function encodedToday(): int
    {
        return OrderEncode::where('mode', self::MODE)->where('created_at', '>=', now()->startOfDay())->count();
    }

    /**
     * Pagkatapos gumawa ang import ng bagong macro_output row. $row ay ang raw na A..I ng TO WEBSITE, $sheetRow ang row number nito
     * (kapareho ng All Orders). Ang order na na-encode na ng flow hook (parehong sheet, tab at row) ay hindi inuulit: ang macro_output_id lang ang itinatakda.
     */
    public function created(array $row, MacroOutput $made, ?string $sheetId, ?int $sheetRow): void
    {
        try {
            if ($sheetId === null || $sheetRow === null || !$this->on() || !Schema::hasTable('order_encodes')) return;
            $sheetId = FlowGraph::spreadsheetId($sheetId) ?? $sheetId;

            $existing = OrderEncode::where('spreadsheet_id', $sheetId)->where('row_number', $sheetRow)->whereRaw('LOWER(tab) = ?', [mb_strtolower(FlowHook::TAB)])->first();
            if ($existing) {
                if (!$existing->macro_output_id) $existing->update(['macro_output_id' => $made->id]);

                return;
            }

            $page = (string) $made->PAGE;
            if (in_array(mb_strtolower(trim($page)), $this->skipPages(), true)) {
                $this->countSkipped('pages');

                return;
            }
            if ($this->encodedToday() >= $this->cap()) {
                $this->countSkipped('cap');

                return;
            }

            $order = OrderEncode::firstOrCreate(['spreadsheet_id' => $sheetId, 'tab' => FlowHook::TAB, 'row_number' => $sheetRow], [
                'page' => $page, 'mode' => self::MODE, 'status' => 'queued', 'macro_output_id' => $made->id,
                'input' => ['row' => array_map('strval', array_slice(array_pad(array_values($row), 8, ''), 0, 8)), 'source' => 'import'],
            ]);
            if (!$order->wasRecentlyCreated) return;
            try {
                EncodeOrder::dispatch($order->id);
            } catch (Throwable $e) {
                $order->update(['status' => 'failed', 'error' => 'The encode could not be queued.']); // walang naiwang queued na hindi tatakbo kailanman
                report($e);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function app(string $key): string
    {
        try {
            return (string) AppSetting::get($key, '');
        } catch (Throwable) {
            return ''; // wala pang app_settings table
        }
    }
}
