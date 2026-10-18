<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Ang mga mensahe ng chatblast (gaya ng flow sa Botcake): may pangalan at mga bersyon (A, B, …) ng text. Ang
     * sequence (chatblast_campaigns) ay may step_messages: ang mensahe ng bawat step. Ang days ng campaign ay nananatiling
     * ang text ng mga step nito (binubuo ulit tuwing nagbabago ang step o ang mensahe), kaya hindi nagbabago ang pagbuo at
     * pagpapadala. Ang bawat araw ng mga dating campaign ay nagiging mensaheng "<campaign> · Day N" na eksaktong pareho ang
     * mga bersyon; ang botcake_messages ay hindi ginagalaw.
     */
    public function up(): void
    {
        if (!Schema::hasTable('chatblast_messages')) {
            Schema::create('chatblast_messages', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150)->unique();
                $table->json('versions');           // mga text: A, B, …
                $table->json('log')->nullable();    // huling mga pagbabago: sino, kailan, ano
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('chatblast_campaigns')) return;
        if (!Schema::hasColumn('chatblast_campaigns', 'step_messages')) {
            Schema::table('chatblast_campaigns', function (Blueprint $table) {
                $table->json('step_messages')->nullable(); // id ng mensahe ng bawat step (Day 1, Day 2, …)
            });
        }

        DB::transaction(fn () => $this->convert()); // buo o wala: ang ulit ay hindi gumagawa ng doble
    }

    private function convert(): void
    {
        foreach (DB::table('chatblast_campaigns')->whereNull('step_messages')->orderBy('id')->get(['id', 'name', 'days']) as $c) {
            $days = json_decode((string) $c->days, true);
            if (!is_array($days)) continue;
            $ids = [];
            foreach ($days as $i => $versions) {
                $base = $c->name . ' · Day ' . ($i + 1);
                $name = $base;
                for ($n = 2; DB::table('chatblast_messages')->where('name', $name)->exists(); $n++) $name = $base . ' (' . $n . ')';
                $now = now();
                $ids[] = DB::table('chatblast_messages')->insertGetId([
                    'name' => $name, 'versions' => json_encode($versions, JSON_UNESCAPED_UNICODE), 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('chatblast_campaigns')->where('id', $c->id)->update(['step_messages' => json_encode($ids)]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('chatblast_campaigns') && Schema::hasColumn('chatblast_campaigns', 'step_messages')) {
            Schema::table('chatblast_campaigns', function (Blueprint $table) {
                $table->dropColumn('step_messages');
            });
        }
        Schema::dropIfExists('chatblast_messages');
    }
};
