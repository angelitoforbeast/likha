<?php

namespace Tests\Feature\PancakePsid;

use App\Models\MacroOutput;
use App\Services\AstraEncoder;
use App\Services\BotcakeCustomerMatcher;
use App\Services\MacroChecker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Spec 307 FU-4: ang kaso ng 18/11 mula simula hanggang dulo, na may pekeng model. Ang order ay "Facebook User" na may
 * sariling PSID na wala pang Pancake conversation; ang conversation ng ibang "Facebook User" (si Mercedita) ay nasa
 * parehong page. Si Astra ay gumagawa mula sa sariling input ng order lamang.
 */
class PlaceholderAstraTest extends PancakePsidTestCase
{
    private const PAGE = 'Elyse Abad';
    private const PAGE_ID = '6001';
    private const OWN_PHONE = '639913485181';

    private static ?array $maps = null;

    /** @var Request[] lahat ng request sa pekeng model */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.openai.astra_encoder_max_web' => 4]);
        AstraEncoder::storeApiKey('test-key-not-real');
        $this->mapPage(self::PAGE, self::PAGE_ID);
    }

    private function maps(): array
    {
        return self::$maps ??= MacroChecker::loadAddressMaps();
    }

    /** Ang order ng customer (sariling pangalan, phone at address sa sariling chat). */
    private function order(): MacroOutput
    {
        return MacroOutput::create([
            'TIMESTAMP'      => '20:41 18-11-2026',
            'ts_date'        => '2026-11-18',
            'PAGE'           => self::PAGE,
            'fb_name'        => 'Facebook User',
            'FULL NAME'      => 'Facebook User',
            'PHONE NUMBER'   => self::OWN_PHONE,
            'botcake_psid'   => '25000000000000001',
            'ITEM_NAME'      => 'Slimming Tea',
            'COD'            => '599',
            'all_user_input' => "FB NAME: Facebook User\nJuan Dela Cruz\n" . self::OWN_PHONE . "\n12 Sampaguita St, Holy Spirit, Quezon City",
        ]);
    }

    /**
     * Ang pekeng model: kapag may "Mercedita" sa natanggap, ang pangalan at phone niya ang isasagot (gaya ng nangyari noong
     * 18/11); kung wala, ang sariling datos ng order. Sa unang round ay humihingi ito ng get_chat_history.
     */
    private function fakeModel(): void
    {
        Http::fake(['api.openai.com/v1/responses' => function (Request $request) {
            $this->sent[] = $request;
            if (count($this->sent) === 1) {
                return Http::response(['id' => 'resp_1', 'output' => [
                    ['type' => 'function_call', 'name' => 'get_chat_history', 'call_id' => 'call_1', 'arguments' => '{}'],
                ], 'usage' => ['input_tokens' => 100, 'output_tokens' => 10]]);
            }
            $borrowed = str_contains($request->body(), 'Mercedita');

            return Http::response(['id' => 'resp_2', 'output_text' => json_encode([
                'form' => [
                    'name' => $borrowed ? 'Mercedita Santos' : 'Juan Dela Cruz', 'phone' => $borrowed ? '09170000001' : '09913485181',
                    'house_number' => '12', 'address' => 'Sampaguita St', 'brgy' => 'Holy Spirit', 'city' => 'Quezon City', 'province' => 'Metro Manila',
                ],
                'jnt' => ['province' => 'METRO-MANILA', 'city' => 'QUEZON-CITY', 'barangay' => 'HOLY SPIRIT'],
                'intent' => 'order', 'confidence' => 'high',
            ]), 'usage' => ['input_tokens' => 100, 'output_tokens' => 10]]);
        }]);
    }

    public function test_FU_4_the_18_11_case_end_to_end_keeps_the_rows_own_phone_and_never_shows_mercedita_to_the_model(): void
    {
        $this->conv(self::PAGE_ID, 'Facebook User', "Name: Mercedita Santos\nPhone: 09170000001\nAddress: Purok 3, Brgy Uno", '25000000000000002');
        $order = $this->order();
        $this->fakeModel();

        $result = (new AstraEncoder())->processRow($order->id, $this->maps(), 'https://likhaaitech.com');

        $this->assertCount(2, $this->sent, 'ang unang prompt at ang sagot ng get_chat_history');
        $prompt = (string) $this->sent[0]['input'];
        $this->assertStringContainsString('EARLIER CONVERSATION: none found.', $prompt);
        $this->assertStringContainsString('Juan Dela Cruz', $prompt);
        $toolOutput = json_decode((string) $this->sent[1]['input'][0]['output'], true);
        $this->assertSame(['found' => false, 'chat_history' => 'No earlier conversation found.'], $toolOutput);
        foreach ($this->sent as $request) {
            $this->assertStringNotContainsString('Mercedita', $request->body());
            $this->assertStringNotContainsString('09170000001', $request->body());
        }

        $row = $order->fresh();
        $this->assertSame(BotcakeCustomerMatcher::normPhone(self::OWN_PHONE), BotcakeCustomerMatcher::normPhone($row->{'PHONE NUMBER'}));
        $this->assertStringNotContainsString('Mercedita', (string) $row->{'FULL NAME'});
        $this->assertStringNotContainsString('Mercedita', (string) $row->CXD);
        $this->assertContains('PANCAKE: walang history', $result['log']['evidence']);
    }
}
