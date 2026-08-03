<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Course extends Model
{
    protected $guarded = [];

    public function modules()
    {
        return $this->hasMany(Module::class);
    }

    public function classrooms()
    {
        return $this->belongsToMany(Classroom::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Courses the user may see: those in a class they attend or administer,
     * plus the ones they created themselves.
     */
    public function scopeVisibleTo($query, $user)
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where(function ($q) use ($userId) {
            $q->where('created_by', $userId)
                ->orWhereHas('classrooms', function ($classroom) use ($userId) {
                    $classroom->where('admin_id', $userId)
                        ->orWhereHas('users', fn ($u) => $u->where('users.id', $userId));
                });
        });
    }
}
