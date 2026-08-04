<?php

use App\Models\{Classroom, Content, ContentModuleContent, Course, File, Module, ModuleContent, NoteContent, User};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function seedCourse(): array
{
    $owner = User::factory()->create();
    $mate = User::factory()->create();

    $classroom = Classroom::create(['title' => 'Class A', 'admin_id' => $owner->id]);
    $classroom->users()->attach($mate->id);

    $course = Course::create(['title' => 'Course A', 'slug' => 'course-a', 'created_by' => $owner->id]);
    $classroom->courses()->attach($course->id);

    $module = Module::create(['course_id' => $course->id, 'title' => 'M1', 'slug' => 'm1', 'sort_order' => 1]);
    $moduleContent = ModuleContent::create(['module_id' => $module->id, 'label' => 'Lesson', 'sort_order' => 1]);

    $note = new NoteContent();
    $note->content = 'hello';
    $note->save();
    $content = Content::create(['contentable_type' => NoteContent::class, 'contentable_id' => $note->id]);
    $moduleContent->contents()->attach($content->id, ['sort_order' => 1, 'is_exercise' => true]);
    $pivot = ContentModuleContent::where('module_content_id', $moduleContent->id)->first();

    return compact('owner', 'mate', 'classroom', 'course', 'module', 'moduleContent', 'content', 'pivot');
}

it('lets only the class admin open class management pages', function () {
    ['owner' => $owner, 'mate' => $mate, 'classroom' => $classroom] = seedCourse();

    $this->actingAs($owner)->get(route('classes.show', $classroom))->assertOk();
    $this->actingAs($owner)->get(route('classes.courses.add', $classroom))->assertOk();

    $this->actingAs($mate)->get(route('classes.show', $classroom))->assertForbidden();
    $this->actingAs($mate)->get(route('classes.courses.add', $classroom))->assertForbidden();
});

it('lists only the classes and courses a user owns', function () {
    ['owner' => $owner, 'mate' => $mate] = seedCourse();

    Livewire::actingAs($owner)->test('classes.index')->assertSee('Class A');
    Livewire::actingAs($mate)->test('classes.index')->assertDontSee('Class A');

    Livewire::actingAs($owner)->test('courses.index')->assertSee('Course A');
    Livewire::actingAs($mate)->test('courses.index')->assertDontSee('Course A');
});

it('blocks non-owners from adding modules and content', function () {
    ['owner' => $owner, 'mate' => $mate, 'course' => $course, 'moduleContent' => $moduleContent] = seedCourse();

    Livewire::actingAs($owner)->test('classroom-dashboard', ['courseId' => $course->id])
        ->set('newModuleTitle', 'M2')->call('createModule');
    expect(Module::where('course_id', $course->id)->count())->toBe(2);

    Livewire::actingAs($mate)->test('classroom-dashboard', ['courseId' => $course->id])
        ->set('newModuleTitle', 'Sneaky')->call('createModule')->assertStatus(403);
    expect(Module::where('course_id', $course->id)->count())->toBe(2);

    Livewire::actingAs($mate)->test('classroom-dashboard', ['courseId' => $course->id])
        ->call('addContent')->assertStatus(403);

    $this->actingAs($mate)->get(route('content.create', $moduleContent->id))->assertForbidden();
    $this->actingAs($owner)->get(route('content.create', $moduleContent->id))->assertOk();
});

it('blocks non-owners from sorting modules and content', function () {
    ['owner' => $owner, 'mate' => $mate, 'course' => $course, 'module' => $module, 'moduleContent' => $moduleContent, 'content' => $content] = seedCourse();

    foreach (['moveModuleUp', 'moveModuleDown'] as $method) {
        Livewire::actingAs($mate)->test('classroom-dashboard', ['courseId' => $course->id])
            ->call($method, $module->id)->assertStatus(403);
    }

    foreach (['moveContentUp', 'moveContentDown'] as $method) {
        Livewire::actingAs($mate)->test('classroom-dashboard', ['courseId' => $course->id])
            ->call($method, $moduleContent->id)->assertStatus(403);
    }

    Livewire::actingAs($mate)->test('classroom-dashboard', ['courseId' => $course->id])
        ->call('moveContentToModule', $moduleContent->id, $module->id)->assertStatus(403);

    Livewire::actingAs($mate)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->call('moveContentItemUp', $content->id)->assertStatus(403);

    Livewire::actingAs($owner)->test('classroom-dashboard', ['courseId' => $course->id])
        ->call('moveModuleUp', $module->id)->assertStatus(200);
});

