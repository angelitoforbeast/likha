<?php

namespace Tests\Feature\OrderEncode;

use App\Services\OrderEncode\Gpt;
use App\Services\OrderEncode\Locations;
use App\Services\OrderEncode\Steps;

/** Maliit na address file (parehong anyo ng jnt_address.txt) at ang mga fake na sagot ng OpenAI. */
final class Fixtures
{
    public const KEY = 'sk-test-secret-key-0000';

    /** @return array<int, array{0: string, 1: string, 2: string}> PROVINCE, CITY, BARANGAY ayon sa ayos ng file */
    public static function rows(): array
    {
        return [
            ['AGUSAN-DEL-SUR', 'AGUSAN-DEL-SUR-ESPERANZA', 'CEBULAN'],
            ['CEBU', 'CEBU-CITY', 'LAHUG'],
            ['CEBU', 'CEBU-CITY', 'BANILAD'],
            ['CEBU', 'MANDAUE-CITY', 'BAKILID'],
            ['CEBU', 'MANDAUE-CITY', 'BARANGAY 5'],
            ['METRO-MANILA', 'QUEZON-CITY', 'BAGUMBAYAN'],
            ['METRO-MANILA', 'MAKATI-CITY', 'POBLACION'],
            ['ABRA', 'ABRA-DOLORES', 'BAYAAN'],
            ['CAMARINES-SUR', 'NAGA-CITY', 'PENAFRANCIA'],
        ];
    }

    /** Ang laman ng file: isang PROVINCE|CITY|BARANGAY kada linya. */
    public static function fileText(): string
    {
        return implode("\n", array_map(fn ($r) => implode('|', $r), self::rows())) . "\n";
    }

    public static function locations(): Locations
    {
        return new Locations(self::rows());
    }

    public static function gpt(?Steps $steps = null, string $key = self::KEY): Gpt
    {
        return new Gpt($key, 'gpt-4o-mini', 'gpt-5.2', $steps ?? new Steps($key));
    }

    /** Ang sagot ng Responses API na may text. */
    public static function responses(string $text, int $in = 10, int $out = 5): array
    {
        return ['output_text' => $text, 'usage' => ['input_tokens' => $in, 'output_tokens' => $out]];
    }

    /** Ang sagot ng chat completions na may text. */
    public static function chat(string $text, int $in = 10, int $out = 5): array
    {
        return ['choices' => [['message' => ['content' => $text]]], 'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out]];
    }
}
