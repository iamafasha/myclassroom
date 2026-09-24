<?php

use App\Models\{Content, File, NoteContent, PdfNotesContent};
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(fn () => Queue::fake());

function seedPdfBlock($owner, $moduleContent, array $attributes = []): array
{
    $file = File::create(['user_id' => $owner->id, 'name' => 'Book.pdf', 'file_path' => 'uploads/book.pdf', 'file_type' => 'pdf', 'size' => 10]);

    $pdf = new PdfNotesContent();
    $pdf->forceFill(array_merge([
        'name' => 'Book',
        'file_url' => asset('storage/uploads/book.pdf'),
        'start_percentage' => 0,
        'end_percentage' => 100,
    ], $attributes))->save();

    $content = Content::create(['contentable_type' => PdfNotesContent::class, 'contentable_id' => $pdf->id]);
    $moduleContent->contents()->attach($content->id, ['sort_order' => 2]);

    return compact('file', 'pdf', 'content');
}

it('autosaves an edited block without leaving the page', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent, 'content' => $content] = seedCourse();

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id, 'contentId' => $content->id])
        ->set('noteText', 'rewritten on the fly')
        ->call('autosave')
        ->assertNoRedirect()
        ->assertSee('Saved at')
        ->assertNotDispatched('content-editor-closed');

    expect(NoteContent::find($content->contentable_id)->content)->toBe('rewritten on the fly');
});

it('does not autosave a change that does not validate', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent, 'content' => $content] = seedCourse();

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id, 'contentId' => $content->id])
        ->set('noteText', '')
        ->call('autosave')
        ->assertHasErrors('noteText')
        ->assertSee('Not saved');

    expect(NoteContent::find($content->contentable_id)->content)->toBe('hello');
});

it('never autosaves a block that does not exist yet', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id])
        ->set('noteText', 'draft')
        ->call('autosave');

    expect($moduleContent->fresh()->contents)->toHaveCount(1);
});

it('leaves the lesson title alone when a block is edited in place', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent, 'content' => $content] = seedCourse();

    $form = Livewire::actingAs($owner)->test('create-content-form', [
        'moduleContentId' => $moduleContent->id, 'contentId' => $content->id, 'inline' => true,
    ]);

    // Retitled from the page header while the block's form was open.
    $moduleContent->update(['label' => 'Renamed meanwhile']);

    $form->set('noteText', 'edited')->call('done')->assertDispatched('content-editor-closed')->assertNoRedirect();

    expect($moduleContent->fresh()->label)->toBe('Renamed meanwhile');
});

it('inserts a block added from the form after the chosen one', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent, 'content' => $first] = seedCourse();

    $last = Content::create(['contentable_type' => NoteContent::class, 'contentable_id' => NoteContent::forceCreate(['content' => 'last'])->id]);
    $moduleContent->contents()->attach($last->id, ['sort_order' => 2]);

    Livewire::actingAs($owner)->test('create-content-form', [
        'moduleContentId' => $moduleContent->id, 'type' => 'note', 'insertAfter' => $first->id, 'inline' => true,
    ])
        ->set('noteText', 'middle')
        ->call('save')
        ->assertDispatched('content-editor-closed');

    expect($moduleContent->fresh()->contents->map(fn ($c) => $c->contentable->content)->all())
        ->toBe(['hello', 'middle', 'last']);
});

it('rejects a last page before the first page', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();
    ['content' => $content] = seedPdfBlock($owner, $moduleContent);

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id, 'contentId' => $content->id])
        ->set('pdfStartPage', '9')
        ->set('pdfEndPage', '3')
        ->call('save')
        ->assertHasErrors('pdfEndPage');
});

it('rejects pages past the end of the document once its length is known', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();
    ['content' => $content] = seedPdfBlock($owner, $moduleContent);

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id, 'contentId' => $content->id])
        ->set('pdfPageCount', 12)
        ->set('pdfEndPage', '40')
        ->call('save')
        ->assertHasErrors(['pdfEndPage' => 'max']);
});

