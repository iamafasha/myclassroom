<?php

namespace App\Mail;

use App\Models\LiveClassContent;
use App\Models\ModuleContent;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Tells everyone in the classes taking a course that something new was added to it.
 *
 * A live class carries its calendar invite along, since it happens at a fixed time.
 * A mentor session block does not: there is no time yet, so its invite is sent
 * later, when the session is actually booked.
 */
class CourseContentPublished extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public ModuleContent $moduleContent,
        public $contentable,
        public User $recipient,
    ) {
    }

    public function envelope(): Envelope
    {
        $course = $this->moduleContent->module?->course;

        return new Envelope(
            subject: 'New in ' . ($course?->title ?? 'your course') . ': ' . $this->title(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.course-content',
            with: [
                'title' => $this->title(),
                'typeLabel' => $this->typeLabel(),
                'module' => $this->moduleContent->module,
                'course' => $this->moduleContent->module?->course,
                'liveClass' => $this->liveClass(),
                'url' => route('content.show', $this->moduleContent->id),
            ],
        );
    }

    public function attachments(): array
    {
        $liveClass = $this->liveClass();

        if (! $liveClass) {
            return [];
        }

        return [
            Attachment::fromData(fn () => $liveClass->toIcs(), Str::slug($liveClass->calendarTitle()) . '.ics')
                ->withMime('text/calendar'),
        ];
    }

    private function liveClass(): ?LiveClassContent
    {
        return $this->contentable instanceof LiveClassContent ? $this->contentable : null;
    }

    private function title(): string
    {
        return $this->moduleContent->label ?: 'New content';
    }

    private function typeLabel(): string
    {
        return match (class_basename($this->contentable)) {
            'NoteContent' => 'Text note',
            'PdfNotesContent' => 'PDF document',
            'VideoContent' => 'Video',
            'LinkContent' => 'External link',
            'QuizContent' => 'Quiz',
            'ImageContent' => 'Image',
            'LiveClassContent' => 'Live class',
            'SessionContent' => 'Mentor session',
            default => 'Content',
        };
    }
}
