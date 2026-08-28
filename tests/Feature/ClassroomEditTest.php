<?php

use App\Models\{Classroom, User};
use Livewire\Livewire;

function seedEditableClassroom(): array
{
    $admin = User::factory()->create();
    $stranger = User::factory()->create();

    $classroom = Classroom::create(['title' => 'Original Name', 'admin_id' => $admin->id]);

    return compact('admin', 'stranger', 'classroom');
}

it('renames a class from the list', function () {
    ['admin' => $admin, 'classroom' => $classroom] = seedEditableClassroom();

    Livewire::actingAs($admin)->test('classes.index')
        ->call('startEditing', $classroom->id)
        ->assertSet('editingId', $classroom->id)
        ->assertSet('editingTitle', 'Original Name')
        ->set('editingTitle', '  Renamed Class  ')
        ->call('updateClassroom')
        ->assertHasNoErrors()
        ->assertSet('editingId', null)
        ->assertSee('Renamed Class');

    expect($classroom->refresh()->title)->toBe('Renamed Class');
});

it('renames a class from its management page', function () {
    ['admin' => $admin, 'classroom' => $classroom] = seedEditableClassroom();

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('startEditingTitle')
        ->assertSet('editingTitle', true)
        ->set('title', 'New Title')
        ->call('updateTitle')
        ->assertHasNoErrors()
        ->assertSet('editingTitle', false);

    expect($classroom->refresh()->title)->toBe('New Title');
});

it('rejects a blank class title', function () {
    ['admin' => $admin, 'classroom' => $classroom] = seedEditableClassroom();

    Livewire::actingAs($admin)->test('classes.index')
        ->call('startEditing', $classroom->id)
        ->set('editingTitle', '')
        ->call('updateClassroom')
        ->assertHasErrors(['editingTitle']);

    expect($classroom->refresh()->title)->toBe('Original Name');
});

it('stops a non-admin from renaming a class from the list', function () {
    ['stranger' => $stranger, 'classroom' => $classroom] = seedEditableClassroom();

    Livewire::actingAs($stranger)->test('classes.index')
        ->call('startEditing', $classroom->id)
        ->assertForbidden();
});

it('stops a non-admin from opening the management page to rename', function () {
    ['stranger' => $stranger, 'classroom' => $classroom] = seedEditableClassroom();

    Livewire::actingAs($stranger)->test('classes.show', ['classroom' => $classroom])
        ->assertForbidden();
});
