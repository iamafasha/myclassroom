<?php

use App\Models\{File, User};
use Livewire\Livewire;

function seedFiles(User $user): void
{
    File::create(['user_id' => $user->id, 'name' => 'Holiday Photo', 'file_path' => 'uploads/a.png', 'file_type' => 'image']);
    File::create(['user_id' => $user->id, 'name' => 'Syllabus', 'file_path' => 'uploads/b.pdf', 'file_type' => 'pdf']);
    File::create(['user_id' => $user->id, 'name' => 'Grades Sheet', 'file_path' => 'uploads/c.xlsx', 'file_type' => 'excel']);
    File::create(['user_id' => $user->id, 'name' => 'Notes Archive', 'file_path' => 'uploads/d.zip', 'file_type' => 'zip']);
}

it('filters files by type', function () {
    $user = User::factory()->create();
    seedFiles($user);

    $page = Livewire::actingAs($user)->test('files.index');

    $page->assertSee('Holiday Photo')->assertSee('Syllabus')->assertSee('Notes Archive');

    $page->call('selectType', 'image')
        ->assertSee('Holiday Photo')
        ->assertDontSee('Syllabus')
        ->assertDontSee('Grades Sheet');

    $page->call('selectType', 'spreadsheet')
        ->assertSee('Grades Sheet')
        ->assertDontSee('Holiday Photo');
});

it('groups unrecognised extensions under other', function () {
    $user = User::factory()->create();
    seedFiles($user);

    Livewire::actingAs($user)->test('files.index')
        ->call('selectType', 'other')
        ->assertSee('Notes Archive')
        ->assertDontSee('Holiday Photo')
        ->assertDontSee('Syllabus');
});

it('falls back to all files for an unknown filter', function () {
    $user = User::factory()->create();
    seedFiles($user);

    Livewire::actingAs($user)->test('files.index')
        ->call('selectType', 'bogus')
        ->assertSet('type', 'all')
        ->assertSee('Holiday Photo')
        ->assertSee('Syllabus');
});

it('searches files by name and combines with the type filter', function () {
    $user = User::factory()->create();
    seedFiles($user);
    File::create(['user_id' => $user->id, 'name' => 'Syllabus Photo', 'file_path' => 'uploads/e.png', 'file_type' => 'image']);

    $page = Livewire::actingAs($user)->test('files.index');

    $page->set('search', 'syllabus')
        ->assertSee('Syllabus')
        ->assertSee('Syllabus Photo')
        ->assertDontSee('Holiday Photo');

    $page->call('selectType', 'image')
        ->assertSee('Syllabus Photo')
        ->assertDontSee('Grades Sheet');

    $page->set('search', 'nothing here')->assertSee('Clear filters');

    $page->call('clearFilters')
        ->assertSet('type', 'all')
        ->assertSet('search', '')
        ->assertSee('Holiday Photo');
});

it('only counts and lists files owned by the user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    seedFiles($user);
    File::create(['user_id' => $other->id, 'name' => 'Someone Else Doc', 'file_path' => 'uploads/x.pdf', 'file_type' => 'pdf']);

    Livewire::actingAs($user)->test('files.index')->assertDontSee('Someone Else Doc');

    expect(Livewire::actingAs($user)->test('files.index')->instance()->typeCounts())
        ->toMatchArray(['all' => 4, 'image' => 1, 'pdf' => 1, 'spreadsheet' => 1, 'other' => 1, 'video' => 0]);
});

it('ignores the custom name when several files are uploaded and clears the form', function () {
    Illuminate\Support\Facades\Storage::fake('public');
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('files.index')
        ->set('name', 'My Custom Name')
        ->set('uploads', [
            Illuminate\Http\UploadedFile::fake()->image('one.png'),
            Illuminate\Http\UploadedFile::fake()->create('two.pdf'),
        ])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('name', '')
        ->assertDispatched('uploads-cleared');

    expect(File::pluck('name')->all())->toEqualCanonicalizing(['one.png', 'two.pdf']);
});

it('uses the custom name for a single upload', function () {
    Illuminate\Support\Facades\Storage::fake('public');
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('files.index')
        ->set('name', 'My Custom Name')
        ->set('uploads', [Illuminate\Http\UploadedFile::fake()->image('one.png')])
        ->call('save')
        ->assertHasNoErrors();

    expect(File::pluck('name')->all())->toBe(['My Custom Name']);
});
