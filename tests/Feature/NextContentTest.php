<?php

use App\Models\{Classroom, Content, Course, Module, ModuleContent, NoteContent, User};

function seedLessons(array $modulesWithLabels): array
{
    $owner = User::factory()->create();

    $classroom = Classroom::create(['title' => 'Class N', 'admin_id' => $owner->id]);
    $course = Course::create(['title' => 'Course N', 'slug' => 'course-n', 'created_by' => $owner->id]);
    $classroom->courses()->attach($course->id);

    $lessons = [];
    $moduleOrder = 1;

    foreach ($modulesWithLabels as $moduleTitle => $labels) {
        $module = Module::create([
            'course_id' => $course->id,
            'title' => $moduleTitle,
            'slug' => Illuminate\Support\Str::slug($moduleTitle),
            'sort_order' => $moduleOrder++,
        ]);

        foreach (array_values($labels) as $index => $label) {
            $moduleContent = ModuleContent::create([
                'module_id' => $module->id,
                'label' => $label,
                'sort_order' => $index + 1,
            ]);

            $note = new NoteContent();
            $note->content = $label;
            $note->save();
            $content = Content::create(['contentable_type' => NoteContent::class, 'contentable_id' => $note->id]);
            $moduleContent->contents()->attach($content->id, ['sort_order' => 1]);

            $lessons[$label] = $moduleContent;
        }
    }

    return [$owner, $lessons];
}

it('links to the next content in the same module', function () {
    [$owner, $lessons] = seedLessons(['Module One' => ['Intro', 'Deep Dive', 'Wrap Up']]);

    $this->actingAs($owner)->get(route('content.show', $lessons['Intro']->id))
        ->assertOk()
        ->assertSee('Next: Deep Dive')
        ->assertSee(route('content.show', $lessons['Deep Dive']->id), false);
});

it('rolls over to the first content of the next module', function () {
    [$owner, $lessons] = seedLessons([
        'Module One' => ['Intro', 'Wrap Up'],
        'Module Two' => ['Chapter Two Start', 'Chapter Two End'],
    ]);

    $this->actingAs($owner)->get(route('content.show', $lessons['Wrap Up']->id))
        ->assertOk()
        ->assertSee('Next: Chapter Two Start')
        ->assertSee(route('content.show', $lessons['Chapter Two Start']->id), false);
});

it('shows no next button on the last content of the course', function () {
    [$owner, $lessons] = seedLessons([
        'Module One' => ['Intro'],
        'Module Two' => ['The End'],
    ]);

    $this->actingAs($owner)->get(route('content.show', $lessons['The End']->id))
        ->assertOk()
        ->assertDontSee('Next:')
        ->assertSee("You've reached the last content in this course.", false);
});

it('follows sort order rather than creation order', function () {
    [$owner, $lessons] = seedLessons(['Module One' => ['First', 'Second']]);

    // Swap the two around; the next link should follow.
    $lessons['First']->update(['sort_order' => 2]);
    $lessons['Second']->update(['sort_order' => 1]);

    $this->actingAs($owner)->get(route('content.show', $lessons['Second']->id))
        ->assertOk()
        ->assertSee('Next: First');

    $this->actingAs($owner)->get(route('content.show', $lessons['First']->id))
        ->assertOk()
        ->assertDontSee('Next:');
});

it('breaks ties on id when two contents share a sort order', function () {
    [$owner, $lessons] = seedLessons(['Module One' => ['Alpha', 'Beta']]);

    $lessons['Alpha']->update(['sort_order' => 1]);
    $lessons['Beta']->update(['sort_order' => 1]);

    $this->actingAs($owner)->get(route('content.show', $lessons['Alpha']->id))
        ->assertOk()
        ->assertSee('Next: Beta');
});

it('keeps the next link beside the completion button', function () {
    [$owner, $lessons] = seedLessons(['Module One' => ['Intro', 'Deep Dive']]);

    $body = $this->actingAs($owner)->get(route('content.show', $lessons['Intro']->id))->assertOk()->getContent();

    $completePosition = strpos($body, 'Mark as Completed');
    $nextPosition = strpos($body, 'Next: Deep Dive');

    expect($completePosition)->not->toBeFalse();
    expect($nextPosition)->toBeGreaterThan($completePosition);
});
