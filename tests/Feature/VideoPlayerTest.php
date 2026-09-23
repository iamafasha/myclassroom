<?php

use App\Models\{Classroom, Content, Course, Module, ModuleContent, User, VideoContent};

function seedVideoLesson(string $fileUrl, array $attributes = []): array
{
    $owner = User::factory()->create();

    $classroom = Classroom::create(['title' => 'Class V', 'admin_id' => $owner->id]);
    $course = Course::create(['title' => 'Course V', 'slug' => 'course-v', 'created_by' => $owner->id]);
    $classroom->courses()->attach($course->id);

    $module = Module::create(['course_id' => $course->id, 'title' => 'M1', 'slug' => 'm1', 'sort_order' => 1]);
    $moduleContent = ModuleContent::create(['module_id' => $module->id, 'label' => 'Lesson', 'sort_order' => 1]);

    $video = new VideoContent();
    $video->file_url = $fileUrl;
    $video->name = $attributes['name'] ?? 'Lecture video';
    $video->start_time = $attributes['start_time'] ?? null;
    $video->end_time = $attributes['end_time'] ?? null;
    $video->save();

    $content = Content::create(['contentable_type' => VideoContent::class, 'contentable_id' => $video->id]);
    $moduleContent->contents()->attach($content->id, ['sort_order' => 1]);

    return compact('owner', 'moduleContent', 'video');
}

it('renders an uploaded video in the fitted player with fullscreen controls', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedVideoLesson('http://localhost/storage/uploads/clip.mp4');

    $response = $this->actingAs($owner)->get(route('content.show', $moduleContent->id))->assertOk();

    $response->assertSee('class="video-player cs-bleed"', false)
        ->assertSee('uploads/clip.mp4', false)
        // Capped so the whole frame stays on screen, letterboxed rather than cropped.
        ->assertSee('max-height: 70vh', false)
        ->assertSee('object-fit: contain', false)
        ->assertSee('.video-player:fullscreen', false)
        ->assertSee('data-role="fullscreen"', false);
});

it('runs media edge to edge on a phone without breaking fullscreen', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedVideoLesson('http://localhost/storage/uploads/clip.mp4');

    $this->actingAs($owner)->get(route('content.show', $moduleContent->id))
        ->assertOk()
        // Cancels the reading view's and the content card's side padding.
        ->assertSee('.content-read .cs-bleed', false)
        ->assertSee('margin-left: -32px', false)
        // The bleed must not follow the player into fullscreen.
        ->assertSee('.content-read .cs-bleed:fullscreen { margin: 0; }', false)
        // The desktop width cap is what kept the frame narrow on a phone.
        ->assertSee('.video-player.is-youtube { max-width: none; }', false);
});

it('offers the full control set on the uploaded player', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedVideoLesson('http://localhost/storage/uploads/clip.mp4');

    $response = $this->actingAs($owner)->get(route('content.show', $moduleContent->id))->assertOk();

    foreach (['play', 'seek', 'mute', 'volume', 'back', 'forward', 'time', 'speed', 'pip', 'fullscreen'] as $control) {
        $response->assertSee('data-role="' . $control . '"', false);
    }
});

it('keeps the clip window when the content limits start and end times', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedVideoLesson('http://localhost/storage/uploads/clip.mp4', [
        'start_time' => '00:00:30',
        'end_time' => '00:01:15',
    ]);

    $this->actingAs($owner)->get(route('content.show', $moduleContent->id))
        ->assertOk()
        ->assertSee('let startSec = 30;', false)
        ->assertSee('let endSec = 75;', false);
});

it('plays a youtube video from youtube in the custom player', function () {
    ['owner' => $owner, 'moduleContent' => $moduleContent] = seedVideoLesson('https://www.youtube.com/watch?v=dQw4w9WgXcQ', [
        'start_time' => '00:00:30',
        'end_time' => '00:01:15',
    ]);

    $response = $this->actingAs($owner)->get(route('content.show', $moduleContent->id))->assertOk();

    // Streams through YouTube's iframe API with its own chrome turned off...
    $response->assertSee('class="video-player cs-bleed is-youtube"', false)
        ->assertSee('https://www.youtube.com/iframe_api', false)
        ->assertSee("'controls': 0", false)
        ->assertSee('coursePlayerYoutubeMedia(', false)
        ->assertSee('dQw4w9WgXcQ', false)
        // ...keeps the clip window...
        ->assertSee('let startSec = 30;', false)
        ->assertSee('let endSec = 75;', false)
        // ...and never falls back to a server copy.
        ->assertDontSee('<video id="course-video-', false);

    // Same control set as an uploaded video.
    foreach (['play', 'seek', 'mute', 'volume', 'back', 'forward', 'time', 'speed', 'fullscreen'] as $control) {
        $response->assertSee('data-role="' . $control . '"', false);
    }
});
