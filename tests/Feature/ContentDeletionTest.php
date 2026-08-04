<?php

use App\Models\{Classroom, Content, ContentExerciseAnswer, ContentModuleContent, Course, Module, ModuleContent, NoteContent, User};
use Livewire\Livewire;

function seedDeletableContent(): array
{
    $owner = User::factory()->create();
    $mate = User::factory()->create();

    $classroom = Classroom::create(['title' => 'Class D', 'admin_id' => $owner->id]);
    $classroom->users()->attach($mate->id);

    $course = Course::create(['title' => 'Course D', 'slug' => 'course-d', 'created_by' => $owner->id]);
    $classroom->courses()->attach($course->id);

    $module = Module::create(['course_id' => $course->id, 'title' => 'Module D', 'slug' => 'module-d', 'sort_order' => 1]);
    $moduleContent = ModuleContent::create(['module_id' => $module->id, 'label' => 'Doomed Lesson', 'sort_order' => 1]);

    $note = new NoteContent();
    $note->content = 'reading material';
    $note->save();
    $content = Content::create(['contentable_type' => NoteContent::class, 'contentable_id' => $note->id]);
    $moduleContent->contents()->attach($content->id, ['sort_order' => 1, 'is_exercise' => true]);

    $pivot = ContentModuleContent::where('module_content_id', $moduleContent->id)->first();
    $answer = ContentExerciseAnswer::create([
        'user_id' => $mate->id,
        'content_module_content_id' => $pivot->id,
        'submission_link' => 'https://example.com/answer',
    ]);

    return compact('owner', 'mate', 'course', 'module', 'moduleContent', 'content', 'note', 'pivot', 'answer');
}

it('deletes the content and everything inside it from the content page', function () {
    [
        'owner' => $owner, 'course' => $course, 'module' => $module,
        'moduleContent' => $moduleContent, 'content' => $content, 'note' => $note,
        'pivot' => $pivot, 'answer' => $answer,
    ] = seedDeletableContent();

    Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->assertSee('Delete Content')
        ->call('deleteModuleContent')
        ->assertRedirect(route('course.module.show', ['courseId' => $course->id, 'moduleId' => $module->id]));

    expect(ModuleContent::find($moduleContent->id))->toBeNull()
        ->and(Content::find($content->id))->toBeNull()
        ->and(NoteContent::find($note->id))->toBeNull()
        ->and(ContentModuleContent::find($pivot->id))->toBeNull()
        ->and(ContentExerciseAnswer::find($answer->id))->toBeNull();
});

it('leaves the rest of the module alone', function () {
    ['owner' => $owner, 'module' => $module, 'moduleContent' => $moduleContent] = seedDeletableContent();

    $keeper = ModuleContent::create(['module_id' => $module->id, 'label' => 'Survivor', 'sort_order' => 2]);

    Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('deleteModuleContent');

    expect(Module::find($module->id))->not->toBeNull()
        ->and(ModuleContent::find($keeper->id))->not->toBeNull();
});

it('deletes a single block without touching the rest of the content', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent, 'content' => $content, 'note' => $note] = seedDeletableContent();

    $extraNote = new NoteContent();
    $extraNote->content = 'second block';
    $extraNote->save();
    $extra = Content::create(['contentable_type' => NoteContent::class, 'contentable_id' => $extraNote->id]);
    $moduleContent->contents()->attach($extra->id, ['sort_order' => 2]);

    Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('deleteContentItem', $extra->id)
        ->assertSee('reading material')
        ->assertDontSee('second block');

    expect(Content::find($extra->id))->toBeNull()
        ->and(NoteContent::find($extraNote->id))->toBeNull()
        ->and(ModuleContent::find($moduleContent->id))->not->toBeNull()
        ->and(Content::find($content->id))->not->toBeNull()
        ->and(NoteContent::find($note->id))->not->toBeNull()
        ->and($moduleContent->fresh()->contents)->toHaveCount(1);
});

it('keeps the content around when its last block is deleted', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent, 'content' => $content] = seedDeletableContent();

    Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('deleteContentItem', $content->id);

    expect(ModuleContent::find($moduleContent->id))->not->toBeNull()
        ->and($moduleContent->fresh()->contents)->toHaveCount(0);
});

it('will not delete a block belonging to another content', function () {
    ['owner' => $owner, 'module' => $module, 'moduleContent' => $moduleContent] = seedDeletableContent();

    $otherModuleContent = ModuleContent::create(['module_id' => $module->id, 'label' => 'Other', 'sort_order' => 2]);
    $otherNote = new NoteContent();
    $otherNote->content = 'somebody else';
    $otherNote->save();
    $otherContent = Content::create(['contentable_type' => NoteContent::class, 'contentable_id' => $otherNote->id]);
    $otherModuleContent->contents()->attach($otherContent->id, ['sort_order' => 1]);

    Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('deleteContentItem', $otherContent->id);

    expect(Content::find($otherContent->id))->not->toBeNull()
        ->and(NoteContent::find($otherNote->id))->not->toBeNull();
});

it('does not let a student delete a block', function () {
    ['mate' => $mate, 'moduleContent' => $moduleContent, 'content' => $content] = seedDeletableContent();

    Livewire::actingAs($mate)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('deleteContentItem', $content->id)
        ->assertForbidden();

    expect(Content::find($content->id))->not->toBeNull();
});

it('does not let a student delete content', function () {
    ['mate' => $mate, 'moduleContent' => $moduleContent] = seedDeletableContent();

    Livewire::actingAs($mate)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->assertDontSee('Delete Content')
        ->call('deleteModuleContent')
        ->assertForbidden();

    expect(ModuleContent::find($moduleContent->id))->not->toBeNull();
});
