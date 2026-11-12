<?php

namespace App\Services\Jnt;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Fetches waybill labels from J&T in parallel (spec 269): `jnt.labels.batch` calls at a time (10), each signed with
 * the J&T account of the waybill's page, under the shared JntLabelLimiter, and keeps each label in JntLabelStore.
 * Read-only on J&T's side (BILLQUERY).
 */
class JntLabelFetcher
{
    /** @var array<string, JntClient> page => client ('' = the .env account) */
    private array $clients = [];

    public function __construct(private JntLabelStore $store, private JntLabelLimiter $limiter) {}

    public function batchSize(): int
    {
        return max(1, (int) config('jnt.labels.batch', 10));
    }

    /**
     * Per mailno: the label file, or why not. `temp`: the label could not be kept; the caller deletes the file.
     *
     * @param  array<string, ?string>  $pages  mailno => page of the waybill (null: the .env account)
     * @return array<string, array{path?: string, temp?: bool, error?: string}>
     */
    public function fetchMany(array $pages, int $waitSeconds): array
    {
        $out = [];
        $todo = array_map('strval', array_keys($pages));

        while ($todo !== []) {
            $slots = $this->limiter->acquire(min($this->batchSize(), count($todo)), $waitSeconds);
            if ($slots === []) {
                foreach ($todo as $mailno) {
                    $out[$mailno] = ['error' => 'J&T label calls busy, try again'];
                }
                break;
            }

            $chunk = array_splice($todo, 0, count($slots));
            try {
                $out += $this->fetchChunk($chunk, $pages);
            } finally {
                $this->limiter->release($slots);
            }
        }

        return $out;
    }

    /**
     * One batch, sent together.
     *
     * @param  array<int, string>  $mailnos
     * @param  array<string, ?string>  $pages
     */
    protected function fetchChunk(array $mailnos, array $pages): array
    {
        $out = [];
        $calls = [];
        foreach (array_values($mailnos) as $i => $mailno) {
            try {
                $calls["k{$i}"] = [$mailno, $this->client($pages[$mailno] ?? null)];
            } catch (\Throwable $e) {
                $out[$mailno] = ['error' => $e->getMessage()];
            }
        }
        if ($calls === []) {
            return $out;
        }

        $responses = Http::pool(function (Pool $pool) use ($calls) {
            foreach ($calls as $key => [$mailno, $client]) {
                $pool->as($key)
                    ->asForm()
                    ->timeout($client->getTimeoutSeconds())
                    ->post($client->printWaybillUrl(), $client->printWaybillForm($mailno));
            }
        });

        foreach ($calls as $key => [$mailno]) {
            try {
                $resp = $responses[$key] ?? null;
                if ($resp instanceof \Throwable) {
                    throw $resp;
                }
                if (! $resp instanceof Response) {
                    throw new \RuntimeException('no answer from J&T');
                }

                $bytes = JntLabelStore::pdfFromPrintResponse(JntClient::printWaybillJson($resp), $mailno);
                $out[$mailno] = $this->keep($mailno, $bytes);
            } catch (\Throwable $e) {
                $out[$mailno] = ['error' => $e->getMessage()];
            }
        }

        return $out;
    }

    /** Keeps the label; when it cannot be kept (not a PDF, disk), the caller still gets it as a temp file. */
    private function keep(string $mailno, string $bytes): array
    {
        try {
            return ['path' => $this->store->put($mailno, $bytes)];
        } catch (\Throwable $e) {
            $tmp = tempnam(sys_get_temp_dir(), 'wb_');
            file_put_contents($tmp, $bytes);

            return ['path' => $tmp, 'temp' => true];
        }
    }

    private function client(?string $page): JntClient
    {
        $key = trim((string) $page);

        return $this->clients[$key] ??= $key !== '' ? JntClient::fromPageOrConfig($key) : JntClient::fromConfig();
    }
}
