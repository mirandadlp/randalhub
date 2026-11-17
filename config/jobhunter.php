<?php

/*
|--------------------------------------------------------------------------
| AI Job Hunter configuration
|--------------------------------------------------------------------------
| Values under "defaults" seed the Settings page. Everything a user can edit
| lives in the system_settings table (see App\Services\SettingsService) and
| overrides these defaults at runtime.
*/

return [

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
        'effort' => env('ANTHROPIC_EFFORT', 'medium'),
        'input_price_per_mtok' => (float) env('ANTHROPIC_INPUT_PRICE_PER_MTOK', 4.00),
        'output_price_per_mtok' => (float) env('ANTHROPIC_OUTPUT_PRICE_PER_MTOK', 20.00),
        'max_tokens' => 16000,
    ],

    'adzuna' => [
        'app_id' => env('ADZUNA_APP_ID'),
        'app_key' => env('ADZUNA_APP_KEY'),
    ],

    'notify_email' => env('JOBHUNTER_NOTIFY_EMAIL'),

    'http' => [
        'user_agent' => env('JOBHUNTER_HTTP_USER_AGENT', 'AIJobHunter/1.0 (personal job search; respects robots.txt)'),
        'timeout' => (int) env('JOBHUNTER_HTTP_TIMEOUT', 30),
        'request_delay_ms' => (int) env('JOBHUNTER_REQUEST_DELAY_MS', 500),
        'retries' => 2,
    ],

    'automation' => [
        'node_binary' => env('JOBHUNTER_NODE_BINARY', 'node'),
        'chromium_executable' => env('PLAYWRIGHT_CHROMIUM_EXECUTABLE'),
        'headless' => (bool) env('JOBHUNTER_AUTOFILL_HEADLESS', false),
        'script' => base_path('automation/autofill.mjs'),
    ],

    // A source is marked closed after this many consecutive successful scans that no longer list it.
    'close_after_missed_scans' => 2,

    'defaults' => [
        'preferred_titles' => [
            'AI Consultant', 'AI Transformation Manager', 'AI Adoption Lead', 'AI Enablement Manager',
            'AI Solutions Consultant', 'Enterprise Solutions Consultant', 'Implementation Consultant',
            'Implementation Manager', 'SaaS Onboarding Manager', 'Customer Onboarding Lead',
            'Customer Success Manager', 'AI Customer Success Manager', 'Professional Services Consultant',
            'Digital Transformation Manager', 'Technology Strategy Consultant', 'Business Systems Consultant',
            'AI Program Manager', 'AI Operations Manager', 'Automation Consultant', 'Technical Account Manager',
            'Partner Enablement Manager', 'Solutions Engineer', 'Forward Deployed Consultant',
            'Hospitality Technology Consultant',
        ],
        // Title keywords used by the rule-based pre-screen (before any AI cost is spent).
        'title_keywords' => [
            'consultant', 'consulting', 'transformation', 'adoption', 'enablement', 'implementation',
            'onboarding', 'customer success', 'professional services', 'solutions', 'strategy',
            'business systems', 'program manager', 'operations', 'automation', 'account manager',
            'partner', 'forward deployed', 'deployment', 'hospitality', 'advisor', 'change management',
            'engagement manager', 'customer experience', 'training', 'education', 'success',
        ],
        'excluded_title_keywords' => [
            'intern', 'internship', 'phd', 'staff software engineer', 'senior software engineer',
            'software engineer', 'research scientist', 'research engineer', 'machine learning engineer',
            'data scientist', 'firmware', 'hardware', 'mechanical', 'electrical engineer', 'accountant',
            'counsel', 'attorney', 'paralegal', 'nurse', 'physician', 'warehouse', 'driver',
        ],
        'preferred_industries' => [
            'Artificial Intelligence', 'Enterprise Software', 'SaaS', 'Big Tech', 'Fintech',
            'Mortgage & Lending', 'Banking & Financial Services', 'Insurance', 'Healthcare Technology',
            'Hospitality Technology', 'Real Estate Technology', 'Consulting', 'Startups',
            'Business Automation', 'Cloud Computing', 'Digital Transformation',
        ],
        'excluded_companies' => [],
        'minimum_salary' => null,
        'location_preferences' => [
            'remote_us' => true,              // U.S. remote roles
            'preferred_locations' => ['Las Vegas, NV', 'Nevada', 'Henderson, NV'],
            'other_us_if_remote' => true,     // other U.S. locations when remote/hybrid-remote is possible
            'allow_onsite_outside_preferred' => false,
        ],
        'remote_preference' => 'remote_or_local', // remote_only | remote_or_local | any
        'match_alert_threshold' => 90,
        'high_match_threshold' => 80,
        'priority_weights' => [
            'qualification' => 40,
            'freshness' => 25,
            'location' => 15,
            'compensation' => 10,
            'competition' => 10,
        ],
        'scan_interval_hours' => 6,
        'analysis_batch_limit' => 40,          // max AI analyses per scan (cost guardrail)
        'prescreen_minimum' => 35,             // jobs below this pre-screen score are not sent to AI
        'monthly_ai_budget_usd' => 25,
        'notifications' => [
            'email_enabled' => true,
            'priority_alerts_enabled' => true,
            'digest_enabled' => true,
            'digest_time' => '07:30',
            'digest_size' => 10,
            'digest_frequency' => 'daily', // daily | weekdays | off
            'timezone' => 'America/Los_Angeles',
        ],
        'automation' => [
            'autofill_enabled' => false,     // must be explicitly turned on
            'allow_resume_upload' => true,
            'require_approval_before_submit' => true, // cannot be disabled
        ],
        'ai' => [
            'resume_parsing_enabled' => true,
            'job_matching_enabled' => true,
            'application_assistant_enabled' => true,
        ],
        'retention' => [
            'archive_closed_after_days' => 30,
            'archive_unseen_after_days' => 60,
            'prune_observation_payloads_after_days' => 90,
            'prune_logs_after_days' => 180,
        ],
        'source_defaults' => [
            'max_jobs_per_source' => 2000,
        ],
    ],
];
