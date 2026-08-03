<?php

use App\Models\{Classroom, Content, Course, LiveClassContent, Module, ModuleContent, User};
use Livewire\Livewire;

function seedLiveClass(?Carbon\Carbon $startsAt = null): array
{
    $owner = User::factory()->create();
    $attendee = User::factory()->create();
    $outsider = User::factory()->create();

    $classroom = Classroom::create(['title' => 'Class A', 'admin_id' => $owner->id]);
    $classroom->users()->attach($attendee->id);

    $course = Course::create(['title' => 'Course A', 'slug' => 'course-a-' . $owner->id, 'created_by' => $owner->id]);
    $classroom->courses()->attach($course->id);

    $module = Module::create(['course_id' => $course->id, 'title' => 'M1', 'slug' => 'm1-' . $course->id, 'sort_order' => 1]);
    $moduleContent = ModuleContent::create(['module_id' => $module->id, 'label' => 'Lesson', 'sort_order' => 1]);

    $liveClass = LiveClassContent::create([
        'title' => 'Algebra Recap',
        'description' => 'Bring questions; semicolons, commas and all.',
        'join_link' => 'https://meet.example.com/algebra',
        'starts_at' => $startsAt ?? now()->addDays(2)->setTime(14, 0),
        'duration_minutes' => 90,
    ]);

    $content = Content::create([
        'contentable_type' => LiveClassContent::class,
        'contentable_id' => $liveClass->id,
    ]);
    $moduleContent->contents()->attach($content->id, ['sort_order' => 1]);

    return compact('owner', 'attendee', 'outsider', 'course', 'moduleContent', 'liveClass');
}

it('serves an ics file to anyone who can see the course', function () {
    ['attendee' => $attendee, 'liveClass' => $liveClass] = seedLiveClass();

    $response = $this->actingAs($attendee)->get(route('live-class.ics', $liveClass->id));

    $response->assertOk();
    $response->assertHeader('content-type', 'text/calendar; charset=utf-8');

    $ics = $response->getContent();

    expect($ics)
        ->toContain('BEGIN:VCALENDAR')
        ->toContain('SUMMARY:Algebra Recap')
        ->toContain('DTSTART:' . $liveClass->starts_at->copy()->utc()->format('Ymd\THis\Z'))
        ->toContain('DTEND:' . $liveClass->endsAt()->copy()->utc()->format('Ymd\THis\Z'))
        ->toContain('LOCATION:https://meet.example.com/algebra')
        // RFC 5545 requires commas and semicolons in text values to be escaped.
        ->toContain('semicolons\, commas')
        ->toContain('END:VCALENDAR');
});

it('refuses the ics file to users outside the course', function () {
    ['outsider' => $outsider, 'liveClass' => $liveClass] = seedLiveClass();

    $this->actingAs($outsider)
        ->get(route('live-class.ics', $liveClass->id))
        ->assertForbidden();
});

it('lists upcoming live classes in the sidebar notifications for course members only', function () {
    ['owner' => $owner, 'attendee' => $attendee, 'outsider' => $outsider] = seedLiveClass();

    Livewire::actingAs($owner)->test('notifications')->assertSee('Algebra Recap');
    Livewire::actingAs($attendee)->test('notifications')->assertSee('Algebra Recap');

    Livewire::actingAs($outsider)->test('notifications')
        ->assertDontSee('Algebra Recap')
        ->assertSee("You're all caught up", false);
});

it('drops finished live classes from the notifications feed', function () {
    ['attendee' => $attendee] = seedLiveClass(now()->subHours(5));

    Livewire::actingAs($attendee)->test('notifications')->assertDontSee('Algebra Recap');
});

it('counts a class starting within a day as urgent but not one next week', function () {
    ['attendee' => $attendee] = seedLiveClass(now()->addHours(3));
    Livewire::actingAs($attendee)->test('notifications')->assertSet('urgentCount', 1);

    ['attendee' => $later] = seedLiveClass(now()->addDays(9));
    Livewire::actingAs($later)->test('notifications')->assertSet('urgentCount', 0);
});
