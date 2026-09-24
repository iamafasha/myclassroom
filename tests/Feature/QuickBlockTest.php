<?php

use App\Models\{Content, File, ImageContent, LinkContent, NoteContent, PdfNotesContent, VideoContent};
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(fn () => Queue::fake());

function blockTypes($moduleContent): array
{
    return $moduleContent->fresh()->contents->map(fn ($content) => class_basename($content->contentable))->all();
}

it('picks the block type from what was pasted', function (string $pasted, string $type) {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();

    Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('addFromPaste', $pasted)
        ->assertReturned(null);

    expect(blockTypes($moduleContent))->toBe(['NoteContent', $type]);
})->with([
    'youtube link' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'VideoContent'],
    'short youtube link' => ['https://youtu.be/dQw4w9WgXcQ', 'VideoContent'],
    'video file link' => ['https://cdn.example.com/lecture.mp4', 'VideoContent'],
    'image link' => ['https://cdn.example.com/diagram.PNG', 'ImageContent'],
    'any other link' => ['https://example.com/article', 'LinkContent'],
    'plain text' => ["Read chapter two\nbefore Monday.", 'NoteContent'],
]);

it('names a pasted link after its file or its site', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();

    Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('addFromPaste', 'https://www.example.com/articles/42')
        ->call('addFromPaste', 'https://cdn.example.com/week%20one.mp4');

    expect(LinkContent::first()->name)->toBe('example.com')
        ->and(VideoContent::first()->name)->toBe('week one');
});

it('turns a link it cannot save into a message instead of a block', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();

    Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('addFromPaste', 'https://example.com/' . str_repeat('a', 300))
        ->assertReturned('That link is too long to save.');

    expect(blockTypes($moduleContent))->toBe(['NoteContent']);
});

it('adds an uploaded file as the block its type calls for', function (string $fileType, string $name, string $type) {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();
    $file = File::create(['user_id' => $owner->id, 'name' => $name, 'file_path' => 'uploads/' . $name, 'file_type' => $fileType, 'size' => 10]);

    Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('addFromFile', $file->id);

    expect(blockTypes($moduleContent))->toBe(['NoteContent', $type]);
})->with([
    'pdf' => ['pdf', 'Handout.pdf', 'PdfNotesContent'],
    'video' => ['video', 'Lecture.mp4', 'VideoContent'],
    'image' => ['image', 'Diagram.png', 'ImageContent'],
    'anything else' => ['word', 'Syllabus.docx', 'LinkContent'],
]);

it('shows a dropped pdf whole, named after the file', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();
    $file = File::create(['user_id' => $owner->id, 'name' => 'Week One.pdf', 'file_path' => 'uploads/w1.pdf', 'file_type' => 'pdf', 'size' => 10]);

    Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('addFromFile', $file->id);

    $pdf = PdfNotesContent::first();
    expect($pdf->name)->toBe('Week One')
        ->and($pdf->start_position)->toBeNull()
        ->and($pdf->end_position)->toBeNull()
        ->and((int) $pdf->start_percentage)->toBe(0)
        ->and((int) $pdf->end_percentage)->toBe(100);
});

it('will not add somebody else\'s file', function () {
    ['owner' => $owner, 'mate' => $mate, 'moduleContent' => $moduleContent] = seedCourse();
    $file = File::create(['user_id' => $mate->id, 'name' => 'Theirs.pdf', 'file_path' => 'uploads/t.pdf', 'file_type' => 'pdf', 'size' => 10]);

    expect(fn () => Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('addFromFile', $file->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect(blockTypes($moduleContent))->toBe(['NoteContent']);
});

it('adds a block right where the inserter was', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent, 'content' => $first] = seedCourse();

    $component = Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('addFromPaste', 'https://example.com/last');

    $component->call('addFromPaste', 'https://youtu.be/dQw4w9WgXcQ', $first->id);

    expect(blockTypes($moduleContent))->toBe(['NoteContent', 'VideoContent', 'LinkContent']);
});

it('announces a quick-added block to the class', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();

    Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('addFromPaste', 'https://example.com/article');

    Queue::assertPushed(\App\Jobs\NotifyClassOfNewContent::class);
});

it('keeps students from adding, reordering or retitling', function () {
    ['mate' => $mate, 'moduleContent' => $moduleContent, 'content' => $content] = seedCourse();

    Livewire::actingAs($mate)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('addFromPaste', 'https://example.com')->assertForbidden();
    Livewire::actingAs($mate)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('reorderContentItems', [$content->id])->assertForbidden();
    Livewire::actingAs($mate)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('saveDetails', 'Hijacked', null)->assertForbidden();

    expect($moduleContent->fresh()->label)->toBe('Lesson')
        ->and(blockTypes($moduleContent))->toBe(['NoteContent']);
});

it('reorders blocks in one go', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent, 'content' => $first] = seedCourse();

    $component = Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('addFromPaste', 'https://example.com/article')
        ->call('addFromPaste', 'https://youtu.be/dQw4w9WgXcQ');

    $ids = $moduleContent->fresh()->contents->pluck('id')->all();

    $component->call('reorderContentItems', [$ids[2], $ids[0], $ids[1]]);

    expect(blockTypes($moduleContent))->toBe(['VideoContent', 'NoteContent', 'LinkContent']);
});

it('saves the title and start date from the content page', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();

    Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('saveDetails', '  Week 2: Recursion ', '2026-10-05')
        ->assertReturned(true)
        ->assertSee('Week 2: Recursion');

    $fresh = $moduleContent->fresh();
    expect($fresh->label)->toBe('Week 2: Recursion')
        ->and($fresh->study_at->format('Y-m-d'))->toBe('2026-10-05');
});

it('will not blank out the title', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();

    Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('saveDetails', '   ', null)
        ->assertHasErrors('label');

    expect($moduleContent->fresh()->label)->toBe('Lesson');
});

it('opens a block\'s form in place and closes it when done', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent, 'content' => $content] = seedCourse();

    Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('editContentItem', $content->id)
        ->assertSet('editingContentId', $content->id)
        ->assertSee('Editing this block')
        ->dispatch('content-editor-closed')
        ->assertSet('editingContentId', null)
        ->assertDontSee('Editing this block');
});

it('opens a new block\'s form where it will appear', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent, 'content' => $content] = seedCourse();

    Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('startAdding', 'quiz', $content->id)
        ->assertSet('addingType', 'quiz')
        ->assertSet('addingAfter', $content->id)
        ->assertSee('New block')
        ->call('startAdding', 'not-a-type')
        ->assertSet('addingType', 'quiz');
});
