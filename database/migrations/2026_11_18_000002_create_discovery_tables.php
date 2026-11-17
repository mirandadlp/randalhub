<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('website')->nullable();
            $table->string('careers_url', 1024)->nullable();
            $table->string('industry', 80)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_watched')->default(true);
            $table->boolean('is_preferred')->default(false);
            $table->boolean('is_excluded')->default(false);
            // seed | user | search_api | ats_probe
            $table->string('discovered_via', 20)->default('user');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('industry');
            $table->index(['is_watched', 'is_excluded']);
        });

        Schema::create('job_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            // greenhouse | lever | ashby | smartrecruiters | workable | career_page | adzuna
            $table->string('type', 30);
            $table->string('identifier', 512);  // board token, account slug, URL, or search query
            $table->string('name')->nullable();
            $table->json('config')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('max_jobs')->nullable();
            // unknown | healthy | degraded | failing | blocked
            $table->string('health', 20)->default('unknown');
            $table->text('limitation_note')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('last_job_count')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['type', 'identifier'], 'job_sources_type_identifier_unique');
            $table->index(['enabled', 'health']);
        });

        Schema::create('scan_runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 20); // scheduled | manual
            $table->string('status', 20)->default('running'); // running | completed | partial | failed
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('sources_total')->default(0);
            $table->unsignedInteger('sources_succeeded')->default(0);
            $table->unsignedInteger('sources_failed')->default(0);
            $table->unsignedInteger('sources_skipped')->default(0);
            $table->unsignedInteger('jobs_found')->default(0);
            $table->unsignedInteger('jobs_new')->default(0);
            $table->unsignedInteger('jobs_updated')->default(0);
            $table->unsignedInteger('jobs_closed')->default(0);
            $table->unsignedInteger('duplicates_found')->default(0);
            $table->text('error_summary')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
        });

        Schema::create('job_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('primary_source_id')->nullable()->constrained('job_sources')->nullOnDelete();
            $table->string('company_name');
            $table->string('title');
            $table->string('normalized_title');
            $table->string('location', 512)->nullable();
            $table->boolean('is_us')->nullable();
            $table->boolean('is_nevada')->default(false);
            $table->string('remote_type', 10)->default('unknown'); // remote | hybrid | onsite | unknown
            $table->string('employment_type', 40)->nullable();
            $table->string('department')->nullable();
            $table->string('industry', 80)->nullable();
            $table->unsignedInteger('salary_min')->nullable();
            $table->unsignedInteger('salary_max')->nullable();
            $table->string('salary_currency', 3)->nullable();
            $table->string('salary_interval', 10)->nullable(); // year | hour | month
            $table->string('salary_text')->nullable();
            $table->longText('description_html')->nullable();
            $table->longText('description_text')->nullable();
            $table->longText('requirements_text')->nullable();
            $table->text('source_url');
            $table->text('application_url')->nullable();
            $table->text('canonical_url');
            $table->char('url_hash', 64)->index();
            $table->char('fingerprint', 64)->index();  // company + title + location
            $table->char('content_hash', 64);
            $table->timestamp('posted_at')->nullable();   // employer-provided
            $table->timestamp('first_seen_at');           // first discovered by this app
            $table->timestamp('last_seen_at');
            $table->timestamp('content_changed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('status', 10)->default('open'); // open | closed | archived
            $table->unsignedSmallInteger('missed_scans')->default(0);
            $table->boolean('is_direct_employer')->default(true);
            $table->unsignedInteger('applicant_count')->nullable();
            $table->string('applicant_count_source')->nullable();
            $table->timestamp('applicant_count_observed_at')->nullable();
            $table->boolean('is_repost')->default(false);
            $table->foreignId('repost_of_id')->nullable()->constrained('job_listings')->nullOnDelete();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('job_listings')->nullOnDelete();
            $table->unsignedSmallInteger('prescreen_score')->nullable(); // rule-based relevance, not AI
            $table->timestamps();

            $table->index(['status', 'first_seen_at']);
            $table->index('posted_at');
            $table->index(['company_id', 'status']);
            $table->index('remote_type');
            $table->index('salary_max');
            $table->index('prescreen_score');
        });

        Schema::create('job_source_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('last_scan_run_id')->nullable()->constrained('scan_runs')->nullOnDelete();
            $table->string('external_id');
            $table->text('source_url');
            $table->char('content_hash', 64);
            $table->longText('raw_payload')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('missed_scans')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['job_source_id', 'external_id'], 'obs_source_external_unique');
            $table->index(['job_listing_id', 'is_active']);
        });

        Schema::create('scan_run_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_source_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20); // succeeded | failed | skipped
            $table->unsignedInteger('jobs_found')->default(0);
            $table->unsignedInteger('jobs_new')->default(0);
            $table->unsignedInteger('jobs_updated')->default(0);
            $table->unsignedInteger('jobs_closed')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['scan_run_id', 'job_source_id']);
        });

        Schema::create('import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_source_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('scan_run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('error_type', 40); // http | parse | robots | validation | blocked
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_errors');
        Schema::dropIfExists('scan_run_sources');
        Schema::dropIfExists('job_source_observations');
        Schema::dropIfExists('job_listings');
        Schema::dropIfExists('scan_runs');
        Schema::dropIfExists('job_sources');
        Schema::dropIfExists('companies');
    }
};
