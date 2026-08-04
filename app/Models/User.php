<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function classrooms()
    {
        return $this->belongsToMany(Classroom::class);
    }

    /** Sessions this user asked for, as a student. */
    public function sessionRequests()
    {
        return $this->hasMany(MentorSession::class, 'student_id');
    }

    /** Sessions asked of this user, as the mentor who owns the course. */
    public function mentorSessions()
    {
        return $this->hasMany(MentorSession::class, 'mentor_id');
    }
}
