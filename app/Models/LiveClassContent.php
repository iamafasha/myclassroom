<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Support\Ics;
use App\Traits\IsContent;

class LiveClassContent extends Model
{
    use IsContent;

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'is_join_enabled' => 'boolean',
    ];

    /** The join button only shows when the owner has switched joining on and given a link. */
    public function canJoin(): bool
    {
        return $this->is_join_enabled && filled($this->join_link);
    }

    public function endsAt()
    {
        return $this->starts_at?->copy()->addMinutes($this->duration_minutes ?: 60);
    }

    /** upcoming | live | ended */
    public function status(): string
    {
        if (!$this->starts_at) {
            return 'upcoming';
        }

        return match (true) {
            now()->lt($this->starts_at) => 'upcoming',
            now()->lte($this->endsAt()) => 'live',
            default => 'ended',
        };
    }

    /** Where this live class sits in a course. A content may be placed in several modules; the first one wins. */
    public function moduleContent(): ?ModuleContent
    {
        return $this->content?->moduleContents->first();
    }

    public function course(): ?Course
    {
        return $this->moduleContent()?->module?->course;
    }

    /** Anyone who can see the course the class belongs to may add it to their calendar. */
    public function isVisibleTo($user): bool
    {
        $course = $this->course();

        return $course !== null && Course::visibleTo($user)->whereKey($course->id)->exists();
    }

    public function calendarTitle(): string
    {
        return $this->title ?: 'Live Class';
    }

    /** A .ics event body, importable by Google Calendar, Apple Calendar and Outlook. */
    public function toIcs(): string
    {
        $description = collect([
            $this->description,
            $this->canJoin() ? 'Join: ' . $this->join_link : null,
        ])->filter()->implode("\n\n");

        return Ics::calendar([
            Ics::event(
                uid: Ics::uid('live-class', $this->id),
                start: $this->starts_at,
                end: $this->endsAt(),
                summary: $this->calendarTitle(),
                description: $description,
                location: $this->canJoin() ? $this->join_link : null,
                url: $this->canJoin() ? $this->join_link : null,
                // A 15 minute heads-up, the same nudge the dashboard badge gives.
                alarmMinutes: 15,
                alarmText: $this->calendarTitle() . ' starts in 15 minutes',
            ),
        ], prodId: '-//Classroom//Live Class//EN');
    }

    /** "Add to Google Calendar" prefilled event link. */
    public function googleCalendarUrl(): string
    {
        $stamp = fn ($date) => $date->copy()->utc()->format('Ymd\THis\Z');

        return 'https://calendar.google.com/calendar/render?' . http_build_query([
            'action' => 'TEMPLATE',
            'text' => $this->calendarTitle(),
            'dates' => $stamp($this->starts_at) . '/' . $stamp($this->endsAt()),
            'details' => collect([
                $this->description,
                $this->canJoin() ? 'Join: ' . $this->join_link : null,
            ])->filter()->implode("\n\n"),
            'location' => $this->canJoin() ? $this->join_link : '',
        ]);
    }

}
