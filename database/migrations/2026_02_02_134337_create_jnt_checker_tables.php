<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jnt_checker_runs', function (Blueprint $table) {
            $table->id();

            $table->string('status')->default('pending')->index();

            $table->date('filter_date_start')->nullable()->index();
            $table->date('filter_date_end')->nullable()->index();

            $table->longText('payload')->nullable();

            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('not_matched_count')->default(0);
            $table->unsignedInteger('not_in_excel_count')->default(0);
            $table->unsignedInteger('mapping_missing_count')->default(0);
            $table->unsignedInteger('skipped_cancel_count')->default(0);
            $table->unsignedInteger('processed_files_count')->default(0);
            $table->unsignedInteger('updatable_count')->default(0);

            $table->boolean('perfect_match')->default(false);

            $table->longText('error')->nullable();

            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();

            $table->timestamps();
        });

        Schema::create('jnt_checker_run_files', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('run_id')->index();
            $table->foreign('run_id')
                ->references('id')
                ->on('jnt_checker_runs')
                ->onDelete('cascade');

            $table->string('original_name');
            $table->string('stored_path');
            $table->string('ext', 20)->nullable();
            $table->unsignedBigInteger('size')->default(0);

            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('skipped_cancel_rows')->default(0);

            $table->longText('error')->nullable();

            $table->timestamps();
        });

        Schema::create('jnt_checker_run_items', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('run_id')->index();
            $table->foreign('run_id')
                ->references('id')
                ->on('jnt_checker_runs')
                ->onDelete('cascade');

            $table->unsignedBigInteger('file_id')->index();
            $table->foreign('file_id')
                ->references('id')
                ->on('jnt_checker_run_files')
                ->onDelete('cascade');

            $table->string('source_file')->nullable();

            $table->string('order_status')->nullable();
            $table->string('sender')->nullable();
            $table->string('page')->nullable();
            $table->string('receiver')->nullable();
            $table->string('item')->nullable();
            $table->string('cod')->nullable();
            $table->string('waybill')->nullable();

            $table->boolean('matched')->default(false)->index();
            $table->unsignedBigInteger('matched_id')->nullable()->index();
            $table->boolean('is_mapping_missing')->default(false)->index();

            // NOTE: api debug columns are added in the next migration (separate)
            $table->timestamps();
        });

        // FIX: your jnt_checker_run_extras currently returns [] columns,
        // so we recreate it properly here.
        Schema::create('jnt_checker_run_extras', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('run_id')->index();
            $table->foreign('run_id')
                ->references('id')
                ->on('jnt_checker_runs')
                ->onDelete('cascade');

            $table->string('key')->index();
            $table->longText('value')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::dropIfExists('jnt_checker_run_extras');
        Schema::dropIfExists('jnt_checker_run_items');
        Schema::dropIfExists('jnt_checker_run_files');
        Schema::dropIfExists('jnt_checker_runs');

        Schema::enableForeignKeyConstraints();
    }
};
