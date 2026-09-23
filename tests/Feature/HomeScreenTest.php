<?php

use App\Models\{Classroom, Content, Course, LiveClassContent, Module, ModuleContent, NoteContent, User};

/** A class with one course, one module and the given lessons, seen by an attendee. */
function seedHome(array $labels = ['Intro', 'Deep Dive'], string $moduleTitle = 'Frontend 1: Intro to HTML'): array
{
    $owner = User::factory()->create(['name' => 'Owner Person']);
    $student = User::factory()->create(['name' => 'Afasha Isakiye']);

    $classroom = Classroom::create(['title' => 'Class H', 'admin_id' => $owner->id]);
    $classroom->users()->attach($student->id);

    $course = Course::create(['title' => 'Frontend Track', 'slug' => 'frontend-track', 'created_by' => $owner->id]);
    $classroom->courses()->attach($course->id);

    $module = Module::create([
        'course_id' => $course->id,
        'title' => $moduleTitle,
        'slug' => Illuminate\Support\Str::slug($moduleTitle),
        'sort_order' => 1,
    ]);

    $lessons = [];

    foreach (array_values($labels) as $index => $label) {
        $lesson = ModuleContent::create([
            'module_id' => $module->id,
            'label' => $label,
            'sort_order' => $index + 1,
        ]);

        $note = new NoteContent();
        $note->content = $label;
        $note->save();

        $content = Content::create(['contentable_type' => NoteContent::class, 'contentable_id' => $note->id]);
        $lesson->contents()->attach($content->id, ['sort_order' => 1]);

        $lessons[$label] = $lesson;
    }

    return [$student, $course, $module, $lessons];
}

it('greets the user and points at the first unfinished lesson', function () {
    [$student, , , $lessons] = seedHome();

    $this->actingAs($student)->get(route('home'))
        ->assertOk()
        ->assertSee('Welcome Back')
        ->assertSee('Afasha')
        ->assertSee('Pick up where you left off')
        ->assertSee('Intro')
        ->assertSee(route('content.show', $lessons['Intro']->id), false);
});

it('skips lessons already completed', function () {
    [$student, , , $lessons] = seedHome();
    $lessons['Intro']->markCompletedFor($student);

    $this->actingAs($student)->get(route('home'))
        ->assertOk()
        ->assertSee(route('content.show', $lessons['Deep Dive']->id), false);
});

/** Puts a live class into the module as its own lesson. */
function addLiveClass(Module $module, string $title, $startsAt, array $attributes = []): array
{
    $liveClass = new LiveClassContent();
    $liveClass->title = $title;
    $liveClass->starts_at = $startsAt;
    $liveClass->duration_minutes = 60;
    foreach ($attributes as $key => $value) {
        $liveClass->{$key} = $value;
    }
    $liveClass->save();

    $lesson = ModuleContent::create(['module_id' => $module->id, 'label' => $title, 'sort_order' => 9]);
    $content = Content::create(['contentable_type' => LiveClassContent::class, 'contentable_id' => $liveClass->id]);
    $lesson->contents()->attach($content->id, ['sort_order' => 1]);

    return [$liveClass, $lesson];
}

it('shows the module carousel with its progress and course', function () {
    [$student, , $module, $lessons] = seedHome();
    $lessons['Intro']->markCompletedFor($student);

    $this->actingAs($student)->get(route('home'))
        ->assertOk()
        ->assertSee('Frontend 1: Intro to HTML')
        ->assertSee('Frontend Track')
        ->assertSee('50% of this module done');
});

it('shows the most recent ended live class as the latest class', function () {
    [$student, , $module] = seedHome();
    addLiveClass($module, 'Older Session', now()->subDays(5));
    [, $recent] = addLiveClass($module, 'Recent Session', now()->subDays(2));
    addLiveClass($module, 'Running Session', now()->subMinutes(10));
    addLiveClass($module, 'Future Session', now()->addDays(2));

    $this->actingAs($student)->get(route('home'))
        ->assertOk()
        ->assertSeeInOrder(['Your latest class', 'Recent Session', route('content.show', $recent->id)], false)
        ->assertDontSee('Older Session');
});

it('hides the latest class band when no live class has ended yet', function () {
    [$student, , $module] = seedHome();
    addLiveClass($module, 'Future Session', now()->addDays(2));

    $this->actingAs($student)->get(route('home'))
        ->assertOk()
        ->assertDontSee('Your latest class');
});

it('shows the class in progress as the upcoming class with a join button', function () {
    [$student, , $module] = seedHome();
    addLiveClass($module, 'Running Session', now()->subMinutes(10), ['is_join_enabled' => true, 'join_link' => 'https://meet.example.com/abc']);
    addLiveClass($module, 'Future Session', now()->addDays(2));

    $this->actingAs($student)->get(route('home'))
        ->assertOk()
        ->assertSeeInOrder(['Upcoming class :', 'Running Session', 'Live now', 'https://meet.example.com/abc'], false);
});

