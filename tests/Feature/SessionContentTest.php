<?php

use App\Models\{Classroom, Content, Course, MentorSession, Module, ModuleContent, SessionContent, User};
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/** A Session block sitting in a lesson of a course the student attends. */
function seedSessionBlock(array $attributes = []): array
{
    $n = Course::count() + 1;

    $mentor = User::factory()->create(['name' => 'Mentor Mo']);
    $student = User::factory()->create(['name' => 'Student Sam']);
    $classmate = User::factory()->create(['name' => 'Classmate Kim']);
    $outsider = User::factory()->create();

    $classroom = Classroom::create(['title' => 'Class ' . $n, 'admin_id' => $mentor->id]);
    $classroom->users()->attach([$student->id, $classmate->id]);

    $course = Course::create(['title' => 'Course ' . $n, 'slug' => 'course-' . $n, 'created_by' => $mentor->id]);
    $classroom->courses()->attach($course->id);

    $module = Module::create(['course_id' => $course->id, 'title' => 'Module ' . $n, 'slug' => 'module-' . $n]);
    $moduleContent = ModuleContent::create([
        'module_id' => $module->id,
        'label' => 'Office hours',
        'slug' => 'office-hours-' . $n,
    ]);

    $block = SessionContent::create(array_merge([
        'title' => 'Office hours',
        'duration_minutes' => 30,
    ], $attributes));

    $content = Content::create([
        'contentable_id' => $block->id,
        'contentable_type' => SessionContent::class,
    ]);

    $moduleContent->contents()->attach($content->id, ['sort_order' => 1]);

    return compact('mentor', 'student', 'classmate', 'outsider', 'course', 'moduleContent', 'block');
}

it('creates a session of its own for each student who books on the block', function () {
    ['student' => $student, 'classmate' => $classmate, 'mentor' => $mentor, 'course' => $course, 'block' => $block] = seedSessionBlock();

    foreach ([$student, $classmate] as $user) {
        Livewire::actingAs($user)->test('sessions.content-panel', ['sessionContent' => $block->id])
            ->call('requestSession')
            ->assertHasNoErrors();
    }

    expect(MentorSession::count())->toBe(2);

    $mine = MentorSession::forStudent($student)->first();

    expect($mine->session_content_id)->toBe($block->id)
        ->and($mine->mentor_id)->toBe($mentor->id)
        ->and($mine->course_id)->toBe($course->id)
        // Subject and length come from the block, never from the student.
        ->and($mine->topic)->toBe('Office hours')
        ->and($mine->duration_minutes)->toBe(30)
        ->and($mine->message)->toBeNull()
        ->and($mine->preferred_at)->toBeNull()
        ->and($mine->status)->toBe(MentorSession::STATUS_PENDING)
        // Each student only ever works with their own session on the block.
        ->and($block->sessionsFor($student)->pluck('id')->all())->toBe([$mine->id])
        ->and($block->sessionsFor($classmate)->pluck('id')->all())
        ->not->toBe([$mine->id]);
});

it('books instantly on a time the mentor published', function () {
    $slot = now()->addDays(3)->setTime(14, 0);

    ['student' => $student, 'block' => $block] = seedSessionBlock([
        'meeting_link' => 'https://meet.example.com/office',
        'available_slots' => [$slot->format('Y-m-d H:i:00')],
    ]);

    Livewire::actingAs($student)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->set('chosenSlot', $slot->format('Y-m-d H:i:00'))
        ->call('bookSlot')
        ->assertHasNoErrors();

    $session = MentorSession::first();

    expect($session->status)->toBe(MentorSession::STATUS_SCHEDULED)
        ->and($session->topic)->toBe('Office hours')
        ->and($session->scheduled_at->format('Y-m-d H:i'))->toBe($slot->format('Y-m-d H:i'))
        ->and($session->meeting_link)->toBe('https://meet.example.com/office')
        // One-to-one: the slot is gone for everyone else now.
        ->and($block->openSlots()->count())->toBe(0);
});

