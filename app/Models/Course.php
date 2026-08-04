<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $guarded = [];

    public function modules()
    {
        return $this->hasMany(Module::class);
    }

    public function classrooms()
    {
        return $this->belongsToMany(Classroom::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function mentorSessions()
    {
        return $this->hasMany(MentorSession::class);
    }

    /**
     * The classes this course is taught in, as one label. Null when it belongs to
     * none — a course can exist on its own before it is added to a class.
     */
    public function classLabel(): ?string
    {
        $names = $this->classrooms->pluck('title')->filter();

        return $names->isEmpty() ? null : $names->implode(' · ');
    }

    /**
     * Only the creator manages a course: modules, contents and submissions.
     */
    public function isManagedBy($user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $this->created_by !== null && (int) $this->created_by === (int) $userId;
    }

    public function scopeManagedBy($query, $user)
    {
        return $query->where('created_by', $user instanceof User ? $user->id : $user);
    }

    /**
     * Courses a user may book a session on: ones they can see, taught by
     * someone else. You do not request a session with yourself.
     */
    public function scopeSessionRequestableBy($query, $user)
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->visibleTo($userId)
            ->whereNotNull('created_by')
            ->where('created_by', '!=', $userId);
    }

    /**
     * Courses the user may see: those in a class they attend or administer,
     * plus the ones they created themselves.
     */
    public function scopeVisibleTo($query, $user)
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where(function ($q) use ($userId) {
            $q->where('created_by', $userId)
                ->orWhereHas('classrooms', function ($classroom) use ($userId) {
                    $classroom->where('admin_id', $userId)
                        ->orWhereHas('users', fn ($u) => $u->where('users.id', $userId));
                });
        });
    }
}
