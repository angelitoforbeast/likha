<?php

namespace Tests\Feature\CardReconcile;

use App\Services\CardStatement\StatementReadException;
use App\Services\CardStatement\StatementVision;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Screenshot ng statement → OpenAI vision (fake) → mga linya. Mahigpit ang JSON; mali = tanggi, walang naise-save. */
class CardStatementVisionTest extends TestCase
{
    private string $image;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.openai.key' => 'sk-test-fake', 'services.card_statement.model' => 'vision-test-model']);
        $this->image = tempnam(sys_get_temp_dir(), 'stmt');
        file_put_contents($this->image, "\x89PNG fake bytes");
    }

    protected function tearDown(): void
    {
        @unlink($this->image);
        parent::tearDown();
    }

    private function answer(string $content, int $status = 200): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => $content]]]], $status)]);
    }

    public function test_S_049_4_strict_json_lines_from_the_vision_model_are_read(): void
    {
        $this->answer(json_encode(['lines' => [
            ['sale_date' => '2026-09-09', 'post_date' => '2026-09-10', 'description' => 'FACEBK *AB12CD34EF DUBLIN IR', 'amount' => 6278.17, 'is_credit' => false],
            ['sale_date' => null, 'post_date' => null, 'description' => 'PREVIOUS STATEMENT BALANCE', 'amount' => '12345.67', 'is_credit' => false],
            ['sale_date' => '2026-09-12', 'post_date' => '2026-09-12', 'description' => 'CASH PAYMENT', 'amount' => 5000, 'is_credit' => true],
        ]]));

        $lines = (new StatementVision())->read($this->image, 'image/png');

        $this->assertSame([
            ['sale_date' => '2026-09-09', 'post_date' => '2026-09-10', 'description' => 'FACEBK *AB12CD34EF DUBLIN IR', 'amount' => '6278.17', 'is_credit' => false],
            ['sale_date' => null, 'post_date' => null, 'description' => 'PREVIOUS STATEMENT BALANCE', 'amount' => '12345.67', 'is_credit' => false],
            ['sale_date' => '2026-09-12', 'post_date' => '2026-09-12', 'description' => 'CASH PAYMENT', 'amount' => '5000.00', 'is_credit' => true],
        ], $lines);

        Http::assertSent(function (Request $r) {
            $content = $r['messages'][0]['content'] ?? [];
            $image = collect($content)->firstWhere('type', 'image_url');

            return $r->url() === 'https://api.openai.com/v1/chat/completions'
                && $r->hasHeader('Authorization', 'Bearer sk-test-fake')
                && $r['model'] === 'vision-test-model'
                && $r['response_format'] === ['type' => 'json_object']
                && ($image['image_url']['url'] ?? '') === 'data:image/png;base64,' . base64_encode("\x89PNG fake bytes");
        });
    }

    public function test_S_049_5_an_answer_that_is_not_strict_json_lines_is_rejected(): void
    {
        $line = ['sale_date' => '2026-09-09', 'post_date' => null, 'description' => 'FACEBK X', 'amount' => 1, 'is_credit' => false];
        foreach ([
            'not json'          => ['Here are the lines: ...', 200],
            'no lines list'     => [json_encode(['rows' => [$line]]), 200],
            'lines not a list'  => [json_encode(['lines' => ['a' => $line]]), 200],
            'bad date'          => [json_encode(['lines' => [['sale_date' => '09/09/26'] + $line]]), 200],
            'impossible date'   => [json_encode(['lines' => [['sale_date' => '2026-02-30'] + $line]]), 200],
            'amount not number' => [json_encode(['lines' => [['amount' => '6,278.17'] + $line]]), 200],
            'negative amount'   => [json_encode(['lines' => [['amount' => -5] + $line]]), 200],
            'credit not bool'   => [json_encode(['lines' => [['is_credit' => 'yes'] + $line]]), 200],
            'missing field'     => [json_encode(['lines' => [array_diff_key($line, ['description' => 1])]]), 200],
            'extra key'         => [json_encode(['lines' => [['note' => 'x'] + $line]]), 200],
            'null content'      => [null, 200],
            'too many lines'    => [json_encode(['lines' => array_fill(0, 501, $line)]), 200],
            'http error'        => [json_encode(['error' => 'x']), 500],
            'connection error'  => ['timeout', 0],
        ] as $case => [$content, $status]) {
            Http::swap(new Factory()); // bagong fake bawat kaso: ang naunang stub ay laging nauunang tumugma
            Http::preventStrayRequests();
            if ($status === 0) {
                Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
            } else {
                Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => $content]]]], $status)]);
            }
            try {
                (new StatementVision())->read($this->image, 'image/png');
                $this->fail("tinanggap: {$case}");
            } catch (StatementReadException $e) {
                $this->assertNotSame('', $e->getMessage(), $case);
                $this->assertStringNotContainsString('sk-test-fake', $e->getMessage(), $case);
            }
        }
    }
}
