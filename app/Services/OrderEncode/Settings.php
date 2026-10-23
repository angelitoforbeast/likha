<?php

namespace App\Services\OrderEncode;

use App\Models\AppSetting;
use App\Services\Chatbot\FlowGraph;
use App\Services\PageSettings;
use Throwable;

/**
 * Ang config ng order encode: bawat page sa page_settings (provider order_encode: mode, compare_sheet) at ang
 * global sa app_settings (mga model). Ang OpenAI key ay sa config lang.
 */
class Settings
{
    public const PROVIDER = 'order_encode';
    public const KEYS = ['mode', 'compare_sheet'];
    public const MODES = ['off', 'shadow', 'live'];

    public const MODEL_EXTRACT = 'order_encode_model_extract';
    public const MODEL_CHECKER = 'order_encode_model_checker';
    public const DEFAULT_EXTRACT = 'gpt-4o-mini';
    public const DEFAULT_CHECKER = 'gpt-5.2';

    public function __construct(private PageSettings $pages)
    {
    }

    /** off / shadow / live; anumang iba o walang setting = off. */
    public function mode(string $page): string
    {
        $mode = strtolower(trim((string) $this->pages->get($page, self::PROVIDER, 'mode')));

        return in_array($mode, self::MODES, true) ? $mode : 'off';
    }

    /** Ang id ng AFTER MACRO (compare_sheet) ng page, o null. */
    public function sheetId(string $page, string $key): ?string
    {
        $v = $this->pages->get($page, self::PROVIDER, $key);

        return $v === null ? null : FlowGraph::spreadsheetId($v);
    }

    public function modelExtract(): string
    {
        return $this->app(self::MODEL_EXTRACT, self::DEFAULT_EXTRACT);
    }

    public function modelChecker(): string
    {
        return $this->app(self::MODEL_CHECKER, self::DEFAULT_CHECKER);
    }

    public function key(): string
    {
        return trim((string) config('services.openai.order_encode_key'));
    }

    private function app(string $key, string $default): string
    {
        try {
            $v = trim((string) AppSetting::get($key, ''));
        } catch (Throwable) {
            $v = ''; // wala pang app_settings table (hal. sa test)
        }

        return $v !== '' ? $v : $default;
    }
}
