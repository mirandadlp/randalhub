<?php

namespace App\Services\Applications;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\JobMatch;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiException;
use App\Services\Ai\PromptSafety;
use App\Services\SettingsService;
use Illuminate\Support\Str;

/**
 * Generates an editable application package from the verified profile only:
 * requirements analysis, resume recommendations, tailored resume, cover letter and Q&A answers.
 * Output is post-validated: experience entries and skills not present in the profile are removed.
 */
class ApplicationPackageService
{
    public function __construct(private AiClient $ai, private SettingsService $settings, private ApplicationTracker $tracker) {}

    public function generate(Application $application): array
    {
        if (! $this->settings->get('ai.application_assistant_enabled', true)) {
            throw new AiException('The AI application assistant is turned off in Settings.', 'disabled');
        }
        $user = $application->user;
        $profile = $user->profile;
        if (! $profile || ! $profile->isUsable()) {
            throw new AiException('Complete your professional profile before generating an application package.', 'not_configured');
        }
        $job = $application->listing;
        $match = JobMatch::where('user_id', $user->id)->where('job_listing_id', $job->id)->first();

        $result = $this->ai->structured(
            purpose: 'application_package',
            system: [['text' => $this->systemPrompt()]],
            user: "VERIFIED CANDIDATE PROFILE (the only allowed source of facts about the candidate):\n"
                .json_encode($profile->toAiPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                ."\n\nPRIOR MATCH ANALYSIS (may be empty):\n".json_encode([
                    'resume_emphasis' => $match?->resume_emphasis,
                    'skills_missing' => $match?->skills_missing,
                ], JSON_UNESCAPED_UNICODE)
                ."\n\nCandidate salary floor (USD/yr, may be null): ".json_encode($this->settings->get('minimum_salary'))
                ."\n\nJOB: {$job->title} at {$job->company_name} ({$job->location}, {$job->remote_type})\n"
                .PromptSafety::wrapUntrusted('untrusted_job_posting', $job->analysisText(20000))
                ."\n\nProduce the application package JSON.",
            schema: self::schema(),
            related: $application,
        );

        $d = $this->enforceVerified($result->data, $profile->toAiPayload());
        $docs = [
            'requirements_analysis' => $this->requirementsMarkdown($d['requirements_analysis'] ?? []),
            'resume_recommendations' => collect($d['resume_recommendations'] ?? [])->map(fn ($r) => '- '.$r)->implode("\n"),
            'tailored_resume' => json_encode($d['tailored_resume'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            'qa_answers' => json_encode($d['qa_answers'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ];
        if (($d['cover_letter_recommended'] ?? true) && filled($d['cover_letter'] ?? '')) {
            $docs['cover_letter'] = $d['cover_letter'];
        }

        $saved = [];
        foreach ($docs as $type => $content) {
            $saved[] = $this->saveDocument($application, $type, $content, $result->model);
        }

        $this->tracker->advanceTo($application, 'preparing', 'Application package generated');

        return ['documents' => $saved, 'removed_unverified' => $d['_removed'] ?? []];
    }

    public function saveDocument(Application $application, string $type, string $content, ?string $model = null, bool $userEdited = false): ApplicationDocument
    {
        $version = (int) ApplicationDocument::where('application_id', $application->id)->where('type', $type)->max('version') + 1;

        return ApplicationDocument::create([
            'application_id' => $application->id,
            'type' => $type,
            'title' => ApplicationDocument::TYPES[$type].' — '.$application->listing->company_name,
            'content' => $content,
            'format' => in_array($type, ['tailored_resume', 'qa_answers'], true) ? 'json' : 'markdown',
            'version' => $version,
            'is_user_edited' => $userEdited,
            'model' => $model,
            'generated_at' => $userEdited ? null : now(),
        ]);
    }

    /** Remove any experience, certification or skill the profile does not contain. */
    public function enforceVerified(array $d, array $profile): array
    {
        $removed = [];
        $norm = fn ($s) => Str::lower(trim(preg_replace('/\s+/', ' ', (string) $s)));

        $known = collect($profile['work_history'] ?? [])->map(fn ($w) => $norm($w['company'] ?? '').'|'.$norm($w['title'] ?? ''))->all();
        $knownCompanies = collect($profile['work_history'] ?? [])->map(fn ($w) => $norm($w['company'] ?? ''))->all();
        $experience = [];
        foreach ($d['tailored_resume']['experience'] ?? [] as $exp) {
            $key = $norm($exp['company'] ?? '').'|'.$norm($exp['title'] ?? '');
            if (in_array($key, $known, true) || in_array($norm($exp['company'] ?? ''), $knownCompanies, true)) {
                $experience[] = $exp;
            } else {
                $removed[] = 'Experience not in profile: '.($exp['title'] ?? '?').' at '.($exp['company'] ?? '?');
            }
        }
        $d['tailored_resume']['experience'] = $experience;

        $skillPool = collect($profile['skills'] ?? [])->map(fn ($s) => $norm(is_array($s) ? ($s['name'] ?? '') : $s))->filter()->all();
        $profileText = $norm(json_encode($profile));
        $skills = [];
        foreach ($d['tailored_resume']['core_skills'] ?? [] as $skill) {
            $n = $norm($skill);
            $supported = in_array($n, $skillPool, true)
                || collect($skillPool)->contains(fn ($p) => str_contains($p, $n) || str_contains($n, $p))
                || str_contains($profileText, $n);
            if ($supported) {
                $skills[] = $skill;
            } else {
                $removed[] = 'Skill not established in profile: '.$skill;
            }
        }
        $d['tailored_resume']['core_skills'] = $skills;

        $certPool = collect($profile['certifications'] ?? [])->map(fn ($c) => $norm($c['name'] ?? ''))->all();
        $certs = [];
        foreach ($d['tailored_resume']['certifications'] ?? [] as $cert) {
            if (in_array($norm($cert), $certPool, true) || collect($certPool)->contains(fn ($p) => $p !== '' && str_contains($norm($cert), $p))) {
                $certs[] = $cert;
            } else {
                $removed[] = 'Certification not in profile: '.$cert;
            }
        }
        $d['tailored_resume']['certifications'] = $certs;
        $d['_removed'] = $removed;

        return $d;
    }

    private function requirementsMarkdown(array $analysis): string
    {
        $lines = [($analysis['summary'] ?? '')."\n", '| Requirement | Status | Evidence from your profile |', '|---|---|---|'];
        foreach ($analysis['key_requirements'] ?? [] as $r) {
            $lines[] = '| '.str_replace('|', '/', $r['requirement'] ?? '').' | '.($r['status'] ?? '').' | '.str_replace('|', '/', $r['evidence'] ?? '').' |';
        }

        return implode("\n", $lines);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You help a job seeker prepare an application. You may ONLY use facts from the verified candidate profile.
Hard rules:
- Never invent employers, titles, dates, metrics, degrees, certifications, tools or skills.
- You may rephrase and reorder verified experience to emphasize what this job values.
- tailored_resume.experience entries must use company and title exactly as they appear in the profile.
- core_skills and certifications must come from the profile.
- In qa_answers, when an answer depends on facts not in the profile (work authorization, sponsorship, start date,
  relocation, salary when no floor is given, demographic questions), set needs_user_input=true and give an
  empty answer or a short template with [brackets]. Never guess these.
- Write the cover letter (250-350 words) only when it adds value; set cover_letter_recommended accordingly.
- Do not include the candidate's contact details; they are added locally.
- requirements_analysis.status is one of met, partial, gap.
PROMPT."\n\n".PromptSafety::UNTRUSTED_NOTICE;
    }

    public static function schema(): array
    {
        $str = ['type' => 'string'];
        $list = ['type' => 'array', 'items' => $str];
        $obj = fn (array $props) => ['type' => 'object', 'properties' => $props, 'required' => array_keys($props), 'additionalProperties' => false];

        return $obj([
            'requirements_analysis' => $obj([
                'summary' => $str,
                'key_requirements' => ['type' => 'array', 'items' => $obj([
                    'requirement' => $str,
                    'status' => ['type' => 'string', 'enum' => ['met', 'partial', 'gap']],
                    'evidence' => $str,
                ])],
            ]),
            'resume_recommendations' => $list,
            'tailored_resume' => $obj([
                'headline' => $str,
                'summary' => $str,
                'core_skills' => $list,
                'experience' => ['type' => 'array', 'items' => $obj([
                    'company' => $str, 'title' => $str, 'start' => $str, 'end' => $str, 'bullets' => $list,
                ])],
                'education' => $list,
                'certifications' => $list,
            ]),
            'cover_letter_recommended' => ['type' => 'boolean'],
            'cover_letter' => $str,
            'qa_answers' => ['type' => 'array', 'items' => $obj([
                'question' => $str, 'answer' => $str, 'needs_user_input' => ['type' => 'boolean'],
            ])],
        ]);
    }
}
