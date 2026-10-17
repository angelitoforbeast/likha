<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card statement na inupload para itugma sa Meta ad payments (payment_activity_ads_manager, hindi ginagalaw).
 * Ang file mismo ay hindi sine-save; ang mga nabasang linya lang, naka-mask ang card number.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('card_statements')) {
            Schema::create('card_statements', function (Blueprint $t) {
                $t->id();
                $t->string('card_label', 128)->nullable();
                $t->string('bank', 32);
                $t->string('source_kind', 16);
                $t->string('original_name')->nullable();
                $t->date('period_start')->nullable();
                $t->date('period_end')->nullable();
                $t->string('status', 16)->default('preview');
                $t->unsignedInteger('lines_count')->default(0);
                $t->unsignedInteger('ads_count')->default(0);
                $t->decimal('card_total', 14, 2)->default(0);
                $t->decimal('meta_total', 14, 2)->default(0);
                $t->unsignedInteger('matched_count')->default(0);
                $t->decimal('matched_total', 14, 2)->default(0);
                $t->unsignedInteger('no_meta_count')->default(0);
                $t->decimal('no_meta_total', 14, 2)->default(0);
                $t->unsignedInteger('no_card_count')->default(0);
                $t->decimal('no_card_total', 14, 2)->default(0);
                $t->decimal('difference_total', 14, 2)->default(0);
                $t->unsignedInteger('groups_tallied')->default(0);
                $t->unsignedInteger('groups_untallied')->default(0);
                $t->unsignedBigInteger('uploaded_by')->nullable();
                $t->timestamp('matched_at')->nullable();
                $t->timestamps();
                $t->index('created_at');
            });
        }

        if (!Schema::hasTable('card_statement_lines')) {
            Schema::create('card_statement_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('card_statement_id');
                $t->unsignedInteger('line_no');
                $t->date('sale_date')->nullable();
                $t->date('post_date')->nullable();
                $t->string('description', 255);
                $t->decimal('amount', 12, 2);
                $t->boolean('is_credit')->default(false);
                $t->boolean('is_ads')->default(false);
                $t->unsignedBigInteger('payment_activity_id')->nullable();
                $t->string('group_key', 32)->nullable();
                $t->string('match_kind', 16)->nullable();
                $t->smallInteger('match_days')->nullable();
                $t->timestamps();
                $t->index(['card_statement_id', 'line_no']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('card_statement_lines');
        Schema::dropIfExists('card_statements');
    }
};
