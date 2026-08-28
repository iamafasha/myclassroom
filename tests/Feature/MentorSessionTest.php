<?php

use App\Models\{Classroom, Course, MentorSession, User};
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

function seedMentorSession(): array
{
    // Unique per call, so a test may seed more than one mentor/course pair.
    $n = Course::count() + 1;

    $mentor = User::factory()->create(['name' => 'Mentor Mo']);
    $student = User::factory()->create(['name' => 'Student Sam']);
    $outsider = User::factory()->create();

    $classroom = Classroom::create(['title' => 'Class ' . $n, 'admin_id' => $mentor->id]);
    $classroom->users()->attach($student->id);

    $course = Course::create(['title' => 'Course ' . $n, 'slug' => 'course-' . $n, 'created_by' => $mentor->id]);
    $classroom->courses()->attach($course->id);

    return compact('mentor', 'student', 'outsider', 'classroom', 'course');
}

function makeSession(array $overrides = []): MentorSession
{
    ['mentor' => $mentor, 'student' => $student, 'course' => $course] = seedMentorSession();

    return MentorSession::create(array_merge([
        'course_id' => $course->id,
        'student_id' => $student->id,
        'mentor_id' => $mentor->id,
        'topic' => 'Loops',
        'duration_minutes' => 30,
        'status' => MentorSession::STATUS_PENDING,
    ], $overrides));
}

it('lets a student request a session with the course owner', function () {
    ['student' => $student, 'mentor' => $mentor, 'course' => $course] = seedMentorSession();

    Livewire::actingAs($student)->test('sessions.index')
        ->set('courseId', $course->id)
        ->set('topic', 'Help with loops')
        ->set('message', 'Stuck on the exercise')
        ->set('preferredAt', now()->addDays(2)->format('Y-m-d\TH:i'))
        ->set('durationMinutes', 45)
        ->call('requestSession')
        ->assertHasNoErrors();

    $session = MentorSession::first();

    expect($session->student_id)->toBe($student->id)
        ->and($session->mentor_id)->toBe($mentor->id)
        ->and($session->course_id)->toBe($course->id)
        ->and($session->duration_minutes)->toBe(45)
        ->and($session->status)->toBe(MentorSession::STATUS_PENDING);
});

it('does not offer courses you own or cannot see', function () {
    ['mentor' => $mentor, 'student' => $student, 'outsider' => $outsider, 'course' => $course] = seedMentorSession();

    expect(Course::sessionRequestableBy($student)->pluck('id')->all())->toBe([$course->id])
        ->and(Course::sessionRequestableBy($mentor)->count())->toBe(0)
        ->and(Course::sessionRequestableBy($outsider)->count())->toBe(0);
});

it('rejects a request for a course the student cannot see', function () {
    ['outsider' => $outsider, 'course' => $course] = seedMentorSession();

    expect(fn () => Livewire::actingAs($outsider)->test('sessions.index')
        ->set('courseId', $course->id)
        ->set('topic', 'Sneaky')
        ->call('requestSession'))->toThrow(ModelNotFoundException::class);

    expect(MentorSession::count())->toBe(0);
});

it('requires a topic and a future preferred time', function () {
    ['student' => $student, 'course' => $course] = seedMentorSession();

    Livewire::actingAs($student)->test('sessions.index')
        ->set('courseId', $course->id)
        ->set('topic', '')
        ->set('preferredAt', now()->subDay()->format('Y-m-d\TH:i'))
        ->call('requestSession')
        ->assertHasErrors(['topic', 'preferredAt']);
});

it('lets the mentor offer times, moving the session to the student', function () {
    $session = makeSession();
    $first = now()->addDays(2)->setTime(10, 0);
    $second = now()->addDays(3)->setTime(14, 30);

    Livewire::actingAs($session->mentor)->test('sessions.index')
        ->call('respondTo', $session->id)
        ->set('slotInputs', [$second->format('Y-m-d\TH:i'), $first->format('Y-m-d\TH:i')])
        ->set('meetingLink', 'https://meet.example.com/abc')
        ->call('proposeSlots')
        ->assertHasNoErrors();

    $session->refresh();

    expect($session->status)->toBe(MentorSession::STATUS_PROPOSED)
        ->and($session->scheduled_at)->toBeNull()
        ->and($session->meeting_link)->toBe('https://meet.example.com/abc')
        // Stored sorted, earliest first, regardless of the order they were typed.
        ->and($session->slots()->map->format('Y-m-d H:i')->all())
        ->toBe([$first->format('Y-m-d H:i'), $second->format('Y-m-d H:i')]);
});

