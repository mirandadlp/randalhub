<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateApplicationPackageJob;
use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Services\Applications\ApplicationPackageService;
use App\Services\Applications\ApplicationTracker;
use App\Services\Applications\DocumentExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ApplicationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $apps = Application::with(['listing:id,title,company_name,location,remote_type,application_url,source_url,status',
            'listing.match' => fn ($m) => $m->where('user_id', $request->user()->id)->select('id', 'job_listing_id', 'match_score', 'priority_score')])
            ->where('user_id', $request->user()->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'statuses' => Application::STATUSES,
            'data' => $apps->map(fn (Application $a) => $this->summary($a)),
        ]);
    }

    public function show(Request $request, Application $application): JsonResponse
    {
        $this->authorizeOwner($request, $application);
        $application->load(['listing', 'events', 'autofillSessions', 'resume:id,label']);
        $docs = $application->documents()->orderBy('type')->orderByDesc('version')->get()
            ->groupBy('type')->map(fn ($versions) => [
                'latest' => $versions->first()->only(['id', 'type', 'title', 'content', 'format', 'version', 'is_user_edited', 'model', 'generated_at', 'updated_at']),
                'versions' => $versions->map(fn ($v) => $v->only(['id', 'version', 'is_user_edited', 'created_at'])),
            ]);

        return response()->json([
            'data' => $this->summary($application) + [
                'notes' => $application->notes,
                'resume_id' => $application->resume_id,
                'recruiter_name' => $application->recruiter_name,
                'recruiter_email' => $application->recruiter_email,
                'recruiter_phone' => $application->recruiter_phone,
                'recruiter_notes' => $application->recruiter_notes,
                'events' => $application->events->map->only(['from_status', 'to_status', 'note', 'created_at']),
                'documents' => $docs,
                'package_status' => cache()->get(GenerateApplicationPackageJob::statusKey($application->id)),
                'autofill' => $application->autofillSessions->first()?->only(['id', 'status', 'adapter', 'fields_filled', 'fields_skipped', 'message', 'approved_at', 'submitted_at', 'updated_at']),
            ],
            'statuses' => Application::STATUSES,
        ]);
    }

    public function update(Request $request, Application $application): JsonResponse
    {
        $this->authorizeOwner($request, $application);
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:20000'],
            'resume_id' => ['nullable', Rule::exists('resumes', 'id')->where('user_id', $request->user()->id)],
            'interview_at' => ['nullable', 'date'],
            'next_follow_up_at' => ['nullable', 'date'],
            'applied_at' => ['nullable', 'date'],
            'recruiter_name' => ['nullable', 'string', 'max:255'],
            'recruiter_email' => ['nullable', 'email', 'max:255'],
            'recruiter_phone' => ['nullable', 'string', 'max:50'],
            'recruiter_notes' => ['nullable', 'string', 'max:5000'],
        ]);
        if (array_key_exists('applied_at', $data) && $application->status !== 'applied' && ! $application->applied_confirmation) {
            unset($data['applied_at']); // applied date is only editable once submission was recorded
        }
        $application->update($data);

        return response()->json(['ok' => true]);
    }

    public function status(Request $request, Application $application, ApplicationTracker $tracker): JsonResponse
    {
        $this->authorizeOwner($request, $application);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Application::STATUSES))],
            'note' => ['nullable', 'string', 'max:1000'],
            'confirm_submitted' => ['nullable', 'boolean'],
            'applied_at' => ['nullable', 'date'],
        ]);
        $tracker->transition(
            $application,
            $data['status'],
            $data['note'] ?? null,
            ! empty($data['confirm_submitted']) ? 'manual' : null,
            isset($data['applied_at']) ? Carbon::parse($data['applied_at']) : null,
        );

        return response()->json(['status' => $application->fresh()->status]);
    }

    public function generate(Request $request, Application $application): JsonResponse
    {
        $this->authorizeOwner($request, $application);
        cache()->put(GenerateApplicationPackageJob::statusKey($application->id), ['state' => 'queued'], 3600);
        GenerateApplicationPackageJob::dispatch($application->id);

        return response()->json(['state' => 'queued'], 202);
    }

    public function packageStatus(Request $request, Application $application): JsonResponse
    {
        $this->authorizeOwner($request, $application);

        return response()->json(cache()->get(GenerateApplicationPackageJob::statusKey($application->id)) ?? ['state' => 'idle']);
    }

    /** Saving an edit creates a new version so earlier drafts are never lost. */
    public function saveDocument(Request $request, Application $application, ApplicationPackageService $service): JsonResponse
    {
        $this->authorizeOwner($request, $application);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(ApplicationDocument::TYPES))],
            'content' => ['required', 'string', 'max:100000'],
        ]);
        if (in_array($data['type'], ['tailored_resume', 'qa_answers'], true) && ! is_array(json_decode($data['content'], true))) {
            return response()->json(['message' => 'This document must be valid JSON.'], 422);
        }
        $doc = $service->saveDocument($application, $data['type'], $data['content'], null, true);

        return response()->json(['id' => $doc->id, 'version' => $doc->version]);
    }

    public function download(Request $request, ApplicationDocument $document, DocumentExporter $exporter)
    {
        $application = $document->application;
        $this->authorizeOwner($request, $application);
        $format = $request->validate(['format' => ['required', Rule::in(['pdf', 'docx', 'md'])]])['format'];
        $profile = $request->user()->profile;
        $name = Str::slug(($profile?->full_name ?: 'document').' '.$application->listing->company_name.' '.$document->type).'.'.$format;

        return match ($format) {
            'pdf' => response($exporter->pdf($document, $profile), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'"']),
            'docx' => response($exporter->docx($document, $profile), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'Content-Disposition' => 'attachment; filename="'.$name.'"']),
            'md' => response($document->content, 200, ['Content-Type' => 'text/markdown; charset=utf-8', 'Content-Disposition' => 'attachment; filename="'.$name.'"']),
        };
    }

    private function summary(Application $a): array
    {
        return [
            'id' => $a->id,
            'status' => $a->status,
            'status_label' => Application::STATUSES[$a->status],
            'job' => $a->listing ? [
                'id' => $a->listing->id, 'title' => $a->listing->title, 'company' => $a->listing->company_name,
                'location' => $a->listing->location, 'remote_type' => $a->listing->remote_type,
                'application_url' => $a->listing->application_url ?: $a->listing->source_url,
                'listing_status' => $a->listing->status,
                'match_score' => $a->listing->match?->match_score,
                'priority_score' => $a->listing->match?->priority_score,
            ] : null,
            'applied_at' => $a->applied_at?->toIso8601String(),
            'applied_confirmation' => $a->applied_confirmation,
            'interview_at' => $a->interview_at?->toIso8601String(),
            'next_follow_up_at' => $a->next_follow_up_at?->toIso8601String(),
            'updated_at' => $a->updated_at->toIso8601String(),
        ];
    }

    private function authorizeOwner(Request $request, Application $application): void
    {
        abort_unless($application->user_id === $request->user()->id, 404);
    }
}
