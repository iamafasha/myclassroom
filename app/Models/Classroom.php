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
}
