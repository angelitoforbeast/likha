<?php

namespace Tests\Feature\OrderEncode;

use App\Models\AppSetting;
use App\Models\OrderEncode;
use App\Services\OrderEncode\Runner;
use App\Services\OrderEncode\Settings;
use Illuminate\Support\Facades\Http;

/** Ang buong takbo ng isang order (Runner) na may fake na OpenAI at sariling J&T list, para sa mga test ng spec 135. */
abstract class EncodeRunTestCase extends OrderEncodeTestCase
{
    /** @var array<int, string> ang mga gawain ng GPT na tinawag, ayon sa ayos */
    protected array $tasks = [];

    protected function writeList(array $rows): void
    {
        file_put_contents($this->addressFile, implode("\n", array_map(fn ($r) => implode('|', $r), $rows)) . "\n");
    }

    /**
     * Ang buong takbo ng isang order (Runner), na may fake na OpenAI. $answers: gawain => ang JSON na isasagot ng GPT sa checker.
     * @param array<string, array> $answers province | city | barangay | verify
     */
    protected function runOrder(string $cxd, array $rows, array $answers = [], string $rules = 'improved', array $over = []): OrderEncode
    {
        $this->writeList($rows);
        AppSetting::set(Settings::RULES, $rules);
        $this->tasks = [];
        $answers += ['verify' => ['province_ok' => true, 'city_ok' => true, 'barangay_ok' => true], 'name_addr' => ['full_name' => 'Ana Cruz', 'address_line1' => 'Blk 5']];

        Http::fake([
            'api.openai.com/v1/chat/completions' => fn ($r) => Http::response(Fixtures::chat(str_contains(json_encode($r->data()), 'Extract Address Line 1') ? 'Blk 5 Lot 3' : '')),
            'api.openai.com/v1/responses' => function ($r) use ($answers) {
                $text = json_encode($r->data(), JSON_UNESCAPED_UNICODE);
                $task = match (true) {
                    str_contains($text, 'correct the PROVINCE') => 'province',
                    str_contains($text, 'correct the CITY') => 'city',
                    str_contains($text, 'correct BARANGAY') => 'barangay',
                    str_contains($text, 'province_ok') => 'verify',
                    default => 'name_addr',
                };
                $this->tasks[] = $task;
                $this->prompts[$task] = $text;

                return Http::response(Fixtures::responses(json_encode($answers[$task] ?? new \stdClass())));
            },
        ]);

        $o = OrderEncode::create([
            'page' => self::PAGE, 'spreadsheet_id' => self::ORDERS, 'tab' => 'All Orders', 'row_number' => random_int(1000, 999999), 'mode' => 'import', 'status' => 'queued',
            'input' => ['source' => 'import', 'row' => $this->withCxd($cxd, $over)],
        ]);
        app(Runner::class)->run($o);

        return $o->fresh();
    }

    /** @var array<string, string> */
    protected array $prompts = [];

    /** Ang row ng order (A..H) na ang G ay ang extracted details. */
    protected function withCxd(string $cxd, array $over = []): array
    {
        $row = $this->orderRow();
        $row[6] = $cxd;
        foreach ($over as $i => $v) $row[$i] = $v;

        return $row;
    }

    protected function cxd(string $province, string $city, string $brgy, string $name = 'Ana Cruz', string $phone = '09171234567'): string
    {
        return "- Name: {$name}\n- Phone Number: {$phone}\n- Province: {$province}\n- City: {$city}\n- Brgy: {$brgy}\n- Address: Blk 5 Lot 3";
    }

    protected function step(OrderEncode $o, string $step): array
    {
        return collect($o->steps)->firstWhere('step', $step) ?? [];
    }

    protected static function bungadRows(): array
    {
        return [
            ['ISABELA', 'ISABELA-SAN-PABLO', 'BUNGAD'],
            ['METRO-MANILA', 'QUEZON-CITY', 'BUNGAD'],
            ['METRO-MANILA', 'QUEZON-CITY', 'PROJECT 7'],
            ['METRO-MANILA', 'CALOOCAN-CITY', 'BUNGAD'],
        ];
    }
}
