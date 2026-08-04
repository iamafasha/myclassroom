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

    /** Maps an uploaded file's extension onto the stored file_type value. */
    public static function typeForExtension(?string $extension): string
    {
        $extension = strtolower(trim((string) $extension));

        return match (true) {
            in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'svg']) => 'image',
            $extension === 'pdf' => 'pdf',
            in_array($extension, ['doc', 'docx']) => 'word',
            in_array($extension, ['xls', 'xlsx']) => 'excel',
            in_array($extension, ['mp4', 'mov', 'avi']) => 'video',
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
