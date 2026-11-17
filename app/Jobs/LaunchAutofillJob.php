<?php

namespace App\Jobs;

use App\Models\AutofillSession;
use App\Services\Resume\ResumeStorage;
use App\Services\SettingsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Launches automation/autofill.mjs as a detached Node process so the queue worker is not blocked
 * while the browser waits for the user's approval.
 */
class LaunchAutofillJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $sessionId, public string $token) {}

    public function handle(ResumeStorage $resumes, SettingsService $settings): void
    {
        $session = AutofillSession::with('application.user.profile', 'application.resume', 'application.user.resumes')->find($this->sessionId);
        if (! $session || $session->status !== 'queued') {
            return;
        }
        $app = $session->application;
        $profile = $app->user->profile;
        $resume = $app->resume ?? $app->user->resumes()->where('is_master', true)->first();

        $nameParts = preg_split('/\s+/', trim((string) $profile->full_name), 2);
        $payload = [
            'session_id' => $session->id,
            'api_base' => rtrim(config('app.url'), '/').'/api/automation/sessions/'.$session->id,
            'token' => $this->token,
            'url' => $session->target_url,
            'adapter' => $session->adapter,
            'headless' => (bool) config('jobhunter.automation.headless'),
            'executable_path' => config('jobhunter.automation.chromium_executable') ?: null,
            'fields' => [
                'first_name' => $nameParts[0] ?? '',
                'last_name' => $nameParts[1] ?? '',
                'full_name' => $profile->full_name,
                'email' => $profile->email,
                'phone' => $profile->phone,
                'location' => $profile->location,
                'linkedin' => $profile->linkedin_url,
                'website' => $profile->website_url,
            ],
            'resume_path' => ($resume && $settings->get('automation.allow_resume_upload', true)) ? $resumes->temporaryCopy($resume) : null,
            'approval_timeout_minutes' => 45,
        ];

        $dir = storage_path('app/private/tmp');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $payloadPath = $dir.'/autofill-'.$session->id.'.json';
        file_put_contents($payloadPath, json_encode($payload));
        chmod($payloadPath, 0600);

        $logPath = storage_path('logs/autofill-'.$session->id.'.log');
        $cmd = sprintf(
            'nohup %s %s %s > %s 2>&1 &',
            escapeshellcmd(config('jobhunter.automation.node_binary')),
            escapeshellarg(config('jobhunter.automation.script')),
            escapeshellarg($payloadPath),
            escapeshellarg($logPath),
        );
        exec($cmd, $out, $code);

        $session->update([
            'status' => $code === 0 ? 'running' : 'failed',
            'message' => $code === 0 ? 'Browser launching…' : 'Could not start Node.js (exit '.$code.'). Check JOBHUNTER_NODE_BINARY.',
        ]);
    }
}