it('shows submissions and scoring only to the course owner', function () {
    ['owner' => $owner, 'mate' => $mate, 'moduleContent' => $moduleContent, 'pivot' => $pivot] = seedCourse();

    Livewire::actingAs($owner)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->assertSee('Submissions &amp; Scoring', escape: false)
        ->call('openScoring', $pivot->id)
        ->assertSee('Exercise Submissions &amp; Scoring', escape: false);

    $mateView = Livewire::actingAs($mate)->test('content-show', ['moduleContent' => $moduleContent->id]);
    $mateView->assertDontSee('Submissions &amp; Scoring', escape: false);
    $mateView->call('openScoring', $pivot->id)->assertStatus(403);

    // Forcing the property from the browser must not reveal the table either.
    Livewire::actingAs($mate)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->set('scoringPivotId', $pivot->id)
        ->assertDontSee('Exercise Submissions &amp; Scoring', escape: false);
});

it('still lets a classmate view content and submit their own exercise', function () {
    ['mate' => $mate, 'moduleContent' => $moduleContent, 'pivot' => $pivot] = seedCourse();

    Livewire::actingAs($mate)->test('content-show', ['moduleContent' => $moduleContent->id])
        ->set("submissionLinks.{$pivot->id}", 'https://example.com/mine')
        ->call('submitExercise', $pivot->id)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('content_exercise_answers', [
        'user_id' => $mate->id,
        'content_module_content_id' => $pivot->id,
        'submission_link' => 'https://example.com/mine',
    ]);
});

it('keeps content of an unrelated course out of reach', function () {
    ['moduleContent' => $moduleContent] = seedCourse();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)->get(route('content.show', $moduleContent->id))->assertForbidden();
});

it('shows each user only their own files', function () {
    Storage::fake('public');
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($owner)->postJson(route('files.upload'), [
        'name' => 'Owner Notes',
        'file' => UploadedFile::fake()->create('owner.pdf', 10, 'application/pdf'),
    ])->assertOk();

    $this->actingAs($other)->postJson(route('files.upload'), [
        'file' => UploadedFile::fake()->create('other.pdf', 10, 'application/pdf'),
    ])->assertOk();

    expect(File::ownedBy($owner)->pluck('name')->all())->toBe(['Owner Notes']);
    expect(File::ownedBy($other)->pluck('name')->all())->toBe(['other.pdf']);

    Livewire::actingAs($owner)->test('files.index')->assertSee('Owner Notes')->assertDontSee('other.pdf');
    Livewire::actingAs($other)->test('files.index')->assertSee('other.pdf')->assertDontSee('Owner Notes');
});

it('stops a user from deleting someone else file', function () {
    Storage::fake('public');
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($owner)->postJson(route('files.upload'), [
        'file' => UploadedFile::fake()->create('owner.pdf', 10, 'application/pdf'),
    ])->assertOk();

    $file = File::ownedBy($owner)->first();

    expect(fn () => Livewire::actingAs($other)->test('files.index')->call('delete', $file->id))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    $this->assertDatabaseHas('files', ['id' => $file->id]);

    Livewire::actingAs($owner)->test('files.index')->call('delete', $file->id);
    $this->assertDatabaseMissing('files', ['id' => $file->id]);
});

it('offers only your own files when building content', function () {
    Storage::fake('public');
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedCourse();
    $other = User::factory()->create();

    $mine = File::create(['user_id' => $owner->id, 'name' => 'My Slides', 'file_path' => 'uploads/mine.pdf', 'file_type' => 'pdf']);
    $theirs = File::create(['user_id' => $other->id, 'name' => 'Their Slides', 'file_path' => 'uploads/theirs.pdf', 'file_type' => 'pdf']);

    $form = Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id]);
    expect(collect($form->instance()->pdfFiles)->pluck('name')->all())->toBe(['My Slides']);

    // Picking a file you do not own is rejected server-side.
    $form->set('type', 'pdf')->set('pdfFileId', $theirs->id)
        ->call('save')->assertHasErrors('pdfFileId');

    $form->set('pdfFileId', $mine->id)->call('save')->assertHasNoErrors();
});

it('names the class a course belongs to in the course selector', function () {
    ['owner' => $owner, 'course' => $course] = seedCourse();

    // A course on its own: nothing to name.
    $loner = Course::create(['title' => 'Course B', 'slug' => 'course-b', 'created_by' => $owner->id]);

    expect($course->classLabel())->toBe('Class A')
        ->and($loner->classLabel())->toBeNull();

    Livewire::actingAs($owner)->test('classroom-dashboard', ['courseId' => $course->id])
        ->assertSee('in Class A');

    // Taught in two classes at once, both named.
    Classroom::create(['title' => 'Class B', 'admin_id' => $owner->id])->courses()->attach($course->id);

    expect($course->fresh()->classLabel())->toBe('Class A · Class B');
});
