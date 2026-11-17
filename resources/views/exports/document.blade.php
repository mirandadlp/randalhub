<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 10.5pt; color: #1f2328; line-height: 1.4; }
    h1 { font-size: 18pt; margin: 0 0 2pt 0; }
    h2 { font-size: 11pt; text-transform: uppercase; letter-spacing: 1px; border-bottom: 1px solid #999; margin: 14pt 0 6pt; padding-bottom: 2pt; }
    h3 { font-size: 10.5pt; margin: 8pt 0 2pt; }
    .muted { color: #555; }
    ul { margin: 2pt 0 6pt 14pt; padding: 0; }
    li { margin-bottom: 2pt; }
    p { margin: 0 0 8pt; }
</style>
</head>
<body>
@if($resume !== null)
    <h1>{{ $profile?->full_name }}</h1>
    <p class="muted">{{ collect([$profile?->location, $profile?->email, $profile?->phone, $profile?->linkedin_url])->filter()->implode(' · ') }}</p>
    @if(!empty($resume['headline']))<p><strong>{{ $resume['headline'] }}</strong></p>@endif
    @if(!empty($resume['summary']))<h2>Summary</h2><p>{{ $resume['summary'] }}</p>@endif
    @if(!empty($resume['core_skills']))<h2>Core Skills</h2><p>{{ implode(' · ', $resume['core_skills']) }}</p>@endif
    @if(!empty($resume['experience']))
        <h2>Experience</h2>
        @foreach($resume['experience'] as $exp)
            <h3>{{ $exp['title'] ?? '' }} — {{ $exp['company'] ?? '' }}</h3>
            <p class="muted">{{ trim(($exp['start'] ?? '').' – '.($exp['end'] ?? ''), ' –') }}</p>
            <ul>@foreach($exp['bullets'] ?? [] as $b)<li>{{ $b }}</li>@endforeach</ul>
        @endforeach
    @endif
    @if(!empty($resume['education']))<h2>Education</h2><ul>@foreach($resume['education'] as $e)<li>{{ $e }}</li>@endforeach</ul>@endif
    @if(!empty($resume['certifications']))<h2>Certifications</h2><ul>@foreach($resume['certifications'] as $c)<li>{{ $c }}</li>@endforeach</ul>@endif
@else
    @if($doc->type === 'cover_letter')
        <p>{{ $profile?->full_name }}<br>{{ collect([$profile?->email, $profile?->phone])->filter()->implode(' · ') }}</p>
        <p>{{ now()->format('F j, Y') }}</p>
    @else
        <h1>{{ $doc->title }}</h1>
    @endif
    @foreach(preg_split("/\n\s*\n/", (string) $body) as $para)
        <p>{!! nl2br(e($para)) !!}</p>
    @endforeach
@endif
</body>
</html>
