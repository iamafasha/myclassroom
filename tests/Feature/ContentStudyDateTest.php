<?php

use App\Models\{Classroom, Content, Course, Module, ModuleContent, NoteContent, User};
use Livewire\Livewire;

function seedStudyDateCourse(): array
{
    $owner = User::factory()->create();
    $mate = User::factory()->create();

    $classroom = Classroom::create(['title' => 'Class S', 'admin_id' => $owner->id]);
    $classroom->users()->attach($mate->id);

    $course = Course::create(['title' => 'Course S', 'slug' => 'course-s', 'created_by' => $owner->id]);
    $classroom->courses()->attach($course->id);

    $module = Module::create(['course_id' => $course->id, 'title' => 'Module S', 'slug' => 'module-s', 'sort_order' => 1]);
    $moduleContent = ModuleContent::create(['module_id' => $module->id, 'label' => 'Chapter One', 'sort_order' => 1]);

    $note = new NoteContent();
    $note->content = 'reading material';
    $note->save();
    $content = Content::create(['contentable_type' => NoteContent::class, 'contentable_id' => $note->id]);
    $moduleContent->contents()->attach($content->id, ['sort_order' => 1]);

    return compact('owner', 'mate', 'course', 'module', 'moduleContent', 'content');
}

it('dates the module from the earliest date across its contents', function () {
    ['owner' => $owner, 'course' => $course, 'module' => $module, 'moduleContent' => $moduleContent] = seedStudyDateCourse();

    $later = ModuleContent::create(['module_id' => $module->id, 'label' => 'Chapter Two', 'sort_order' => 2, 'study_at' => '2026-11-20']);
    $moduleContent->update(['study_at' => '2026-09-15']);

    expect($module->fresh()->startDate()->format('Y-m-d'))->toBe('2026-09-15');

    Livewire::actingAs($owner)->test('classroom-dashboard', ['courseId' => $course->id, 'moduleId' => $module->id])
        ->assertSeeHtml('<div class="module-date">15 September</div>');

    // Pulling the second content earlier drags the module date back with it.
    $later->update(['study_at' => '2026-08-01']);

    expect($module->fresh()->startDate()->format('Y-m-d'))->toBe('2026-08-01');
});

it('falls back to the module date while it has no contents', function () {
    ['module' => $module] = seedStudyDateCourse();

    $empty = Module::create(['course_id' => $module->course_id, 'title' => 'Empty', 'slug' => 'empty', 'sort_order' => 2]);

    expect($empty->startDate()->format('d F'))->toBe($empty->created_at->format('d F'));
});

it('shows the content added date until a start date is planned', function () {
    ['owner' => $owner, 'course' => $course, 'module' => $module, 'moduleContent' => $moduleContent] = seedStudyDateCourse();

    Livewire::actingAs($owner)->test('classroom-dashboard', ['courseId' => $course->id, 'moduleId' => $module->id])
        ->assertSee($moduleContent->created_at->format('d F'))
        ->assertDontSee('Start ' . $moduleContent->created_at->format('d F'));
});

it('lets the course owner plan when a content should be started', function () {
    ['owner' => $owner, 'course' => $course, 'module' => $module, 'moduleContent' => $moduleContent] = seedStudyDateCourse();

    Livewire::actingAs($owner)->test('classroom-dashboard', ['courseId' => $course->id, 'moduleId' => $module->id])
        ->call('editContentDate', $moduleContent->id)
        ->assertSet('showContentDateModal', true)
        ->set('contentStudyDate', '2026-09-15')
        ->call('updateContentDate')
        ->assertSet('showContentDateModal', false)
        ->assertSee('Start 15 September');

    expect($moduleContent->fresh()->study_at->format('Y-m-d'))->toBe('2026-09-15');
});

it('lets the owner clear a planned start date', function () {
    ['owner' => $owner, 'course' => $course, 'module' => $module, 'moduleContent' => $moduleContent] = seedStudyDateCourse();

    $moduleContent->update(['study_at' => '2026-09-15']);

    Livewire::actingAs($owner)->test('classroom-dashboard', ['courseId' => $course->id, 'moduleId' => $module->id])
        ->call('editContentDate', $moduleContent->id)
        ->assertSet('contentStudyDate', '2026-09-15')
        ->call('clearContentDate');

    expect($moduleContent->fresh()->study_at)->toBeNull();
});

it('rejects an invalid start date', function () {
    ['owner' => $owner, 'course' => $course, 'module' => $module, 'moduleContent' => $moduleContent] = seedStudyDateCourse();

    Livewire::actingAs($owner)->test('classroom-dashboard', ['courseId' => $course->id, 'moduleId' => $module->id])
        ->call('editContentDate', $moduleContent->id)
        ->set('contentStudyDate', 'not-a-date')
        ->call('updateContentDate')
        ->assertHasErrors(['contentStudyDate' => 'date']);

    expect($moduleContent->fresh()->study_at)->toBeNull();
});

it('keeps students out of the start date planner', function () {
    ['mate' => $mate, 'course' => $course, 'module' => $module, 'moduleContent' => $moduleContent] = seedStudyDateCourse();

    Livewire::actingAs($mate)->test('classroom-dashboard', ['courseId' => $course->id, 'moduleId' => $module->id])
        ->call('editContentDate', $moduleContent->id)
        ->assertForbidden();

    expect($moduleContent->fresh()->study_at)->toBeNull();
});

it('saves the start date from the content form', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent, 'content' => $content] = seedStudyDateCourse();

    Livewire::actingAs($owner)->test('create-content-form', [
        'moduleContentId' => $moduleContent->id,
        'contentId' => $content->id,
    ])
        ->assertSet('studyAt', '')
        ->set('label', 'Chapter One')
        ->set('noteText', 'reading material')
        ->set('studyAt', '2026-10-01')
        ->call('save');

    expect($moduleContent->fresh()->study_at->format('Y-m-d'))->toBe('2026-10-01');
});

it('shows the planned start date on the content page', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedStudyDateCourse();

    $moduleContent->update(['study_at' => '2026-10-01']);

    $this->actingAs($owner)->get(route('content.show', $moduleContent->id))
        ->assertOk()
        ->assertSee('Start Thu, 1 Oct 2026');
});
