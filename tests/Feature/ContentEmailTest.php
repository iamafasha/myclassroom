<?php

use App\Jobs\NotifyClassOfNewContent;
use App\Mail\CourseContentPublished;
use App\Mail\MentorSessionBooked;
use App\Models\{Classroom, Content, Course, LiveClassContent, MentorSession, Module, ModuleContent, SessionContent, User};
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/** A course taught in one class, with a member, the class admin and an outsider. */
function seedNotifiableCourse(): array
{
    $n = Course::count() + 1;

    $owner = User::factory()->create(['name' => 'Owner Ona', 'email' => 'owner' . $n . '@example.com']);
    $student = User::factory()->create(['name' => 'Student Sam', 'email' => 'student' . $n . '@example.com']);
    $outsider = User::factory()->create(['email' => 'outsider' . $n . '@example.com']);

    $classroom = Classroom::create(['title' => 'Class ' . $n, 'admin_id' => $owner->id]);
    $classroom->users()->attach($student->id);

    $course = Course::create(['title' => 'Course ' . $n, 'slug' => 'course-' . $n, 'created_by' => $owner->id]);
    $classroom->courses()->attach($course->id);

    $module = Module::create(['course_id' => $course->id, 'title' => 'M' . $n, 'slug' => 'm-' . $n, 'sort_order' => 1]);
    $moduleContent = ModuleContent::create(['module_id' => $module->id, 'label' => 'Lesson', 'sort_order' => 1]);

    return compact('owner', 'student', 'outsider', 'course', 'classroom', 'moduleContent');
}

it('queues an announcement when content is added, but not when it is edited', function () {
    Queue::fake();

    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedNotifiableCourse();

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id])
        ->set('type', 'note')
        ->set('label', 'Chapter one')
        ->set('noteText', 'Read this first')
        ->call('save')
        ->assertHasNoErrors();

    $content = $moduleContent->contents()->first();

    Queue::assertPushed(
        NotifyClassOfNewContent::class,
        fn ($job) => $job->moduleContentId === $moduleContent->id
            && $job->contentId === $content->id
            && $job->actorId === $owner->id
    );

    // Editing existing content is not news.
    Livewire::actingAs($owner)->test('create-content-form', [
        'moduleContentId' => $moduleContent->id,
        'contentId' => $content->id,
    ])
        ->set('noteText', 'Read this first, carefully')
        ->call('save')
        ->assertHasNoErrors();

    Queue::assertPushed(NotifyClassOfNewContent::class, 1);
});

it('emails the class members and admin, but not the author or outsiders', function () {
    Mail::fake();

    ['owner' => $owner, 'student' => $student, 'outsider' => $outsider, 'moduleContent' => $moduleContent] = seedNotifiableCourse();
    $mate = User::factory()->create(['email' => 'mate@example.com']);
    $moduleContent->module->course->classrooms->first()->users()->attach($mate->id);

    $content = attachNoteTo($moduleContent);

    (new NotifyClassOfNewContent($moduleContent->id, $content->id, $owner->id))->handle();

    Mail::assertQueued(CourseContentPublished::class, fn ($mail) => $mail->hasTo($student->email));
    Mail::assertQueued(CourseContentPublished::class, fn ($mail) => $mail->hasTo($mate->email));

    // The author already knows; nobody outside the class hears about it.
    Mail::assertNotQueued(CourseContentPublished::class, fn ($mail) => $mail->hasTo($owner->email));
    Mail::assertNotQueued(CourseContentPublished::class, fn ($mail) => $mail->hasTo($outsider->email));
    Mail::assertQueued(CourseContentPublished::class, 2);
});

it('attaches a calendar invite for a live class only', function () {
    ['owner' => $owner, 'student' => $student, 'moduleContent' => $moduleContent] = seedNotifiableCourse();

    $liveClass = LiveClassContent::create([
        'title' => 'Algebra Recap',
        'starts_at' => now()->addDays(2)->setTime(14, 0),
        'duration_minutes' => 90,
        'join_link' => 'https://meet.example.com/algebra',
        'is_join_enabled' => true,
    ]);

    $liveMail = new CourseContentPublished($moduleContent, $liveClass, $student);

    $liveMail->assertHasAttachedData($liveClass->toIcs(), 'algebra-recap.ics', ['mime' => 'text/calendar']);

    expect($liveClass->toIcs())
        ->toContain('BEGIN:VCALENDAR')
        ->toContain('SUMMARY:Algebra Recap');

    // A session block has no time yet, so it carries no invite.
    $block = SessionContent::create(['title' => 'Office hours', 'duration_minutes' => 30]);

    expect((new CourseContentPublished($moduleContent, $block, $student))->attachments())->toBeEmpty();
});

