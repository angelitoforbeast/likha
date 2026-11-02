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
     * Every typed property of a queued job must survive an older payload: either it has a class-level default, or it is a
     * required constructor parameter (there since the class was first queued). An optional constructor parameter promoted
     * to a property, or a typed property set only in the constructor body, is left uninitialized by an older payload.
     */
    public function test_S_218_3_no_queued_job_has_a_typed_property_an_older_payload_can_leave_uninitialized(): void
    {
        $bad = [];
        $checked = 0;
        foreach (glob(app_path('Jobs/*.php')) as $file) {
            $class = 'App\\Jobs\\' . basename($file, '.php');
            if (!class_exists($class) || !is_subclass_of($class, ShouldQueue::class)) continue;
            $checked++;
            $r = new \ReflectionClass($class);
            $params = [];
            foreach ($r->getConstructor()?->getParameters() ?? [] as $p) $params[$p->getName()] = $p;
            foreach ($r->getProperties() as $prop) {
                if ($prop->isStatic() || !$prop->hasType() || $prop->hasDefaultValue()) continue;
                $param = $params[$prop->getName()] ?? null;
                // Filled from a required constructor parameter of the same name (promoted or assigned): there since the class was first queued.
                if ($param !== null && !$param->isOptional()) continue;
                $bad[] = $class . '::$' . $prop->getName();
            }
        }
        $this->assertGreaterThan(40, $checked, 'every queued job class was read');
        $this->assertSame([], $bad, 'give these properties a class-level default (and treat it as "no information")');
    }
}
