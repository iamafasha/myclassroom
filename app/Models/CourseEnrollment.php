<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One person's standing in one course of one class: whether the class manager has
 * excused them from it, and whether the manager has signed off that they finished it.
 *
 * No row means the ordinary case — enrolled, unfinished — so absence is the default
 * rather than something to backfill.
 */
class CourseEnrollment extends Model
{
    protected $table = 'classroom_course_user';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'removed_at' => 'datetime',
            'completed_at' => 'datetime',
            'promoted_at' => 'datetime',
        ];
    }

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** The manager who signed the course off. Null once that account is deleted. */
    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** The manager who promoted the student to this course. */
    public function promotedBy()
    {
        return $this->belongsTo(User::class, 'promoted_by');
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function isPromoted(): bool
    {
        return $this->promoted_at !== null;
    }
}
