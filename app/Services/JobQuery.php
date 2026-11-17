<?php

namespace App\Services;

use App\Models\Application;
use App\Models\JobListing;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Builds the filtered/sorted job listing query used by the jobs page. */
class JobQuery
{
    public function build(User $user, array $f): Builder
    {
        $q = JobListing::query()
            ->canonical()
            ->with([
                'match' => fn ($m) => $m->where('user_id', $user->id),
                'primarySource:id,type',
            ]);

        // Saved / dismissed flags.
        $q->withExists(['savedBy as saved_exists' => fn ($s) => $s->where('user_id', $user->id)])
            ->withExists(['dismissedBy as dismissed_exists' => fn ($s) => $s->where('user_id', $user->id)])
            ->with(['application' => fn ($a) => $a->where('user_id', $user->id)]);

        $status = $f['listing_status'] ?? 'open';
        if ($status !== 'all') {
            $q->where('job_listings.status', $status);
        }

        if (empty($f['include_dismissed'])) {
            $q->whereNotIn('job_listings.id', $user->dismissedJobs()->select('job_listing_id'));
        }
        if (! empty($f['saved'])) {
            $q->whereIn('job_listings.id', $user->savedJobs()->select('job_listing_id'));
        }
        if (! empty($f['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $f['q']).'%';
            $q->where(fn ($w) => $w->where('job_listings.title', 'like', $term)->orWhere('job_listings.company_name', 'like', $term));
        }
        if (! empty($f['title'])) {
            $q->where('job_listings.title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $f['title']).'%');
        }
        if (! empty($f['industry'])) {
            $q->whereIn('job_listings.industry', (array) $f['industry']);
        }
        if (! empty($f['company_id'])) {
            $q->whereIn('job_listings.company_id', (array) $f['company_id']);
        }
        if (! empty($f['remote'])) {
            $q->whereIn('job_listings.remote_type', (array) $f['remote']);
        }
        if (! empty($f['location'])) {
            match ($f['location']) {
                'us' => $q->where('job_listings.is_us', true),
                'nevada' => $q->where('job_listings.is_nevada', true),
                'us_remote' => $q->where('job_listings.remote_type', 'remote')->where(fn ($w) => $w->where('is_us', true)->orWhereNull('is_us')),
                'preferred' => $q->where(fn ($w) => $w->where('job_listings.is_nevada', true)
                    ->orWhere(fn ($r) => $r->where('job_listings.remote_type', 'remote')->where(fn ($u) => $u->where('is_us', true)->orWhereNull('is_us')))),
                default => null,
            };
        }
        if (! empty($f['min_salary'])) {
            // Compare annualized maximum salary; listings without salary are excluded by this filter.
            $q->whereRaw("(CASE job_listings.salary_interval WHEN 'hour' THEN job_listings.salary_max * 2080 WHEN 'month' THEN job_listings.salary_max * 12 ELSE job_listings.salary_max END) >= ?", [(int) $f['min_salary']]);
        }
        if (! empty($f['posted_within'])) {
            $since = now()->subDays((int) $f['posted_within']);
            $q->where(fn ($w) => $w->where('job_listings.posted_at', '>=', $since)
                ->orWhere(fn ($n) => $n->whereNull('job_listings.posted_at')->where('job_listings.first_seen_at', '>=', $since)));
        }
        if (! empty($f['new_today'])) {
            $q->where('job_listings.first_seen_at', '>=', now()->subDay());
        }

        $needsMatchJoin = isset($f['min_match']) && $f['min_match'] !== '' || in_array($f['sort'] ?? 'newest', ['match', 'priority'], true) || ! empty($f['analyzed']);
        if ($needsMatchJoin) {
            $q->leftJoin('job_matches as jm', fn ($j) => $j->on('jm.job_listing_id', '=', 'job_listings.id')->where('jm.user_id', '=', $user->id))
                ->select('job_listings.*');
            if (isset($f['min_match']) && $f['min_match'] !== '') {
                $q->where('jm.match_score', '>=', (int) $f['min_match']);
            }
            if (! empty($f['analyzed'])) {
                $q->where('jm.status', 'completed');
            }
        }

        if (! empty($f['application_status'])) {
            $statuses = (array) $f['application_status'];
            $q->where(function ($w) use ($statuses, $user) {
                $w->whereIn('job_listings.id', Application::where('user_id', $user->id)->whereIn('status', $statuses)->select('job_listing_id'));
                if (in_array('discovered', $statuses, true)) {
                    $w->orWhereNotIn('job_listings.id', Application::where('user_id', $user->id)->select('job_listing_id'));
                }
            });
        }

        match ($f['sort'] ?? 'newest') {
            'match' => $q->orderByRaw('jm.match_score IS NULL')->orderByDesc('jm.match_score')->orderByDesc('job_listings.prescreen_score'),
            'priority' => $q->orderByRaw('jm.priority_score IS NULL')->orderByDesc('jm.priority_score')->orderByDesc('job_listings.prescreen_score'),
            'salary' => $q->orderByRaw('job_listings.salary_max IS NULL')->orderByDesc('job_listings.salary_max'),
            'relevance' => $q->orderByDesc('job_listings.prescreen_score')->orderByDesc('job_listings.first_seen_at'),
            default => $q->orderByDesc('job_listings.first_seen_at')->orderByDesc('job_listings.id'),
        };

        return $q;
    }
}
