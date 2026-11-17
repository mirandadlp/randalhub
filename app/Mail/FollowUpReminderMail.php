<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

class FollowUpReminderMail extends Mailable
{
    use Queueable;

    public function __construct(public Collection $applications) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Follow-up reminder: '.$this->applications->count().' application(s)');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.follow-up');
    }
}