it('refuses a time that was never published or has already gone', function () {
    $slot = now()->addDays(3)->setTime(14, 0);

    ['student' => $student, 'classmate' => $classmate, 'block' => $block] = seedSessionBlock([
        'available_slots' => [$slot->format('Y-m-d H:i:00')],
    ]);

    Livewire::actingAs($student)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->set('chosenSlot', now()->addDays(9)->format('Y-m-d H:i:00'))
        ->call('bookSlot')
        ->assertHasErrors('chosenSlot');

    // Taken by someone else between the page rendering and the click.
    MentorSession::create([
        'course_id' => $block->course()->id,
        'session_content_id' => $block->id,
        'student_id' => $classmate->id,
        'mentor_id' => $block->course()->created_by,
        'topic' => 'Mine first',
        'duration_minutes' => 30,
        'status' => MentorSession::STATUS_SCHEDULED,
        'scheduled_at' => $slot,
    ]);

    Livewire::actingAs($student)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->set('chosenSlot', $slot->format('Y-m-d H:i:00'))
        ->call('bookSlot')
        ->assertHasErrors('chosenSlot');

    expect(MentorSession::forStudent($student)->count())->toBe(0);
});

it('runs the whole request cycle inside the block', function () {
    ['student' => $student, 'mentor' => $mentor, 'block' => $block] = seedSessionBlock();

    // 1. The student asks — one click, nothing to fill in.
    Livewire::actingAs($student)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->call('requestSession');

    $session = MentorSession::first();

    // 2. Nothing for them to do but wait: no time of their own to propose.
    expect($session->status)->toBe(MentorSession::STATUS_PENDING)
        ->and($session->preferred_at)->toBeNull();

    Livewire::actingAs($student)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->assertSee('Waiting on')
        ->assertDontSee('What do you want to cover?')
        ->assertDontSee('Preferred time');

    // 3. The mentor offers times from the same block.
    $chosen = now()->addDays(4)->setTime(9, 30);

    Livewire::actingAs($mentor)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->call('respondTo', $session->id)
        ->set('slotInputs', [$chosen->format('Y-m-d\TH:i')])
        ->set('meetingLink', 'https://meet.example.com/abc')
        ->call('proposeSlots')
        ->assertHasNoErrors();

    expect($session->refresh()->status)->toBe(MentorSession::STATUS_PROPOSED);

    // 4. The student books by picking one.
    Livewire::actingAs($student)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->set('selectedSlots.' . $session->id, $chosen->format('Y-m-d H:i:00'))
        ->call('confirmSlot', $session->id)
        ->assertHasNoErrors();

    $session->refresh();

    expect($session->status)->toBe(MentorSession::STATUS_SCHEDULED)
        ->and($session->scheduled_at->format('Y-m-d H:i'))->toBe($chosen->format('Y-m-d H:i'))
        ->and($session->meeting_link)->toBe('https://meet.example.com/abc');

    // 5. It happened; the mentor closes it off.
    Livewire::actingAs($mentor)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->call('completeSession', $session->id);

    expect($session->refresh()->status)->toBe(MentorSession::STATUS_COMPLETED);
});

it('lets the student cancel their own session and book again afterwards', function () {
    ['student' => $student, 'block' => $block] = seedSessionBlock();

    Livewire::actingAs($student)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->call('requestSession');

    $session = MentorSession::first();

    // One open session at a time, unless the block allows repeats.
    expect($block->canBeBookedBy($student))->toBeFalse();

    Livewire::actingAs($student)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->call('cancelSession', $session->id);

    expect($session->refresh()->status)->toBe(MentorSession::STATUS_CANCELLED)
        ->and($block->canBeBookedBy($student))->toBeTrue();

    // A closed session stays closed.
    Livewire::actingAs($student)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->call('cancelSession', $session->id)
        ->assertForbidden();
});

it('keeps each person to their own side of the session', function () {
    ['student' => $student, 'classmate' => $classmate, 'mentor' => $mentor, 'block' => $block] = seedSessionBlock();

    Livewire::actingAs($student)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->call('requestSession');

    $session = MentorSession::first();

    // A classmate cannot touch or even see someone else's session on the block.
    expect(fn () => Livewire::actingAs($classmate)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->call('cancelSession', $session->id))->toThrow(ModelNotFoundException::class);

    Livewire::actingAs($classmate)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->assertDontSee('Student Sam');

    // The student cannot answer as the mentor, and the mentor cannot pick for the student.
    expect(fn () => Livewire::actingAs($student)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->call('respondTo', $session->id))->toThrow(ModelNotFoundException::class);

    expect(fn () => Livewire::actingAs($mentor)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->call('confirmSlot', $session->id))->toThrow(ModelNotFoundException::class);

    // The mentor does see it, on their side of the block.
    Livewire::actingAs($mentor)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->assertSee('Student Sam');
});

