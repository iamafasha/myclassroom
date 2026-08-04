<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('renders the searchable picker with the user files for every file-backed content type', function () {
    Storage::fake('public');
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();

    File::create(['user_id' => $owner->id, 'name' => 'Week One Slides', 'file_path' => 'uploads/slides.pdf', 'file_type' => 'pdf', 'size' => 2048]);
    File::create(['user_id' => $owner->id, 'name' => 'Lecture Recording', 'file_path' => 'uploads/lecture.mp4', 'file_type' => 'video', 'size' => 4096]);
    File::create(['user_id' => $owner->id, 'name' => 'Diagram', 'file_path' => 'uploads/diagram.png', 'file_type' => 'image', 'size' => 512]);

    $form = Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id]);

    $form->set('type', 'pdf')
        ->assertSee('fp-trigger', false)
        ->assertSee('Week One Slides', false)
        ->assertDontSee('-- Choose a PDF --');

    $form->set('type', 'video')->assertSee('Lecture Recording', false);
    $form->set('type', 'image')->assertSee('Diagram', false);
});

it('picks up a file uploaded inline without reloading the page', function () {
    Storage::fake('public');
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();

    $response = $this->actingAs($owner)
        ->post(route('files.upload'), ['file' => UploadedFile::fake()->create('handout.pdf', 30, 'application/pdf')]);

    $response->assertOk()
        ->assertJsonStructure(['id', 'name', 'type', 'size', 'url', 'uploaded'])
        ->assertJson(['name' => 'handout.pdf', 'type' => 'pdf']);

    // The picker selects the id it just got back, and the form accepts it.
    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id])
        ->set('type', 'pdf')
        ->set('label', 'Handout')
        ->set('pdfFileId', $response->json('id'))
        ->call('save')
        ->assertHasNoErrors();
});

it('keeps the pdf preview in step with the picked file', function () {
    Storage::fake('public');
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();

    $file = File::create(['user_id' => $owner->id, 'name' => 'Notes', 'file_path' => 'uploads/notes.pdf', 'file_type' => 'pdf']);

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id])
        ->set('type', 'pdf')
        ->set('pdfFileId', $file->id)
        ->assertDispatched('pdf-preview-changed', url: asset('storage/uploads/notes.pdf'));
});

it('lists a freshly uploaded file in the picker on the next render', function () {
    Storage::fake('public');
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();

    $form = Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id])->set('type', 'image');
    $form->assertSee('No images uploaded yet');

    File::create(['user_id' => $owner->id, 'name' => 'Just Uploaded', 'file_path' => 'uploads/new.png', 'file_type' => 'image', 'size' => 100]);

    $form->set('label', 'Anything')->assertSee('Just Uploaded', false);
});

it('saves an image from an external url without a file being picked', function () {
    Storage::fake('public');
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id])
        ->set('type', 'image')
        ->set('label', 'Remote picture')
        ->set('imageSourceType', 'url')
        ->set('imageFileId', '')
        ->set('imageExternalUrl', 'https://example.com/picture.jpg')
        ->call('save')
        ->assertHasNoErrors();
});
