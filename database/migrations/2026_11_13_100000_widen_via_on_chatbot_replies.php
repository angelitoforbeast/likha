<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * chatbot_replies.via: ang pangalan ng gateway na nagpadala (forbeast, incepxionbot) o botcake. Ang "incepxionbot" ay 12
     * letra, kaya 10 → 20. Walang binabagong laman: mas mahaba lang ang kasya.
     */
    public function up(): void
    {
        if (!Schema::hasTable('chatbot_replies') || !Schema::hasColumn('chatbot_replies', 'via')) return;

        Schema::table('chatbot_replies', function (Blueprint $table) {
            $table->string('via', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Walang ibinabalik: ang row na may "incepxionbot" ay hindi kasya sa 10.
    }
};
