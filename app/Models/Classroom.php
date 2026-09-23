<?php

namespace App\Models;

use App\Notifications\ClassroomInvitation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;
use App\Models\Course;

class Classroom extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    /** In the order the manager arranged them, not alphabetically. */
    public function courses()
    {
        return $this->belongsToMany(Course::class)
            ->withPivot('sort_order')
            ->orderByPivot('sort_order')
            ->orderBy('courses.title');
    }

    /** Per-person exclusions and sign-offs across this class's courses. */
    public function courseEnrollments()
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    /**
     * Renumber this class's courses 0..n-1 in their current order, so the positions
     * are contiguous before a swap. Attaching a course leaves it at 0 alongside
     * whatever else has never been ordered, and a detach leaves a hole.
     */
    public function resequenceCourses(): \Illuminate\Support\Collection
    {
        $courses = $this->courses()->get();

        foreach ($courses->values() as $position => $course) {
            if ((int) $course->pivot->sort_order !== $position) {
                $this->courses()->updateExistingPivot($course->id, ['sort_order' => $position]);
                $course->pivot->sort_order = $position;
            }
        }

        return $courses->values();
    }

    /**
     * Swap a course with its neighbour, `$offset` being -1 for up and +1 for down.
     * Returns false when it is already at that end.
     */
    public function moveCourse($courseId, int $offset): bool
    {
        $courses = $this->resequenceCourses();

        $index = $courses->search(fn (Course $course) => (int) $course->id === (int) $courseId);
        $target = $index === false ? null : $index + $offset;

        if ($index === false || $target < 0 || $target > $courses->count() - 1) {
            return false;
        }

        $this->courses()->updateExistingPivot($courses[$index]->id, ['sort_order' => $target]);
        $this->courses()->updateExistingPivot($courses[$target]->id, ['sort_order' => $index]);

        return true;
    }

    /**
     * Add a course to this classroom at the top of the syllabus (sort_order = 0),
     * shifting all existing courses down.
     */
    public function addCourse($courseId): void
    {
        $id = $courseId instanceof Course ? $courseId->id : $courseId;

        $this->resequenceCourses();
        \Illuminate\Support\Facades\DB::table('classroom_course')
            ->where('classroom_id', $this->id)
            ->increment('sort_order');

        $this->courses()->attach($id, ['sort_order' => 0]);
    }

    /**
     * The enrolment row for one person on one course, created on first use. Only ever
     * called for a course this class actually teaches.
     */
    public function enrollmentFor($course, $user): CourseEnrollment
    {
        return CourseEnrollment::firstOrNew([
            'classroom_id' => $this->id,
            'course_id' => $course instanceof Course ? $course->id : $course,
            'user_id' => $user instanceof User ? $user->id : $user,
        ]);
    }

    public function isFirstCourse($course): bool
    {
        $courseId = $course instanceof Course ? $course->id : $course;
        $first = $this->courses()->first();

        return $first !== null && (int) $first->id === (int) $courseId;
    }

    public function previousCourseFor($course): ?Course
    {
        $courseId = $course instanceof Course ? $course->id : $course;
        $courses = $this->courses()->get();
        $index = $courses->search(fn (Course $c) => (int) $c->id === (int) $courseId);

        if ($index === false || $index === 0) {
            return null;
        }

        return $courses[$index - 1];
    }

    public function nextCourseFor($course): ?Course
    {
        $courseId = $course instanceof Course ? $course->id : $course;
        $courses = $this->courses()->get();
        $index = $courses->search(fn (Course $c) => (int) $c->id === (int) $courseId);

        if ($index === false || $index >= $courses->count() - 1) {
            return null;
        }

        return $courses[$index + 1];
    }

    public function isCourseFinishedFor($course, $user): bool
    {
        $courseModel = $course instanceof Course ? $course : Course::find($course);
        if (! $courseModel) {
            return false;
        }

        $userId = $user instanceof User ? $user->id : $user;

        // 1. Signed off / marked completed by manager
        $enrollment = $this->courseEnrollments()
            ->where('course_id', $courseModel->id)
            ->where('user_id', $userId)
            ->first();

        if ($enrollment && $enrollment->isCompleted()) {
            return true;
        }

        // 2. All lessons in course completed by user
        return $courseModel->areAllLessonsCompletedBy($userId);
    }

    public function canPromoteStudent($course, $user): bool
    {
        $courseId = $course instanceof Course ? $course->id : $course;
        $userId = $user instanceof User ? $user->id : $user;

        if (! $this->hasMember($userId)) {
            return false;
        }

        if (! $this->courses()->whereKey($courseId)->exists()) {
            return false;
        }

        // First course does not need promotion (enrolled by default)
        if ($this->isFirstCourse($courseId)) {
            return false;
        }

        // Teachers may promote whether or not the previous course is finished
        return ! $this->enrollmentFor($courseId, $userId)->isPromoted();
    }

    public function promoteStudent($course, $user, $promotedBy = null): bool
    {
        if (! $this->canPromoteStudent($course, $user)) {
            return false;
        }

        $enrollment = $this->enrollmentFor($course, $user);
        $enrollment->promoted_at = now();
        $enrollment->promoted_by = $promotedBy instanceof User ? $promotedBy->id : $promotedBy;
        $enrollment->removed_at = null;
        $enrollment->save();

        return true;
    }

    public function demoteStudent($course, $user): bool
    {
        $enrollment = $this->enrollmentFor($course, $user);
        $enrollment->promoted_at = null;
        $enrollment->promoted_by = null;
        $enrollment->save();

        return true;
    }

    public function hasAccessToCourse($course, $user): bool
    {
        $courseId = $course instanceof Course ? $course->id : $course;
        $userId = $user instanceof User ? $user->id : $user;

        if (! $this->hasMember($userId)) {
            return false;
        }

        if (! $this->courses()->whereKey($courseId)->exists()) {
            return false;
        }

        $enrollment = $this->enrollmentFor($courseId, $userId);
        if ($enrollment->isRemoved()) {
            return false;
        }

        // First course is accessible by default
        if ($this->isFirstCourse($courseId)) {
            return true;
        }

        // Subsequent courses require a teacher's promotion
        return $enrollment->isPromoted();
    }

    public function isAdministeredBy($user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $this->admin_id !== null && (int) $this->admin_id === (int) $userId;
    }

    /**
     * Puts an email address in this class, whether or not it belongs to an account yet.
     * Unknown addresses get a partial account they complete themselves at registration.
     *
     * @return string One of: added (existing account), invited (new partial account),
     *                already (nothing to do).
     */
    public function inviteByEmail(string $email, User $invitedBy): array
    {
        $email = strtolower(trim($email));

        $user = User::where('email', $email)->first();
        $isNew = false;

        if (! $user) {
            $user = User::create([
                'email' => $email,
                'invited_at' => now(),
                'invited_by' => $invitedBy->id,
            ]);

            $isNew = true;
        }

        if ($this->isAdministeredBy($user) || $this->hasMember($user)) {
            return ['user' => $user, 'status' => 'already'];
        }

        $this->users()->attach($user->id);

        $user->notify(new ClassroomInvitation($this, $invitedBy));

        return ['user' => $user, 'status' => $isNew ? 'invited' : 'added'];
    }

    public function hasMember($user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $this->users()->where('users.id', $userId)->exists();
    }

    /**
     * Public classes anyone may find and join: not mine, not already joined.
     */
    public function scopeJoinableBy($query, $user)
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where('is_public', true)
            ->where('admin_id', '!=', $userId)
            ->whereDoesntHave('users', fn ($u) => $u->where('users.id', $userId));
    }

    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('title', 'like', '%' . $term . '%')
                ->orWhereHas('admin', fn ($a) => $a->where('name', 'like', '%' . $term . '%'));
        });
    }
}