it('refuses times in the past and empty slot lists', function () {
    $session = makeSession();

    Livewire::actingAs($session->mentor)->test('sessions.index')
        ->call('respondTo', $session->id)
        ->set('slotInputs', [now()->subDay()->format('Y-m-d\TH:i')])
        ->call('proposeSlots')
        ->assertHasErrors('slotInputs.0');

    Livewire::actingAs($session->mentor)->test('sessions.index')
        ->call('respondTo', $session->id)
        ->set('slotInputs', ['', ''])
        ->call('proposeSlots')
        ->assertHasErrors('slotInputs');

    expect($session->refresh()->status)->toBe(MentorSession::STATUS_PENDING);
});

it('books the session on the slot the student picks', function () {
    $chosen = now()->addDays(3)->setTime(14, 30);

    $session = makeSession([
        'status' => MentorSession::STATUS_PROPOSED,
        'proposed_slots' => [
            now()->addDays(2)->setTime(10, 0)->format('Y-m-d H:i:00'),
            $chosen->format('Y-m-d H:i:00'),
        ],
    ]);

    Livewire::actingAs($session->student)->test('sessions.index')
        ->set('selectedSlots.' . $session->id, $chosen->format('Y-m-d H:i:00'))
        ->call('confirmSlot', $session->id)
        ->assertHasNoErrors();

    $session->refresh();

    expect($session->status)->toBe(MentorSession::STATUS_SCHEDULED)
        ->and($session->scheduled_at->format('Y-m-d H:i'))->toBe($chosen->format('Y-m-d H:i'));
});

it('refuses a time the mentor never offered', function () {
    $session = makeSession([
        'status' => MentorSession::STATUS_PROPOSED,
        'proposed_slots' => [now()->addDays(2)->setTime(10, 0)->format('Y-m-d H:i:00')],
    ]);

    Livewire::actingAs($session->student)->test('sessions.index')
        ->set('selectedSlots.' . $session->id, now()->addDays(9)->format('Y-m-d H:i:00'))
        ->call('confirmSlot', $session->id)
        ->assertHasErrors('selectedSlots.' . $session->id);

    expect($session->refresh()->status)->toBe(MentorSession::STATUS_PROPOSED);
});

it('drops offered times that have already passed', function () {
    $future = now()->addDays(2)->setTime(10, 0);

    $session = makeSession([
        'status' => MentorSession::STATUS_PROPOSED,
        'proposed_slots' => [
            now()->subDay()->format('Y-m-d H:i:00'),
            $future->format('Y-m-d H:i:00'),
        ],
    ]);

    expect($session->slots()->count())->toBe(1)
        ->and($session->hasSlot(now()->subDay()->format('Y-m-d H:i:00')))->toBeFalse()
        ->and($session->hasSlot($future->format('Y-m-d H:i:00')))->toBeTrue();
});

it('keeps each side to its own actions', function () {
    $session = makeSession([
        'status' => MentorSession::STATUS_PROPOSED,
        'proposed_slots' => [now()->addDays(2)->setTime(10, 0)->format('Y-m-d H:i:00')],
    ]);

    // The mentor cannot pick on the student's behalf.
    expect(fn () => Livewire::actingAs($session->mentor)->test('sessions.index')
        ->call('confirmSlot', $session->id))->toThrow(ModelNotFoundException::class);

    // The student cannot offer times or decline their own request as the mentor.
    expect(fn () => Livewire::actingAs($session->student)->test('sessions.index')
        ->call('respondTo', $session->id))->toThrow(ModelNotFoundException::class);

    expect(fn () => Livewire::actingAs($session->student)->test('sessions.index')
        ->call('declineSession', $session->id))->toThrow(ModelNotFoundException::class);

    expect($session->refresh()->status)->toBe(MentorSession::STATUS_PROPOSED);
});

it('lets the student cancel and the mentor decline while the session is open', function () {
    $toCancel = makeSession();

    Livewire::actingAs($toCancel->student)->test('sessions.index')
        ->call('cancelRequest', $toCancel->id);

    expect($toCancel->refresh()->status)->toBe(MentorSession::STATUS_CANCELLED);

    // A closed session stays closed.
    Livewire::actingAs($toCancel->student)->test('sessions.index')
        ->call('cancelRequest', $toCancel->id)
        ->assertForbidden();

    $toDecline = makeSession();

    Livewire::actingAs($toDecline->mentor)->test('sessions.index')
        ->call('declineSession', $toDecline->id);

    expect($toDecline->refresh()->status)->toBe(MentorSession::STATUS_DECLINED);
});

it('marks a booked session completed once it has happened', function () {
    $session = makeSession([
        'status' => MentorSession::STATUS_SCHEDULED,
        'scheduled_at' => now()->subHours(2),
    ]);

    expect($session->hasPassed())->toBeTrue();

    Livewire::actingAs($session->mentor)->test('sessions.index')
        ->call('completeSession', $session->id);

    expect($session->refresh()->status)->toBe(MentorSession::STATUS_COMPLETED);

    Livewire::actingAs($session->mentor)->test('sessions.index')
        ->call('completeSession', $session->id)
        ->assertForbidden();
});

