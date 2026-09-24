<?php

namespace App\Support;

use App\Jobs\NotifyClassOfNewContent;
use App\Models\Content;
use App\Models\File;
use App\Models\ImageContent;
use App\Models\LinkContent;
use App\Models\ModuleContent;
use App\Models\NoteContent;
use App\Models\PdfNotesContent;
use App\Models\VideoContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Builds a content block from whatever the author dropped or pasted, picking the type
 * from the thing itself so they never choose one. Every other setting takes its default
 * and can be changed afterwards.
 */
class QuickBlock
{
    private const VIDEO_EXTENSIONS = ['mp4', 'mov', 'webm', 'm4v', 'avi'];

    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];

    /** A link: YouTube and video files play as video, images show inline, anything else is a link. */
    public static function fromUrl(string $url): Model
    {
        $label = self::labelForUrl($url);
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        if (self::isYoutube($url) || in_array($extension, self::VIDEO_EXTENSIONS, true)) {
            return VideoContent::forceCreate(['name' => $label, 'file_url' => $url]);
        }

        if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return ImageContent::forceCreate(['name' => $label, 'file_url' => $url]);
        }

        return LinkContent::forceCreate(['name' => $label, 'url' => $url]);
    }

    /** An uploaded file: PDFs, videos and images show inline; any other file becomes a download link. */
    public static function fromFile(File $file): Model
    {
        $url = asset('storage/' . $file->file_path);
        $label = self::labelForFile($file);

        return match ($file->file_type) {
            'pdf' => PdfNotesContent::forceCreate([
                'name' => $label,
                'file_url' => $url,
                'start_percentage' => 0,
                'end_percentage' => 100,
            ]),
            'video', 'mp4', 'mov', 'avi', 'webm' => VideoContent::forceCreate(['name' => $label, 'file_url' => $url]),
            'image', 'png', 'jpg', 'jpeg' => ImageContent::forceCreate(['name' => $label, 'file_url' => $url]),
            default => LinkContent::forceCreate(['name' => $label, 'url' => $url]),
        };
    }

    public static function fromText(string $text): Model
    {
        return NoteContent::forceCreate(['content' => $text]);
    }

    /**
     * Places the block in the lesson and announces it, the same as adding it through the form.
     * A lesson with no label yet borrows the block's.
     */
    public static function attach(ModuleContent $moduleContent, Model $contentable, $afterContentId = null, ?string $label = null): Content
    {
        $content = Content::create([
            'contentable_type' => get_class($contentable),
            'contentable_id' => $contentable->id,
        ]);

        $moduleContent->addBlock($content, false, $afterContentId);

        if (! $moduleContent->label && $label) {
            $moduleContent->label = $label;
        }
        if (! $moduleContent->slug) {
            $moduleContent->slug = Str::slug(($moduleContent->label ?: 'content') . '-' . time());
        }
        $moduleContent->save();

        NotifyClassOfNewContent::dispatch($moduleContent->id, $content->id, auth()->id());

        return $content;
    }

    public static function isYoutube(string $url): bool
    {
        return (bool) preg_match('~^https?://(www\.|m\.)?(youtube\.com|youtu\.be)/~i', $url);
    }

    /** The 11-character video id of a YouTube link, the same match the player uses. */
    public static function youtubeId(?string $url): ?string
    {
        if ($url && preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public static function labelForFile(File $file): string
    {
        return preg_replace('/\.[^.]+$/', '', $file->name) ?: 'Untitled';
    }

    /** The file name when the link points at one, otherwise the site's name. */
    public static function labelForUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $basename = urldecode(pathinfo($path, PATHINFO_FILENAME));

        if ($basename !== '' && pathinfo($path, PATHINFO_EXTENSION) !== '') {
            return Str::limit($basename, 250, '');
        }

        return preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST)) ?: 'Link';
    }
}
