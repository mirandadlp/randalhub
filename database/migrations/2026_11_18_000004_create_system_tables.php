<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->longText('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // priority_alert | daily_digest
            $table->string('dedupe_key', 120);
            $table->string('channel', 20)->default('mail');
            $table->string('subject')->nullable();
            $table->json('job_listing_ids')->nullable();
            $table->string('status', 20)->default('sent'); // sent | failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['type', 'created_at']);
        });

        Schema::create('api_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);  // anthropic | adzuna
            $table->string('purpose', 40);   // job_match | resume_parse | application_package | search
            $table->string('model', 60)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->string('status', 20); // success | error | refusal
            $table->unsignedInteger('duration_ms')->default(0);
            $table->nullableMorphs('related');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['provider', 'created_at']);
        });

        Schema::create('task_runs', function (Blueprint $table) {
            $table->id();
            $table->string('task', 80);
            $table->string('status', 20); // running | succeeded | failed | skipped
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->text('output')->nullable();
            $table->timestamps();

            $table->index(['task', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_runs');
        Schema::dropIfExists('api_usage_logs');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('system_settings');
    }
};
