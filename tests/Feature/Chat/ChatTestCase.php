<?php

namespace Tests\Feature\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMember;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Base ng mga /chat test (sqlite in-memory, spec 296).
 *
 * Ang users at employee_profiles ay ginagawa dito (may MySQL-only na ALTER ang ibang lumang migration); ang tatlong
 * chat table ay mula sa mga migration mismo. Naka-fake ang public disk at bawal ang stray HTTP.
 */
abstract class ChatTestCase extends TestCase
{
    protected const MIGRATIONS = [
        '2026_11_27_296000_create_chat_conversations_table.php',
        '2026_11_27_296100_create_chat_members_table.php',
        '2026_11_27_296200_create_chat_messages_table.php',
        '2026_11_28_300000_add_messenger_columns_to_chat_tables.php',
        '2026_12_01_308000_add_chat_push_to_users.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Http::preventStrayRequests();

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

        foreach (self::MIGRATIONS as $file) {
            (require database_path("migrations/$file"))->up();
        }
    }

    /** Isang tao na may profile (role, status); $profile = false para walang employee profile. */
    protected function person(?string $role = 'Marketing', string $name = 'Tester', ?string $status = 'Active', bool $profile = true): User
    {
        static $n = 0;
        $n++;
        $user = User::create(['name' => $name, 'email' => "chat{$n}@example.test", 'password' => 'secret-password']);
        if ($profile) {
            $user->employeeProfile()->update(['role' => $role, 'status' => $status, 'name' => $name]);
        } else {
            DB::table('employee_profiles')->where('user_id', $user->id)->delete();
        }

        return $user->fresh();
    }

    protected function ceo(string $name = 'Owner Boss'): User
    {
        return $this->person('CEO', $name);
    }

    protected function direct(User $a, User $b): ChatConversation
    {
        $c = ChatConversation::create([
            'kind' => ChatConversation::DIRECT,
            'direct_key' => ChatConversation::directKey($a->id, $b->id),
            'created_by' => $a->id,
        ]);
        foreach ([$a, $b] as $u) {
            ChatMember::create(['conversation_id' => $c->id, 'user_id' => $u->id, 'role' => ChatMember::MEMBER, 'joined_at' => now()]);
        }

        return $c;
    }

    /** Isang group; ang $admin ang creator at admin. */
    protected function group(User $admin, array $members, string $title = 'Ops team'): ChatConversation
    {
        $c = ChatConversation::create(['kind' => ChatConversation::GROUP, 'title' => $title, 'created_by' => $admin->id]);
        ChatMember::create(['conversation_id' => $c->id, 'user_id' => $admin->id, 'role' => ChatMember::ADMIN, 'joined_at' => now()]);
        foreach ($members as $u) {
            ChatMember::create(['conversation_id' => $c->id, 'user_id' => $u->id, 'role' => ChatMember::MEMBER, 'joined_at' => now()]);
        }

        return $c;
    }

    protected function message(ChatConversation $c, ?User $from, string $body = 'Hello', array $extra = []): ChatMessage
    {
        $m = ChatMessage::create(array_merge([
            'conversation_id' => $c->id,
            'sender_user_id' => $from?->id,
            'body' => $body,
        ], $extra));
        $c->forceFill(['last_message_at' => $m->created_at])->save();

        return $m;
    }
}
