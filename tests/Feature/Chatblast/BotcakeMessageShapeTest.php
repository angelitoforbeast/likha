<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\BotcakeOutbox;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ang "message" ng tumangging Botcake ay hindi laging string (minsan array o object): ang dahilan ay nababasang text pa
 * rin, hanggang 150 letra, at walang exception.
 */
class BotcakeMessageShapeTest extends TestCase
{
    public static function messages(): array
    {
        $long = str_repeat('a', 200);

        return [
            'string'               => [['success' => false, 'message' => 'Not found customer'], 'Botcake said "Not found customer" (HTTP 200)'],
            'array'                => [['success' => false, 'message' => ['psid wrong format', 'other']], 'Botcake said "psid wrong format" (HTTP 200)'],
            'nested array'         => [['success' => false, 'message' => ['error' => ['detail' => 'deep reason']]], 'Botcake said "deep reason" (HTTP 200)'],
            'object'               => [['success' => false, 'message' => (object) ['error' => 'x']], 'Botcake said "x" (HTTP 200)'],
            'no string inside'     => [['success' => false, 'message' => ['code' => 5]], 'Botcake said "{"code":5}" (HTTP 200)'],
            'null'                 => [['success' => false, 'message' => null], 'Botcake refused it (HTTP 200)'],
            'missing'              => [['success' => false], 'Botcake refused it (HTTP 200)'],
            'number'               => [['success' => false, 'message' => 42], 'Botcake refused it (HTTP 200)'],
            'long string, capped'  => [['success' => false, 'message' => $long], 'Botcake said "' . str_repeat('a', 150) . '" (HTTP 200)'],
        ];
    }

    #[DataProvider('messages')]
    public function test_S_095_1_a_refusal_of_any_message_shape_is_failed_with_a_readable_reason(array $body, string $reason): void
    {
        $this->assertSame(['status' => 'failed', 'error' => $reason, 'http' => 200], BotcakeOutbox::outcome(['http' => 200, 'body' => $body]));
    }
}
