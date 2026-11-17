<?php

namespace App\Services\Automation;

use App\Jobs\LaunchAutofillJob;
use App\Models\Application;
use App\Models\AutofillSession;
use App\Services\Applications\ApplicationTracker;
use App\Services\SettingsService;
use App\Services\Support\UrlCanonicalizer;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Assisted application. Opens the employer's form in a real browser (Playwright), fills only
 * verified contact fields and the selected resume, then pauses. Submission happens only after the
 * user clicks "Approve & submit" in the app (or submits in the browser themselves).
 */
class AutofillService
{
    public const ADAPTERS = [
        'greenhouse' => ['job-boards.greenhouse.io', 'boards.greenhouse.io'],
        'lever' => ['jobs.lever.co'],
        'ashby' => ['jobs.ashbyhq.com'],
        'workable' => ['apply.workable.com'],
    ];

    public function __construct(private SettingsService $settings, private ApplicationTracker $tracker) {}

    public static function adapterFor(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        foreach (self::ADAPTERS as $adapter => $hosts) {
            if (in_array($host, $hosts, true)) {
                return $adapter;
            }
        }

        return 'generic';
    }

    /** @return array{session: AutofillSession, token: ?string} token is only returned for a newly created session */
    public function start(Application $application): array
    {
        if (! $this->settings->get('automation.autofill_enabled', false)) {
            throw ValidationException::withMessages(['automation' => 'Assisted application is turned off. Enable it in Settings → Automation.']);
        }
        if (in_array($application->status, ['applied', 'interviewing', 'offer'], true)) {
            throw ValidationException::withMessages(['automation' => 'This application is already marked as submitted.']);
        }
        $url = $application->listing->application_url ?: $application->listing->source_url;
        if (! UrlCanonicalizer::isSafePublicUrl($url)) {
            throw ValidationException::withMessages(['automation' => 'This job has no valid application URL.']);
        }
        $profile = $application->user->profile;
        if (! $profile || blank($profile->email) || blank($profile->full_name)) {
            throw ValidationException::withMessages(['automation' => 'Add your name and email to your profile first.']);
        }

        $active = $application->autofillSessions()->whereIn('status', AutofillSession::ACTIVE_STATUSES)
            ->where('updated_at', '>', now()->subHour())->first();
        if ($active) {
            return ['session' => $active, 'token' => null];
        }

        $token = Str::random(48);
        $session = AutofillSession::create([
            'application_id' => $application->id,
            'status' => 'queued',
            'adapter' => self::adapterFor($url),
            'token_hash' => hash('sha256', $token),
            'target_url' => $url,
        ]);

        $this->tracker->advanceTo($application, 'ready', 'Assisted application started');
        LaunchAutofillJob::dispatch($session->id, $token);

        return ['session' => $session, 'token' => $token];
    }

    public function verifyToken(AutofillSession $session, ?string $token): bool
    {
        return $token !== null && hash_equals($session->token_hash, hash('sha256', $token));
    }

    /** Called by the browser script. */
    public function report(AutofillSession $session, array $data): AutofillSession
    {
        $allowed = ['running', 'awaiting_approval', 'submitting', 'submitted', 'manual_required', 'failed', 'cancelled'];
        $status = in_array($data['status'] ?? null, $allowed, true) ? $data['status'] : $session->status;

        // A session that was not approved can only reach "submitted" if the user submitted in the browser.
        $session->fill([
            'status' => $status,
            'fields_filled' => array_slice((array) ($data['fields_filled'] ?? $session->fields_filled ?? []), 0, 50),
            'fields_skipped' => array_slice((array) ($data['fields_skipped'] ?? $session->fields_skipped ?? []), 0, 50),
            'message' => isset($data['message']) ? mb_substr((string) $data['message'], 0, 2000) : $session->message,
        ]);

        if ($status === 'submitted' && ! $session->submitted_at) {
            $session->submitted_at = now();
            $this->tracker->transition(
                $session->application,
                'applied',
                ($session->approved_at ? 'Submitted after your approval' : 'Submitted by you in the assisted browser')
                    .' — confirmation page detected',
                'automation',
            );
        }
        $session->save();

        return $session;
    }

    public function approve(AutofillSession $session): AutofillSession
    {
        if ($session->status !== 'awaiting_approval') {
            throw ValidationException::withMessages(['automation' => 'The form is not ready for submission yet.']);
        }
        $session->update(['status' => 'approved', 'approved_at' => now()]);

        return $session;
    }

    public function cancel(AutofillSession $session): AutofillSession
    {
        if (in_array($session->status, AutofillSession::ACTIVE_STATUSES, true)) {
            $session->update(['status' => 'cancelled', 'message' => 'Cancelled by you.']);
        }

        return $session;
    }
}
