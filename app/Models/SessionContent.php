<?php

namespace App\Models;

use App\Traits\IsContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A booking point for one-to-one time, placed inside a course module.
 *
 * Unlike a live class, which is one event everybody attends, this block holds no
 * session of its own: each student who opens it gets their own MentorSession
 * attached to it, and manages that session from inside the content itself.
 */
class SessionContent extends Model
{
    use IsContent;

    protected $guarded = [];

    /** Mirrors the column defaults, so a block behaves the same before it is re-read. */
    protected $attributes = [
        'duration_minutes' => 30,
        'is_booking_enabled' => true,
        'allow_multiple' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_booking_enabled' => 'boolean',
            'allow_multiple' => 'boolean',
            'available_slots' => 'array',
        ];
    }

    /** Every session booked from this block, whoever booked it. */
    public function mentorSessions()
    {
        return $this->hasMany(MentorSession::class, 'session_content_id');
    }

    /** Where this block sits in a course. A content may be placed in several modules; the first one wins. */
    public function moduleContent(): ?ModuleContent
    {
        return $this->content?->moduleContents->first();
    }

    public function course(): ?Course
    {
        return $this->moduleContent()?->module?->course;
    }

    /** The person students book with: whoever owns the course this block lives in. */
    public function mentor(): ?User
    {
        return $this->course()?->creator;
    }

    /** Anyone who can see the course can see the block; only its owner manages it. */
    public function isVisibleTo($user): bool
    {
        $course = $this->course();

        return $course !== null && Course::visibleTo($user)->whereKey($course->id)->exists();
    }

    public function isManagedBy($user): bool
    {
        return (bool) $this->course()?->isManagedBy($user);
    }

    public function displayTitle(): string
    {
        return $this->title ?: 'Mentor Session';
    }

    /** The mentor's published times, past ones dropped. */
    public function slots()
    {
        return collect($this->available_slots ?? [])
            ->map(fn ($slot) => Carbon::parse($slot))
            ->filter(fn ($slot) => $slot->isFuture())
            ->sort()
            ->values();
    }

    /** Published times nobody has booked yet — one-to-one, so a taken slot is gone. */
    public function openSlots()
    {
        $taken = $this->mentorSessions()
            ->whereIn('status', [MentorSession::STATUS_SCHEDULED, MentorSession::STATUS_COMPLETED])
            ->whereNotNull('scheduled_at')
            ->pluck('scheduled_at')
            ->map(fn ($date) => $date->format('Y-m-d H:i:00'))
            ->all();

        return $this->slots()->reject(fn ($slot) => in_array($slot->format('Y-m-d H:i:00'), $taken, true))->values();
    }

    public function hasOpenSlot($value): bool
    {
        $target = Carbon::parse($value);

        return $this->openSlots()->contains(fn ($slot) => $slot->equalTo($target));
    }

    /** Sessions this user booked here, newest activity first. */
    public function sessionsFor($user)
    {
        return $this->mentorSessions()
            ->forStudent($user)
            ->with(['mentor', 'course'])
            ->orderByRaw("CASE WHEN status IN ('proposed', 'pending', 'scheduled') THEN 0 ELSE 1 END")
            ->orderByRaw('COALESCE(scheduled_at, preferred_at, created_at) DESC')
            ->get();
    }

    /**
     * Whether this user may open another session here: bookings must be on, the
     * mentor must be someone else, and unless repeats are allowed the student
     * must have no session still running.
     */
    public function canBeBookedBy($user): bool
    {
        $course = $this->course();
        $userId = $user instanceof User ? $user->id : $user;

        if (!$this->is_booking_enabled || !$course || !$course->created_by || (int) $course->created_by === (int) $userId) {
            return false;
        }

        if ($this->allow_multiple) {
            return true;
        }

        return !$this->mentorSessions()
            ->forStudent($userId)
            ->whereIn('status', [
                MentorSession::STATUS_PENDING,
                MentorSession::STATUS_PROPOSED,
                MentorSession::STATUS_SCHEDULED,
            ])
            ->exists();
    }
}
