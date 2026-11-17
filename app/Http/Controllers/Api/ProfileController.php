<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ParseResumeJob;
use App\Models\ProfessionalProfile;
use App\Models\Resume;
use App\Services\Resume\ResumeStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = ProfessionalProfile::firstOrCreate(['user_id' => $request->user()->id]);

        return response()->json($profile);
    }

    public function update(Request $request): JsonResponse
    {
        $str = ['nullable', 'string', 'max:255'];
        $data = $request->validate([
            'full_name' => $str, 'headline' => $str, 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'],
            'location' => $str, 'linkedin_url' => ['nullable', 'url', 'max:255'], 'website_url' => ['nullable', 'url', 'max:255'],
            'summary' => ['nullable', 'string', 'max:10000'],
            'skills' => ['nullable', 'array', 'max:200'],
            'skills.*.name' => ['required', 'string', 'max:120'],
            'skills.*.category' => ['nullable', 'string', 'max:40'],
            'skills.*.evidence' => ['nullable', 'string', 'max:500'],
            'work_history' => ['nullable', 'array', 'max:50'],
            'work_history.*.company' => ['required', 'string', 'max:255'],
            'work_history.*.title' => ['required', 'string', 'max:255'],
            'work_history.*.start' => ['nullable', 'string', 'max:40'],
            'work_history.*.end' => ['nullable', 'string', 'max:40'],
            'work_history.*.location' => ['nullable', 'string', 'max:255'],
            'work_history.*.highlights' => ['nullable', 'array', 'max:30'],
            'work_history.*.highlights.*' => ['string', 'max:1000'],
            'education' => ['nullable', 'array', 'max:20'],
            'education.*.institution' => ['required', 'string', 'max:255'],
            'education.*.degree' => ['nullable', 'string', 'max:255'],
            'education.*.field' => ['nullable', 'string', 'max:255'],
            'education.*.year' => ['nullable', 'string', 'max:20'],
            'certifications' => ['nullable', 'array', 'max:50'],
            'certifications.*.name' => ['required', 'string', 'max:255'],
            'certifications.*.issuer' => ['nullable', 'string', 'max:255'],
            'certifications.*.year' => ['nullable', 'string', 'max:20'],
            'industries' => ['nullable', 'array', 'max:50'], 'industries.*' => ['string', 'max:120'],
            'achievements' => ['nullable', 'array', 'max:50'], 'achievements.*' => ['string', 'max:1000'],
        ]);
        $profile = ProfessionalProfile::firstOrNew(['user_id' => $request->user()->id]);
        $before = $profile->content_hash;
        $profile->fill($data);
        $profile->save();

        return response()->json($profile->toArray() + ['qualifications_changed' => $before !== $profile->content_hash]);
    }

    public function verify(Request $request): JsonResponse
    {
        $profile = ProfessionalProfile::where('user_id', $request->user()->id)->firstOrFail();
        $profile->update(['is_verified' => true, 'verified_at' => now()]);

        return response()->json($profile);
    }

    public function resumes(Request $request): JsonResponse
    {
        return response()->json(Resume::where('user_id', $request->user()->id)->latest()->get());
    }

    public function upload(Request $request, ResumeStorage $storage): JsonResponse
    {
        $data = $request->validate([
            // 10 MB max; PDF/DOCX only (content is re-checked by magic bytes).
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,docx'],
            'label' => ['nullable', 'string', 'max:120'],
            'is_master' => ['nullable', 'boolean'],
            'parse' => ['nullable', 'boolean'],
            'overwrite' => ['nullable', 'boolean'],
        ]);
        $resume = $storage->store($request->user(), $data['file'], $data['label'] ?? null, (bool) ($data['is_master'] ?? false));
        if (! in_array($resume->mime_type, array_keys(ResumeStorage::MIME_TYPES), true)) {
            $storage->delete($resume);

            return response()->json(['message' => 'Only PDF and DOCX files are accepted.'], 422);
        }
        if (($data['parse'] ?? true) && $resume->parse_status !== 'failed') {
            ParseResumeJob::dispatch($resume->id, (bool) ($data['overwrite'] ?? false));
        }

        return response()->json($resume->fresh(), 201);
    }

    public function parse(Request $request, Resume $resume): JsonResponse
    {
        abort_unless($resume->user_id === $request->user()->id, 404);
        $resume->update(['parse_status' => 'pending', 'parse_error' => null]);
        ParseResumeJob::dispatch($resume->id, $request->boolean('overwrite'));

        return response()->json(['queued' => true], 202);
    }

    public function makeMaster(Request $request, Resume $resume): JsonResponse
    {
        abort_unless($resume->user_id === $request->user()->id, 404);
        Resume::where('user_id', $request->user()->id)->update(['is_master' => false]);
        $resume->update(['is_master' => true]);

        return response()->json($resume);
    }

    public function download(Request $request, Resume $resume, ResumeStorage $storage)
    {
        abort_unless($resume->user_id === $request->user()->id, 404);

        return response($storage->contents($resume), 200, [
            'Content-Type' => $resume->mime_type,
            'Content-Disposition' => 'attachment; filename="'.addcslashes($resume->original_filename, '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, Resume $resume, ResumeStorage $storage): JsonResponse
    {
        abort_unless($resume->user_id === $request->user()->id, 404);
        $storage->delete($resume);

        return response()->json(['ok' => true]);
    }
}
