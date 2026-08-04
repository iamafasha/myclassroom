<?php

use App\Models\{Classroom, Content, Course, Module, ModuleContent, NoteContent, QuizContent, User};
use Livewire\Livewire;

function seedProgressCourse(): array
{
    $owner = User::factory()->create(['name' => 'Owner']);
    $student = User::factory()->create(['name' => 'Student']);
    $newcomer = User::factory()->create(['name' => 'Newcomer']);

    $classroom = Classroom::create(['title' => 'Class P', 'admin_id' => $owner->id]);
    $classroom->users()->attach([$student->id, $newcomer->id]);

    $course = Course::create(['title' => 'Course P', 'slug' => 'course-p', 'created_by' => $owner->id]);
    $classroom->courses()->attach($course->id);

    $module = Module::create(['course_id' => $course->id, 'title' => 'Module P', 'slug' => 'module-p', 'sort_order' => 1]);

    $lessons = collect(['Lesson One', 'Lesson Two'])->mapWithKeys(function ($label, $index) use ($module) {
        $moduleContent = ModuleContent::create([
            'module_id' => $module->id,
            'label' => $label,
            'sort_order' => $index + 1,
        ]);

        $note = new NoteContent();
        $note->content = $label . ' body';
        $note->save();
        $content = Content::create(['contentable_type' => NoteContent::class, 'contentable_id' => $note->id]);
        $moduleContent->contents()->attach($content->id, ['sort_order' => 1]);

        return [$label => $moduleContent];
    });

    return compact('owner', 'student', 'newcomer', 'classroom', 'course', 'module', 'lessons');
}

it('keeps one persons completion off everybody elses lessons', function () {
    ['owner' => $owner, 'student' => $student, 'newcomer' => $newcomer, 'lessons' => $lessons] = seedProgressCourse();

    $lesson = $lessons['Lesson One'];
    $lesson->markCompletedFor($owner);
    $lesson->markCompletedFor($student);

    expect($lesson->isCompletedFor($owner))->toBeTrue()
        ->and($lesson->isCompletedFor($student))->toBeTrue()
        ->and($lesson->isCompletedFor($newcomer))->toBeFalse();
});

it('shows a freshly invited member nothing as completed', function () {
    ['owner' => $owner, 'newcomer' => $newcomer, 'course' => $course, 'module' => $module, 'lessons' => $lessons] = seedProgressCourse();

    // The owner works through the whole module before the newcomer ever arrives.
    foreach ($lessons as $lesson) {
        $lesson->markCompletedFor($owner);
    }

    Livewire::actingAs($newcomer)->test('classroom-dashboard', ['courseId' => $course->id, 'moduleId' => $module->id])
        ->assertDontSee('Completed');

    Livewire::actingAs($owner)->test('classroom-dashboard', ['courseId' => $course->id, 'moduleId' => $module->id])
        ->assertSee('Completed');

    $this->actingAs($newcomer)->get(route('home'))
        ->assertOk()
        ->assertSee('0 / 2 completed');

    $this->actingAs($owner)->get(route('home'))
        ->assertOk()
        ->assertSee('2 / 2 completed');
});

it('ticks a lesson off for the person clicking and nobody else', function () {
    ['owner' => $owner, 'student' => $student, 'course' => $course, 'module' => $module, 'lessons' => $lessons] = seedProgressCourse();

    $lesson = $lessons['Lesson One'];

    Livewire::actingAs($student)->test('classroom-dashboard', ['courseId' => $course->id, 'moduleId' => $module->id])
        ->call('toggleComplete', $lesson->id);

    expect($lesson->fresh()->isCompletedFor($student))->toBeTrue()
        ->and($lesson->fresh()->isCompletedFor($owner))->toBeFalse();

    // Toggling again clears it, still only for that person.
    Livewire::actingAs($student)->test('classroom-dashboard', ['courseId' => $course->id, 'moduleId' => $module->id])
        ->call('toggleComplete', $lesson->id);

    expect($lesson->fresh()->isCompletedFor($student))->toBeFalse();
});

it('marks a lesson complete for the reader through the content page button', function () {
    ['owner' => $owner, 'student' => $student, 'lessons' => $lessons] = seedProgressCourse();

    $lesson = $lessons['Lesson One'];

    $this->actingAs($student)->post(route('content.toggle-complete', $lesson->id))->assertRedirect();

    expect($lesson->fresh()->isCompletedFor($student))->toBeTrue()
        ->and($lesson->fresh()->isCompletedFor($owner))->toBeFalse();
});

it('scores a quiz per person', function () {
    ['owner' => $owner, 'student' => $student, 'newcomer' => $newcomer, 'module' => $module] = seedProgressCourse();

    $quizLesson = ModuleContent::create(['module_id' => $module->id, 'label' => 'Quiz Time', 'sort_order' => 3]);
    $quiz = new QuizContent();
    $quiz->title = 'Quiz Time';
    $quiz->questions = [
        ['question' => 'Pick A', 'options' => ['A', 'B'], 'correct_answers' => [0]],
        ['question' => 'Pick B', 'options' => ['A', 'B'], 'correct_answers' => [1]],
    ];
    $quiz->save();
    $content = Content::create(['contentable_type' => QuizContent::class, 'contentable_id' => $quiz->id]);
    $quizLesson->contents()->attach($content->id, ['sort_order' => 1]);

    $this->actingAs($student)
        ->post(route('content.submit-quiz', $quizLesson->id), ['answers' => [0 => [0], 1 => [1]]])
        ->assertRedirect();

    $this->actingAs($newcomer)
        ->post(route('content.submit-quiz', $quizLesson->id), ['answers' => [0 => [1], 1 => [1]]])
        ->assertRedirect();

    $quizLesson = $quizLesson->fresh();

    expect($quizLesson->quizScoreFor($student))->toBe('2/2')
        ->and($quizLesson->quizScoreFor($newcomer))->toBe('1/2')
        ->and($quizLesson->quizScoreFor($owner))->toBeNull()
        ->and($quizLesson->isCompletedFor($owner))->toBeFalse();

    $this->actingAs($student)->get(route('content.show', $quizLesson->id))
        ->assertOk()
        ->assertSee('Score: 2/2');

    $this->actingAs($newcomer)->get(route('content.show', $quizLesson->id))
        ->assertOk()
        ->assertSee('Score: 1/2')
        ->assertDontSee('Score: 2/2');
});

it('completes a lesson for the student who submits an exercise', function () {
    ['owner' => $owner, 'student' => $student, 'module' => $module] = seedProgressCourse();

    $lesson = ModuleContent::create(['module_id' => $module->id, 'label' => 'Exercise', 'sort_order' => 4]);
    $note = new NoteContent();
    $note->content = 'do the thing';
    $note->save();
    $content = Content::create(['contentable_type' => NoteContent::class, 'contentable_id' => $note->id]);
    $lesson->contents()->attach($content->id, ['sort_order' => 1, 'is_exercise' => true]);
    $pivotId = $lesson->contents()->first()->pivot->id;

    Livewire::actingAs($student)->test('content-show', ['moduleContent' => $lesson->id])
        ->set("submissionLinks.$pivotId", 'https://example.com/my-answer')
        ->call('submitExercise', $pivotId)
        ->assertHasNoErrors();

    expect($lesson->fresh()->isCompletedFor($student))->toBeTrue()
        ->and($lesson->fresh()->isCompletedFor($owner))->toBeFalse();
});
