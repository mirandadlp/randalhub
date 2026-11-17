<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\JobListingResource;
use App\Jobs\GenerateApplicationPackageJob;
use App\Models\Company;
use App\Models\DismissedJob;
use App\Models\JobListing;
use App\Models\SavedJob;
use App\Services\Ai\AiException;
use App\Services\Applications\ApplicationTracker;
use App\Services\JobQuery;
use App\Services\Matching\JobMatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class JobController extends Controller
{
    public function index(Request $request, JobQuery $query)
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'title' => ['nullable', 'string', 'max:200'],
            'industry' => ['nullable', 'array'], 'industry.*' => ['string', 'max:80'],
            'company_id' => ['nullable', 'array'], 'company_id.*' => ['integer'],
            'remote' => ['nullable', 'array'], 'remote.*' => [Rule::in(['remote', 'hybrid', 'onsite', 'unknown'])],
            'location' => ['nullable', Rule::in(['any', 'us', 'nevada', 'us_remote', 'preferred'])],
            'min_match' => ['nullable', 'integer', 'between:0,100'],
            'min_salary' => ['nullable', 'integer', 'min:0'],
            'posted_within' => ['nullable', 'integer', 'between:1,365'],
            'application_status' => ['nullable', 'array'],
            'saved' => ['nullable', 'boolean'],
            'analyzed' => ['nullable', 'boolean'],
            'new_today' => ['nullable', 'boolean'],
            'include_dismissed' => ['nullable', 'boolean'],
            'listing_status' => ['nullable', Rule::in(['open', 'closed', 'archived', 'all'])],
            'sort' => ['nullable', Rule::in(['newest', 'match', 'priority', 'salary', 'relevance'])],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ]);

        $page = $query->build($request->user(), $f)->paginate($f['per_page'] ?? 25);

        return JobListingResource::collection($page);
    }

    public function filters(): JsonResponse
    {
        return response()->json([
            'industries' => JobListing::query()->whereNotNull('industry')->distinct()->orderBy('industry')->pluck('industry'),
            'companies' => Company::whereHas('listings', fn ($q) => $q->where('status', 'open'))->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, JobListing $job): JsonResponse
    {
        $user = $request->user();
        $job->load([
            'match' => fn ($m) => $m->where('user_id', $user->id),
            'application' => fn ($a) => $a->where('user_id', $user->id),
            'company', 'primarySource', 'observations.source.company', 'repostOf',
        ])->loadExists([
            'savedBy as saved_exists' => fn ($s) => $s->where('user_id', $user->id),
            'dismissedBy as dismissed_exists' => fn ($s) => $s->where('user_id', $user->id),
        ]);

        return response()->json(['data' => (new JobListingResource($job))->detailed()->toArray($request)]);
    }

    public function save(Request $request, JobListing $job, ApplicationTracker $tracker): JsonResponse
    {
        SavedJob::firstOrCreate(['user_id' => $request->user()->id, 'job_listing_id' => $job->id]);
        DismissedJob::where('user_id', $request->user()->id)->where('job_listing_id', $job->id)->delete();
        $app = $tracker->ensure($request->user(), $job, 'saved');
        $tracker->advanceTo($app, 'saved');

        return response()->json(['saved' => true]);
    }

    public function unsave(Request $request, JobListing $job): JsonResponse
    {
        SavedJob::where('user_id', $request->user()->id)->where('job_listing_id', $job->id)->delete();

        return response()->json(['saved' => false]);
    }

    public function dismiss(Request $request, JobListing $job): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        DismissedJob::updateOrCreate(['user_id' => $request->user()->id, 'job_listing_id' => $job->id], ['reason' => $data['reason'] ?? null]);

        return response()->json(['dismissed' => true]);
    }

    public function undismiss(Request $request, JobListing $job): JsonResponse
    {
        DismissedJob::where('user_id', $request->user()->id)->where('job_listing_id', $job->id)->delete();

        return response()->json(['dismissed' => false]);
    }

    public function analyze(Request $request, JobListing $job, JobMatchService $service): JsonResponse
    {
        try {
            $match = $service->analyze($request->user()->load('profile'), $job, $request->boolean('force'));
        } catch (AiException $e) {
            return response()->json(['message' => $e->getMessage(), 'kind' => $e->kind], 422);
        }

        return response()->json(['match_score' => $match->match_score, 'priority_score' => $match->priority_score]);
    }

    public function track(Request $request, JobListing $job, ApplicationTracker $tracker): JsonResponse
    {
        $app = $tracker->ensure($request->user(), $job, 'saved');

        return response()->json(['application_id' => $app->id]);
    }

    /** "Prepare application": create tracking record and generate the package in the background. */
    public function prepare(Request $request, JobListing $job, ApplicationTracker $tracker): JsonResponse
    {
        $app = $tracker->ensure($request->user(), $job, 'preparing');
        $tracker->advanceTo($app, 'preparing', 'Preparing application');
        if ($request->boolean('generate', true)) {
            cache()->put(GenerateApplicationPackageJob::statusKey($app->id), ['state' => 'queued'], 3600);
            GenerateApplicationPackageJob::dispatch($app->id);
        }

        return response()->json(['application_id' => $app->id], 202);
    }
}
