<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Categories (Phase 1 — active) ──
        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['income', 'expense']);
            $table->boolean('is_system')->default(false);
            $table->string('host_scope')->default('likha'); // likha | incepxion
            $table->timestamps();

            $table->index(['host_scope', 'type']);
        });

        // Seed system defaults for both scopes
        $defaults = [
            // Income
            ['name' => 'Remittance (J&T)',   'type' => 'income',  'is_system' => true],
            ['name' => 'Other Income',       'type' => 'income',  'is_system' => true],
            // Expense
            ['name' => 'Ad Spend',           'type' => 'expense', 'is_system' => true],
            ['name' => 'COGS / Product Cost','type' => 'expense', 'is_system' => true],
            ['name' => 'Shipping Fee',       'type' => 'expense', 'is_system' => true],
            ['name' => 'COD Fees',           'type' => 'expense', 'is_system' => true],
            ['name' => 'Salaries / Wages',   'type' => 'expense', 'is_system' => true],
            ['name' => 'Rent / Utilities',   'type' => 'expense', 'is_system' => true],
            ['name' => 'Software / Tools',   'type' => 'expense', 'is_system' => true],
            ['name' => 'Capital Expense',    'type' => 'expense', 'is_system' => true],
            ['name' => "Owner's Withdrawal", 'type' => 'expense', 'is_system' => true],
            ['name' => 'Miscellaneous',      'type' => 'expense', 'is_system' => true],
        ];

        $now = now();
        foreach (['likha', 'incepxion'] as $scope) {
            foreach ($defaults as $d) {
                DB::table('finance_categories')->insert(array_merge($d, [
                    'host_scope' => $scope,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }
        }

        // ── 2. Transactions (Phase 1 — active) ──
        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->enum('type', ['income', 'expense']);
            $table->foreignId('category_id')->constrained('finance_categories')->cascadeOnDelete();
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->text('notes')->nullable();
            $table->string('host_scope')->default('likha');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['host_scope', 'date']);
            $table->index(['host_scope', 'type']);
        });

        // ── 3. Balances — monthly snapshots (Phase 2 — created but unused) ──
        Schema::create('finance_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('closing_balance', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->string('host_scope')->default('likha');
            $table->timestamps();

            $table->unique(['host_scope', 'year', 'month']);
        });

        // ── 4. Capital tracking (Phase 2 — created but unused) ──
        Schema::create('finance_capital', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->enum('type', ['injection', 'withdrawal']);
            $table->decimal('amount', 14, 2);
            $table->string('description')->nullable();
            $table->string('host_scope')->default('likha');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['host_scope', 'date']);
        });

        // ── 5. Receivables (Phase 2 — created but unused) ──
        Schema::create('finance_receivables', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->enum('status', ['pending', 'received'])->default('pending');
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('host_scope')->default('likha');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['host_scope', 'status']);
        });

        // ── 6. Payables (Phase 2 — created but unused) ──
        Schema::create('finance_payables', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->enum('status', ['pending', 'paid'])->default('pending');
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('host_scope')->default('likha');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['host_scope', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_payables');
        Schema::dropIfExists('finance_receivables');
        Schema::dropIfExists('finance_capital');
        Schema::dropIfExists('finance_balances');
        Schema::dropIfExists('finance_transactions');
        Schema::dropIfExists('finance_categories');
    }
};