it('emails both sides with an invite once a session gets a time', function () {
    Mail::fake();

    ['owner' => $mentor, 'student' => $student, 'course' => $course] = seedNotifiableCourse();
    $slot = now()->addDays(3)->setTime(15, 0);

    $session = MentorSession::create([
        'course_id' => $course->id,
        'student_id' => $student->id,
        'mentor_id' => $mentor->id,
        'topic' => 'Loops',
        'duration_minutes' => 30,
        'status' => MentorSession::STATUS_PROPOSED,
        'proposed_slots' => [$slot->format('Y-m-d H:i:00')],
    ]);

    // Nothing goes out while it is still waiting on a time.
    Mail::assertNothingQueued();

    Livewire::actingAs($student)->test('sessions.index')
        ->set('selectedSlots.' . $session->id, $slot->format('Y-m-d H:i:00'))
        ->call('confirmSlot', $session->id)
        ->assertHasNoErrors();

    expect($session->refresh()->status)->toBe(MentorSession::STATUS_SCHEDULED);

    Mail::assertQueued(MentorSessionBooked::class, fn ($mail) => $mail->hasTo($student->email));
    Mail::assertQueued(MentorSessionBooked::class, fn ($mail) => $mail->hasTo($mentor->email));
    Mail::assertQueued(MentorSessionBooked::class, 2);
});

it('carries the booked slot in the session invite', function () {
    ['owner' => $mentor, 'student' => $student, 'course' => $course] = seedNotifiableCourse();
    $slot = now()->addDays(3)->setTime(15, 0);

    $session = MentorSession::create([
        'course_id' => $course->id,
        'student_id' => $student->id,
        'mentor_id' => $mentor->id,
        'topic' => 'Loops; arrays, too',
        'duration_minutes' => 45,
        'status' => MentorSession::STATUS_SCHEDULED,
        'scheduled_at' => $slot,
        'meeting_link' => 'https://meet.example.com/loops',
    ]);

    $mail = new MentorSessionBooked($session, $student);

    $mail->assertSeeInHtml('Your session is booked');
    $mail->assertSeeInHtml($slot->format('l, j F Y'));
    $mail->assertHasAttachedData($session->toIcs(), 'loops-arrays-too.ics', ['mime' => 'text/calendar']);

    expect($session->toIcs())
        ->toContain('BEGIN:VCALENDAR')
        ->toContain('DTSTART:' . $slot->copy()->utc()->format('Ymd\THis\Z'))
        ->toContain('DTEND:' . $session->endsAt()->copy()->utc()->format('Ymd\THis\Z'))
        ->toContain('LOCATION:https://meet.example.com/loops')
        // RFC 5545 escaping, same as the live class invite.
        ->toContain('SUMMARY:Loops\; arrays\, too')
        ->toContain('END:VCALENDAR');
});

it('books from inside the session block and mails both sides', function () {
    Mail::fake();

    ['owner' => $mentor, 'student' => $student, 'moduleContent' => $moduleContent] = seedNotifiableCourse();
    $slot = now()->addDays(4)->setTime(9, 0);

    $block = SessionContent::create([
        'title' => 'Office hours',
        'duration_minutes' => 30,
        'available_slots' => [$slot->format('Y-m-d H:i:00')],
        'meeting_link' => 'https://meet.example.com/office',
    ]);

    $content = Content::create(['contentable_type' => SessionContent::class, 'contentable_id' => $block->id]);
    $moduleContent->contents()->attach($content->id, ['sort_order' => 1]);

    Livewire::actingAs($student)->test('sessions.content-panel', ['sessionContent' => $block->id])
        ->set('chosenSlot', $slot->format('Y-m-d H:i:00'))
        ->call('bookSlot')
        ->assertHasNoErrors();

    $session = MentorSession::first();

    expect($session->status)->toBe(MentorSession::STATUS_SCHEDULED)
        ->and($session->meeting_link)->toBe('https://meet.example.com/office');

    Mail::assertQueued(MentorSessionBooked::class, 2);
    Mail::assertQueued(MentorSessionBooked::class, fn ($mail) => $mail->hasTo($mentor->email));
});

function attachNoteTo(ModuleContent $moduleContent): Content
{
    $note = new App\Models\NoteContent();
    $note->content = 'hello';
    $note->save();

    $content = Content::create(['contentable_type' => App\Models\NoteContent::class, 'contentable_id' => $note->id]);
    $moduleContent->contents()->attach($content->id, ['sort_order' => 1]);

    return $content;
}
