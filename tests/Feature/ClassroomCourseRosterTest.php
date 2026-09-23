<?php

use App\Models\{Classroom, Course, CourseEnrollment, Module, User};
use Livewire\Livewire;

/**
 * A class with three courses attached in a deliberately non-alphabetical order and
 * one attendee, which is what the reordering and per-person controls act on.
 */
function seedRoster(bool $promoteAll = false): array
{
    $admin = User::factory()->create();
    $learner = User::factory()->create();
    $other = User::factory()->create();

    $classroom = Classroom::create(['title' => 'Class A', 'admin_id' => $admin->id]);
    $classroom->users()->attach([$learner->id, $other->id]);

    $courses = collect(['Cee', 'Aay', 'Bee'])->mapWithKeys(function ($title, $index) use ($admin, $classroom) {
        $course = Course::create([
            'title' => $title,
            'slug' => strtolower($title),
            'created_by' => $admin->id,
        ]);

        Module::create([
            'course_id' => $course->id,
            'title' => 'M1',
            'slug' => 'm1-' . strtolower($title),
            'sort_order' => 1,
        ]);
        $classroom->courses()->attach($course->id, ['sort_order' => $index]);

        return [$title => $course];
    });

    if ($promoteAll) {
        foreach ([$learner, $other] as $user) {
            $classroom->enrollmentFor($courses['Cee'], $user)->fill(['completed_at' => now(), 'completed_by' => $admin->id])->save();
            $classroom->enrollmentFor($courses['Aay'], $user)->fill(['promoted_at' => now(), 'promoted_by' => $admin->id, 'completed_at' => now(), 'completed_by' => $admin->id])->save();
            $classroom->enrollmentFor($courses['Bee'], $user)->fill(['promoted_at' => now(), 'promoted_by' => $admin->id])->save();
        }
    }

    return compact('admin', 'learner', 'other', 'classroom', 'courses');
}

it('keeps a class in the order the manager arranged rather than alphabetically', function () {
    ['classroom' => $classroom] = seedRoster();

    expect($classroom->courses()->pluck('title')->all())->toBe(['Cee', 'Aay', 'Bee']);
});

