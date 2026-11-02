<?php

namespace Tests\Feature\Chatblast;

use App\Jobs\GatewayAfterSend;
use App\Jobs\RunChatbotSequenceStep;
use App\Services\Chatbot\ForbeastWatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * Spec 218: ang job na naka-queue na bago ang deploy (serialized ng lumang bersyon ng klase) ay tumatakbo pagkatapos ng
 * deploy gaya ng bagong dispatch, at ang guard na humuhuli sa typed property na maiiwang walang laman ng lumang payload.
 */
class QueuedJobCompatTest extends SequenceHandoffTestCase
{
    /** The payload a worker reads from the queue: PHP's serialize() of the job, as the previous class version wrote it. */
    private static function payload(string $class, array $props): string
    {
        $out = '';
        foreach ($props as $name => $value) $out .= serialize($name) . serialize($value);

        return 'O:' . strlen($class) . ':"' . $class . '":' . count($props) . ':{' . $out . '}';
    }

    public function test_S_218_1_a_step_job_queued_by_the_previous_class_version_runs_after_the_deploy(): void
    {
        $seq = $this->sequence('Old payload', [$this->stepFlow(['Hi'])->id]);
        $id = $this->join($seq, '7001');
        $this->tick();
        $claim = (string) DB::table('chatbot_sequence_subscriptions')->where('id', $id)->value('dispatched_at');
        (new \Illuminate\Bus\UniqueLock(app('cache.store')))->release(\Illuminate\Support\Facades\Queue::pushed(RunChatbotSequenceStep::class)->first());

        // Exactly the shape of the jobs that failed: subscriptionId, claim, connection, queue (no $earlier).
        $raw = self::payload(RunChatbotSequenceStep::class, ['subscriptionId' => $id, 'claim' => $claim, 'connection' => 'redis', 'queue' => 'chatbot']);
        $this->assertStringStartsWith('O:31:"App\Jobs\RunChatbotSequenceStep":4:{', $raw);
        $job = unserialize($raw);
        app()->call([$job, 'handle']);

        $this->assertNull($job->earlier, 'read as "no earlier claim", like a fresh dispatch');
        $this->assertCount(1, $this->tickets(), 'the step ran and handed its send off');
        $this->settle(3);
        $this->assertSame(['sent: Hi'], $this->replies('7001'));
    }

    public function test_S_218_2_the_resume_and_gateway_watch_jobs_run_from_their_first_payload_shape(): void
    {
        $seq = $this->sequence('Resume', [$this->stepFlow(['Hi'])->id]);
        $this->join($seq, '7001');
        $this->steps();
        $this->roundsUntil(1);
        $this->answerAll();
        $ticket = $this->tickets()->first()->request_id;
        $resume = unserialize(self::payload(\App\Jobs\ResumeChatbotSequenceSend::class, ['ticket' => ['request_id' => $ticket], 'connection' => 'redis', 'queue' => 'chatbot']));
        app()->call([$resume, 'handle']);
        $this->assertSame('completed', $this->sub($seq, '7001')->status);

        $watch = \Mockery::spy(ForbeastWatch::class);
        $this->app->instance(ForbeastWatch::class, $watch);
        $after = unserialize(self::payload(GatewayAfterSend::class, ['page' => 'Page Dos', 'outcome' => ['status' => 'sent', 'error' => null, 'http' => 202],
            'body' => ['accepted' => true], 'gateway' => 'forbeast', 'connection' => 'redis', 'queue' => 'chatbot']));
        app()->call([$after, 'handle']);
        $watch->shouldHaveReceived('afterSend')->once();
    }

    /**
     * Every queued job (app/Jobs and its subfolders, with or without the trait): a typed property with no class default, that
     * an older payload can leave uninitialized, passes only if
     *  - it is a REQUIRED constructor parameter of the same name (every payload carries it), or
     *  - the class uses RestoresMissingDefaults and it is an OPTIONAL constructor parameter of the same name and the same type
     *    (the trait restores the parameter's raw default, so the property must be promoted or assigned straight from it).
     * Anything else fails, a property set in the constructor body from a computed value included: give it a class default.
     * The payload format stays as it is for the trait's classes (no class defaults, which SerializesModels would leave out of
     * the payload and the previous code could not read).
     *
     * Known gap: a new REQUIRED constructor parameter on a job that is already queued is not covered (there is no default to
     * restore); a job that gains a required parameter needs a default or a drained queue before the deploy.
     */
    public function test_S_218_3_every_queued_job_with_an_optional_typed_property_restores_missing_defaults(): void
    {
        $bad = [];
        $checked = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path('Jobs'), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') continue;
            $relative = substr($file->getPathname(), strlen(app_path('Jobs')) + 1, -4);
            $class = 'App\\Jobs\\' . str_replace(['/', '\\'], '\\', $relative);
            if (!class_exists($class) || !is_subclass_of($class, ShouldQueue::class)) continue;
            $checked++;
            $r = new \ReflectionClass($class);
            $restores = in_array(\App\Jobs\Concerns\RestoresMissingDefaults::class, class_uses_recursive($class), true);
            $params = [];
            foreach ($r->getConstructor()?->getParameters() ?? [] as $p) $params[$p->getName()] = $p;
            foreach ($r->getProperties() as $prop) {
                if ($prop->isStatic() || !$prop->hasType() || $prop->hasDefaultValue()) continue;
                $param = $params[$prop->getName()] ?? null;
                if ($param !== null && !$param->isOptional()) continue;
                if ($restores && $param !== null && (string) $param->getType() === (string) $prop->getType()) continue;
                $bad[] = $class . '::$' . $prop->getName();
            }
        }
        $this->assertGreaterThan(40, $checked, 'every queued job class was read, subfolders included');
        $this->assertSame([], $bad, 'a required constructor parameter, RestoresMissingDefaults with an optional parameter of the same type, or a class default');
    }

    public function test_S_218_3_a_comment_job_with_or_without_until_in_the_payload_keeps_its_retry_window(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-11-03 01:00:00');
        $old = unserialize(self::payload(\App\Jobs\HandleComment::class, ['maxExceptions' => 1, 'until' => 1762138800, 'eventId' => 5]));
        $this->assertSame(1762138800, $old->retryUntil()->getTimestamp(), 'the until in the payload is kept');

        $missing = unserialize(self::payload(\App\Jobs\HandleComment::class, ['maxExceptions' => 1, 'eventId' => 5]));
        $this->assertNull($missing->until, 'the class default, not an uninitialized property');
        $this->assertSame(now()->addHours(6)->getTimestamp(), $missing->retryUntil()->getTimestamp(), '6 hours from now, like a fresh dispatch');
    }

    public function test_S_218_3_the_guard_reads_subfolders(): void
    {
        $this->assertTrue(is_subclass_of(\App\Jobs\Boardroom\RunTurnJob::class, ShouldQueue::class), 'app/Jobs/Boardroom is a queued job folder the guard must read');
    }
}
