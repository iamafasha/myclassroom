<?php

use App\Models\{Classroom, Course, User};
use Livewire\Livewire;

function seedPublicClassroom(array $attributes = []): array
{
    $admin = User::factory()->create(['name' => 'Ada Lovelace']);
    $seeker = User::factory()->create();

    $classroom = Classroom::create(array_merge([
        'title' => 'Open Physics',
        'is_public' => true,
        'admin_id' => $admin->id,
    ], $attributes));

    $course = Course::create(['title' => 'Physics 101', 'slug' => 'physics-101', 'created_by' => $admin->id]);
    $classroom->courses()->attach($course->id);

    return compact('admin', 'seeker', 'classroom', 'course');
}

it('lists only public classes the user is not already part of', function () {
    ['admin' => $admin, 'seeker' => $seeker] = seedPublicClassroom();

    Classroom::create(['title' => 'Closed Chemistry', 'is_public' => false, 'admin_id' => $admin->id]);
    $joined = Classroom::create(['title' => 'Already Joined', 'is_public' => true, 'admin_id' => $admin->id]);
    $joined->users()->attach($seeker->id);
    $own = Classroom::create(['title' => 'My Own Public Class', 'is_public' => true, 'admin_id' => $seeker->id]);

    Livewire::actingAs($seeker)->test('classes.index')
        ->set('tab', 'discover')
        ->assertSee('Open Physics')
        ->assertDontSee('Closed Chemistry')
        ->assertDontSee('Already Joined')
        ->assertDontSee('My Own Public Class');

    expect(Classroom::joinableBy($seeker)->pluck('id')->all())
        ->not->toContain($joined->id)
        ->not->toContain($own->id);
});

it('searches public classes by title and by admin name', function () {
    ['seeker' => $seeker, 'admin' => $admin] = seedPublicClassroom();
    Classroom::create(['title' => 'Open Biology', 'is_public' => true, 'admin_id' => $admin->id]);
    Classroom::create(['title' => 'Open Latin', 'is_public' => true, 'admin_id' => User::factory()->create(['name' => 'Grace Hopper'])->id]);

    $page = Livewire::actingAs($seeker)->test('classes.index')->set('tab', 'discover');

    $page->set('search', 'physics')->assertSee('Open Physics')->assertDontSee('Open Biology')->assertDontSee('Open Latin');
    $page->set('search', 'Grace')->assertSee('Open Latin')->assertDontSee('Open Physics');
    $page->set('search', 'nothing here')->assertSee('No Matches');
});

it('hides soft deleted classes from discovery', function () {
    ['seeker' => $seeker, 'classroom' => $classroom] = seedPublicClassroom();

    $classroom->delete();

    Livewire::actingAs($seeker)->test('classes.index')
        ->set('tab', 'discover')
        ->assertDontSee('Open Physics');
});

it('lets a user join a public class and see its courses', function () {
    ['seeker' => $seeker, 'classroom' => $classroom, 'course' => $course] = seedPublicClassroom();

    expect(Course::visibleTo($seeker)->pluck('id')->all())->toBe([]);

    Livewire::actingAs($seeker)->test('classes.index')
        ->set('tab', 'discover')
        ->call('joinClassroom', $classroom->id)
        ->assertStatus(200);

    $this->assertDatabaseHas('classroom_user', ['classroom_id' => $classroom->id, 'user_id' => $seeker->id]);
    expect(Course::visibleTo($seeker)->pluck('id')->all())->toBe([$course->id]);

    // The class moves out of Discover and into the classes I attend.
    Livewire::actingAs($seeker)->test('classes.index')->set('tab', 'discover')->assertDontSee('Open Physics');
    Livewire::actingAs($seeker)->test('classes.index')->set('tab', 'attending')->assertSee('Open Physics');
});

it('refuses to join a private class', function () {
    ['seeker' => $seeker, 'classroom' => $classroom] = seedPublicClassroom(['is_public' => false]);

    Livewire::actingAs($seeker)->test('classes.index')
        ->call('joinClassroom', $classroom->id)
        ->assertStatus(403);

    $this->assertDatabaseMissing('classroom_user', ['classroom_id' => $classroom->id, 'user_id' => $seeker->id]);
});

it('does not double attach a user who joins twice', function () {
    ['seeker' => $seeker, 'classroom' => $classroom] = seedPublicClassroom();

    $page = Livewire::actingAs($seeker)->test('classes.index');
    $page->call('joinClassroom', $classroom->id);
    $page->call('joinClassroom', $classroom->id);

    expect($classroom->users()->where('users.id', $seeker->id)->count())->toBe(1);
});

it('lets the admin flip a class between public and private', function () {
    ['admin' => $admin, 'seeker' => $seeker, 'classroom' => $classroom] = seedPublicClassroom(['is_public' => false]);

    Livewire::actingAs($seeker)->test('classes.index')->set('tab', 'discover')->assertDontSee('Open Physics');

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])->call('toggleVisibility');
    expect($classroom->fresh()->is_public)->toBeTrue();

    Livewire::actingAs($seeker)->test('classes.index')->set('tab', 'discover')->assertSee('Open Physics');

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])->call('toggleVisibility');
    expect($classroom->fresh()->is_public)->toBeFalse();
});

it('creates a class with the chosen visibility', function () {
    $admin = User::factory()->create();

    Livewire::actingAs($admin)->test('classes.index')
        ->set('title', 'Public Maths')
        ->set('is_public', true)
        ->call('createClassroom')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('classrooms', ['title' => 'Public Maths', 'is_public' => true]);

    Livewire::actingAs($admin)->test('classes.index')
        ->set('title', 'Private Maths')
        ->call('createClassroom')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('classrooms', ['title' => 'Private Maths', 'is_public' => false]);
});
