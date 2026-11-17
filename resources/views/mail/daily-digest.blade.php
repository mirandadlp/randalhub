<x-mail::message>
# Your top new opportunities — {{ $date }}

@if($jobs->isEmpty())
No new jobs were discovered in the last 24 hours.
@endif

@foreach($jobs as $i => $job)
@php($m = $job->match)
### {{ $i + 1 }}. {{ $job->title }} — {{ $job->company_name }}
**Match:** {{ $m?->match_score !== null ? $m->match_score.'%' : 'Not analyzed yet' }} · {{ ucfirst($job->remote_type) }} · {{ $job->location ?? 'Location not stated' }}
· **Salary:** {{ $job->salary_max ? '$'.number_format($job->salary_min).'–$'.number_format($job->salary_max).' / '.$job->salary_interval : 'Not published' }}
· **Posted:** {{ $job->posted_at ? $job->posted_at->diffForHumans() : 'Not published (first seen '.$job->first_seen_at->diffForHumans().')' }}

@if($m?->qualification_summary)
{{ \Illuminate\Support\Str::limit($m->qualification_summary, 280) }}
@endif

[Apply on employer site]({{ $job->application_url ?? $job->source_url }}) · [View in AI Job Hunter]({{ url('/jobs/'.$job->id) }})

@endforeach
<small>Manage thresholds and delivery time in Settings → Notifications.</small>
</x-mail::message>
