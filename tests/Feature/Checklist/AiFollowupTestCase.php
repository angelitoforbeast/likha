<?php

namespace Tests\Feature\Checklist;

use App\Models\AppSetting;
use App\Models\ChecklistAiFollowup;
use App\Models\ChecklistTask;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Base ng mga test ng AI follow-ups (spec 293). Ang "ngayon" ay Miyerkules 2026-11-18 4:00 PM Manila.
 *
 * Bagong Http fake (hindi ang approval fake ng ChecklistTestCase): bawat tawag sa OpenAI ay naitatala sa
 * $openAi, at ang sagot ay $followupReply (array na body, o closure na nagbabalik ng response). Walang totoong tawag.
 */
abstract class AiFollowupTestCase extends ChecklistTestCase
{
    /** @var array|\Closure|null */
    protected $followupReply = null;

    /** @var list<Request> */
    protected array $openAi = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 16:00:00');

        Schema::create('app_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });
        foreach (['2026_10_16_100002_create_staff_events_table.php', '2026_10_16_200001_add_source_to_staff_events.php'] as $file) {
            (require database_path("migrations/$file"))->up();
        }

        Http::swap(new Factory(app('events')));
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => function (Request $request) {
            $this->openAi[] = $request;
            $reply = $this->followupReply ?? $this->reply('Hi Joy, please post the 3 stories and submit them on the checklist by 5:00 PM. Thank you!');

            return $reply instanceof \Closure ? $reply($request) : Http::response($reply);
        }]);
    }

    /** Body ng chat completion na may JSON na sagot at usage. */
    protected function reply(string $message, string $deadline = '17:00', int $in = 1000, int $out = 100): array
    {
        return [
            'choices' => [['message' => ['content' => json_encode(['message' => $message, 'deadline' => $deadline])]]],
            'usage'   => ['prompt_tokens' => $in, 'completion_tokens' => $out, 'total_tokens' => $in + $out],
        ];
    }

    protected function enable(): void
    {
        AppSetting::set('ai_followups.enabled', '1');
    }

    protected function ceo(): User
    {
        return $this->user('CEO', null, 'Angelito');
    }

    /** Marketing staff na may pangalan sa profile (ang unang pangalan ang ginagamit ng AI). */
    protected function marketer(string $role = 'Marketing', string $name = 'Joy Ramos', ?string $department = null): User
    {
        $user = $this->user($role, $department, $name);
        DB::table('employee_profiles')->where('user_id', $user->id)->update(['name' => $name]);

        return $user->fresh();
    }

    /** Task ngayong araw na lampas na ang deadline (3:30 PM), naka-assign sa $user. */
    protected function lateTask(User $user, array $attrs = []): ChecklistTask
    {
        return $this->task(array_merge([
            'title'         => 'Post 3 stories on the main page',
            'description'   => 'Three stories, each with the price.',
            'deadline_time' => '15:30',
            'submission_type' => 'individual',
            'created_at'    => '2026-11-01 08:00:00',
        ], $attrs), [$user]);
    }

    protected function runFollowups(): void
    {
        $this->artisan('checklist:ai-followups')->assertExitCode(0);
    }

    /** Isang follow-up na diretsong ginawa (walang AI call), para sa review at staff tests. */
    protected function followup(User $person, ChecklistTask $task, array $attrs = []): ChecklistAiFollowup
    {
        $f = ChecklistAiFollowup::create(array_merge([
            'title'           => $task->title,
            'description'     => 'Hi Joy, please post the 3 stories by 5:00 PM.',
            'deadline_time'   => '17:00',
            'follows_task_id' => $task->id,
            'follows_date'    => '2026-11-18',
            'follows_reason'  => 'late',
            'review_status'   => 'draft',
            'ai_draft'        => ['message' => 'Hi Joy, please post the 3 stories by 5:00 PM.', 'deadline' => '17:00',
                                  'model' => 'gpt-4o-mini', 'prompt_tokens' => 1000, 'completion_tokens' => 100],
            'ai_cost_usd'     => 0.00021,
        ], $attrs));
        $f->assignedUsers()->sync([$person->id]);

        return $f->fresh();
    }

    protected function reviewed(ChecklistAiFollowup $f, User $by, string $status, array $attrs = []): ChecklistAiFollowup
    {
        $f->forceFill(array_merge([
            'review_status' => $status,
            'reviewed_by'   => $by->id,
            'reviewed_at'   => now(),
            'is_active'     => $status === 'approved',
        ], $attrs))->save();

        return $f->fresh();
    }
}
