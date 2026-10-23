<?php

namespace Tests\Feature\OrderEncode;

use App\Services\OrderEncode\Gpt;
use App\Services\OrderEncode\Locations;
use App\Services\OrderEncode\Steps;

/** Maliit na LocationData para sa tests (ayon sa ayos ng sheet) at ang mga fake na sagot ng OpenAI. */
final class Fixtures
{
    public const KEY = 'sk-test-secret-key-0000';

    /** @return array<int, array{0: string, 1: string, 2: string, 3: string}> A, B, C, D ng macro sheet */
    public static function macroRows(): array
    {
        return array_map(fn ($r) => [$r[0] . ' ' . $r[1] . ' ' . $r[2], $r[0], $r[1], $r[2]], [
            ['AGUSAN-DEL-SUR', 'AGUSAN-DEL-SUR-ESPERANZA', 'CEBULAN'],
            ['CEBU', 'CEBU-CITY', 'LAHUG'],
            ['CEBU', 'CEBU-CITY', 'BANILAD'],
            ['CEBU', 'MANDAUE-CITY', 'BAKILID'],
            ['METRO-MANILA', 'QUEZON-CITY', 'BAGUMBAYAN'],
            ['METRO-MANILA', 'MAKATI-CITY', 'POBLACION'],
            ['ABRA', 'ABRA-DOLORES', 'BAYAAN'],
        ]);
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> B, C, D ng AFTER MACRO */
    public static function checkerRows(): array
    {
        return [
            ['CEBU', 'CEBU CITY', 'LAHUG'],
            ['CEBU', 'CEBU CITY', 'BANILAD'],
            ['CEBU', 'MANDAUE CITY', 'BAKILID'],
            ['CEBU', 'MANDAUE CITY', 'BARANGAY 5'],
            ['METRO MANILA', 'QUEZON CITY', 'BAGUMBAYAN'],
            ['METRO MANILA', 'MAKATI CITY', 'POBLACION'],
            ['ABRA', 'DOLORES', 'BAYAAN'],
        ];
    }

    public static function locations(): Locations
    {
        return new Locations(self::checkerRows());
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
