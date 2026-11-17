<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\JobSource;
use App\Services\Discovery\AtsDetector;
use App\Services\Support\UrlCanonicalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companies = Company::query()
            ->withCount(['listings as open_jobs' => fn ($q) => $q->where('status', 'open')->whereNull('duplicate_of_id')])
            ->with('sources:id,company_id,type,identifier,enabled,health,last_success_at,last_error,limitation_note,last_job_count')
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))
            ->when($request->string('filter')->toString() === 'watched', fn ($q) => $q->where('is_watched', true))
            ->when($request->string('filter')->toString() === 'discovered', fn ($q) => $q->where('discovered_via', 'search_api'))
            ->when($request->string('filter')->toString() === 'excluded', fn ($q) => $q->where('is_excluded', true))
            ->orderByDesc('is_preferred')->orderBy('name')
            ->paginate(50);

        return response()->json($companies);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:companies,name'],
            'industry' => ['nullable', 'string', 'max:80'],
            'website' => ['nullable', 'url', 'max:255'],
            'careers_url' => ['nullable', 'url', 'max:1024'],
            'is_preferred' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $company = Company::create($data + ['is_watched' => true, 'discovered_via' => 'user']);

        return response()->json($company, 201);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('companies', 'name')->ignore($company->id)],
            'industry' => ['nullable', 'string', 'max:80'],
            'website' => ['nullable', 'url', 'max:255'],
            'careers_url' => ['nullable', 'url', 'max:1024'],
            'is_watched' => ['boolean'],
            'is_preferred' => ['boolean'],
            'is_excluded' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $company->update($data);
        if (array_key_exists('is_excluded', $data) || array_key_exists('is_watched', $data)) {
            $company->sources()->update(['enabled' => $company->is_watched && ! $company->is_excluded]);
        }

        return response()->json($company->fresh('sources'));
    }

    public function destroy(Company $company): JsonResponse
    {
        $company->delete();

        return response()->json(['ok' => true]);
    }

    /** Detect a public ATS board for a company name. */
    public function detect(Request $request, AtsDetector $detector): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        return response()->json(['boards' => $detector->detect($data['name'])]);
    }

    public function addSource(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(JobSource::TYPES)],
            'identifier' => ['required', 'string', 'max:512'],
            'max_jobs' => ['nullable', 'integer', 'between:1,10000'],
            'config' => ['nullable', 'array'],
            'config.follow_pattern' => ['nullable', 'string', 'max:200'],
            'config.max_pages' => ['nullable', 'integer', 'between:1,100'],
        ]);
        if ($data['type'] === 'career_page' && ! UrlCanonicalizer::isSafePublicUrl($data['identifier'])) {
            return response()->json(['message' => 'Career page must be a public http(s) URL.'], 422);
        }
        $source = JobSource::firstOrCreate(
            ['type' => $data['type'], 'identifier' => trim($data['identifier'])],
            ['company_id' => $company->id, 'name' => $company->name.' ('.$data['type'].')', 'max_jobs' => $data['max_jobs'] ?? null, 'config' => $data['config'] ?? null, 'enabled' => true],
        );

        return response()->json($source, 201);
    }
}
