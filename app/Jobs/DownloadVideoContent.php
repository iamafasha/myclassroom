<?php

namespace App\Jobs;

use App\Models\VideoContent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Pulls a video down from an external link with yt-dlp, then repoints the content
 * at the local copy.
 *
 * This used to run inside the save request, which meant the teacher sat on the
 * form until PHP timed out. The content is saved against the source link first
 * and plays from it straight away; the local copy quietly takes over when it
 * lands. If the download never happens — no queue worker, no yt-dlp, a dead
 * link — the content keeps working off the original URL.
 */
class DownloadVideoContent implements ShouldQueue
{
    use Queueable;

    /** yt-dlp is slow on long videos; give it room but never forever. */
    public int $timeout = 1800;

    /** A failed download is not worth retrying: the link still plays. */
    public int $tries = 1;

    public function __construct(
        public int $videoContentId,
        public string $sourceUrl,
        public ?string $startTime = null,
        public ?string $endTime = null,
    ) {
    }

    public function handle(): void
    {
        $video = VideoContent::find($this->videoContentId);

        // Deleted, or edited to point somewhere else while we were queued.
        if (! $video || $video->file_url !== $this->sourceUrl) {
            return;
        }

        $binary = base_path('yt-dlp');

        if (! is_executable($binary)) {
            Log::warning('yt-dlp is missing or not executable; keeping the source link.', ['path' => $binary]);

            return;
        }

        $directory = storage_path('app/public/videos');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = 'yt_' . time() . '_' . Str::random(6) . '.mp4';
        $outputPath = $directory . '/' . $filename;

        $command = [$binary, '-f', 'best[ext=mp4]/best', '--force-overwrites', '-o', $outputPath];

        // Trimming at download time, so the saved copy is already just the clip.
        if ($this->startTime || $this->endTime) {
            $command[] = '--download-sections';
            $command[] = '*' . ($this->startTime ?: '00:00') . '-' . ($this->endTime ?: 'inf');
        }

        $command[] = $this->sourceUrl;

        // A list command runs without a shell, so the URL needs no escaping.
        $result = Process::timeout($this->timeout - 60)->run($command);

        if (! $result->successful() || ! file_exists($outputPath)) {
            Log::warning('yt-dlp could not fetch the video; keeping the source link.', [
                'video_content_id' => $video->id,
                'url' => $this->sourceUrl,
                'error' => Str::limit($result->errorOutput() ?: $result->output(), 500),
            ]);

            return;
        }

        // Re-read: an edit may have landed while the download ran.
        $video->refresh();

        if ($video->file_url !== $this->sourceUrl) {
            @unlink($outputPath);

            return;
        }

        $video->forceFill([
            'file_url' => asset('storage/videos/' . $filename),
            // The clip is already cut, so the player must not trim it again.
            'start_time' => null,
            'end_time' => null,
        ])->save();
    }
}
