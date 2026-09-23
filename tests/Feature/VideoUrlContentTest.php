<?php

use App\Jobs\NotifyClassOfNewContent;
use App\Models\{Classroom, Course, Module, ModuleContent, VideoContent};
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function seedVideoModuleContent(): array
{
    $owner = User::factory()->create();

    $classroom = Classroom::create(['title' => 'Class V', 'admin_id' => $owner->id]);
    $course = Course::create(['title' => 'Course V', 'slug' => 'course-v', 'created_by' => $owner->id]);
    $classroom->courses()->attach($course->id);

    $module = Module::create(['course_id' => $course->id, 'title' => 'M1', 'slug' => 'm1-v', 'sort_order' => 1]);
    $moduleContent = ModuleContent::create(['module_id' => $module->id, 'label' => 'Lesson', 'sort_order' => 1]);

    return compact('owner', 'moduleContent');
}

it('saves a youtube link as-is without copying the video to the server', function () {
    Queue::fake();
    Process::fake();

    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedVideoModuleContent();

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id])
        ->set('type', 'video')
        ->set('label', 'Intro clip')
        ->set('videoSourceType', 'url')
        ->set('videoExternalUrl', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ')
        ->set('videoStartTime', '00:30')
        ->set('videoEndTime', '01:15')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('content.show', $moduleContent->id));

    $video = VideoContent::first();

    // Plays from the link, trim points intact.
    expect($video->file_url)->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ')
        ->and($video->start_time)->toBe('00:30')
        ->and($video->end_time)->toBe('01:15')
        ->and($moduleContent->fresh()->contents)->toHaveCount(1);

    // Nothing fetches the video: no background job and no yt-dlp run.
    expect(collect(array_keys(Queue::pushedJobs()))->reject(fn ($job) => $job === NotifyClassOfNewContent::class)->all())
        ->toBe([]);
    Process::assertNothingRan();
});
