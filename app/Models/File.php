<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class File extends Model
{
    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Files belong to whoever uploaded them. */
    public function scopeOwnedBy($query, $user)
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    /** Human readable size, e.g. "1.4 MB". Null for files uploaded before sizes were recorded. */
    public function sizeForHumans(): ?string
    {
        if ($this->size === null) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $this->size;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, $size < 10 && $unit > 0 ? 1 : 0) . ' ' . $units[$unit];
    }

    /** The shape the searchable file picker renders for one file. */
    public function pickerEntry(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'type' => $this->file_type,
            'size' => $this->sizeForHumans(),
            'url' => asset('storage/' . $this->file_path),
            'uploaded' => $this->created_at?->diffForHumans(),
        ];
    }

    /** Maps an uploaded file's extension onto the stored file_type value. */
    public static function typeForExtension(?string $extension): string
    {
        $extension = strtolower(trim((string) $extension));

        return match (true) {
            in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'svg']) => 'image',
            $extension === 'pdf' => 'pdf',
            in_array($extension, ['doc', 'docx']) => 'word',
            in_array($extension, ['xls', 'xlsx']) => 'excel',
            in_array($extension, ['mp4', 'mov', 'avi', 'webm']) => 'video',
            in_array($extension, ['mp3', 'wav']) => 'audio',
            default => $extension ?: 'other',
        };
    }

    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where('name', 'like', '%' . $term . '%');
    }
}
