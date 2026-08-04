<?php

use App\Models\{File, User};
use Livewire\Livewire;

function ownedFile(User $user, array $attributes = []): File
{
    return File::create(array_merge([
        'user_id' => $user->id,
        'name' => 'IMG_2931.png',
        'file_path' => 'uploads/27ce1255.png',
        'file_type' => 'image',
    ], $attributes));
}

it('renames a file without touching the stored path the urls are built from', function () {
    $user = User::factory()->create();
    $file = ownedFile($user);

    Livewire::actingAs($user)->test('files.index')
        ->call('startRename', $file->id)
        ->assertSet('editingName', 'IMG_2931.png')
        ->set('editingName', 'Lecture Slides')
        ->call('rename')
        ->assertHasNoErrors()
        ->assertSet('editingId', null)
        ->assertSee('Lecture Slides');

    $file->refresh();
    expect($file->name)->toBe('Lecture Slides');
    // The URL every other part of the system links to is derived from file_path.
    expect($file->file_path)->toBe('uploads/27ce1255.png');
    expect($file->file_type)->toBe('image');
});

it('trims the new name and rejects an empty one', function () {
    $user = User::factory()->create();
    $file = ownedFile($user);

    Livewire::actingAs($user)->test('files.index')
        ->call('startRename', $file->id)
        ->set('editingName', '   ')
        ->call('rename')
        ->assertHasErrors(['editingName' => 'required']);

    expect($file->fresh()->name)->toBe('IMG_2931.png');

    Livewire::actingAs($user)->test('files.index')
        ->call('startRename', $file->id)
        ->set('editingName', '  Padded Name  ')
        ->call('rename')
        ->assertHasNoErrors();

    expect($file->fresh()->name)->toBe('Padded Name');
});

it('rejects a name longer than the column allows', function () {
    $user = User::factory()->create();
    $file = ownedFile($user);

    Livewire::actingAs($user)->test('files.index')
        ->call('startRename', $file->id)
        ->set('editingName', str_repeat('a', 256))
        ->call('rename')
        ->assertHasErrors(['editingName' => 'max']);

    expect($file->fresh()->name)->toBe('IMG_2931.png');
});

it('will not rename a file belonging to someone else', function () {
    $user = User::factory()->create();
    $intruder = User::factory()->create();
    $file = ownedFile($user);

    expect(fn () => Livewire::actingAs($intruder)->test('files.index')->call('startRename', $file->id))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect(fn () => Livewire::actingAs($intruder)->test('files.index')
        ->set('editingId', $file->id)
        ->set('editingName', 'Stolen')
        ->call('rename'))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect($file->fresh()->name)->toBe('IMG_2931.png');
});

it('drops the edit when cancelled', function () {
    $user = User::factory()->create();
    $file = ownedFile($user);

    Livewire::actingAs($user)->test('files.index')
        ->call('startRename', $file->id)
        ->set('editingName', 'Half Typed')
        ->call('cancelRename')
        ->assertSet('editingId', null)
        ->assertSet('editingName', '')
        ->assertSee('IMG_2931.png');

    expect($file->fresh()->name)->toBe('IMG_2931.png');
});

it('keeps the renamed file findable by its new name and its old link', function () {
    $user = User::factory()->create();
    $file = ownedFile($user, ['name' => 'Old Name']);

    Livewire::actingAs($user)->test('files.index')
        ->call('startRename', $file->id)
        ->set('editingName', 'Week 3 Recording')
        ->call('rename');

    Livewire::actingAs($user)->test('files.index')
        ->set('search', 'week 3')
        ->assertSee('Week 3 Recording');

    // Content saved earlier points at storage/<file_path>; that lookup still resolves.
    expect(File::ownedBy($user)->where('file_path', 'uploads/27ce1255.png')->first()->id)->toBe($file->id);
});
