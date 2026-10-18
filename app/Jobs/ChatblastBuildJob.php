<?php

namespace App\Jobs;

use App\Http\Controllers\ChatblastController;
use App\Services\Chatblast;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Ang "Fetch new" ng /chatblast, sa queue: ang pagbuo sa lahat ng page ay maaaring lumampas sa 55 s ng web request, at
 * ang pinatay na request ay nag-iiwan ng build lock nang 300 s. Isang build lang ang naka-queue o tumatakbo (SLOT); ang
 * lagay nito (RUN) ay nakikita sa list ng page. Ang lock ay laging nabibitawan: sa finally ng build, o sa failed() kapag
 * pinatay ng worker (timeout), at ang sarili lang nitong lock (token).
 */
class ChatblastBuildJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const SLOT = 'chatblast-build-slot';
    public const RUN  = 'chatblast-build-run';
    private const SLOT_TTL = 600; // ang naka-queue na hindi kinuha ng worker sa loob nito ay itinuturing na tumigil

    public int $timeout = 280; // mas maikli sa 300 s ng build lock
    public int $tries = 1;
    public bool $failOnTimeout = true;

    public function __construct(public ?string $kind, public ?int $userId, public string $token)
    {
    }

    /** Kunin ang slot at i-queue; null kapag may naka-queue o tumatakbo na. */
    public static function start(?string $kind, ?int $userId): ?array
    {
        $token = Str::random(32);
        if (!Cache::add(self::SLOT, $token, self::SLOT_TTL)) return null;

        Cache::put(self::RUN, ['status' => 'queued', 'token' => $token, 'kind' => $kind, 'queued_at' => time()], 86400);
        self::dispatch($kind, $userId, $token);

        return self::run();
    }

    /** Ang lagay ng huling build para sa page (walang token); "stopped" kapag nawala ang slot habang hindi pa tapos. */
    public static function run(): ?array
    {
        $run = Cache::get(self::RUN);
        if (!is_array($run)) return null;
        if (in_array($run['status'], ['queued', 'running'], true) && Cache::get(self::SLOT) !== $run['token']) {
            $run['error'] = $run['status'] === 'queued' ? 'The fetch waited too long for the worker; press Fetch new again.'
                : 'The fetch stopped before it finished; press Fetch new again.';
            $run['status'] = 'stopped';
        }
        $since = $run['started_at'] ?? $run['queued_at'];
        $run['seconds'] = max(0, (($run['finished_at'] ?? null) ?: time()) - $since);
        unset($run['token']);

        return $run;
    }

    public function handle(Chatblast $chatblast): void
    {
        // Ang slot ay binibilang mula sa pagpila; ang job na matagal naghintay (hal. sa likod ng "Sync lahat") ay kumukuha
        // ulit ng buong oras pagsimula, para hindi ito lumabas na "stopped" habang tumatakbo. Hindi inaagaw ang slot ng iba.
        if (Cache::get(self::SLOT) === $this->token) Cache::put(self::SLOT, $this->token, self::SLOT_TTL);
        else Cache::add(self::SLOT, $this->token, self::SLOT_TTL);

        try {
            $this->update(['status' => 'running', 'started_at' => time()]);
            $result = $chatblast->build($this->kind, $this->userId, ChatblastController::WEB_TRACK_BUDGET, null, $this->token);
            $this->update(!empty($result['busy'])
                ? ['status' => 'busy', 'error' => 'A fetch is already running; try again after it.', 'finished_at' => time()]
                : ['status' => 'done', 'result' => $result, 'finished_at' => time()]);
        } finally {
            $this->freeSlot();
        }
    }

    /** Pumalya o pinatay (timeout): bitawan ang sariling lock at slot, at ipakitang failed. */
    public function failed(?\Throwable $e): void
    {
        Cache::restoreLock(Chatblast::BUILD_LOCK, $this->token)->release();
        $this->update(['status' => 'failed', 'error' => 'The fetch stopped before it finished; press Fetch new again.', 'finished_at' => time()]);
        $this->freeSlot();
    }

    private function update(array $values): void
    {
        $run = Cache::get(self::RUN);
        if (is_array($run) && ($run['token'] ?? null) === $this->token) Cache::put(self::RUN, $values + $run, 86400);
    }

    private function freeSlot(): void
    {
        if (Cache::get(self::SLOT) === $this->token) Cache::forget(self::SLOT);
    }
}
