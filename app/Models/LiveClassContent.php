<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        $stamp = fn ($date) => $date->copy()->utc()->format('Ymd\THis\Z');

        $description = collect([
            $this->description,
            $this->canJoin() ? 'Join: ' . $this->join_link : null,
        ])->filter()->implode("\n\n");

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Classroom//Live Class//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:live-class-' . $this->id . '@' . parse_url(config('app.url'), PHP_URL_HOST),
            'DTSTAMP:' . $stamp(now()),
            'DTSTART:' . $stamp($this->starts_at),
            'DTEND:' . $stamp($this->endsAt()),
            'SUMMARY:' . $this->escapeIcsText($this->calendarTitle()),
            'DESCRIPTION:' . $this->escapeIcsText($description),
            'SEQUENCE:0',
            'STATUS:CONFIRMED',
        ];

        if ($this->canJoin()) {
            $lines[] = 'LOCATION:' . $this->escapeIcsText($this->join_link);
            $lines[] = 'URL:' . $this->escapeIcsText($this->join_link);
        }

        // A 15 minute heads-up, the same nudge the dashboard badge gives.
        $lines = array_merge($lines, [
            'BEGIN:VALARM',
            'TRIGGER:-PT15M',
            'ACTION:DISPLAY',
            'DESCRIPTION:' . $this->escapeIcsText($this->calendarTitle() . ' starts in 15 minutes'),
            'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        return implode("\r\n", $lines) . "\r\n";
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

    /** RFC 5545 escaping: backslashes, semicolons, commas and newlines. */
    private function escapeIcsText(?string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n"],
            ['\\\\', '\;', '\,', '\n', '\n'],
            (string) $value
        );
    }
}
