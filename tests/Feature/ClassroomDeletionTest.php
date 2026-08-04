<?php

use App\Models\{Classroom, Course, User};
use Livewire\Livewire;

function seedClassroom(): array
{
    $admin = User::factory()->create();
    $attendee = User::factory()->create();

    $classroom = Classroom::create(['title' => 'Class A', 'admin_id' => $admin->id]);
    $classroom->users()->attach($attendee->id);

    $course = Course::create(['title' => 'Course A', 'slug' => 'course-a', 'created_by' => $admin->id]);
    $classroom->courses()->attach($course->id);

    return compact('admin', 'attendee', 'classroom', 'course');
}

it('soft deletes a class the admin owns', function () {
    ['admin' => $admin, 'attendee' => $attendee, 'classroom' => $classroom, 'course' => $course] = seedClassroom();

    Livewire::actingAs($admin)->test('classes.index')
        ->call('deleteClassroom', $classroom->id)
        ->assertStatus(200)
        ->assertDontSee('Class A');

    $this->assertSoftDeleted('classrooms', ['id' => $classroom->id]);

    // Attendees and courses stay attached so the class can be restored.
    $this->assertDatabaseHas('classroom_user', ['classroom_id' => $classroom->id, 'user_id' => $attendee->id]);
    $this->assertDatabaseHas('classroom_course', ['classroom_id' => $classroom->id, 'course_id' => $course->id]);

    // But the deleted class no longer grants access to its courses.
    expect(Course::visibleTo($attendee)->pluck('id')->all())->toBe([]);
    Livewire::actingAs($attendee)->test('classes.index')->assertDontSee('Class A');
    $this->actingAs($admin)->get(route('classes.show', $classroom->id))->assertNotFound();
});

it('deletes a class from its management page', function () {
    ['admin' => $admin, 'classroom' => $classroom] = seedClassroom();

    Livewire::actingAs($admin)->test('classes.show', ['classroom' => $classroom])
        ->call('deleteClassroom')
        ->assertRedirect(route('classes.index'));

    $this->assertSoftDeleted('classrooms', ['id' => $classroom->id]);
});

it('stops an attendee from deleting a class', function () {
    ['attendee' => $attendee, 'classroom' => $classroom] = seedClassroom();

    Livewire::actingAs($attendee)->test('classes.index')
        ->call('deleteClassroom', $classroom->id)
        ->assertStatus(403);

    $this->assertDatabaseHas('classrooms', ['id' => $classroom->id, 'deleted_at' => null]);
});

it('lets an attendee leave a class', function () {
    ['attendee' => $attendee, 'classroom' => $classroom] = seedClassroom();

    Livewire::actingAs($attendee)->test('classes.index')
        ->call('leaveClassroom', $classroom->id)
        ->assertStatus(200);

    $this->assertDatabaseMissing('classroom_user', ['classroom_id' => $classroom->id, 'user_id' => $attendee->id]);
    $this->assertDatabaseHas('classrooms', ['id' => $classroom->id, 'deleted_at' => null]);
    expect(Course::visibleTo($attendee)->pluck('id')->all())->toBe([]);
});

it('stops the admin from leaving their own class and outsiders from leaving at all', function () {
    ['admin' => $admin, 'classroom' => $classroom] = seedClassroom();
    $stranger = User::factory()->create();

    Livewire::actingAs($admin)->test('classes.index')
        ->call('leaveClassroom', $classroom->id)
        ->assertStatus(403);

    Livewire::actingAs($stranger)->test('classes.index')
        ->call('leaveClassroom', $classroom->id)
        ->assertStatus(403);

    expect(Course::visibleTo($admin)->count())->toBe(1);
});
