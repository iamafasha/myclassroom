<?php

use App\Models\{Content, Course, File, Module, ModuleContent, PdfNotesContent, User};
use Livewire\Livewire;

function importOutline(): array
{
    return [
        [
            'title' => 'Foundations',
            'contents' => [
                ['title' => 'Introduction', 'startPage' => 1, 'endPage' => 14],
                ['title' => 'Getting Started', 'startPage' => 15, 'endPage' => 40],
            ],
        ],
        [
            'title' => 'Advanced',
            'contents' => [
                ['title' => 'Patterns', 'startPage' => 41, 'endPage' => 88],
            ],
        ],
    ];
}

it('builds modules, lessons and pdf slices from a submitted outline', function () {
    $owner = User::factory()->create();
    $file = File::create([
        'user_id' => $owner->id,
        'name' => 'Handbook',
        'file_path' => 'uploads/handbook.pdf',
        'file_type' => 'pdf',
    ]);

    Livewire::actingAs($owner)->test('courses.index')
        ->set('title', 'From The Handbook')
        ->set('slug', 'from-the-handbook')
        ->set('pdfImportFileId', $file->id)
        ->set('importStructure', importOutline())
        ->call('createCourseFromPdf')
        ->assertHasNoErrors()
        ->assertRedirect();

    $course = Course::where('slug', 'from-the-handbook')->firstOrFail();
    expect($course->created_by)->toBe($owner->id);

    $modules = Module::where('course_id', $course->id)->orderBy('sort_order')->get();
    expect($modules->pluck('title')->all())->toBe(['Foundations', 'Advanced'])
        ->and($modules->pluck('sort_order')->all())->toBe([1, 2]);

    $lessons = ModuleContent::where('module_id', $modules[0]->id)->orderBy('sort_order')->get();
    expect($lessons->pluck('label')->all())->toBe(['Introduction', 'Getting Started'])
        ->and($lessons->pluck('sort_order')->all())->toBe([1, 2]);

    $slice = $lessons[1]->contents->first()->contentable;
    expect($slice)->toBeInstanceOf(PdfNotesContent::class)
        ->and($slice->file_url)->toContain('uploads/handbook.pdf')
        ->and($slice->start_position)->toBe('15')
        ->and($slice->end_position)->toBe('40');

    expect((bool) $lessons[1]->contents->first()->pivot->is_exercise)->toBeFalse()
        ->and(PdfNotesContent::count())->toBe(3)
        ->and(Content::count())->toBe(3);
});

it('rejects an outline pointing at a file the user does not own', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $file = File::create([
        'user_id' => $stranger->id,
        'name' => 'Not Yours',
        'file_path' => 'uploads/not-yours.pdf',
        'file_type' => 'pdf',
    ]);

    Livewire::actingAs($owner)->test('courses.index')
        ->set('title', 'Sneaky')
        ->set('slug', 'sneaky')
        ->set('pdfImportFileId', $file->id)
        ->set('importStructure', importOutline())
        ->call('createCourseFromPdf')
        ->assertHasErrors('pdfImportFileId');

    expect(Course::where('slug', 'sneaky')->exists())->toBeFalse();
});

it('needs a non-empty structure to import', function () {
    $owner = User::factory()->create();
    $file = File::create([
        'user_id' => $owner->id,
        'name' => 'Handbook',
        'file_path' => 'uploads/handbook.pdf',
        'file_type' => 'pdf',
    ]);

    Livewire::actingAs($owner)->test('courses.index')
        ->set('title', 'Empty')
        ->set('slug', 'empty')
        ->set('pdfImportFileId', $file->id)
        ->set('importStructure', [])
        ->call('createCourseFromPdf')
        ->assertHasErrors('importStructure');

    expect(Course::where('slug', 'empty')->exists())->toBeFalse();
});

it('still creates a plain course when no PDF is chosen', function () {
    $owner = User::factory()->create();

    Livewire::actingAs($owner)->test('courses.index')
        ->set('title', 'Plain Course')
        ->set('slug', 'plain-course')
        ->call('createCourse')
        ->assertHasNoErrors();

    expect(Course::where('slug', 'plain-course')->exists())->toBeTrue()
        ->and(Module::count())->toBe(0);
});

it('lists only the creator\'s own PDFs for import', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    File::create(['user_id' => $owner->id, 'name' => 'Mine', 'file_path' => 'uploads/mine.pdf', 'file_type' => 'pdf']);
    File::create(['user_id' => $owner->id, 'name' => 'My Video', 'file_path' => 'uploads/mine.mp4', 'file_type' => 'video']);
    File::create(['user_id' => $other->id, 'name' => 'Theirs', 'file_path' => 'uploads/theirs.pdf', 'file_type' => 'pdf']);

    $component = Livewire::actingAs($owner)->test('courses.index');

    expect(collect($component->instance()->pdfImportFiles)->pluck('name')->all())->toBe(['Mine']);
});
