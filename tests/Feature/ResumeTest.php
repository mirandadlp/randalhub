<?php

namespace Tests\Feature;

use App\Models\Resume;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class ResumeTest extends TestCase
{
    use RefreshDatabase;

    private function docx(string $text): UploadedFile
    {
        $word = new PhpWord;
        $section = $word->addSection();
        foreach (explode("\n", $text) as $line) {
            $section->addText($line);
        }
        $path = tempnam(sys_get_temp_dir(), 'cv').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($path);

        return new UploadedFile($path, 'Master Resume.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
    }

    private function resumeText(): string
    {
        return "Pat Candidate\npat@example.test | (702) 555-0100 | linkedin.com/in/patcandidate\n"
            ."Principal AI Consultant, Acme Advisory, 2021 - Present\nLed enterprise AI transformation and SaaS onboarding programs for 30 clients.";
    }

    public function test_upload_stores_encrypted_file_extracts_text_and_parses_with_redaction(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $ai = $this->fakeAi()->push([
            'headline' => 'Principal AI Consultant', 'location' => 'Las Vegas, NV', 'summary' => 'AI transformation consultant.',
            'skills' => [['name' => 'AI transformation', 'category' => 'ai', 'evidence' => 'Led enterprise AI transformation']],
            'work_history' => [['company' => 'Acme Advisory', 'title' => 'Principal AI Consultant', 'start' => '2021', 'end' => 'Present', 'location' => '', 'highlights' => ['Led programs']]],
            'education' => [], 'certifications' => [], 'industries' => ['Consulting'], 'achievements' => [],
        ]);

        $response = $this->actingAs($user)->post('/api/resumes', ['file' => $this->docx($this->resumeText()), 'parse' => 1], ['Accept' => 'application/json']);
        $response->assertCreated();

        $resume = Resume::sole();
        $this->assertTrue($resume->is_master);
        $this->assertSame('parsed', $resume->parse_status);
        $this->assertStringContainsString('Acme Advisory', $resume->extracted_text);

        // The stored file is encrypted: plaintext must not appear on disk.
        $raw = Storage::disk('local')->get($resume->storage_path);
        $this->assertStringNotContainsString('Acme Advisory', $raw);
        $this->assertStringNotContainsString('PK', substr($raw, 0, 2));

        // Contact details never reach the AI provider, but are captured locally.
        $this->assertStringNotContainsString('pat@example.test', $ai->calls[0]['user']);
        $this->assertStringNotContainsString('555-0100', $ai->calls[0]['user']);
        $profile = $user->fresh()->profile;
        $this->assertSame('pat@example.test', $profile->email);
        $this->assertSame('Pat Candidate', $profile->full_name);
        $this->assertSame('Acme Advisory', $profile->work_history[0]['company']);
        $this->assertFalse($profile->is_verified);

        // Download returns the original bytes.
        $download = $this->actingAs($user)->get("/api/resumes/{$resume->id}/download");
        $download->assertOk();
        $this->assertStringStartsWith('PK', $download->getContent());
    }

    public function test_duplicate_upload_is_deduplicated(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $file = $this->docx($this->resumeText());
        $this->actingAs($user)->post('/api/resumes', ['file' => $file, 'parse' => 0], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($user)->post('/api/resumes', ['file' => $file, 'parse' => 0], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(1, Resume::count());
    }

    public function test_rejects_non_resume_files(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $exe = UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream');
        $txt = UploadedFile::fake()->createWithContent('resume.pdf', 'not really a pdf <script>');

        $this->actingAs($user)->postJson('/api/resumes', ['file' => $exe])->assertStatus(422);
        $this->actingAs($user)->post('/api/resumes', ['file' => $txt], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(0, Resume::count());
    }

    public function test_parsing_without_ai_keeps_raw_text_for_manual_editing(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->fakeAi()->configured = false;

        $this->actingAs($user)->post('/api/resumes', ['file' => $this->docx($this->resumeText())], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame('parsed', Resume::sole()->parse_status);
        $this->assertStringContainsString('Claude is not configured', Resume::sole()->parse_error);
        $this->assertStringContainsString('Acme Advisory', $user->fresh()->profile->summary);
    }

    public function test_users_cannot_access_other_users_resumes(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/api/resumes', ['file' => $this->docx($this->resumeText()), 'parse' => 0], ['Accept' => 'application/json']);
        $other = User::factory()->create();

        $this->actingAs($other)->get('/api/resumes/'.Resume::sole()->id.'/download')->assertNotFound();
    }
}