it('keeps crop controls hidden until cropping is switched on', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();
    ['content' => $content] = seedPdfBlock($owner, $moduleContent);

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id, 'contentId' => $content->id])
        ->assertSet('pdfCropEnabled', false)
        ->assertDontSee('First page starts at')
        ->set('pdfCropEnabled', true)
        ->assertSee('First page starts at');
});

it('opens with cropping on for a pdf that is already cropped', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();
    ['content' => $content] = seedPdfBlock($owner, $moduleContent, ['start_percentage' => 30]);

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id, 'contentId' => $content->id])
        ->assertSet('pdfCropEnabled', true);
});

it('shows whole pages again once cropping is switched off', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();
    ['content' => $content, 'pdf' => $pdf] = seedPdfBlock($owner, $moduleContent, ['start_percentage' => 30, 'end_percentage' => 70]);

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id, 'contentId' => $content->id])
        ->set('pdfCropEnabled', false)
        ->assertSet('pdfStartPercentage', 0)
        ->assertSet('pdfEndPercentage', 100)
        ->call('done');

    $pdf->refresh();
    expect((int) $pdf->start_percentage)->toBe(0)
        ->and((int) $pdf->end_percentage)->toBe(100);
});

it('tells the preview to draw crop handles while cropping', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();
    ['content' => $content] = seedPdfBlock($owner, $moduleContent);

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id, 'contentId' => $content->id])
        ->set('pdfCropEnabled', true)
        ->assertDispatched('pdf-preview-changed', fn ($name, $params) => $params['cropEditing'] === true);
});

it('previews a pasted youtube link from its start time', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id])
        ->set('type', 'video')
        ->set('videoSourceType', 'url')
        ->set('videoExternalUrl', 'https://youtu.be/dQw4w9WgXcQ')
        ->set('videoStartTime', '01:20')
        ->assertDispatched('video-preview-changed', fn ($name, $params) => $params['youtubeId'] === 'dQw4w9WgXcQ'
            && $params['url'] === 'https://youtu.be/dQw4w9WgXcQ'
            && $params['start'] === 80
            && $params['end'] === null);
});

it('previews a picked video file', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();
    $file = File::create(['user_id' => $owner->id, 'name' => 'Lecture.mp4', 'file_path' => 'uploads/lecture.mp4', 'file_type' => 'video', 'size' => 10]);

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id])
        ->set('type', 'video')
        ->set('videoFileId', (string) $file->id)
        ->set('videoEndTime', '1:02:03')
        ->assertDispatched('video-preview-changed', fn ($name, $params) => $params['url'] === asset('storage/uploads/lecture.mp4')
            && $params['youtubeId'] === null
            && $params['end'] === 3723);
});

it('only previews the video file of its owner', function () {
    ['owner' => $owner, 'mate' => $mate, 'moduleContent' => $moduleContent] = seedCourse();
    $file = File::create(['user_id' => $mate->id, 'name' => 'Theirs.mp4', 'file_path' => 'uploads/theirs.mp4', 'file_type' => 'video', 'size' => 10]);

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id])
        ->set('type', 'video')
        ->set('videoFileId', (string) $file->id)
        ->assertDispatched('video-preview-changed', fn ($name, $params) => $params['url'] === null);
});

it('rejects video times the player cannot read', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id])
        ->set('type', 'video')
        ->set('label', 'Clip')
        ->set('videoSourceType', 'url')
        ->set('videoExternalUrl', 'https://youtu.be/dQw4w9WgXcQ')
        ->set('videoStartTime', '80')
        ->call('save')
        ->assertHasErrors(['videoStartTime' => 'regex'])
        ->assertSee('Use minutes:seconds');
});

it('rejects a video end time before its start', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id])
        ->set('type', 'video')
        ->set('label', 'Clip')
        ->set('videoSourceType', 'url')
        ->set('videoExternalUrl', 'https://youtu.be/dQw4w9WgXcQ')
        ->set('videoStartTime', '05:00')
        ->set('videoEndTime', '01:00')
        ->call('save')
        ->assertHasErrors('videoEndTime');
});