it('shows each user only their own sessions', function () {
    $session = makeSession(['topic' => 'Private topic']);
    $outsider = User::factory()->create();

    Livewire::actingAs($session->student)->test('sessions.index')->assertSee('Private topic');
    Livewire::actingAs($session->mentor)->test('sessions.index', ['tab' => 'incoming'])->assertSee('Private topic');
    Livewire::actingAs($outsider)->test('sessions.index')->assertDontSee('Private topic');
});

it('opens the request form prefilled from a course link', function () {
    ['student' => $student, 'course' => $course] = seedMentorSession();

    $this->actingAs($student)->get(route('sessions.index', ['course' => $course->id]))
        ->assertOk()
        ->assertSee('Request a Session');

    Livewire::actingAs($student)->test('sessions.index', ['course' => $course->id])
        ->assertSet('showRequestForm', true)
        ->assertSet('courseId', $course->id);
});

it('lets a mentor start a session with a student on their course', function () {
    ['mentor' => $mentor, 'student' => $student, 'course' => $course] = seedMentorSession();

    $slot = now()->addDays(3)->setTime(14, 0)->format('Y-m-d\TH:i');

    Livewire::actingAs($mentor)->test('sessions.index')
        ->call('openInviteForm')
        ->assertSet('showInviteForm', true)
        ->set('inviteCourseId', $course->id)
        ->set('inviteStudentId', $student->id)
        ->set('inviteTopic', 'Review your project plan')
        ->set('inviteSlotInputs', [$slot])
        ->set('inviteDurationMinutes', 45)
        ->set('inviteMeetingLink', 'https://meet.google.com/abc-defg-hij')
        ->call('inviteToSession')
        ->assertHasNoErrors()
        ->assertSet('showInviteForm', false)
        ->assertSet('tab', 'incoming');

    $session = MentorSession::first();

    expect($session->mentor_id)->toBe($mentor->id)
        ->and($session->student_id)->toBe($student->id)
        ->and($session->course_id)->toBe($course->id)
        ->and($session->topic)->toBe('Review your project plan')
        ->and($session->duration_minutes)->toBe(45)
        ->and($session->status)->toBe(MentorSession::STATUS_PROPOSED)
        ->and($session->slots())->toHaveCount(1);
});

it('lets the student book a mentor-initiated session by picking a time', function () {
    ['mentor' => $mentor, 'student' => $student, 'course' => $course] = seedMentorSession();

    $slot = now()->addDays(2)->setTime(9, 0);

    $session = MentorSession::create([
        'course_id' => $course->id,
        'student_id' => $student->id,
        'mentor_id' => $mentor->id,
        'topic' => 'Kickoff',
        'duration_minutes' => 30,
        'status' => MentorSession::STATUS_PROPOSED,
        'proposed_slots' => [$slot->format('Y-m-d H:i:00')],
    ]);

    Livewire::actingAs($student)->test('sessions.index')
        ->set('selectedSlots.' . $session->id, $slot->format('Y-m-d H:i:00'))
        ->call('confirmSlot', $session->id)
        ->assertHasNoErrors();

    expect($session->refresh()->status)->toBe(MentorSession::STATUS_SCHEDULED);
});

it('requires a course, student and future time to start a session', function () {
    ['mentor' => $mentor] = seedMentorSession();

    Livewire::actingAs($mentor)->test('sessions.index')
        ->set('inviteTopic', '')
        ->set('inviteSlotInputs', [now()->subDay()->format('Y-m-d\TH:i')])
        ->call('inviteToSession')
        ->assertHasErrors(['inviteCourseId', 'inviteStudentId', 'inviteTopic', 'inviteSlotInputs.0']);
});

it('will not let a mentor invite someone outside the course classes', function () {
    ['mentor' => $mentor, 'course' => $course, 'outsider' => $outsider] = seedMentorSession();

    expect(fn () => Livewire::actingAs($mentor)->test('sessions.index')
        ->set('inviteCourseId', $course->id)
        ->set('inviteStudentId', $outsider->id)
        ->set('inviteTopic', 'Nope')
        ->set('inviteSlotInputs', [now()->addDay()->format('Y-m-d\TH:i')])
        ->call('inviteToSession'))->toThrow(ModelNotFoundException::class);

    expect(MentorSession::count())->toBe(0);
});

it('hides the start-a-session button from someone who owns no course', function () {
    ['student' => $student] = seedMentorSession();

    Livewire::actingAs($student)->test('sessions.index')
        ->assertDontSee('Start a Session');
});
