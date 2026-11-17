<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportError;
use App\Models\JobSource;
use App\Models\ScanRun;
use App\Services\Discovery\ScanCoordinator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SourceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sources = JobSource::with('company:id,name')
            ->when($request->filled('health'), fn ($q) => $q->where('health', $request->string('health')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->orderByRaw("CASE health WHEN 'failing' THEN 0 WHEN 'blocked' THEN 1 WHEN 'degraded' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $sources,
            'summary' => JobSource::selectRaw('health, COUNT(*) as c')->groupBy('health')->pluck('c', 'health'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['adzuna'])],
            'identifier' => ['required', 'string', 'max:200'],
            'config' => ['nullable', 'array'],
        ]);
        $source = JobSource::firstOrCreate(['type' => $data['type'], 'identifier' => $data['identifier']], [
            'name' => 'Adzuna search: '.$data['identifier'], 'config' => $data['config'] ?? ['max_days_old' => 14, 'pages' => 2], 'enabled' => true,
        ]);

        return response()->json($source, 201);
    }

    public function update(Request $request, JobSource $source): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['boolean'],
            'max_jobs' => ['nullable', 'integer', 'between:1,10000'],
            'config' => ['nullable', 'array'],
        ]);
        $source->update($data);

        return response()->json($source);
    }

    public function destroy(JobSource $source): JsonResponse
    {
        $source->delete();

        return response()->json(['ok' => true]);
    }

    public function resetHealth(JobSource $source): JsonResponse
    {
        $source->update(['health' => 'unknown', 'consecutive_failures' => 0, 'last_error' => null]);

        return response()->json($source);
    }

    public function scan(JobSource $source, ScanCoordinator $coordinator): JsonResponse
    {
        if ($source->health !== 'healthy') {
            $source->update(['health' => 'unknown']); // manual retry clears the weekly back-off
        }
        $run = $coordinator->start('manual', [$source->id]);

        return $run
            ? response()->json(['scan_run_id' => $run->id], 202)
            : response()->json(['message' => 'A scan is already running. Try again when it finishes.'], 409);
    }

    public function errors(Request $request): JsonResponse
    {
        return response()->json(ImportError::with('source:id,name,type')
            ->when($request->filled('source_id'), fn ($q) => $q->where('job_source_id', $request->integer('source_id')))
            ->latest('occurred_at')->limit(100)->get());
    }

    public function scans(): JsonResponse
    {
        return response()->json(ScanRun::latest('started_at')->limit(50)->get());
    }

    public function scanDetail(ScanRun $scan): JsonResponse
    {
        return response()->json($scan->load('sourceResults.source:id,name,type'));
    }

    public function startScan(ScanCoordinator $coordinator): JsonResponse
    {
        $run = $coordinator->start('manual');

        return $run
            ? response()->json(['scan_run_id' => $run->id], 202)
            : response()->json(['message' => 'A scan is already running.'], 409);
    }
}
