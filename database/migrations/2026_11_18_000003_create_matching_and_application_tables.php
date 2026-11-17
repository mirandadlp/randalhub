<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_listing_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending'); // pending | completed | failed
            $table->unsignedTinyInteger('match_score')->nullable();          // AI qualification 0-100
            $table->decimal('priority_score', 5, 2)->nullable();             // weighted opportunity score
            $table->json('priority_breakdown')->nullable();
            $table->string('application_priority', 10)->nullable();         // high | medium | low | skip
            $table->string('seniority_fit', 20)->nullable();                 // under | match | over
            $table->text('qualification_summary')->nullable();               // why you qualify
            $table->json('relevant_experience')->nullable();
            $table->json('skills_matched')->nullable();
            $table->json('skills_missing')->nullable();
            $table->json('missing_qualifications')->nullable();
            $table->json('transferable_skills')->nullable();
            $table->json('resume_emphasis')->nullable();
            $table->json('dimension_scores')->nullable();
            $table->text('concerns')->nullable();
            $table->string('model', 60)->nullable();
            $table->char('profile_hash', 64)->nullable();
            $table->char('job_content_hash', 64)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->text('error')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'job_listing_id']);
            $table->index(['user_id', 'match_score']);
            $table->index(['user_id', 'priority_score']);
        });

        Schema::create('saved_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_listing_id')->constrained()->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'job_listing_id']);
        });

        Schema::create('dismissed_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_listing_id')->constrained()->cascadeOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'job_listing_id']);
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resume_id')->nullable()->constrained()->nullOnDelete();
            // discovered | saved | preparing | ready | applied | interviewing | offer | rejected | archived
            $table->string('status', 20)->default('discovered');
            $table->text('notes')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->string('applied_confirmation', 20)->nullable(); // manual | automation
            $table->timestamp('interview_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->string('recruiter_name')->nullable();
            $table->string('recruiter_email')->nullable();
            $table->string('recruiter_phone', 50)->nullable();
            $table->text('recruiter_notes')->nullable();
            $table->timestamps();

            // One application per job: protects against duplicate applications.
            $table->unique(['user_id', 'job_listing_id']);
            $table->index(['user_id', 'status']);
            $table->index('next_follow_up_at');
        });

        Schema::create('application_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('application_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            // requirements_analysis | resume_recommendations | tailored_resume | cover_letter | qa_answers
            $table->string('type', 40);
            $table->string('title');
            $table->longText('content');
            $table->string('format', 10)->default('markdown'); // markdown | json | text
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_user_edited')->default(false);
            $table->string('model', 60)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'type']);
        });

        Schema::create('autofill_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            // queued | running | awaiting_approval | approved | submitting | submitted | manual_required | failed | cancelled
            $table->string('status', 20)->default('queued');
            $table->string('adapter', 30)->nullable();
            $table->char('token_hash', 64);
            $table->text('target_url');
            $table->json('fields_filled')->nullable();
            $table->json('fields_skipped')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autofill_sessions');
        Schema::dropIfExists('application_documents');
        Schema::dropIfExists('application_events');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('dismissed_jobs');
        Schema::dropIfExists('saved_jobs');
        Schema::dropIfExists('job_matches');
    }
};
