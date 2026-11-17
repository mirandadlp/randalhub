<?php

use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\AutofillController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SourceController;
use App\Http\Controllers\Api\SystemController;
use Illuminate\Support\Facades\Route;

// Called by the local Playwright script; authenticated by a per-session token, not the user session.
Route::prefix('automation/sessions/{session}')->middleware('throttle:automation')->group(function () {
    Route::get('poll', [AutofillController::class, 'poll']);
    Route::post('report', [AutofillController::class, 'report']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', fn () => request()->user()->only(['id', 'name', 'email']));
    Route::get('dashboard', DashboardController::class);

    // Jobs
    Route::get('jobs', [JobController::class, 'index']);
    Route::get('jobs/filters', [JobController::class, 'filters']);
    Route::get('jobs/{job}', [JobController::class, 'show']);
    Route::post('jobs/{job}/save', [JobController::class, 'save']);
    Route::delete('jobs/{job}/save', [JobController::class, 'unsave']);
    Route::post('jobs/{job}/dismiss', [JobController::class, 'dismiss']);
    Route::delete('jobs/{job}/dismiss', [JobController::class, 'undismiss']);
    Route::post('jobs/{job}/analyze', [JobController::class, 'analyze'])->middleware('throttle:expensive');
    Route::post('jobs/{job}/track', [JobController::class, 'track']);
    Route::post('jobs/{job}/prepare', [JobController::class, 'prepare'])->middleware('throttle:expensive');

    // Applications
    Route::get('applications', [ApplicationController::class, 'index']);
    Route::get('applications/{application}', [ApplicationController::class, 'show']);
    Route::patch('applications/{application}', [ApplicationController::class, 'update']);
    Route::post('applications/{application}/status', [ApplicationController::class, 'status']);
    Route::post('applications/{application}/package', [ApplicationController::class, 'generate'])->middleware('throttle:expensive');
    Route::get('applications/{application}/package-status', [ApplicationController::class, 'packageStatus']);
    Route::post('applications/{application}/documents', [ApplicationController::class, 'saveDocument']);
    Route::get('documents/{document}/download', [ApplicationController::class, 'download']);
    Route::post('applications/{application}/autofill', [AutofillController::class, 'start'])->middleware('throttle:expensive');
    Route::get('autofill/{session}', [AutofillController::class, 'show']);
    Route::post('autofill/{session}/approve', [AutofillController::class, 'approve']);
    Route::post('autofill/{session}/cancel', [AutofillController::class, 'cancel']);

    // Companies, sources, scans
    Route::get('companies', [CompanyController::class, 'index']);
    Route::post('companies', [CompanyController::class, 'store']);
    Route::post('companies/detect', [CompanyController::class, 'detect'])->middleware('throttle:expensive');
    Route::patch('companies/{company}', [CompanyController::class, 'update']);
    Route::delete('companies/{company}', [CompanyController::class, 'destroy']);
    Route::post('companies/{company}/sources', [CompanyController::class, 'addSource']);
    Route::get('sources', [SourceController::class, 'index']);
    Route::post('sources', [SourceController::class, 'store']);
    Route::patch('sources/{source}', [SourceController::class, 'update']);
    Route::delete('sources/{source}', [SourceController::class, 'destroy']);
    Route::post('sources/{source}/scan', [SourceController::class, 'scan']);
    Route::post('sources/{source}/reset', [SourceController::class, 'resetHealth']);
    Route::get('import-errors', [SourceController::class, 'errors']);
    Route::get('scans', [SourceController::class, 'scans']);
    Route::post('scans', [SourceController::class, 'startScan']);
    Route::get('scans/{scan}', [SourceController::class, 'scanDetail']);

    // Profile & resumes
    Route::get('profile', [ProfileController::class, 'show']);
    Route::put('profile', [ProfileController::class, 'update']);
    Route::post('profile/verify', [ProfileController::class, 'verify']);
    Route::get('resumes', [ProfileController::class, 'resumes']);
    Route::post('resumes', [ProfileController::class, 'upload'])->middleware('throttle:expensive');
    Route::post('resumes/{resume}/parse', [ProfileController::class, 'parse'])->middleware('throttle:expensive');
    Route::post('resumes/{resume}/master', [ProfileController::class, 'makeMaster']);
    Route::get('resumes/{resume}/download', [ProfileController::class, 'download']);
    Route::delete('resumes/{resume}', [ProfileController::class, 'destroy']);

    // Settings & system
    Route::get('settings', [SettingsController::class, 'show']);
    Route::put('settings', [SettingsController::class, 'update']);
    Route::put('settings/secrets', [SettingsController::class, 'secrets']);
    Route::post('settings/test-email', [SettingsController::class, 'testEmail'])->middleware('throttle:expensive');
    Route::post('settings/test-claude', [SettingsController::class, 'testClaude'])->middleware('throttle:expensive');
    Route::get('system', SystemController::class);
    Route::post('system/failed-jobs/{uuid}/retry', [SystemController::class, 'retryFailed']);
    Route::post('system/failed-jobs/flush', [SystemController::class, 'flushFailed']);
});
