<?php

use App\Models\{Classroom, Course, Module, User};
use Livewire\Livewire;

/** One class with one course and module, owned by $teacher and attended by $learner. */
function seedPickerClass(string $title, User $teacher, User $learner): array
{
    $classroom = Classroom::create(['title' => $title, 'admin_id' => $teacher->id]);
    $classroom->users()->attach($learner->id);

    $course = Course::create([
        'title' => "$title Course",
        'slug' => Illuminate\Support\Str::slug("$title Course"),
        'created_by' => $teacher->id,
    ]);
    $classroom->courses()->attach($course->id, ['sort_order' => 0]);

    $module = Module::create([
        'course_id' => $course->id,
        'title' => "$title Module",
        'slug' => Illuminate\Support\Str::slug("$title Module"),
        'sort_order' => 1,
    ]);

    return [$classroom, $course, $module];
}

it('asks a learner in several classes to pick one before showing courses', function () {
    $teacher = User::factory()->create();
    $learner = User::factory()->create();
    [$alpha] = seedPickerClass('Alpha', $teacher, $learner);
    [$beta] = seedPickerClass('Beta', $teacher, $learner);

    Livewire::actingAs($learner)->test('classroom-dashboard')
        ->assertSet('selectedCourseId', null)
        ->assertSee('Which class do you want to see?')
        ->assertSee(route('dashboard', ['class' => $alpha->id]), false)
        ->assertSee(route('dashboard', ['class' => $beta->id]), false)
        ->assertDontSee('Alpha Course');
});

it('shows only the picked class courses', function () {
    $teacher = User::factory()->create();
    $learner = User::factory()->create();
    [$alpha, $alphaCourse, $alphaModule] = seedPickerClass('Alpha', $teacher, $learner);
    seedPickerClass('Beta', $teacher, $learner);

    Livewire::actingAs($learner)->withQueryParams(['class' => $alpha->id])->test('classroom-dashboard')
        ->assertSet('selectedCourseId', $alphaCourse->id)
        ->assertSet('selectedModuleId', $alphaModule->id)
        ->assertSee('Alpha Course')
        ->assertDontSee('Beta Course')
        ->assertDontSee('Which class do you want to see?');
});

it('skips the picker for a learner in a single class', function () {
    $teacher = User::factory()->create();
    $learner = User::factory()->create();
    [$alpha, $alphaCourse] = seedPickerClass('Alpha', $teacher, $learner);

    Livewire::actingAs($learner)->test('classroom-dashboard')
        ->assertSet('classroomId', $alpha->id)
        ->assertSet('selectedCourseId', $alphaCourse->id)
        ->assertDontSee('Which class do you want to see?');
});

it('adopts the class of a course opened directly', function () {
    $teacher = User::factory()->create();
    $learner = User::factory()->create();
    seedPickerClass('Alpha', $teacher, $learner);
    [$beta, $betaCourse] = seedPickerClass('Beta', $teacher, $learner);

    Livewire::actingAs($learner)->test('classroom-dashboard', ['courseId' => $betaCourse->id])
        ->assertSet('classroomId', $beta->id)
        ->assertSet('selectedCourseId', $betaCourse->id)
        ->assertDontSee('Alpha Course');
});

it('ignores a class the learner does not belong to', function () {
    $teacher = User::factory()->create();
    $learner = User::factory()->create();
    $stranger = User::factory()->create();
    seedPickerClass('Alpha', $teacher, $learner);
    seedPickerClass('Beta', $teacher, $learner);
    [$foreign] = seedPickerClass('Foreign', $teacher, $stranger);

    Livewire::actingAs($learner)->withQueryParams(['class' => $foreign->id])->test('classroom-dashboard')
        ->assertSet('classroomId', null)
        ->assertSee('Which class do you want to see?')
        ->assertDontSee('Foreign Course');
});

it('puts the course a learner was most recently promoted to first and opens it', function () {
    $teacher = User::factory()->create();
    $learner = User::factory()->create();
    [$alpha, $first] = seedPickerClass('Alpha', $teacher, $learner);

    $second = Course::create(['title' => 'Second Course', 'slug' => 'second-course', 'created_by' => $teacher->id]);
    $third = Course::create(['title' => 'Third Course', 'slug' => 'third-course', 'created_by' => $teacher->id]);
    $alpha->courses()->attach($second->id, ['sort_order' => 1]);
    $alpha->courses()->attach($third->id, ['sort_order' => 2]);
    $thirdModule = Module::create(['course_id' => $third->id, 'title' => 'Third Module', 'slug' => 'third-module', 'sort_order' => 1]);

    $alpha->promoteStudent($second, $learner, $teacher);
    $alpha->enrollmentFor($second, $learner)->forceFill(['promoted_at' => now()->subDay()])->save();
    $alpha->promoteStudent($third, $learner, $teacher);

    $component = Livewire::actingAs($learner)->test('classroom-dashboard')
        ->assertSet('selectedCourseId', $third->id)
        ->assertSet('selectedModuleId', $thirdModule->id);

    expect($component->instance()->courses->pluck('id')->all())
        ->toBe([$third->id, $first->id, $second->id]);
});

it('opens the first course when the learner has not been promoted', function () {
    $teacher = User::factory()->create();
    $learner = User::factory()->create();
    [$alpha, $first] = seedPickerClass('Alpha', $teacher, $learner);

    $second = Course::create(['title' => 'Second Course', 'slug' => 'second-course', 'created_by' => $teacher->id]);
    $alpha->courses()->attach($second->id, ['sort_order' => 1]);

    $component = Livewire::actingAs($learner)->test('classroom-dashboard')
        ->assertSet('selectedCourseId', $first->id);

    // The second course stays locked until a promotion, so it is not listed at all.
    expect($component->instance()->courses->pluck('id')->all())->toBe([$first->id]);
});