it('keeps the block out of reach of people outside the course', function () {
    ['outsider' => $outsider, 'block' => $block] = seedSessionBlock();

    Livewire::actingAs($outsider)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->assertForbidden();
});

it('closes bookings when the owner switches them off, without touching booked sessions', function () {
    ['student' => $student, 'block' => $block] = seedSessionBlock();

    Livewire::actingAs($student)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->call('requestSession');

    $block->update(['is_booking_enabled' => false]);

    expect($block->fresh()->canBeBookedBy($student))->toBeFalse();

    Livewire::actingAs($student)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->call('requestSession')
        ->assertForbidden();

    // The session booked before the switch is still there and still theirs to manage.
    $session = MentorSession::first();

    Livewire::actingAs($student)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->call('cancelSession', $session->id);

    expect($session->refresh()->status)->toBe(MentorSession::STATUS_CANCELLED);
});

it('allows repeat bookings when the block says so', function () {
    ['student' => $student, 'block' => $block] = seedSessionBlock(['allow_multiple' => true]);

    foreach (range(1, 2) as $ignored) {
        Livewire::actingAs($student)->test('sessions.content-panel', ['sessionContent' => $block->id])
            ->call('requestSession')
            ->assertHasNoErrors();
    }

    expect(MentorSession::forStudent($student)->count())->toBe(2);
});

it('never lets the course owner book a session with themselves', function () {
    ['mentor' => $mentor, 'block' => $block] = seedSessionBlock();

    expect($block->canBeBookedBy($mentor))->toBeFalse();

    Livewire::actingAs($mentor)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->call('requestSession')
        ->assertForbidden();
});

it('is created and edited from the content form by the course owner', function () {
    ['mentor' => $mentor, 'student' => $student, 'moduleContent' => $moduleContent] = seedSessionBlock();
    $slot = now()->addDays(5)->setTime(11, 0);

    Livewire::actingAs($mentor)->test('create-content-form', ['moduleContentId' => $moduleContent->id])
        ->set('type', 'session')
        ->set('label', 'Weekly check-in')
        ->set('sessionDescription', 'Bring your questions')
        ->set('sessionDuration', 45)
        ->set('sessionMeetingLink', 'https://meet.example.com/weekly')
        ->set('sessionSlots', [$slot->format('Y-m-d\TH:i'), now()->subDay()->format('Y-m-d\TH:i')])
        ->call('save')
        // Past times are refused rather than silently stored.
        ->assertHasErrors('sessionSlots.1')
        ->set('sessionSlots', [$slot->format('Y-m-d\TH:i')])
        ->call('save')
        ->assertHasNoErrors();

    $block = SessionContent::where('title', 'Weekly check-in')->first();

    expect($block->duration_minutes)->toBe(45)
        ->and($block->meeting_link)->toBe('https://meet.example.com/weekly')
        ->and($block->is_booking_enabled)->toBeTrue()
        ->and($block->slots()->map->format('Y-m-d H:i')->all())->toBe([$slot->format('Y-m-d H:i')])
        ->and($block->moduleContent()->id)->toBe($moduleContent->id);

    // Editing loads the block back and keeps it as one content, not a second one.
    Livewire::actingAs($mentor)->test('create-content-form', [
        'moduleContentId' => $moduleContent->id,
        'contentId' => $block->content->id,
    ])
        ->assertSet('type', 'session')
        ->assertSet('sessionDuration', 45)
        ->set('sessionBookingEnabled', false)
        ->call('save')
        ->assertHasNoErrors();

    expect($block->refresh()->is_booking_enabled)->toBeFalse()
        ->and(SessionContent::count())->toBe(2); // the seeded block plus this one

    // A student cannot open the form for someone else's course.
    Livewire::actingAs($student)->test('create-content-form', ['moduleContentId' => $moduleContent->id])
        ->assertForbidden();
});

it('renders the block inside the lesson it was added to', function () {
    ['student' => $student, 'moduleContent' => $moduleContent] = seedSessionBlock();

    $this->actingAs($student)->get(route('content.show', $moduleContent->id))
        ->assertOk()
        ->assertSee('One-to-one session')
        ->assertSee('View session');
});
