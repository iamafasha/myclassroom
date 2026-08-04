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

    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where('name', 'like', '%' . $term . '%');
    }
}
