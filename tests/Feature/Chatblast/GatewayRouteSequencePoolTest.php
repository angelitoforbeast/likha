<?php

namespace Tests\Feature\Chatblast;

use App\Services\Chatbot\SequenceSendPool;
use App\Services\ChatbotFields;
use App\Services\PageSettings;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Psr\Http\Message\RequestInterface;

/**
 * Ang padala ng sequence (chatbot:sequence-pool) sa gateway: ang address ng page, ang IPv4-only switch, at ang detalye
 * ng walang sagot, gaya ng GatewayProvider::send.
 */
class GatewayRouteSequencePoolTest extends SequenceHandoffTestCase
{
    /** Ang Guzzle options ng bawat tawag ng pool. */
    private array $options = [];

    protected function newPool(): SequenceSendPool
    {
        return new SequenceSendPool(function (RequestInterface $req, array $options) {
            $p = new Promise();
            $this->calls[] = ['req' => $req, 'p' => $p, 't' => $this->clock->now(), 'timeout' => $options['timeout'] ?? null];
            $this->options[] = $options;

            return $p;
        }, $this->clock);
    }

    public function test_S_223_2d_and_6c_a_sequence_send_uses_the_page_address_ipv4_only_and_records_why_it_got_no_answer(): void
    {
        config(['services.gateway.url' => 'https://gw.test/api', 'services.gateway.key' => 'gk-test-not-real',
            'services.gateway.urls' => 'incepxion=https://incepxion.test/api', 'services.gateway.force_ipv4' => true]);
        app(PageSettings::class)->set('Page Dos', ChatbotFields::PROVIDER, 'provider', 'gateway');
        app(PageSettings::class)->set('Page Dos', ChatbotFields::PROVIDER, 'gateway_app', 'incepxion', false, null, true);
        $seq = $this->sequence('Route', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '8001', '1002');
        $this->steps();
        $this->round();

        $this->assertCount(1, $this->calls);
        $this->assertSame(['incepxion.test', '/api/v1/pages/1002/messages'], [self::req($this->calls[0])['host'], self::req($this->calls[0])['path']]);
        $this->assertSame('v4', $this->options[0]['force_ip_resolve'] ?? null);

        $refuse = fn (int $i) => $this->calls[$i]['p']->reject(new ConnectException('cURL error 7: Failed to connect', new PsrRequest('POST', (string) $this->calls[$i]['req']->getUri()), null,
            ['errno' => 7, 'error' => 'Failed to connect to incepxion.test port 443 after 3001 ms: Timeout was reached', 'connect_time' => 0.0, 'total_time' => 3.001]));
        $refuse(0);
        $this->pool->tick(false);
        $this->pool->tick(false);
        // Spec 273: the request never left, so it is tried once more; the second failure is the result.
        $this->assertCount(2, $this->calls);
        $this->assertSame([], $this->replies('8001'));
        $refuse(1);
        $this->pool->tick(false);
        $this->pool->tick(false);
        $this->assertCount(2, $this->calls, 'one retry only');
        $this->resumes();

        $this->assertSame(['failed: Hi (Gateway did not answer (HTTP 0) · cURL error 7: Failed to connect to incepxion.test port 443 after 3001 ms: Timeout was reached · connect 0.00 s, total 3.00 s)'],
            $this->replies('8001'));
    }
}
