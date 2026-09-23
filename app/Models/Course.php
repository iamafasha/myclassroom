<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Course extends Model
{
    protected $guarded = [];

    public function modules()
    {
        return $this->hasMany(Module::class);
    }

    public function classrooms()
    {
        return $this->belongsToMany(Classroom::class)->withPivot('sort_order');
    }

    /** Per-person exclusions and sign-offs on this course, across every class. */
    public function enrollments()
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    /**
     * Reads from the loaded relation when it is there, so a list of courses rendered
     * with `with('enrollments')` doesn't fire a query per row.
     */
    public function enrollmentFor($user, $classroom = null): ?CourseEnrollment
    {
        $userId = $user instanceof User ? $user->id : $user;
        $classroomId = $classroom instanceof Classroom ? $classroom->id : $classroom;

        if (! $userId) {
            return null;
        }

        if ($this->relationLoaded('enrollments')) {
            return $this->enrollments->first(fn (CourseEnrollment $enrollment) => (int) $enrollment->user_id === (int) $userId
                && ($classroomId === null || (int) $enrollment->classroom_id === (int) $classroomId));
        }

        return $this->enrollments()
            ->where('user_id', $userId)
            ->when($classroomId, fn ($q) => $q->where('classroom_id', $classroomId))
            ->first();
    }

    /**
     * A sign-off in any class the person takes this course in counts: they are done
     * with the course, whichever class they were signed off in.
     */
    public function isCompletedFor($user, $classroom = null): bool
    {
        return (bool) $this->enrollmentFor($user, $classroom)?->isCompleted();
    }

    /**
     * True if the user has completed all lessons (module contents) in this course,
     * and the course has at least one lesson.
     */
    public function areAllLessonsCompletedBy($user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;
        if (! $userId) {
            return false;
        }

        $totalLessons = ModuleContent::whereHas('module', fn ($q) => $q->where('course_id', $this->id))->count();
        if ($totalLessons === 0) {
            return false;
        }

        $completedLessons = ModuleContent::whereHas('module', fn ($q) => $q->where('course_id', $this->id))
            ->whereHas('progress', fn ($q) => $q->where('user_id', $userId)->whereNotNull('completed_at'))
            ->count();

        return $completedLessons >= $totalLessons;
    }

    /**
     * A course is finished for a student if the manager signed it off or if
     * the student completed every lesson in the course.
     */
    public function isFinishedFor($user, $classroom = null): bool
    {
        return $this->isCompletedFor($user, $classroom) || $this->areAllLessonsCompletedBy($user);
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
     *
     * In a class:
     * - A student is only in the first course by default.
     * - Subsequent courses are only visible once the manager has promoted them to the
     *   course, whether or not they finished the previous one.
     * - An excused course (removed_at is not null) is hidden.
     * - Being an attendee never hides a course from its creator or from the class admin.
     */
    public function scopeVisibleTo($query, $user)
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where(function ($q) use ($userId) {
            $q->where('created_by', $userId)
                ->orWhereHas('classrooms', fn ($classroom) => $classroom->where('admin_id', $userId))
                ->orWhereHas('classrooms', function ($classroom) use ($userId) {
                    $classroom
                        ->whereHas('users', fn ($u) => $u->where('users.id', $userId))
                        ->whereDoesntHave(
                            'courseEnrollments',
                            fn ($enrollment) => $enrollment
                                ->where('classroom_course_user.user_id', $userId)
                                ->whereColumn('classroom_course_user.course_id', 'courses.id')
                                ->whereNotNull('classroom_course_user.removed_at')
                        )
                        ->where(function ($access) use ($userId) {
                            // 1. It is the first course in this classroom's syllabus
                            $access->whereNotExists(function ($sub) {
                                $sub->select(DB::raw(1))
                                    ->from('classroom_course as cc_first')
                                    ->whereColumn('cc_first.classroom_id', 'classrooms.id')
                                    ->whereColumn('cc_first.sort_order', '<', 'classroom_course.sort_order');
                            })
                            // 2. OR the teacher promoted the student to this course
                            ->orWhereExists(function ($sub) use ($userId) {
                                $sub->select(DB::raw(1))
                                    ->from('classroom_course_user as ccu_promo')
                                    ->whereColumn('ccu_promo.classroom_id', 'classrooms.id')
                                    ->whereColumn('ccu_promo.course_id', 'courses.id')
                                    ->where('ccu_promo.user_id', $userId)
                                    ->whereNotNull('ccu_promo.promoted_at');
                            });
                        });
                });
        });
    }

    /**
     * Order by where a class puts this course in its syllabus. Only meaningful when
     * the results are already narrowed to that one class.
     */
    public function scopeOrderedForClassroom($query, $classroomId)
    {
        $classroomId = $classroomId instanceof Classroom ? $classroomId->id : $classroomId;

        if (! $classroomId) {
            return $query->orderBy('courses.title');
        }

        // Ordered by a correlated subquery rather than a join: a join needs
        // select('courses.*') to stop the pivot's columns overwriting the course's
        // own, and that select silently discards any withCount already applied.
        return $query
            ->orderBy(
                DB::table('classroom_course')
                    ->select('sort_order')
                    ->whereColumn('classroom_course.course_id', 'courses.id')
                    ->where('classroom_course.classroom_id', $classroomId)
                    ->limit(1)
            )
            ->orderBy('courses.title');
    }
}
