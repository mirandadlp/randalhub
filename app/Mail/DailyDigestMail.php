<?php

namespace App\Mail;

use App\Models\JobListing;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

class DailyDigestMail extends Mailable
{
    use Queueable;

    /** @param Collection<int, JobListing> $jobs */
    public function __construct(public Collection $jobs, public string $date) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'AI Job Hunter digest — '.$this->jobs->count().' new opportunities ('.$this->date.')');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.daily-digest');
    }
}
