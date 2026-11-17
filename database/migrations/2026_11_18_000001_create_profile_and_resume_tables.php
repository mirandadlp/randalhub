<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('original_filename');
            $table->string('storage_path');           // encrypted file on the private disk
            $table->string('mime_type', 120);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->longText('extracted_text')->nullable(); // encrypted cast
            $table->boolean('is_master')->default(false);
            $table->string('parse_status', 20)->default('pending'); // pending|parsed|failed
            $table->text('parse_error')->nullable();
            $table->timestamp('parsed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'sha256']);
            $table->index(['user_id', 'is_master']);
        });

        Schema::create('professional_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('source_resume_id')->nullable()->constrained('resumes')->nullOnDelete();
            $table->string('full_name')->nullable();
            $table->string('headline')->nullable();
            // Contact info is kept locally and never sent to the AI provider.
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('location')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('website_url')->nullable();
            $table->text('summary')->nullable();
            $table->json('skills')->nullable();          // [{name, category, years, evidence}]
            $table->json('work_history')->nullable();    // [{company, title, start, end, location, highlights[]}]
            $table->json('education')->nullable();       // [{institution, degree, field, year}]
            $table->json('certifications')->nullable();  // [{name, issuer, year}]
            $table->json('industries')->nullable();      // [string]
            $table->json('achievements')->nullable();    // [string]
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->char('content_hash', 64)->nullable(); // hash of qualification-relevant fields
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_profiles');
        Schema::dropIfExists('resumes');
    }
};