it('shows the next scheduled class as the upcoming class without a join button when joining is off', function () {
    [$student, , $module] = seedHome();
    addLiveClass($module, 'Future Session', now()->addDays(2), ['is_join_enabled' => false, 'join_link' => 'https://meet.example.com/hidden']);

    $this->actingAs($student)->get(route('home'))
        ->assertOk()
        ->assertSeeInOrder(['Upcoming class :', 'Future Session'], false)
        ->assertDontSee('https://meet.example.com/hidden');
});

it('lists later live classes in the side rail, leaving the next one to the upcoming band', function () {
    [$student, , $module] = seedHome();
    addLiveClass($module, 'Live Q&A Session', now()->addDays(2));
    addLiveClass($module, 'Revision Session', now()->addDays(4));

    $this->actingAs($student)->get(route('home'))
        ->assertOk()
        ->assertSeeInOrder(['Upcoming class :', 'Live Q&amp;A Session', 'Coming up', 'Revision Session'], false)
        ->assertDontSee('Upcoming Class');
});

it('shows no upcoming class when none is scheduled', function () {
    [$student] = seedHome();

    $this->actingAs($student)->get(route('home'))
        ->assertOk()
        ->assertDontSee('Upcoming class :')
        ->assertDontSee('Coming up');
});

it('does not leak courses from classes the user is not in', function () {
    [$student] = seedHome();

    $stranger = User::factory()->create();
    $hidden = Course::create(['title' => 'Secret Course', 'slug' => 'secret-course', 'created_by' => $stranger->id]);
    Module::create(['course_id' => $hidden->id, 'title' => 'Secret Module', 'slug' => 'secret-module', 'sort_order' => 1]);

    $this->actingAs($student)->get(route('home'))
        ->assertOk()
        ->assertDontSee('Secret Course')
        ->assertDontSee('Secret Module');
});

it('shows an empty state for a user with no courses', function () {
    $loner = User::factory()->create();

    $this->actingAs($loner)->get(route('home'))
        ->assertOk()
        ->assertSee('Nothing to study yet')
        ->assertSee('You are not in any course yet.');
});

/** A second class, with its own course and module, that $student also attends. */
function seedSecondClass(User $student): array
{
    $owner = User::factory()->create();

    $classroom = Classroom::create(['title' => 'Design Club', 'admin_id' => $owner->id]);
    $classroom->users()->attach($student->id);

    $course = Course::create(['title' => 'Design Basics', 'slug' => 'design-basics', 'created_by' => $owner->id]);
    $classroom->courses()->attach($course->id);

    $module = Module::create(['course_id' => $course->id, 'title' => 'Colour Theory', 'slug' => 'colour-theory', 'sort_order' => 1]);

    return [$classroom, $course, $module];
}

it('hides the class picker when the user is only in one class', function () {
    [$student] = seedHome();

    $this->actingAs($student)->get(route('home'))
        ->assertOk()
        ->assertDontSee('All classes');
});

it('offers a class picker once the user is in several classes', function () {
    [$student] = seedHome();
    seedSecondClass($student);

    $this->actingAs($student)->get(route('home'))
        ->assertOk()
        ->assertSee('All classes')
        ->assertSee('Class H')
        ->assertSee('Design Club');
});

it('narrows the screen to the picked class', function () {
    [$student] = seedHome();
    [$classroom] = seedSecondClass($student);

    Livewire::actingAs($student)->test('home')
        ->assertSee('Frontend Track')
        ->assertSee('Design Basics')
        ->call('selectClassroom', $classroom->id)
        ->assertSee('Design Basics')
        ->assertSee('Colour Theory')
        ->assertDontSee('Frontend Track')
        ->call('selectClassroom', null)
        ->assertSee('Frontend Track');
});

it('ignores a class the user does not belong to', function () {
    [$student] = seedHome();
    seedSecondClass($student);

    $stranger = User::factory()->create();
    $foreign = Classroom::create(['title' => 'Not Yours', 'admin_id' => $stranger->id]);

    Livewire::actingAs($student)->withQueryParams(['class' => $foreign->id])->test('home')
        ->assertSet('classroomId', null)
        ->assertSee('Frontend Track');
});

it('walks through modules with the carousel', function () {
    [$student, $course] = seedHome();

    Module::create(['course_id' => $course->id, 'title' => 'Frontend 2: Basic Javascript', 'slug' => 'frontend-2', 'sort_order' => 2]);

    // The carousel opens on the newest module, so the older one is only a click away.
    Livewire::actingAs($student)->test('home')
        ->assertSee('Frontend 2: Basic Javascript')
        ->assertDontSee('Frontend 1: Intro to HTML')
        ->call('previousModule')
        ->assertSee('Frontend 1: Intro to HTML')
        ->call('nextModule')
        ->assertDontSee('Frontend 1: Intro to HTML');
});
