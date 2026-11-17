<?php

namespace App\Services\Applications;

use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\JobListing;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplicationTracker
{
    /** Ordered pipeline; used to avoid moving an application "backwards" implicitly. */
    public const ORDER = ['discovered', 'saved', 'preparing', 'ready', 'applied', 'interviewing', 'offer', 'rejected', 'archived'];

    /** Returns the user's single application record for a job (creating it if needed). */
    public function ensure(User $user, JobListing $job, string $status = 'discovered'): Application
    {
        return DB::transaction(function () use ($user, $job, $status) {
            $app = Application::where('user_id', $user->id)->where('job_listing_id', $job->id)->lockForUpdate()->first();
            if ($app) {
                return $app;
            }
            $app = Application::create(['user_id' => $user->id, 'job_listing_id' => $job->id, 'status' => $status]);
            ApplicationEvent::create(['application_id' => $app->id, 'from_status' => null, 'to_status' => $status, 'note' => 'Tracking started']);

            return $app;
        });
    }

    /** Move forward only (used by automatic actions such as "Save" or "Prepare"). */
    public function advanceTo(Application $app, string $status, ?string $note = null): Application
    {
        if (array_search($status, self::ORDER, true) > array_search($app->status, self::ORDER, true)
            && ! in_array($app->status, ['applied', 'interviewing', 'offer', 'rejected', 'archived'], true)) {
            return $this->transition($app, $status, $note);
        }

        return $app;
    }

    /**
     * Explicit status change. "applied" requires either a manual confirmation by the user
     * or a confirmed submission from the assisted-application flow.
     *
     * @param  string|null  $appliedConfirmation  manual | automation
     */
    public function transition(Application $app, string $status, ?string $note = null, ?string $appliedConfirmation = null, ?\DateTimeInterface $appliedAt = null): Application
    {
        if (! array_key_exists($status, Application::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'Unknown status.']);
        }
        if ($status === 'applied' && $app->status !== 'applied' && ! in_array($appliedConfirmation, ['manual', 'automation'], true)) {
            throw ValidationException::withMessages([
                'status' => 'An application can only be marked Applied after you confirm you submitted it.',
            ]);
        }
        if ($status === $app->status) {
            return $app;
        }

        $from = $app->status;
        $app->status = $status;
        if ($status === 'applied' && ! $app->applied_at) {
            $app->applied_at = $appliedAt ?? now();
            $app->applied_confirmation = $appliedConfirmation;
            $app->next_follow_up_at ??= now()->addDays(7);
        }
        $app->save();

        ApplicationEvent::create(['application_id' => $app->id, 'from_status' => $from, 'to_status' => $status, 'note' => $note]);

        return $app;
    }
}
