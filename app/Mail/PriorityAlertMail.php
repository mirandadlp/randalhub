<?php

namespace App\Mail;

use App\Models\JobMatch;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

class PriorityAlertMail extends Mailable
{
    use Queueable;

    /** @param Collection<int, JobMatch> $matches */
    public function __construct(public Collection $matches, public int $threshold) {}

    public function envelope(): Envelope
    {
        $first = $this->matches->first();
        $subject = $this->matches->count() === 1
            ? "High match ({$first->match_score}%): {$first->listing->title} at {$first->listing->company_name}"
            : $this->matches->count()." new jobs at or above {$this->threshold}% match";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.priority-alert');
    }
}
