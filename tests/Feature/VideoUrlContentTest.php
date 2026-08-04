<?php

use App\Jobs\DownloadVideoContent;
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

it('saves a video link straight away and leaves the form', function () {
    Queue::fake();

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
        // The bug: the download ran inline, so the request timed out here instead.
        ->assertRedirect(route('content.show', $moduleContent->id));

    $video = VideoContent::first();

    // Playable from the link the moment it is saved, trim points intact.
    expect($video->file_url)->toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ')
        ->and($video->start_time)->toBe('00:30')
        ->and($video->end_time)->toBe('01:15')
        ->and($moduleContent->fresh()->contents)->toHaveCount(1);

    Queue::assertPushed(
        DownloadVideoContent::class,
        fn ($job) => $job->videoContentId === $video->id
            && $job->sourceUrl === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'
            && $job->startTime === '00:30'
            && $job->endTime === '01:15'
    );
});

it('does not queue a download for a video picked from uploaded files', function () {
    Queue::fake();

    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedVideoModuleContent();

    $file = App\Models\File::create([
        'user_id' => $owner->id,
        'name' => 'Clip',
        'file_path' => 'uploads/clip.mp4',
        'file_type' => 'video',
    ]);

    Livewire::actingAs($owner)->test('create-content-form', ['moduleContentId' => $moduleContent->id])
        ->set('type', 'video')
        ->set('label', 'Uploaded clip')
        ->set('videoSourceType', 'file')
        ->set('videoFileId', $file->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('content.show', $moduleContent->id));

    // The class announcement still goes out; only the download is skipped.
    Queue::assertNotPushed(DownloadVideoContent::class);
});

it('leaves the content on its link when the download fails', function () {
    Process::fake(['*' => Process::result(output: 'ERROR: unavailable', exitCode: 1)]);

    $video = new VideoContent();
    $video->name = 'Clip';
    $video->file_url = 'https://example.com/clip.mp4';
    $video->save();

    (new DownloadVideoContent($video->id, 'https://example.com/clip.mp4'))->handle();

    // Still playable from the source: a failed fetch must not break the content.
    expect($video->fresh()->file_url)->toBe('https://example.com/clip.mp4');
});

it('does not fetch anything for a content that moved on while queued', function () {
    Process::fake();

    $video = new VideoContent();
    $video->name = 'Clip';
    $video->file_url = 'https://example.com/clip.mp4';
    $video->save();

    // The teacher edited the content to a different source after queueing.
    (new DownloadVideoContent($video->id, 'https://example.com/old.mp4'))->handle();

    Process::assertNothingRan();
    expect($video->fresh()->file_url)->toBe('https://example.com/clip.mp4');
});
