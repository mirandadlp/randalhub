<x-mail::message>
# New high-match opportunities

These newly discovered jobs scored at or above your **{{ $threshold }}%** qualification threshold.

@foreach($matches as $m)
@php($job = $m->listing)
## {{ $job->title }} — {{ $job->company_name }}
**Match:** {{ $m->match_score }}% · **Priority:** {{ $m->priority_score !== null ? round($m->priority_score) : '—' }} · {{ ucfirst($job->remote_type) }} · {{ $job->location ?? 'Location not stated' }}
@if($job->salary_max)
**Salary:** ${{ number_format($job->salary_min) }}–${{ number_format($job->salary_max) }} / {{ $job->salary_interval }}
@endif
**Posted:** {{ $job->posted_at ? $job->posted_at->diffForHumans() : 'Not published (first seen '.$job->first_seen_at->diffForHumans().')' }}

{{ $m->qualification_summary }}

<x-mail::button :url="$job->application_url ?? $job->source_url">Open application page</x-mail::button>
<x-mail::button :url="url('/jobs/'.$job->id)" color="success">View analysis</x-mail::button>

@endforeach
<small>Scores are AI-generated from your verified profile and may be imperfect. Applicant counts are shown only when a source publishes them.</small>
</x-mail::message>
