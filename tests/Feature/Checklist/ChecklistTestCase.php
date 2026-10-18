<?php

namespace Tests\Feature\Checklist;

use App\Models\ChecklistSubmission;
use App\Models\ChecklistTask;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Base ng mga /checklist test (sqlite in-memory).
 *
 * Ang checklist tables ay ginagawa dito sa huling hugis nila: may MySQL-only na ALTER sa mga
 * migration (ENUM 'both'), kaya hindi sila mapatakbo sa sqlite. Walang totoong OpenAI call:
 * lahat ng Http ay naka-fake at bawal ang stray request.
 */
abstract class ChecklistTestCase extends TestCase
{
    /** Ang isasagot ng (fake) OpenAI; puwedeng palitan ng test bago ang request. */
    protected string $aiAnswer = "APPROVED\n\nMukhang kumpleto ang submission.";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => fn () => Http::response([
            'choices' => [['message' => ['content' => $this->aiAnswer]]],
        ])]);

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('employee_profiles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('name')->nullable();
            $t->string('employee_code')->nullable();
            $t->string('role')->nullable();
            $t->string('department')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });
        // Binibilang ng layout (pending tasks sa nav) sa bawat page.
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('status')->nullable();
            $t->timestamps();
        });
        Schema::create('checklist_tasks', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->text('description')->nullable();
            $t->text('ai_prompt')->nullable();
            $t->text('approval_prompt')->nullable();
            $t->unsignedTinyInteger('required_photos')->default(0);
            $t->string('frequency')->default('daily');
            $t->unsignedTinyInteger('frequency_day')->nullable();
            $t->string('department')->nullable();
            $t->foreignId('created_by')->nullable();
            $t->string('type')->default('any');
            $t->boolean('is_active')->default(true);
            $t->integer('sort_order')->default(0);
            $t->string('scheduled_time')->nullable();
            $t->string('submission_type')->default('group');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('checklist_task_users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('checklist_task_id');
            $t->foreignId('user_id');
            $t->unique(['checklist_task_id', 'user_id']);
            $t->timestamps();
        });
        Schema::create('checklist_submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('checklist_task_id');
            $t->foreignId('user_id');
            $t->date('date');
            $t->text('notes')->nullable();
            $t->string('file_path')->nullable();
            $t->string('file_original_name')->nullable();
            $t->string('file_mime', 100)->nullable();
            $t->timestamps();
            $t->unique(['checklist_task_id', 'user_id', 'date']);
        });
        Schema::create('checklist_submission_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('checklist_submission_id');
            $t->string('file_path');
            $t->string('file_original_name');
            $t->string('file_mime', 100);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('checklist_submission_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('checklist_submission_id');
            $t->foreignId('user_id');
            $t->string('action');
            $t->text('notes_snapshot')->nullable();
            $t->unsignedTinyInteger('file_count')->default(0);
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('checklist_analysis_logs', function (Blueprint $t) {
            $t->id();
            $t->string('log_type')->default('analysis');
            $t->foreignId('submission_id');
            $t->foreignId('user_id')->nullable();
            $t->text('prompt_used')->nullable();
            $t->text('analysis_result');
            $t->string('verdict')->nullable();
            $t->timestamps();
        });

        // Ang mga mas bagong checklist table ay sqlite-safe: ang migration mismo ang nagpapatakbo.
        foreach (self::MIGRATIONS as $file) {
            (require database_path("migrations/$file"))->up();
        }
    }

    private const MIGRATIONS = [
        '2026_10_15_000001_create_checklist_task_changes_table.php',
        '2026_10_15_000002_add_extract_fields_to_checklist_tasks.php',
        '2026_10_15_000003_create_checklist_submission_values_table.php',
        '2026_10_18_510000_add_deadline_time_to_checklist_tasks.php',
    ];

    protected function user(string $role, ?string $department = null, string $name = 'Tester'): User
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => "$name $n", 'email' => "user{$n}@example.test", 'password' => 'secret-password']);
        $user->employeeProfile()->update(['role' => $role, 'department' => $department]);

        return $user->fresh();
    }

    protected function task(array $attrs = [], array $assigned = []): ChecklistTask
    {
        $task = ChecklistTask::create(array_merge([
            'title'     => 'Linisin ang bodega',
            'type'      => 'any',
            'is_active' => true,
            'frequency' => 'daily',
        ], $attrs));
        // Hindi fillable ang mga oras na ito; diretso sa table.
        $stamps = array_intersect_key($attrs, array_flip(['created_at', 'deleted_at']));
        if ($stamps) {
            DB::table('checklist_tasks')->where('id', $task->id)->update($stamps);
        }
        $task->assignedUsers()->sync(collect($assigned)->pluck('id')->all());

        return ChecklistTask::withTrashed()->find($task->id);
    }

    /** 'Y-m-d' lang ang date, gaya ng DATE column sa MySQL (ang date cast ng model ay nagdadagdag ng oras sa sqlite). */
    protected function submission(ChecklistTask $task, User $user, string $date): ChecklistSubmission
    {
        $id = DB::table('checklist_submissions')->insertGetId([
            'checklist_task_id' => $task->id,
            'user_id'           => $user->id,
            'date'              => $date,
            'notes'             => 'Tapos na po.',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return ChecklistSubmission::find($id);
    }

    /** Isang approval run; $at = oras ng run (para masubok ang "pinakahuling verdict"). */
    protected function approval(ChecklistSubmission $submission, string $verdict, string $at): void
    {
        DB::table('checklist_analysis_logs')->insert([
            'log_type'        => 'approval',
            'submission_id'   => $submission->id,
            'analysis_result' => strtoupper(str_replace('_', ' ', $verdict)),
            'verdict'         => $verdict,
            'created_at'      => $at,
            'updated_at'      => $at,
        ]);
    }
}
