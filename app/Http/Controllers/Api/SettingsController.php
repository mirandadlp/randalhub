<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\JobSource;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiException;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function show(SettingsService $settings): JsonResponse
    {
        return response()->json([
            'settings' => $settings->all() + [
                'anthropic_model' => $settings->get('anthropic_model') ?: config('jobhunter.anthropic.model'),
                'anthropic_effort' => $settings->get('anthropic_effort') ?: config('jobhunter.anthropic.effort'),
            ],
            'secrets' => [
                'anthropic_api_key' => $settings->hasSecret('anthropic_api_key') ? 'settings' : (config('jobhunter.anthropic.api_key') ? 'env' : null),
                'adzuna' => $settings->hasSecret('adzuna_app_key') ? 'settings' : (config('jobhunter.adzuna.app_key') ? 'env' : null),
            ],
            'mail' => [
                'mailer' => config('mail.default'),
                'host' => config('mail.mailers.smtp.host'),
                'from' => config('mail.from.address'),
                'to' => request()->user()->notificationEmail(),
                'is_log_driver' => config('mail.default') === 'log',
            ],
            'defaults' => config('jobhunter.defaults'),
        ]);
    }

    public function update(Request $request, SettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'preferred_titles' => ['sometimes', 'array', 'max:100'], 'preferred_titles.*' => ['string', 'max:120'],
            'title_keywords' => ['sometimes', 'array', 'max:100'], 'title_keywords.*' => ['string', 'max:60'],
            'excluded_title_keywords' => ['sometimes', 'array', 'max:100'], 'excluded_title_keywords.*' => ['string', 'max:60'],
            'preferred_industries' => ['sometimes', 'array', 'max:50'], 'preferred_industries.*' => ['string', 'max:80'],
            'excluded_companies' => ['sometimes', 'array', 'max:200'], 'excluded_companies.*' => ['string', 'max:120'],
            'minimum_salary' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2000000'],
            'location_preferences' => ['sometimes', 'array'],
            'location_preferences.remote_us' => ['boolean'],
            'location_preferences.preferred_locations' => ['array'], 'location_preferences.preferred_locations.*' => ['string', 'max:120'],
            'location_preferences.other_us_if_remote' => ['boolean'],
            'location_preferences.allow_onsite_outside_preferred' => ['boolean'],
            'remote_preference' => ['sometimes', Rule::in(['remote_only', 'remote_or_local', 'any'])],
            'match_alert_threshold' => ['sometimes', 'integer', 'between:50,100'],
            'high_match_threshold' => ['sometimes', 'integer', 'between:40,100'],
            'priority_weights' => ['sometimes', 'array'],
            'priority_weights.*' => ['numeric', 'between:0,100'],
            'scan_interval_hours' => ['sometimes', 'integer', 'between:1,72'],
            'analysis_batch_limit' => ['sometimes', 'integer', 'between:0,500'],
            'prescreen_minimum' => ['sometimes', 'integer', 'between:0,100'],
            'monthly_ai_budget_usd' => ['sometimes', 'numeric', 'between:0,10000'],
            'anthropic_model' => ['sometimes', 'string', 'max:60', 'regex:/^claude-[a-z0-9\-]+$/'],
            'anthropic_effort' => ['sometimes', Rule::in(['low', 'medium', 'high', 'xhigh', 'max'])],
            'notifications' => ['sometimes', 'array'],
            'notifications.email_enabled' => ['boolean'],
            'notifications.priority_alerts_enabled' => ['boolean'],
            'notifications.digest_enabled' => ['boolean'],
            'notifications.digest_time' => ['string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'notifications.digest_size' => ['integer', 'between:1,50'],
            'notifications.digest_frequency' => [Rule::in(['daily', 'weekdays', 'off'])],
            'notifications.timezone' => ['timezone:all'],
            'automation' => ['sometimes', 'array'],
            'automation.autofill_enabled' => ['boolean'],
            'automation.allow_resume_upload' => ['boolean'],
            'ai' => ['sometimes', 'array'],
            'ai.resume_parsing_enabled' => ['boolean'],
            'ai.job_matching_enabled' => ['boolean'],
            'ai.application_assistant_enabled' => ['boolean'],
            'retention' => ['sometimes', 'array'],
            'retention.*' => ['integer', 'between:1,3650'],
            'source_defaults' => ['sometimes', 'array'],
            'source_defaults.max_jobs_per_source' => ['integer', 'between:1,10000'],
        ]);

        // Merge nested groups so partial updates keep other keys.
        foreach (['location_preferences', 'notifications', 'automation', 'ai', 'retention', 'source_defaults', 'priority_weights'] as $group) {
            if (isset($data[$group])) {
                $data[$group] = array_replace((array) $settings->get($group, []), $data[$group]);
            }
        }
        if (isset($data['automation'])) {
            $data['automation']['require_approval_before_submit'] = true; // cannot be disabled
        }
        if (isset($data['excluded_companies'])) {
            Company::query()->update(['is_excluded' => false]);
            Company::whereIn('name', $data['excluded_companies'])->update(['is_excluded' => true]);
        }
        $settings->setMany($data);

        return $this->show($settings);
    }

    public function secrets(Request $request, SettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'anthropic_api_key' => ['sometimes', 'nullable', 'string', 'max:300'],
            'adzuna_app_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'adzuna_app_key' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        foreach ($data as $key => $value) {
            $settings->setSecret($key, $value);
        }
        if (array_key_exists('adzuna_app_key', $data)) {
            // Seeded Adzuna keyword searches switch on/off with the credentials.
            $configured = (bool) $settings->adzunaCredentials();
            JobSource::where('type', 'adzuna')->update([
                'enabled' => $configured,
                'health' => 'unknown',
                'limitation_note' => $configured ? null : 'Requires Adzuna API credentials (ADZUNA_APP_ID / ADZUNA_APP_KEY).',
            ]);
        }

        return $this->show($settings);
    }

    public function testEmail(Request $request): JsonResponse
    {
        try {
            Mail::raw('This is a test message from AI Job Hunter. Email delivery is working.', function ($m) use ($request) {
                $m->to($request->user()->notificationEmail())->subject('AI Job Hunter test email');
            });
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'mailer' => config('mail.default')]);
    }

    public function testClaude(AiClient $ai): JsonResponse
    {
        try {
            $r = $ai->structured('connection_test', [['text' => 'Reply with the requested JSON only.']], 'Return {"ok": true}.', [
                'type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']], 'required' => ['ok'], 'additionalProperties' => false,
            ], null, 1000);
        } catch (AiException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => (bool) ($r->data['ok'] ?? false), 'model' => $r->model, 'cost_usd' => $r->costUsd]);
    }
}
