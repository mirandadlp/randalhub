<x-mail::message>
# Time to follow up

@foreach($applications as $app)
- **{{ $app->listing->title }}** at {{ $app->listing->company_name }} — {{ \App\Models\Application::STATUSES[$app->status] }}{{ $app->recruiter_name ? ' · Recruiter: '.$app->recruiter_name : '' }} · [Open]({{ url('/applications/'.$app->id) }})
@endforeach

Update the follow-up date in the application tracker once you have reached out.
</x-mail::message>
