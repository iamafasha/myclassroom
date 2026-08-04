<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A one-to-one session a student requests with the mentor who owns a course.
 *
 * The student proposes a topic and a preferred time. The mentor either declines,
 * or accepts by offering a shortlist of times. The student then picks the slot
 * that suits them, which is what books the session.
 *
 * pending -> proposed -> scheduled -> completed
 *          \-> declined            cancelled (either side, any time it is still open)
 */
class MentorSession extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'preferred_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'proposed_slots' => 'array',
        ];
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function mentor()
    {
        return $this->belongsTo(User::class, 'mentor_id');
    }

    public function scopeForStudent($query, $user)
    {
        return $query->where('student_id', $user instanceof User ? $user->id : $user);
    }

    public function scopeForMentor($query, $user)
    {
        return $query->where('mentor_id', $user instanceof User ? $user->id : $user);
    }

    /** Either side of the session may open it; nobody else. */
    public function scopeVisibleTo($query, $user)
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where(fn ($q) => $q->where('student_id', $userId)->orWhere('mentor_id', $userId));
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeAwaitingStudent($query)
    {
        return $query->where('status', self::STATUS_PROPOSED);
    }

    /** Booked sessions that have not started yet, soonest first. */
    public function scopeUpcoming($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Mentor has offered times; the ball is in the student's court. */
    public function isProposed(): bool
    {
        return $this->status === self::STATUS_PROPOSED;
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    /** Still live: waiting on someone, or booked and yet to happen. */
    public function isOpen(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_PROPOSED,
            self::STATUS_SCHEDULED,
        ], true);
    }

    /** The mentor's offered times as Carbon instances, past ones dropped. */
    public function slots(bool $futureOnly = true)
    {
        return collect($this->proposed_slots ?? [])
            ->map(fn ($slot) => Carbon::parse($slot))
            ->when($futureOnly, fn ($slots) => $slots->filter(fn ($slot) => $slot->isFuture()))
            ->sort()
            ->values();
    }

    /** A student can only pick from what is offered and has not already passed. */
    public function hasSlot($value): bool
    {
        $target = Carbon::parse($value);

        return $this->slots()->contains(fn ($slot) => $slot->equalTo($target));
    }

    public function endsAt()
    {
        return $this->scheduled_at?->copy()->addMinutes($this->duration_minutes ?: 30);
    }

    /** True once a booked session's slot has passed, so it can be marked done. */
    public function hasPassed(): bool
    {
        return $this->isScheduled() && $this->endsAt()?->isPast();
    }

    /** The time to show: the booked slot, falling back to what the student asked for. */
    public function displayAt()
    {
        return $this->scheduled_at ?? $this->preferred_at;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Awaiting reply',
            self::STATUS_PROPOSED => 'Pick a time',
            self::STATUS_SCHEDULED => $this->hasPassed() ? 'Awaiting confirmation' : 'Scheduled',
            self::STATUS_DECLINED => 'Declined',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_COMPLETED => 'Completed',
            default => ucfirst($this->status),
        };
    }

    /** [background, text] for the status pill. */
    public function statusColors(): array
    {
        return match ($this->status) {
            self::STATUS_PENDING => ['#FFFBEB', '#B45309'],
            self::STATUS_PROPOSED => ['#F5F3FF', '#6D28D9'],
            self::STATUS_SCHEDULED => ['#EFF6FF', '#1D4ED8'],
            self::STATUS_DECLINED => ['#FEF2F2', '#B91C1C'],
            self::STATUS_CANCELLED => ['#F3F4F6', '#4B5563'],
            self::STATUS_COMPLETED => ['#ECFDF5', '#047857'],
            default => ['#F3F4F6', '#4B5563'],
        };
    }

    /** Prefilled Google Calendar event for a booked session. */
    public function googleCalendarUrl(): string
    {
        $stamp = fn ($date) => $date->copy()->utc()->format('Ymd\THis\Z');

        return 'https://calendar.google.com/calendar/render?' . http_build_query([
            'action' => 'TEMPLATE',
            'text' => $this->topic,
            'dates' => $stamp($this->scheduled_at) . '/' . $stamp($this->endsAt()),
            'details' => collect([
                $this->message,
                $this->mentor_note,
                filled($this->meeting_link) ? 'Join: ' . $this->meeting_link : null,
            ])->filter()->implode("\n\n"),
            'location' => (string) $this->meeting_link,
        ]);
    }
}
