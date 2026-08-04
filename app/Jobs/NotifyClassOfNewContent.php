<?php

namespace App\Jobs;

use App\Mail\CourseContentPublished;
use App\Models\Content;
use App\Models\ModuleContent;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Emails everyone taking a course when new content lands in it.
 *
 * Runs off the request so that saving content never waits on a mail server, and
 * mails people one at a time so nobody sees anyone else's address.
 */
class NotifyClassOfNewContent implements ShouldQueue
{
    use Queueable;

    /** A missed announcement is better than a duplicated one. */
    public int $tries = 1;

    public function __construct(
        public int $moduleContentId,
        public int $contentId,
        /** Whoever added it — they know already. */
        public ?int $actorId = null,
    ) {
    }

    public function handle(): void
    {
        $moduleContent = ModuleContent::with('module.course')->find($this->moduleContentId);
        $contentable = Content::find($this->contentId)?->contentable;
        $course = $moduleContent?->module?->course;

        if (! $moduleContent || ! $contentable || ! $course) {
            return;
        }

        foreach ($this->recipients($course) as $recipient) {
            Mail::to($recipient)->send(new CourseContentPublished($moduleContent, $contentable, $recipient));
        }
    }

    /** Members and admins of every class the course is taught in, the author aside. */
    private function recipients($course)
    {
        $classroomIds = $course->classrooms()->pluck('classrooms.id');

        if ($classroomIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->where(function ($query) use ($classroomIds) {
                $query->whereHas('classrooms', fn ($c) => $c->whereIn('classrooms.id', $classroomIds))
                    ->orWhereIn('id', \App\Models\Classroom::whereIn('id', $classroomIds)->pluck('admin_id')->filter());
            })
            ->when($this->actorId, fn ($query) => $query->whereKeyNot($this->actorId))
            ->whereNotNull('email')
            ->get();
    }
}