it('moves a course up and down the syllabus', function () {
    ['admin' => $admin, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('moveCourseUp', $courses['Bee']->id);

    expect($classroom->courses()->pluck('title')->all())->toBe(['Cee', 'Bee', 'Aay']);

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('moveCourseDown', $courses['Cee']->id);

    expect($classroom->courses()->pluck('title')->all())->toBe(['Bee', 'Cee', 'Aay']);
});

it('leaves the order alone at either end', function () {
    ['admin' => $admin, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('moveCourseUp', $courses['Cee']->id)
        ->call('moveCourseDown', $courses['Bee']->id);

    expect($classroom->courses()->pluck('title')->all())->toBe(['Cee', 'Aay', 'Bee']);
});

it('puts a newly added course at the top of the syllabus', function () {
    ['admin' => $admin, 'classroom' => $classroom] = seedRoster();

    $extra = Course::create(['title' => 'Aardvark', 'slug' => 'aardvark', 'created_by' => $admin->id]);

    Livewire::actingAs($admin)->test('classes.courses-add', ['classroom' => $classroom])
        ->call('add', $extra->id);

    expect($classroom->courses()->pluck('title')->all())->toBe(['Aardvark', 'Cee', 'Aay', 'Bee']);
    expect($classroom->courses()->pluck('sort_order')->all())->toBe([0, 1, 2, 3]);
});

it('gives a learner the syllabus order without losing the module counts', function () {
    ['learner' => $learner, 'classroom' => $classroom] = seedRoster(true);

    // The shape the home screen's course rail asks for.
    $courses = Course::visibleTo($learner)
        ->whereHas('classrooms', fn ($q) => $q->whereKey($classroom->id))
        ->withCount('modules')
        ->orderedForClassroom($classroom->id)
        ->get();

    expect($courses->pluck('title')->all())->toBe(['Cee', 'Aay', 'Bee']);
    // Ordering must not clobber the withCount those cards render.
    expect($courses->pluck('modules_count')->all())->toBe([1, 1, 1]);
});

it('falls back to alphabetical when no class is picked', function () {
    ['learner' => $learner] = seedRoster(true);

    expect(Course::visibleTo($learner)->orderedForClassroom(null)->pluck('title')->all())
        ->toBe(['Aay', 'Bee', 'Cee']);
});

it('renders the learner home screen with a class selected', function () {
    ['learner' => $learner, 'classroom' => $classroom] = seedRoster();

    Livewire::actingAs($learner)->test('home')
        ->call('selectClassroom', $classroom->id)
        ->assertOk();
});

it('hides a single course from someone removed from it, keeping the rest of the class', function () {
    ['admin' => $admin, 'learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster(true);

    expect(Course::visibleTo($learner)->pluck('title')->sort()->values()->all())
        ->toBe(['Aay', 'Bee', 'Cee']);

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('toggleCourseAccess', $courses['Bee']->id, $learner->id);

    expect(Course::visibleTo($learner)->pluck('title')->sort()->values()->all())
        ->toBe(['Aay', 'Cee']);

    // The class membership itself is untouched.
    expect($classroom->hasMember($learner))->toBeTrue();
});

it('leaves everyone else in the class alone when one person is removed from a course', function () {
    ['admin' => $admin, 'learner' => $learner, 'other' => $other, 'classroom' => $classroom, 'courses' => $courses] = seedRoster(true);

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('toggleCourseAccess', $courses['Bee']->id, $learner->id);

    expect(Course::visibleTo($other)->pluck('title')->sort()->values()->all())
        ->toBe(['Aay', 'Bee', 'Cee']);
});

it('restores access to a course', function () {
    ['admin' => $admin, 'learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster(true);

    $component = Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('toggleCourseAccess', $courses['Bee']->id, $learner->id);

    expect(Course::visibleTo($learner)->whereKey($courses['Bee']->id)->exists())->toBeFalse();

    $component->call('toggleCourseAccess', $courses['Bee']->id, $learner->id);

    expect(Course::visibleTo($learner)->whereKey($courses['Bee']->id)->exists())->toBeTrue();
});

it('still shows a course to its creator and to the class admin', function () {
    ['admin' => $admin, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    // A manager excusing themselves should not lock them out of what they run.
    $classroom->users()->attach($admin->id);
    $classroom->enrollmentFor($courses['Bee'], $admin)->fill(['removed_at' => now()])->save();

    expect(Course::visibleTo($admin)->whereKey($courses['Bee']->id)->exists())->toBeTrue();
});

it('keeps a removal to the class it was made in', function () {
    ['admin' => $admin, 'learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    // The same course taught by a second class the learner also attends.
    $second = Classroom::create(['title' => 'Class B', 'admin_id' => $admin->id]);
    $second->users()->attach($learner->id);
    $second->courses()->attach($courses['Bee']->id, ['sort_order' => 0]);

    $classroom->enrollmentFor($courses['Bee'], $learner)->fill(['removed_at' => now()])->save();

    expect(Course::visibleTo($learner)->whereKey($courses['Bee']->id)->exists())->toBeTrue();

    $second->enrollmentFor($courses['Bee'], $learner)->fill(['removed_at' => now()])->save();

    expect(Course::visibleTo($learner)->whereKey($courses['Bee']->id)->exists())->toBeFalse();
});

it('marks a course completed for one person and records who signed it off', function () {
    ['admin' => $admin, 'learner' => $learner, 'other' => $other, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('toggleCourseCompleted', $courses['Aay']->id, $learner->id);

    expect($courses['Aay']->isCompletedFor($learner))->toBeTrue();
    expect($courses['Aay']->isCompletedFor($other))->toBeFalse();

    $enrollment = CourseEnrollment::where('course_id', $courses['Aay']->id)
        ->where('user_id', $learner->id)
        ->first();

    expect($enrollment->completed_by)->toBe($admin->id);
    expect($enrollment->completed_at)->not->toBeNull();
});

it('clears a completion mark', function () {
    ['admin' => $admin, 'learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    $component = Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('toggleCourseCompleted', $courses['Aay']->id, $learner->id);

    $component->call('toggleCourseCompleted', $courses['Aay']->id, $learner->id);

    expect($courses['Aay']->fresh()->isCompletedFor($learner))->toBeFalse();

    $enrollment = CourseEnrollment::where('course_id', $courses['Aay']->id)
        ->where('user_id', $learner->id)
        ->first();

    expect($enrollment->completed_by)->toBeNull();
});

it('marking a course completed does not hide it from the learner', function () {
    ['admin' => $admin, 'learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('toggleCourseCompleted', $courses['Cee']->id, $learner->id);

    expect(Course::visibleTo($learner)->whereKey($courses['Cee']->id)->exists())->toBeTrue();
});

it('refuses a course or person outside the class', function () {
    ['admin' => $admin, 'learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    $foreignCourse = Course::create(['title' => 'Elsewhere', 'slug' => 'elsewhere', 'created_by' => $admin->id]);
    $stranger = User::factory()->create();

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('toggleCourseCompleted', $foreignCourse->id, $learner->id)
        ->assertStatus(404);

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('toggleCourseAccess', $courses['Aay']->id, $stranger->id)
        ->assertStatus(404);
});

it('drops a person\'s course marks when they leave the class', function () {
    ['admin' => $admin, 'learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('toggleCourseCompleted', $courses['Aay']->id, $learner->id)
        ->call('toggleCourseAccess', $courses['Bee']->id, $learner->id)
        ->call('removeAttendee', $learner->id);

    expect(CourseEnrollment::where('user_id', $learner->id)->count())->toBe(0);
});

it('drops course marks and closes the gap when a course leaves the class', function () {
    ['admin' => $admin, 'learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('toggleCourseCompleted', $courses['Aay']->id, $learner->id)
        ->call('removeCourse', $courses['Aay']->id);

    expect(CourseEnrollment::where('course_id', $courses['Aay']->id)->count())->toBe(0);
    expect($classroom->courses()->pluck('title')->all())->toBe(['Cee', 'Bee']);
    expect($classroom->courses()->pluck('sort_order')->all())->toBe([0, 1]);
});

it('stops an attendee from reordering or marking anyone off', function () {
    ['learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    // Mounting the page is already barred, which is what protects every action on it.
    Livewire::actingAs($learner)->test('classes.show', ['classroom' => $classroom])
        ->assertStatus(403);
});

it('reorders courses from the courses-add component', function () {
    ['admin' => $admin, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    Livewire::actingAs($admin)->test('classes.courses-add', ['classroom' => $classroom])
        ->call('moveCourseUp', $courses['Bee']->id);

    expect($classroom->courses()->pluck('title')->all())->toBe(['Cee', 'Bee', 'Aay']);

    Livewire::actingAs($admin)->test('classes.courses-add', ['classroom' => $classroom])
        ->call('moveCourseDown', $courses['Cee']->id);

    expect($classroom->courses()->pluck('title')->all())->toBe(['Bee', 'Cee', 'Aay']);
});

it('shows courses in syllabus order on home when the learner attends one class', function () {
    ['learner' => $learner, 'classroom' => $classroom] = seedRoster(true);

    $component = Livewire::actingAs($learner)->test('home');

    expect($component->get('courses')->pluck('title')->all())->toBe(['Cee', 'Aay', 'Bee']);
});

it('hides an excused course from the attending list on classes index', function () {
    ['admin' => $admin, 'learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster(true);

    $classroom->enrollmentFor($courses['Bee'], $learner)->fill(['removed_at' => now()])->save();

    $component = Livewire::actingAs($learner)->test('classes.index')
        ->call('selectTab', 'attending');

    $attendingClass = $component->get('attendingClassrooms')->firstWhere('id', $classroom->id);
    expect($attendingClass->courses->pluck('title')->all())->toBe(['Cee', 'Aay']);
});

it('lists courses in syllabus order in classroom dashboard, latest promotion first', function () {
    ['learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster(true);

    // Bee is the course the learner was promoted to last, so it leads; the rest keep syllabus order.
    $component = Livewire::actingAs($learner)->test('classroom-dashboard', ['courseId' => $courses['Aay']->id]);

    expect($component->get('courses')->pluck('title')->all())->toBe(['Bee', 'Cee', 'Aay']);
});

it('selects the first course of the syllabus on dashboard mount', function () {
    ['learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    $component = Livewire::actingAs($learner)->test('classroom-dashboard');

    expect($component->get('selectedCourseId'))->toBe($courses['Cee']->id);
});

it('defaults a student to only the first course in the syllabus', function () {
    ['learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    // The learner attends the class with 3 courses, but has access only to the first course
    $visibleCourseIds = Course::visibleTo($learner)->pluck('id')->all();

    expect($visibleCourseIds)->toBe([$courses['Cee']->id]);
    expect($classroom->hasAccessToCourse($courses['Cee'], $learner))->toBeTrue();
    expect($classroom->hasAccessToCourse($courses['Aay'], $learner))->toBeFalse();
    expect($classroom->hasAccessToCourse($courses['Bee'], $learner))->toBeFalse();
});

it('prevents a student from seeing or accessing subsequent courses before finishing the previous one', function () {
    ['learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    // A student directly navigating to /course/{id} for subsequent course cannot access it
    Livewire::actingAs($learner)->test('classroom-dashboard', ['courseId' => $courses['Aay']->id])
        ->assertSet('selectedCourseId', $courses['Cee']->id); // bounces back to first visible course
});

it('lets a manager promote a student who has not finished the previous course', function () {
    ['admin' => $admin, 'learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    expect($classroom->isCourseFinishedFor($courses['Cee'], $learner))->toBeFalse();
    expect($classroom->canPromoteStudent($courses['Aay'], $learner))->toBeTrue();

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('promoteAttendee', $courses['Aay']->id, $learner->id);

    expect($classroom->hasAccessToCourse($courses['Aay'], $learner))->toBeTrue();
    expect(Course::visibleTo($learner)->whereKey($courses['Aay']->id)->exists())->toBeTrue();
    expect($classroom->canPromoteStudent($courses['Aay'], $learner))->toBeFalse(); // already promoted
    expect($classroom->canPromoteStudent($courses['Cee'], $learner))->toBeFalse(); // first course needs no promotion
});

it('promotes a student to the next course once previous course is marked completed by manager', function () {
    ['admin' => $admin, 'learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    // Mark previous course completed
    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('toggleCourseCompleted', $courses['Cee']->id, $learner->id);

    expect($classroom->canPromoteStudent($courses['Aay'], $learner))->toBeTrue();

    // Now promote student to next course
    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('promoteAttendee', $courses['Aay']->id, $learner->id);

    expect($classroom->hasAccessToCourse($courses['Aay'], $learner))->toBeTrue();
    expect(Course::visibleTo($learner)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$courses['Cee']->id, $courses['Aay']->id])->sort()->values()->all());

    // Still cannot see the third course yet
    expect($classroom->hasAccessToCourse($courses['Bee'], $learner))->toBeFalse();
});

it('promotes a student to the next course once student completes all lessons in previous course', function () {
    ['admin' => $admin, 'learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    // Create module and lesson for course Cee
    $module = $courses['Cee']->modules()->first();
    $lesson = \App\Models\ModuleContent::create([
        'module_id' => $module->id,
        'label' => 'Lesson 1',
        'sort_order' => 1,
    ]);

    // Before lesson is completed, course is not finished
    expect($courses['Cee']->areAllLessonsCompletedBy($learner))->toBeFalse();
    expect($classroom->isCourseFinishedFor($courses['Cee'], $learner))->toBeFalse();

    // Learner completes the lesson
    $lesson->markCompletedFor($learner);

    expect($courses['Cee']->areAllLessonsCompletedBy($learner))->toBeTrue();
    expect($classroom->isCourseFinishedFor($courses['Cee'], $learner))->toBeTrue();
    expect($classroom->canPromoteStudent($courses['Aay'], $learner))->toBeTrue();

    // Teacher can now promote learner to course Aay
    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('promoteAttendee', $courses['Aay']->id, $learner->id);

    expect($classroom->hasAccessToCourse($courses['Aay'], $learner))->toBeTrue();
    expect(Course::visibleTo($learner)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$courses['Cee']->id, $courses['Aay']->id])->sort()->values()->all());
});

it('stops non-managers from promoting students', function () {
    ['learner' => $learner, 'classroom' => $classroom] = seedRoster();

    // Mounting the page is already barred, which protects every action on it
    Livewire::actingAs($learner)->test('classes.show', ['classroom' => $classroom])
        ->assertStatus(403);

    expect($classroom->isAdministeredBy($learner))->toBeFalse();
});

it('demotes a student and revokes access to that course', function () {
    ['admin' => $admin, 'learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    // Mark complete and promote
    $classroom->enrollmentFor($courses['Cee'], $learner)->fill(['completed_at' => now(), 'completed_by' => $admin->id])->save();
    $classroom->promoteStudent($courses['Aay'], $learner, $admin);

    expect($classroom->hasAccessToCourse($courses['Aay'], $learner))->toBeTrue();

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('demoteAttendee', $courses['Aay']->id, $learner->id);

    expect($classroom->hasAccessToCourse($courses['Aay'], $learner))->toBeFalse();
    expect(Course::visibleTo($learner)->pluck('id')->all())->toBe([$courses['Cee']->id]);
});

it('keeps a promoted student in the course if manager clears completion on previous course', function () {
    ['admin' => $admin, 'learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    // Course Cee has a lesson that is not completed by the learner
    $module = $courses['Cee']->modules()->first();
    \App\Models\ModuleContent::create([
        'module_id' => $module->id,
        'label' => 'Unfinished Lesson',
        'sort_order' => 1,
    ]);

    // Manager marks Cee complete and promotes to Aay
    $component = Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom]);
    $component->call('toggleCourseCompleted', $courses['Cee']->id, $learner->id);
    $component->call('promoteAttendee', $courses['Aay']->id, $learner->id);

    expect($classroom->hasAccessToCourse($courses['Aay'], $learner))->toBeTrue();
    expect(Course::visibleTo($learner)->whereKey($courses['Aay']->id)->exists())->toBeTrue();

    // Manager revokes completion on Cee
    $component->call('toggleCourseCompleted', $courses['Cee']->id, $learner->id);

    // Promotion stands on its own, so Aay stays open
    expect($classroom->hasAccessToCourse($courses['Aay'], $learner))->toBeTrue();
    expect(Course::visibleTo($learner)->whereKey($courses['Aay']->id)->exists())->toBeTrue();
});

it('shows progression status badges and controls in class management UI', function () {
    ['admin' => $admin, 'learner' => $learner, 'classroom' => $classroom, 'courses' => $courses] = seedRoster();

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('toggleRoster', $courses['Cee']->id)
        ->assertSee('First Course')
        ->call('toggleRoster', $courses['Aay']->id)
        ->assertSee('Locked')
        ->assertSee('Has not finished "Cee" yet', false);
});


