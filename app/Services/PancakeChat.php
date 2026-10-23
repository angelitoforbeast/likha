<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang iisang paraan para hanapin ang Pancake chat ng isang order. Pinakamalakas na tugma muna:
 * PSID (ang mismong customer) -> page + pangalan -> pangalan lang. Pinakabagong row ang kinukuha sa bawat hakbang.
 */
class PancakeChat
{
    public const MATCH_PSID      = 'psid';
    public const MATCH_PAGE_NAME = 'page_name';
    public const MATCH_NAME_ONLY = 'name_only';

    /**
     * @return array{chat:string, match:string}|null null kung walang row na tumugma
     */
    public function forOrder(?string $pageName, ?string $fbName, ?string $psid): ?array
    {
        if (!Schema::hasTable('pancake_conversations')) return null;

        $pageName = trim((string) $pageName);
        $fbName   = trim((string) $fbName);
        $psid     = trim((string) $psid);
        $pageId   = $this->pageId($pageName);

        // 1) PSID. Page-scoped ang PSID, kaya kapag alam ang page id ay doon lang; kung hindi, PSID lang.
        if ($psid !== '' && Schema::hasColumn('pancake_conversations', 'psid')) {
            $q = DB::table('pancake_conversations')->where('psid', $psid);
            if ($pageId !== null) $q->where('pancake_page_id', $pageId);

            $chat = $q->orderByDesc('id')->first(['customers_chat']);
            if ($chat) return $this->hit($chat, self::MATCH_PSID);
        }

        if ($fbName === '') return null;

        // 2) Page + pangalan
        if ($pageId !== null) {
            $chat = DB::table('pancake_conversations')
                ->where('pancake_page_id', $pageId)->where('full_name', $fbName)
                ->orderByDesc('id')->first(['customers_chat']);
            if ($chat) return $this->hit($chat, self::MATCH_PAGE_NAME);
        }

        // 3) Pangalan lang (maaaring ibang customer)
        $chat = DB::table('pancake_conversations')->where('full_name', $fbName)->orderByDesc('id')->first(['customers_chat']);

        return $chat ? $this->hit($chat, self::MATCH_NAME_ONLY) : null;
    }

    private function hit(object $row, string $match): array
    {
        return ['chat' => trim((string) ($row->customers_chat ?? '')), 'match' => $match];
    }

    /** Pancake page id ng page name (pancake_id mapping), o null kung hindi mapped. */
    private function pageId(string $pageName): ?string
    {
        if ($pageName === '' || !Schema::hasTable('pancake_id')) return null;

        $id = DB::table('pancake_id')
            ->whereRaw('LOWER(TRIM(pancake_page_name)) = ?', [mb_strtolower($pageName)])
            ->value('pancake_page_id');

        return $id !== null && $id !== '' ? (string) $id : null;
    }
}
