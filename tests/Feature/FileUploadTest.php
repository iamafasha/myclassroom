<?php

use App\Models\{File, User};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('public'));

it('stores one file per request so uploads can run side by side', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('files.upload'), ['file' => UploadedFile::fake()->image('lecture.png')])
        ->assertOk()
        ->assertJsonStructure(['id', 'name']);

    $this->actingAs($user)
        ->postJson(route('files.upload'), ['file' => UploadedFile::fake()->create('clip.mp4', 120)])
        ->assertOk();

    expect(File::pluck('name')->all())->toEqualCanonicalizing(['lecture.png', 'clip.mp4']);

    $stored = File::pluck('file_path');
    expect($stored)->toHaveCount(2);
    $stored->each(fn ($path) => Storage::disk('public')->assertExists($path));
});

it('categorises the upload by extension and owns it to the uploader', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('files.upload'), ['file' => UploadedFile::fake()->create('notes.zip', 10)])->assertOk();

    $file = File::first();
    expect($file->file_type)->toBe('zip');
    expect($file->user_id)->toBe($user->id);
    // Recorded at upload time so the list can sort by size.
    expect($file->size)->toBe(10 * 1024);
});

it('uses the supplied name when one is given', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('files.upload'), [
        'file' => UploadedFile::fake()->image('IMG_2931.png'),
        'name' => 'Lecture Slides',
    ])->assertOk();

    expect(File::first()->name)->toBe('Lecture Slides');
});

it('returns a json error the queue can show on a failed upload', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('files.upload'), [])
        ->assertStatus(422)
        ->assertJsonPath('message', 'No file was received. It may be larger than the server allows.');

    expect(File::count())->toBe(0);
});

it('refuses uploads from guests', function () {
    $this->postJson(route('files.upload'), ['file' => UploadedFile::fake()->image('a.png')])
        ->assertStatus(401);

    expect(File::count())->toBe(0);
});

it('maps every known extension onto its filter type', function () {
    expect(File::typeForExtension('JPEG'))->toBe('image')
        ->and(File::typeForExtension('pdf'))->toBe('pdf')
        ->and(File::typeForExtension('docx'))->toBe('word')
        ->and(File::typeForExtension('xlsx'))->toBe('excel')
        ->and(File::typeForExtension('mov'))->toBe('video')
        ->and(File::typeForExtension('wav'))->toBe('audio')
        ->and(File::typeForExtension('rar'))->toBe('rar')
        ->and(File::typeForExtension(''))->toBe('other')
        ->and(File::typeForExtension(null))->toBe('other');
});
