<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;
use App\Models\Course;

class Classroom extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    public function courses()
    {
        return $this->belongsToMany(Course::class);
    }

    public function isAdministeredBy($user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $this->admin_id !== null && (int) $this->admin_id === (int) $userId;
    }

    public function hasMember($user): bool
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $this->users()->where('users.id', $userId)->exists();
    }

    /**
     * Public classes anyone may find and join: not mine, not already joined.
     */
    public function scopeJoinableBy($query, $user)
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where('is_public', true)
            ->where('admin_id', '!=', $userId)
            ->whereDoesntHave('users', fn ($u) => $u->where('users.id', $userId));
    }

    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('title', 'like', '%' . $term . '%')
                ->orWhereHas('admin', fn ($a) => $a->where('name', 'like', '%' . $term . '%'));
        });
    }
}
