<?php

namespace App\Mail;

use App\Models\MentorSession;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/** Sent to both sides once a session has a time, with the invite attached. */
class MentorSessionBooked extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public MentorSession $session,
        public User $recipient,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Session booked: ' . $this->session->topic . ' — ' . $this->session->scheduled_at->format('D j M, H:i'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.session-booked',
            with: [
                'session' => $this->session,
                // The other person: who the recipient is meeting.
                'counterpart' => $this->recipient->is($this->session->student)
                    ? $this->session->mentor
                    : $this->session->student,
                'isStudent' => $this->recipient->is($this->session->student),
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->session->toIcs(), Str::slug($this->session->topic ?: 'session') . '.ics')
                ->withMime('text/calendar'),
        ];
    }
}
